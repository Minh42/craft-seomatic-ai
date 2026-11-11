<?php

namespace seomatic\craftai\services;

use Craft;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\elements\Entry;
use craft\elements\User;
use craft\fields\Assets as AssetsField;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\models\Site;
use DateTime;
use DateTimeInterface;
use seomatic\craftai\helpers\ApiException;
use seomatic\craftai\helpers\Compat;
use seomatic\craftai\Plugin;
use Throwable;
use yii\base\Component;

/**
 * Entries: listing, serialization, saving and creating, per the contract.
 */
class Entries extends Component
{
    /** Preferred body field handles, in order. */
    public const BODY_HANDLES = ['body', 'content', 'postContent', 'articleBody', 'text', 'richText', 'copy', 'mainContent'];

    public const MAX_TITLE = 255;
    public const MAX_META_TEXT = 1000;
    public const MAX_TEXT = 1000000; // 1 MB of text per field

    /** Top-level keys save-entry / create-entry understand. */
    private const KNOWN_KEYS = ['id', 'siteId', 'section', 'type', 'title', 'slug', 'body', 'fields', 'meta', 'enabled', 'postDate', 'featuredImage'];

    // ---------------------------------------------------------------------
    // Fields
    // ---------------------------------------------------------------------

    public function fieldKind(FieldInterface $field): string
    {
        if (is_a($field, SeomaticBridge::FIELD_CLASS)) {
            return 'seomatic';
        }
        if ($field instanceof PlainText) {
            return 'text';
        }
        if (is_a($field, 'craft\\htmlfield\\HtmlField')
            || is_a($field, 'craft\\ckeditor\\Field')
            || is_a($field, 'craft\\redactor\\Field')) {
            return 'html';
        }
        if ($field instanceof Matrix) {
            return 'matrix';
        }
        if ($field instanceof AssetsField) {
            return 'assets';
        }
        return 'other';
    }

    public static function isTranslatable(FieldInterface $field): bool
    {
        $method = property_exists($field, 'translationMethod') ? $field->translationMethod : 'none';
        return $method !== null && $method !== 'none';
    }

    public function isWritable(FieldInterface $field): bool
    {
        $kind = $this->fieldKind($field);
        return $kind === 'text' || $kind === 'html';
    }

    /** @return FieldInterface[] */
    public function layoutFields(?FieldLayout $layout): array
    {
        if (!$layout) {
            return [];
        }
        try {
            return array_values($layout->getCustomFields());
        } catch (Throwable $e) {
            Craft::error('SEOmatic AI could not read a field layout: ' . $e->getMessage(), __METHOD__);
            return [];
        }
    }

    /** @return array<int, array{handle:string,name:string,kind:string,writable:bool}> */
    public function describeFields(?FieldLayout $layout): array
    {
        $out = [];
        foreach ($this->layoutFields($layout) as $field) {
            $out[] = [
                'handle' => (string)$field->handle,
                'name' => (string)$field->name,
                'kind' => $this->fieldKind($field),
                'writable' => $this->isWritable($field),
                // false = one value shared by every site: writing it for one
                // site changes all of them.
                'translatable' => self::isTranslatable($field),
            ];
        }
        return $out;
    }

    public function bodyField(?FieldLayout $layout): ?FieldInterface
    {
        $byHandle = [];
        $firstHtml = null;
        foreach ($this->layoutFields($layout) as $field) {
            if (!$this->isWritable($field)) {
                continue;
            }
            $byHandle[$field->handle] = $field;
            if ($firstHtml === null && $this->fieldKind($field) === 'html') {
                $firstHtml = $field;
            }
        }
        foreach (self::BODY_HANDLES as $handle) {
            if (isset($byHandle[$handle])) {
                return $byHandle[$handle];
            }
        }
        return $firstHtml;
    }

    public function findLayoutField(?FieldLayout $layout, string $handle): ?FieldInterface
    {
        foreach ($this->layoutFields($layout) as $field) {
            if ($field->handle === $handle) {
                return $field;
            }
        }
        return null;
    }

