<?php

namespace seomatic\craftai\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use seomatic\craftai\Plugin;
use yii\base\Component;
use yii\caching\TagDependency;

/**
 * Stored JSON-LD: per entry and site, or site-wide (entryId null).
 */
class SchemaStore extends Component
{
    public const MAX_ITEMS = 50;
    public const MAX_BYTES = 262144; // 256 KB of JSON per set

    /** @return array<int, array<string, mixed>> */
    public function get(?int $entryId, int $siteId): array
    {
        $raw = (new Query())
            ->select(['items'])
            ->from(Plugin::TABLE_SCHEMA)
            ->where(['entryId' => $entryId, 'siteId' => $siteId])
            ->orderBy(['id' => SORT_DESC])
            ->scalar();
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        try {
            $items = Json::decode($raw);
        } catch (\Throwable $e) {
            Craft::error('SEOmatic AI: stored JSON-LD could not be decoded: ' . $e->getMessage(), __METHOD__);
            return [];
        }
        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }

    public function getCached(?int $entryId, int $siteId): array
    {
        $cache = Craft::$app->getCache();
        $key = 'seomatic-ai:schema:' . $siteId . ':' . ($entryId ?? 'site');
        $value = $cache->get($key);
        if ($value === false) {
            $value = ['items' => $this->get($entryId, $siteId)];
            $cache->set($key, $value, 86400, new TagDependency(['tags' => [Plugin::CACHE_TAG]]));
        }
        return $value['items'];
    }

    /**
     * Validates a list of JSON-LD items. Returns an error message, or null
     * when every item is a JSON object with a non-empty `@type`.
     */
    public static function validate(mixed $items): ?string
    {
        if (!is_array($items) || ($items !== [] && !self::isList($items))) {
            return '`items` must be an array of JSON objects.';
        }
        if (count($items) > self::MAX_ITEMS) {
            return 'At most ' . self::MAX_ITEMS . ' items.';
        }
        foreach ($items as $i => $item) {
            if (!is_array($item) || ($item !== [] && self::isList($item))) {
                return "Item $i is not a JSON object.";
            }
            $type = $item['@type'] ?? null;
            $typeOk = (is_string($type) && trim($type) !== '')
                || (is_array($type) && $type !== [] && self::isList($type) && count(array_filter($type, fn($t) => is_string($t) && trim($t) !== '')) === count($type));
            if (!$typeOk) {
                return "Item $i has no `@type`.";
            }
        }
        $json = json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return 'Items could not be encoded as JSON.';
        }
        if (strlen($json) > self::MAX_BYTES) {
            return 'Items are larger than ' . (self::MAX_BYTES / 1024) . ' KB.';
        }
        return null;
    }

    /** array_is_list() for PHP 8.0. */
    public static function isList(array $a): bool
    {
        $i = 0;
        foreach ($a as $k => $_) {
            if ($k !== $i++) {
                return false;
            }
        }
        return true;
    }

    /** Replaces the stored set; an empty list clears it. */
    public function replace(?int $entryId, int $siteId, array $items): void
    {
        $where = ['entryId' => $entryId, 'siteId' => $siteId];
        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();
        try {
            Db::delete(Plugin::TABLE_SCHEMA, $where);
            if ($items !== []) {
                Db::insert(Plugin::TABLE_SCHEMA, $where + [
                    'items' => json_encode(array_values($items), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]);
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
        Plugin::invalidateCaches();
    }
}
