<?php

namespace seomatic\craftai\services;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\Entry;
use Throwable;
use yii\base\Component;

/**
 * Integration with nystudio107's SEOmatic plugin (handle `seomatic`), when
 * it is installed and enabled. Nothing here loads SEOmatic classes unless
 * the plugin is enabled.
 */
class SeomaticBridge extends Component
{
    public const FIELD_CLASS = 'nystudio107\\seomatic\\fields\\SeoSettings';
    public const PLUGIN_CLASS = 'nystudio107\\seomatic\\Seomatic';
    public const ROBOTS_ROUTE = 'seomatic/frontend-template/robots';

    public function isEnabled(): bool
    {
        try {
            return Craft::$app->getPlugins()->isPluginEnabled('seomatic');
        } catch (Throwable $e) {
            return false;
        }
    }

    public function version(): ?string
    {
        try {
            $plugin = Craft::$app->getPlugins()->getPlugin('seomatic');
            return $plugin ? (string)$plugin->getVersion() : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public function isSeoField(FieldInterface $field): bool
    {
        return $this->isEnabled() && is_a($field, self::FIELD_CLASS);
    }

    /** The entry's first "SEO Settings" field, or null. */
    public function findField(ElementInterface $element): ?FieldInterface
    {
        if (!$this->isEnabled()) {
            return null;
        }
        $layout = $element->getFieldLayout();
        if (!$layout) {
            return null;
        }
        foreach ($layout->getCustomFields() as $field) {
            if (is_a($field, self::FIELD_CLASS)) {
                return $field;
            }
        }
        return null;
    }

    /** Contract meta key => SEOmatic metaGlobalVars attribute. */
    public const META_ATTRIBUTES = [
        'title' => 'seoTitle',
        'description' => 'seoDescription',
        'canonical' => 'canonicalUrl',
        'noindex' => 'robots',
    ];

    /**
     * The contract meta keys this SEO Settings field renders. SEOmatic
     * ignores (prunes) a field value whose attribute is not switched on in
     * the field's own settings; by default only the SEO title, description
     * and image are, so canonical and noindex usually are not.
     *
     * @return string[]
     */
    public function nativeKeys(FieldInterface $field): array
    {
        if (property_exists($field, 'generalTabEnabled') && !$field->generalTabEnabled) {
            return [];
        }
        $enabled = property_exists($field, 'generalEnabledFields') ? (array)$field->generalEnabledFields : [];
        $out = [];
        foreach (self::META_ATTRIBUTES as $key => $attr) {
            if (in_array($attr, $enabled, true)) {
                $out[] = $key;
            }
        }
        return $out;
    }

    /**
     * Reads title/description/canonical/noindex from the SEO Settings field.
     * Values that are Twig (pulled from another field or inherited) are not
     * literal text, so they are reported as null.
     *
     * @return array{title:?string,description:?string,canonical:?string,noindex:bool}
     */
    public function readMeta(Entry $entry, FieldInterface $field): array
    {
        $bundle = $this->bundleArray($entry, $field);
        $vars = is_array($bundle['metaGlobalVars'] ?? null) ? $bundle['metaGlobalVars'] : [];
        $settings = is_array($bundle['metaBundleSettings'] ?? null) ? $bundle['metaBundleSettings'] : [];
        $inherited = is_array($vars['inherited'] ?? null) ? $vars['inherited'] : [];
        // Stored custom values only: a title/description counts only when its
        // source is 'fromCustom' (otherwise SEOmatic pulls it from elsewhere).
        $custom = function (string $attr, ?string $sourceKey) use ($vars, $settings, $inherited): ?string {
            if (isset($inherited[$attr])) {
                return null;
            }
            if ($sourceKey !== null && ($settings[$sourceKey] ?? null) !== 'fromCustom') {
                return null;
            }
            return self::literal($vars[$attr] ?? null);
        };
        $robots = isset($inherited['robots']) ? '' : strtolower(trim((string)($vars['robots'] ?? '')));
        return [
            'title' => $custom('seoTitle', 'seoTitleSource'),
            'description' => $custom('seoDescription', 'seoDescriptionSource'),
            'canonical' => $custom('canonicalUrl', null),
            'noindex' => $robots === 'none' || str_contains($robots, 'noindex'),
        ];
    }

    /**
     * Writes the given keys into the field's MetaBundle on the (unsaved)
     * entry. Keys: title, description, canonical (string|null), noindex.
     * The caller saves the entry.
     */
    public function writeMeta(Entry $entry, FieldInterface $field, array $changes): void
    {
        $bundle = $this->bundleArray($entry, $field);
        $vars = is_array($bundle['metaGlobalVars'] ?? null) ? $bundle['metaGlobalVars'] : [];
        $settings = is_array($bundle['metaBundleSettings'] ?? null) ? $bundle['metaBundleSettings'] : [];

        if (array_key_exists('title', $changes)) {
            $vars['seoTitle'] = (string)($changes['title'] ?? '');
            $settings['seoTitleSource'] = 'fromCustom';
        }
        if (array_key_exists('description', $changes)) {
            $vars['seoDescription'] = (string)($changes['description'] ?? '');
            $settings['seoDescriptionSource'] = 'fromCustom';
        }
        if (array_key_exists('canonical', $changes)) {
            // Empty = SEOmatic's own default (the page's URL).
            $vars['canonicalUrl'] = (string)($changes['canonical'] ?? '');
        }
        if (array_key_exists('noindex', $changes)) {
            $current = strtolower(trim((string)($vars['robots'] ?? '')));
            if ($changes['noindex']) {
                $vars['robots'] = str_contains($current, 'nofollow') ? 'none' : 'noindex';
            } elseif ($current === 'none' || str_contains($current, 'noindex')) {
                // Back to the section/global default.
                $vars['robots'] = '';
            }
        }

        // A value marked "inherited" is ignored by SEOmatic: mark each
        // written attribute as overridden instead (or neither, when cleared,
        // so the section/global default applies again).
        foreach (self::META_ATTRIBUTES as $key => $attr) {
            if (!array_key_exists($key, $changes)) {
                continue;
            }
            $inherited = is_array($vars['inherited'] ?? null) ? $vars['inherited'] : [];
            $overrides = is_array($vars['overrides'] ?? null) ? $vars['overrides'] : [];
            unset($inherited[$attr]);
            if (($vars[$attr] ?? '') !== '') {
                $overrides[$attr] = true;
            } else {
                unset($overrides[$attr]);
            }
            $vars['inherited'] = $inherited;
            $vars['overrides'] = $overrides;
        }

        $bundle['metaGlobalVars'] = $vars;
        $bundle['metaBundleSettings'] = $settings;
        // The field normalizes the array back into a MetaBundle.
        $entry->setFieldValue($field->handle, $field->normalizeValue($bundle, $entry));
    }

    /** The field's value as an array, normalized through the field. */
    private function bundleArray(Entry $entry, FieldInterface $field): array
    {
        $value = $entry->getFieldValue($field->handle);
        if (!is_object($value)) {
            $value = $field->normalizeValue($value, $entry);
        }
        if (is_object($value) && method_exists($value, 'toArray')) {
            $arr = $value->toArray();
            return is_array($arr) ? $arr : [];
        }
        return is_array($value) ? $value : [];
    }

    private static function literal(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $v = trim($v);
        if ($v === '' || str_contains($v, '{{') || str_contains($v, '{%')) {
            return null;
        }
        return $v;
    }

    /** SEOmatic's own rendered robots.txt for the current site, or null. */
    public function renderRobots(): ?string
    {
        if (!$this->isEnabled() || !class_exists(self::PLUGIN_CLASS)) {
            return null;
        }
        try {
            $cls = self::PLUGIN_CLASS;
            $plugin = $cls::$plugin ?? null;
            if (!$plugin) {
                return null;
            }
            if (empty($plugin->frontendTemplates->frontendTemplateContainer)) {
                $plugin->frontendTemplates->loadFrontendTemplateContainers();
            }
            $text = $plugin->frontendTemplates->renderTemplate('robots');
            return is_string($text) && $text !== '' ? $text : null;
        } catch (Throwable $e) {
            Craft::warning('SEOmatic AI could not render SEOmatic robots.txt: ' . $e->getMessage(), __METHOD__);
            return null;
        }
    }

    /**
     * True when SEOmatic answers /robots.txt for the site (its robots.txt
     * frontend template is switched on).
     */
    public function servesRobots(int $siteId): bool
    {
        if (!$this->isEnabled() || !class_exists(self::PLUGIN_CLASS)) {
            return false;
        }
        try {
            $cls = self::PLUGIN_CLASS;
            $plugin = $cls::$plugin ?? null;
            if (!$plugin) {
                return false;
            }
            $bundle = $plugin->metaBundles->getGlobalMetaBundle($siteId, false);
            if (!$bundle || !$bundle->frontendTemplatesContainer) {
                return false;
            }
            $data = $bundle->frontendTemplatesContainer->data ?? [];
            $robots = $data['robots'] ?? null;
            return $robots !== null && !empty($robots->include);
        } catch (Throwable $e) {
            Craft::warning('SEOmatic AI could not read SEOmatic robots.txt settings: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }
}