    // ---------------------------------------------------------------------
    // Sections
    // ---------------------------------------------------------------------

    public function describeSections(Site $site): array
    {
        $out = [];
        foreach (Compat::sectionsForSite((int)$site->id) as $section) {
            $types = [];
            foreach ($section->getEntryTypes() as $type) {
                $types[] = [
                    'handle' => (string)$type->handle,
                    'name' => (string)$type->name,
                    'fields' => $this->describeFields($type->getFieldLayout()),
                ];
            }
            $out[] = [
                'handle' => (string)$section->handle,
                'name' => (string)$section->name,
                'type' => (string)$section->type,
                'entryTypes' => $types,
            ];
        }
        return $out;
    }

    // ---------------------------------------------------------------------
    // Reading
    // ---------------------------------------------------------------------

    /** @return int[] */
    private function sectionIdsForSite(int $siteId): array
    {
        return array_map(fn(Section $s) => (int)$s->id, Compat::sectionsForSite($siteId));
    }

    public function list(Site $site, ?string $sectionHandle, ?string $slug, int $offset, int $limit): array
    {
        $sectionIds = $this->sectionIdsForSite((int)$site->id);
        if ($sectionHandle !== null) {
            $section = Compat::sectionByHandle($sectionHandle);
            if (!$section || !in_array((int)$section->id, $sectionIds, true)) {
                throw new ApiException('not_found', 'No section with that handle on this site.', 404);
            }
            $sectionIds = [(int)$section->id];
        }
        if ($sectionIds === []) {
            return ['total' => 0, 'entries' => []];
        }
        $query = Entry::find()
            ->siteId((int)$site->id)
            ->status(null)
            ->sectionId($sectionIds)
            ->orderBy(['elements.id' => SORT_ASC]);
        if ($slug !== null) {
            $query->slug(\craft\helpers\Db::escapeParam($slug));
        }
        $total = (int)(clone $query)->count();
        $entries = $query->offset($offset)->limit($limit)->all();
        return [
            'total' => $total,
            'entries' => array_map(fn(Entry $e) => $this->summary($e), $entries),
        ];
    }

    public function find(int $id, int $siteId): ?Entry
    {
        $entry = Entry::find()->id($id)->siteId($siteId)->status(null)->one();
        if (!$entry instanceof Entry || empty($entry->sectionId)) {
            // Nested (Matrix) entries have no section: not ours to edit.
            return null;
        }
        return $entry;
    }

    public function summary(Entry $entry): array
    {
        $section = Compat::entrySection($entry);
        $type = null;
        try {
            $type = $entry->getType();
        } catch (Throwable $e) {
        }
        return [
            'id' => (int)$entry->id,
            'siteId' => (int)$entry->siteId,
            'title' => $entry->title,
            'slug' => $entry->slug,
            'uri' => $entry->uri,
            'url' => $entry->getUrl(),
            'status' => (string)$entry->getStatus(),
            'enabled' => (bool)$entry->enabled && (bool)$entry->getEnabledForSite(),
            'sectionHandle' => $section ? (string)$section->handle : null,
            'typeHandle' => $type ? (string)$type->handle : null,
            'dateUpdated' => self::date($entry->dateUpdated),
            'postDate' => self::date($entry->postDate),
        ];
    }

    public function full(Entry $entry): array
    {
        $layout = $entry->getFieldLayout();
        $bodyField = $this->bodyField($layout);
        $out = $this->summary($entry);
        $out['bodyField'] = $bodyField ? (string)$bodyField->handle : null;
        $out['body'] = $bodyField ? $this->textValue($entry, $bodyField) : null;
        $out['bodySource'] = $bodyField ? 'field' : null;
        if (!$bodyField) {
            // Read-only grounding: the text of a Matrix body (nested entries
            // on Craft 5, Matrix blocks on Craft 4). Never written to.
            $matrixBody = $this->matrixText($entry);
            if ($matrixBody !== null) {
                $out['body'] = $matrixBody;
                $out['bodySource'] = 'matrix';
            }
        }
        $out['fields'] = $this->describeFields($layout);
        $out['meta'] = $this->readMeta($entry);
        $out['images'] = $this->images($entry);
        return $out;
    }

