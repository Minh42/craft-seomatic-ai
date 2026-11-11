<?php

namespace seomatic\craftai\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use seomatic\craftai\Plugin;
use yii\base\Component;
use yii\caching\TagDependency;

/**
 * The plugin's own meta overrides (title, description, canonical, noindex)
 * per entry and site, rendered into the page head by HeadRenderer. Used
 * only for entries without a nystudio107 SEOmatic "SEO Settings" field.
 */
class MetaStore extends Component
{
    /**
     * @return array{title:?string,description:?string,canonical:?string,noindex:bool}|null
     */
    public function get(int $entryId, int $siteId): ?array
    {
        $row = (new Query())
            ->select(['title', 'description', 'canonical', 'noindex'])
            ->from(Plugin::TABLE_META)
            ->where(['entryId' => $entryId, 'siteId' => $siteId])
            ->one();
        if (!$row) {
            return null;
        }
        return [
            'title' => self::nullableString($row['title']),
            'description' => self::nullableString($row['description']),
            'canonical' => self::nullableString($row['canonical']),
            'noindex' => (bool)$row['noindex'],
        ];
    }

    /** Cached variant for the front end. */
    public function getCached(int $entryId, int $siteId): ?array
    {
        $cache = Craft::$app->getCache();
        $key = 'seomatic-ai:meta:' . $siteId . ':' . $entryId;
        $value = $cache->get($key);
        if ($value === false) {
            $value = ['row' => $this->get($entryId, $siteId)];
            $cache->set($key, $value, 86400, new TagDependency(['tags' => [Plugin::CACHE_TAG]]));
        }
        return $value['row'];
    }

    /**
     * Stores the merged override. A row with nothing in it is removed, so a
     * page with no override is never touched.
     */
    public function set(int $entryId, int $siteId, array $meta): void
    {
        $empty = ($meta['title'] ?? null) === null
            && ($meta['description'] ?? null) === null
            && ($meta['canonical'] ?? null) === null
            && empty($meta['noindex']);
        $where = ['entryId' => $entryId, 'siteId' => $siteId];
        if ($empty) {
            Db::delete(Plugin::TABLE_META, $where);
        } else {
            $columns = [
                'title' => $meta['title'] ?? null,
                'description' => $meta['description'] ?? null,
                'canonical' => $meta['canonical'] ?? null,
                'noindex' => !empty($meta['noindex']),
            ];
            $exists = (new Query())->from(Plugin::TABLE_META)->where($where)->exists();
            if ($exists) {
                Db::update(Plugin::TABLE_META, $columns, $where);
            } else {
                Db::insert(Plugin::TABLE_META, $columns + $where);
            }
        }
        Plugin::invalidateCaches();
    }

    private static function nullableString(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }
}