    /**
     * The text/html content of the entry's first Matrix field that has any,
     * block by block in order, joined with blank lines. Null when there is
     * none. Read-only.
     */
    public function matrixText(Entry $entry): ?string
    {
        foreach ($this->layoutFields($entry->getFieldLayout()) as $field) {
            if (!$field instanceof Matrix) {
                continue;
            }
            try {
                $value = $entry->getFieldValue($field->handle);
                if ($value instanceof \craft\elements\db\ElementQueryInterface) {
                    $blocks = (clone $value)->limit(200)->all();
                } elseif (is_iterable($value)) {
                    $blocks = $value;
                } else {
                    $blocks = [];
                }
                $parts = [];
                foreach ($blocks as $block) {
                    foreach ($this->layoutFields($block->getFieldLayout()) as $inner) {
                        if (!$this->isWritable($inner)) {
                            continue;
                        }
                        $text = $this->textValue($block, $inner);
                        if ($text !== null && trim($text) !== '') {
                            $parts[] = $this->fieldKind($inner) === 'html' ? $text : '<p>' . htmlspecialchars($text, ENT_QUOTES) . '</p>';
                        }
                    }
                }
                if ($parts !== []) {
                    return implode("\n\n", $parts);
                }
            } catch (Throwable $e) {
                Craft::warning('SEOmatic AI could not read Matrix field ' . $field->handle . ': ' . $e->getMessage(), __METHOD__);
            }
        }
        return null;
    }

    /** Raw stored text of a text/html field (not the rendered form). */
    public function textValue(\craft\base\ElementInterface $entry, FieldInterface $field): ?string
    {
        $value = $entry->getFieldValue($field->handle);
        if ($value === null) {
            return null;
        }
        if (is_object($value) && method_exists($value, 'getRawContent')) {
            return (string)$value->getRawContent();
        }
        if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
            return (string)$value;
        }
        return null;
    }

    public function readMeta(Entry $entry): array
    {
        $bridge = Plugin::$plugin->seomaticBridge;
        $field = $bridge->findField($entry);
        $row = Plugin::$plugin->meta->get((int)$entry->id, (int)$entry->siteId);
        if ($field) {
            $fromField = $bridge->readMeta($entry, $field);
            $native = array_flip($bridge->nativeKeys($field));
            $meta = ['title' => null, 'description' => null, 'canonical' => null, 'noindex' => false];
            foreach ($meta as $k => $default) {
                $meta[$k] = isset($native[$k]) ? $fromField[$k] : ($row[$k] ?? $default);
            }
            return $meta + ['source' => 'seomatic_field', 'stored' => self::anyStored($meta)];
        }
        if ($row) {
            return $row + ['source' => 'plugin', 'stored' => self::anyStored($row)];
        }
        return ['title' => null, 'description' => null, 'canonical' => null, 'noindex' => false, 'source' => 'none', 'stored' => false];
    }

    private static function anyStored(array $meta): bool
    {
        return ($meta['title'] ?? null) !== null
            || ($meta['description'] ?? null) !== null
            || ($meta['canonical'] ?? null) !== null
            || !empty($meta['noindex']);
    }

    public function images(Entry $entry): array
    {
        $out = [];
        foreach ($this->layoutFields($entry->getFieldLayout()) as $field) {
            if (!$field instanceof AssetsField) {
                continue;
            }
            try {
                $value = $entry->getFieldValue($field->handle);
                if ($value instanceof AssetQuery) {
                    $assets = (clone $value)->status(null)->limit(50)->all();
                } elseif (is_iterable($value)) {
                    $assets = $value;
                } else {
                    $assets = [];
                }
                foreach ($assets as $asset) {
                    if (!$asset instanceof Asset || $asset->kind !== Asset::KIND_IMAGE) {
                        continue;
                    }
                    $out[] = [
                        'id' => (int)$asset->id,
                        'url' => $asset->getUrl(),
                        'alt' => $asset->alt ?? null,
                        'field' => (string)$field->handle,
                    ];
                }
            } catch (Throwable $e) {
                Craft::warning('SEOmatic AI could not read images of field ' . $field->handle . ': ' . $e->getMessage(), __METHOD__);
            }
        }
        return $out;
    }

    // ---------------------------------------------------------------------
    // Writing
    // ---------------------------------------------------------------------

    /**
     * save-entry.
     * @return array{entry:array,written:string[],dropped:array}
     */
    public function save(Entry $entry, array $payload): array
    {
        $written = [];
        $dropped = [];
        $pendingMeta = $this->applyPayload($entry, $payload, $written, $dropped, false);
        $this->saveOrFail($entry);
        $this->storePluginMeta($entry, $pendingMeta);
        $fresh = $this->find((int)$entry->id, (int)$entry->siteId) ?? $entry;
        // Meta kept by this plugin changes the page without changing the
        // entry, so page caches (Blitz) would not notice on their own.
        Plugin::$plugin->pageCaches->entryChanged($fresh);
        return ['entry' => $this->full($fresh), 'written' => $written, 'dropped' => $dropped];
    }

    /**
     * create-entry.
     * @return array{entry:array,written:string[],dropped:array}
     */
    public function create(Site $site, array $payload): array
    {
        $sectionHandle = $payload['section'] ?? null;
        if (!is_string($sectionHandle) || $sectionHandle === '') {
            throw new ApiException('invalid', '`section` (a section handle) is required.', 400);
        }
        $section = Compat::sectionByHandle($sectionHandle);
        if (!$section) {
            throw new ApiException('not_found', 'No section with that handle.', 404);
        }
        if (!isset($section->getSiteSettings()[(int)$site->id])) {
            throw new ApiException('invalid', 'That section is not enabled for this site.', 400);
        }
        if ($section->type === Section::TYPE_SINGLE) {
            throw new ApiException('unsupported', 'A single has exactly one entry; edit it with save-entry instead.', 400);
        }
        $types = $section->getEntryTypes();
        if ($types === []) {
            throw new ApiException('unsupported', 'That section has no entry types.', 400);
        }
        $type = $types[0];
        if (array_key_exists('type', $payload) && $payload['type'] !== null) {
            $type = null;
            foreach ($types as $t) {
                if (is_string($payload['type']) && $t->handle === $payload['type']) {
                    $type = $t;
                }
            }
            if (!$type) {
                throw new ApiException('invalid', 'That entry type does not belong to the section.', 400);
            }
        }
        if (!array_key_exists('title', $payload) && $type->hasTitleField) {
            throw new ApiException('invalid', '`title` is required.', 400);
        }

        /** @var EntryType $type */
        $entry = new Entry();
        $entry->sectionId = (int)$section->id;
        $entry->typeId = (int)$type->id;
        $entry->siteId = (int)$site->id;
        $author = $this->defaultAuthor();
        if ($author) {
            if (method_exists($entry, 'setAuthorId')) {
                $entry->setAuthorId((int)$author->id);
            } else {
                $entry->authorId = (int)$author->id;
            }
        }
        // New entries stay disabled unless the payload says otherwise.
        $entry->enabled = false;

        $written = [];
        $dropped = [];
        $pendingMeta = $this->applyPayload($entry, $payload, $written, $dropped, true);

        // Craft propagates a new entry to the section's other sites with
        // this site's content (title, slug, body). Those copies must not go
        // live in another site's language: they are created disabled, and
        // only the requested site gets the requested status.
        // Only the sites Craft itself propagates to (the section's
        // propagation method): naming any other site in the map would make
        // Craft create a copy there too.
        $otherSites = [];
        try {
            $supported = \craft\helpers\ElementHelper::supportedSitesForElement($entry);
        } catch (Throwable $e) {
            $supported = [];
        }
        foreach ($supported as $info) {
            $sid = (int)($info['siteId'] ?? 0);
            if ($sid !== (int)$site->id && !empty($info['propagate'])) {
                $otherSites[] = $sid;
            }
        }
        if ($otherSites !== []) {
            $map = array_fill_keys($otherSites, false);
            $map[(int)$site->id] = (bool)$entry->enabled;
            $entry->setEnabledForSite($map);
        }

        $this->saveOrFail($entry);
        $this->storePluginMeta($entry, $pendingMeta);

        if (array_key_exists('featuredImage', $payload) && $payload['featuredImage'] !== null) {
            $this->attachFeaturedImage($entry, $payload['featuredImage'], $written, $dropped);
        }

        // Which other sites now hold a (disabled) copy, per the section's
        // propagation method.
        $propagatedTo = [];
        $copies = Entry::find()->id((int)$entry->id)->siteId('*')->status(null)->all();
        foreach ($copies as $copy) {
            if ((int)$copy->siteId !== (int)$site->id) {
                $propagatedTo[] = ['siteId' => (int)$copy->siteId, 'enabled' => (bool)$copy->enabled && (bool)$copy->getEnabledForSite()];
            }
        }

        $fresh = $this->find((int)$entry->id, (int)$entry->siteId) ?? $entry;
        return ['entry' => $this->full($fresh), 'written' => $written, 'dropped' => $dropped, 'propagatedTo' => $propagatedTo];
    }

    /**
     * Applies title/slug/body/fields/enabled/postDate and SEOmatic-field meta
     * to the unsaved entry. Returns the plugin-store meta changes to write
     * after a successful save (or null).
     */
    private function applyPayload(Entry $entry, array $payload, array &$written, array &$dropped, bool $isNew): ?array
    {
        foreach (array_keys($payload) as $key) {
            if (!in_array($key, self::KNOWN_KEYS, true)) {
                $dropped[] = ['key' => (string)$key, 'reason' => 'unknown key'];
            }
        }
        if (!$isNew && array_key_exists('featuredImage', $payload)) {
            $dropped[] = ['key' => 'featuredImage', 'reason' => 'only create-entry accepts featuredImage'];
        }

        $layout = $entry->getFieldLayout();
        $type = null;
        try {
            $type = $entry->getType();
        } catch (Throwable $e) {
        }

        if (array_key_exists('title', $payload)) {
            $v = $payload['title'];
            if ($type && !$type->hasTitleField) {
                $dropped[] = ['key' => 'title', 'reason' => 'this entry type sets its title automatically'];
            } elseif (!is_string($v) || trim($v) === '') {
                $dropped[] = ['key' => 'title', 'reason' => 'not a non-empty string'];
            } elseif (mb_strlen($v) > self::MAX_TITLE) {
                $dropped[] = ['key' => 'title', 'reason' => 'longer than ' . self::MAX_TITLE . ' characters'];
            } else {
                $entry->title = trim($v);
                $written[] = 'title';
            }
        }

        if (array_key_exists('slug', $payload)) {
            $v = $payload['slug'];
            if (!is_string($v) || trim($v) === '' || mb_strlen($v) > self::MAX_TITLE) {
                $dropped[] = ['key' => 'slug', 'reason' => 'not a non-empty string of at most 255 characters'];
            } else {
                $entry->slug = trim($v);
                $written[] = 'slug';
            }
        }

        if (array_key_exists('body', $payload)) {
            $v = $payload['body'];
            $bodyField = $this->bodyField($layout);
            if (!$bodyField) {
                $dropped[] = ['key' => 'body', 'reason' => 'this entry type has no body field'];
            } elseif (!is_string($v)) {
                $dropped[] = ['key' => 'body', 'reason' => 'not a string'];
            } elseif (strlen($v) > self::MAX_TEXT) {
                $dropped[] = ['key' => 'body', 'reason' => 'larger than 1 MB'];
            } else {
                $entry->setFieldValue($bodyField->handle, $v);
                $written[] = 'body';
            }
        }

        if (array_key_exists('fields', $payload)) {
            $fields = $payload['fields'];
            if (!is_array($fields) || ($fields !== [] && SchemaStore::isList($fields))) {
                $dropped[] = ['key' => 'fields', 'reason' => 'not an object'];
            } else {
                foreach ($fields as $handle => $v) {
                    $key = 'fields.' . $handle;
                    $field = is_string($handle) ? $this->findLayoutField($layout, $handle) : null;
                    if (!$field) {
                        $dropped[] = ['key' => $key, 'reason' => 'no such field on this entry type'];
                    } elseif (!$this->isWritable($field)) {
                        $dropped[] = ['key' => $key, 'reason' => 'not a text field'];
                    } elseif (!is_string($v)) {
                        $dropped[] = ['key' => $key, 'reason' => 'not a string'];
                    } elseif (strlen($v) > self::MAX_TEXT) {
                        $dropped[] = ['key' => $key, 'reason' => 'larger than 1 MB'];
                    } else {
                        $entry->setFieldValue($field->handle, $v);
                        $written[] = $key;
                    }
                }
            }
        }

        if (array_key_exists('enabled', $payload)) {
            $v = $payload['enabled'];
            if (!is_bool($v)) {
                $dropped[] = ['key' => 'enabled', 'reason' => 'not a boolean'];
            } else {
                if ($v) {
                    $entry->enabled = true;
                    $entry->setEnabledForSite(true);
                } elseif ($isNew) {
                    $entry->enabled = false;
                } else {
                    // Disable on this site only; other sites keep their status.
                    $entry->setEnabledForSite(false);
                }
                $written[] = 'enabled';
            }
        }

        if (array_key_exists('postDate', $payload)) {
            $v = $payload['postDate'];
            $date = is_string($v) ? self::parseDate($v) : null;
            if ($v === null) {
                // Ignored: Craft sets the post date when the entry goes live.
            } elseif (!$date) {
                $dropped[] = ['key' => 'postDate', 'reason' => 'not an ISO 8601 date'];
            } else {
                $entry->postDate = $date;
                $written[] = 'postDate';
            }
        }

        if (!array_key_exists('meta', $payload) || $payload['meta'] === null) {
            return null;
        }
        $changes = $this->validateMeta($payload['meta'], $dropped);
        if ($changes === []) {
            return null;
        }
        $bridge = Plugin::$plugin->seomaticBridge;
        $seoField = $bridge->findField($entry);
        if ($seoField) {
            // Keys the field renders go into the field; keys it has switched
            // off (by default canonical and noindex) go to the plugin's own
            // store and are rendered by the plugin, so they still take effect.
            $native = array_intersect_key($changes, array_flip($bridge->nativeKeys($seoField)));
            if ($native !== []) {
                $bridge->writeMeta($entry, $seoField, $native);
                foreach (array_keys($native) as $k) {
                    $written[] = 'meta.' . $k;
                }
            }
            $changes = array_diff_key($changes, $native);
            foreach (array_keys($changes) as $k) {
                $written[] = 'meta.' . $k;
            }
            // Clear any older plugin-stored copy of the keys now in the field.
            foreach (array_keys($native) as $k) {
                $changes[$k] = $k === 'noindex' ? false : null;
            }
            return $changes;
        }
        // Stored in the plugin's own table after a successful save.
        foreach (array_keys($changes) as $k) {
            $written[] = 'meta.' . $k;
        }
        return $changes;
    }

    /** @return array<string, mixed> validated meta changes */
    private function validateMeta(mixed $meta, array &$dropped): array
    {
        if (!is_array($meta) || ($meta !== [] && SchemaStore::isList($meta))) {
            $dropped[] = ['key' => 'meta', 'reason' => 'not an object'];
            return [];
        }
        $changes = [];
        foreach ($meta as $k => $v) {
            $key = 'meta.' . $k;
            switch ($k) {
                case 'title':
                case 'description':
                    if ($v === null || $v === '') {
                        $changes[$k] = null;
                    } elseif (!is_string($v)) {
                        $dropped[] = ['key' => $key, 'reason' => 'not a string'];
                    } elseif (mb_strlen($v) > self::MAX_META_TEXT) {
                        $dropped[] = ['key' => $key, 'reason' => 'longer than ' . self::MAX_META_TEXT . ' characters'];
                    } elseif (self::hasTemplateSyntax($v)) {
                        $dropped[] = ['key' => $key, 'reason' => 'contains Twig syntax ({{, {% or {#)'];
                    } else {
                        $changes[$k] = trim($v);
                    }
                    break;
                case 'canonical':
                    if ($v === null || $v === '') {
                        $changes[$k] = null;
                    } elseif (!is_string($v) || !self::isHttpUrl($v)) {
                        $dropped[] = ['key' => $key, 'reason' => 'not an http(s) URL'];
                    } elseif (self::hasTemplateSyntax($v) || mb_strlen($v) > 2048) {
                        $dropped[] = ['key' => $key, 'reason' => 'not a valid URL'];
                    } else {
                        $changes[$k] = $v;
                    }
                    break;
                case 'noindex':
                    if (!is_bool($v)) {
                        $dropped[] = ['key' => $key, 'reason' => 'not a boolean'];
                    } else {
                        $changes[$k] = $v;
                    }
                    break;
                default:
                    $dropped[] = ['key' => $key, 'reason' => 'unknown key'];
            }
        }
        return $changes;
    }

    private function storePluginMeta(Entry $entry, ?array $changes): void
    {
        if ($changes === null || $changes === []) {
            return;
        }
        $store = Plugin::$plugin->meta;
        $current = $store->get((int)$entry->id, (int)$entry->siteId)
            ?? ['title' => null, 'description' => null, 'canonical' => null, 'noindex' => false];
        $store->set((int)$entry->id, (int)$entry->siteId, array_merge($current, $changes));
    }

    private function saveOrFail(Entry $entry): void
    {
        if ($entry->enabled && $entry->getEnabledForSite()) {
            $entry->setScenario(Entry::SCENARIO_LIVE);
        }
        $ok = Craft::$app->getElements()->saveElement($entry);
        if (!$ok) {
            $messages = [];
            foreach ($entry->getErrors() as $attr => $errs) {
                foreach ($errs as $err) {
                    $messages[] = $attr . ': ' . $err;
                }
            }
            throw new ApiException('save_failed', $messages ? implode(' ', $messages) : 'Craft could not save the entry.', 422, ['messages' => $messages]);
        }
    }

    private function attachFeaturedImage(Entry $entry, mixed $spec, array &$written, array &$dropped): void
    {
        if (!is_array($spec) || !is_string($spec['url'] ?? null)) {
            $dropped[] = ['key' => 'featuredImage', 'reason' => '`featuredImage.url` is required'];
            return;
        }
        $layout = $entry->getFieldLayout();
        $field = null;
        $wanted = $spec['field'] ?? null;
        if (is_string($wanted) && $wanted !== '') {
            $f = $this->findLayoutField($layout, $wanted);
            if ($f instanceof AssetsField) {
                $field = $f;
            }
        }
        if (!$field) {
            foreach ($this->layoutFields($layout) as $f) {
                if ($f instanceof AssetsField) {
                    $field = $f;
                    break;
                }
            }
        }
        if (!$field) {
            $dropped[] = ['key' => 'featuredImage', 'reason' => 'this entry type has no asset field'];
            return;
        }
        if (!empty($field->restrictFiles) && is_array($field->allowedKinds) && !in_array('image', $field->allowedKinds, true)) {
            $dropped[] = ['key' => 'featuredImage', 'reason' => 'the asset field ' . $field->handle . ' does not accept images'];
            return;
        }
        $alt = $spec['alt'] ?? null;
        if ($alt !== null && (!is_string($alt) || mb_strlen($alt) > self::MAX_META_TEXT)) {
            $alt = null;
            $dropped[] = ['key' => 'featuredImage.alt', 'reason' => 'not a string of at most 1000 characters'];
        }
        try {
            $asset = Plugin::$plugin->images->import($spec['url'], $field, $entry, $alt);
        } catch (ApiException $e) {
            $dropped[] = ['key' => 'featuredImage', 'reason' => $e->getMessage()];
            return;
        } catch (Throwable $e) {
            Craft::error('SEOmatic AI featured image import failed: ' . $e->getMessage(), __METHOD__);
            $dropped[] = ['key' => 'featuredImage', 'reason' => 'the image could not be saved'];
            return;
        }
        $entry->setFieldValue($field->handle, [(int)$asset->id]);
        if (!Craft::$app->getElements()->saveElement($entry)) {
            $dropped[] = ['key' => 'featuredImage', 'reason' => 'the entry could not be saved with the image: ' . implode(' ', $entry->getFirstErrors())];
            return;
        }
        $written[] = 'featuredImage';
        if (is_string($alt) && $alt !== '') {
            $written[] = 'featuredImage.alt';
        }
    }

    /**
     * Moves the entry to Craft's trash, on every site (Craft deletes the
     * element, not one site's version). With $siteOnly, removes only that
     * site's version instead (Craft's "Delete for site"), or disables it on
     * that site when the section keeps a version on every site; when that
     * is the entry's only site, the entry is trashed as usual.
     */
    public function delete(int $id, ?int $siteOnly = null): string
    {
        $entry = Entry::find()->id($id)->siteId($siteOnly ?? '*')->status(null)->one();
        if (!$entry instanceof Entry) {
            return 'deleted';
        }
        if (empty($entry->sectionId)) {
            throw new ApiException('not_found', 'No entry with that id in a section.', 404);
        }
        $elements = Craft::$app->getElements();
        if ($siteOnly !== null) {
            $siteIds = Entry::find()->id($id)->siteId('*')->status(null)->select(['elements_sites.siteId'])->column();
            if (count(array_unique($siteIds)) > 1) {
                if (method_exists($elements, 'deleteElementForSite')) {
                    $elements->deleteElementForSite($entry);
                    $still = Entry::find()->id($id)->siteId($siteOnly)->status(null)->one();
                    if (!$still instanceof Entry) {
                        return 'removed_from_site';
                    }
                    $entry = $still;
                }
                // A section that propagates to all its sites keeps a version
                // on every site (Craft re-creates it), so the closest is to
                // take this site's version offline.
                $entry->setEnabledForSite(false);
                if (!$elements->saveElement($entry)) {
                    throw new ApiException('save_failed', 'Craft could not disable the entry on this site.', 422);
                }
                return 'disabled_on_site';
            }
        }
        if (!$elements->deleteElement($entry)) {
            throw new ApiException('save_failed', 'Craft could not delete the entry.', 422);
        }
        return 'trashed';
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function defaultAuthor(): ?User
    {
        $user = User::find()->admin(true)->status(null)->orderBy(['elements.id' => SORT_ASC])->one();
        return $user instanceof User ? $user : null;
    }

    public static function date(mixed $d): ?string
    {
        if (!$d instanceof DateTimeInterface) {
            return null;
        }
        return (new DateTime('@' . $d->getTimestamp()))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM);
    }

    public static function parseDate(string $v): ?DateTime
    {
        $v = trim($v);
        if ($v === '' || !preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?)?(Z|[+-]\d{2}:?\d{2})?$/', $v)) {
            return null;
        }
        try {
            return new DateTime($v);
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function isHttpUrl(string $v): bool
    {
        if (preg_match('/[\s\x00-\x1f]/', $v)) {
            return false;
        }
        $parts = parse_url($v);
        return is_array($parts)
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && !empty($parts['host']);
    }

    public static function hasTemplateSyntax(string $v): bool
    {
        return str_contains($v, '{{') || str_contains($v, '{%') || str_contains($v, '{#');
    }
}
