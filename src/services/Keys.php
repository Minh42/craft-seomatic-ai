<?php

namespace seomatic\craftai\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use seomatic\craftai\Plugin;
use yii\base\Component;

/**
 * The connection key SEOmatic sends in `X-Seomatic-Key`.
 *
 * Format: `smk_` + 44 URL-safe characters (A-Z a-z 0-9 - _), 48 in total.
 * Stored in the plugin's own table, never in project config.
 */
class Keys extends Component
{
    public const SETTING_NAME = 'apiKey';
    public const PREFIX = 'smk_';

    public static function generate(): string
    {
        // 33 random bytes = exactly 44 base64 characters, no padding.
        return self::PREFIX . rtrim(strtr(base64_encode(random_bytes(33)), '+/', '-_'), '=');
    }

    public static function isWellFormed(string $key): bool
    {
        return (bool)preg_match('/^smk_[A-Za-z0-9_-]{44}$/', $key);
    }

    /** The stored key; created on first use if the row is missing. */
    public function getKey(): string
    {
        $value = (new Query())
            ->select(['value'])
            ->from(Plugin::TABLE_SETTINGS)
            ->where(['name' => self::SETTING_NAME])
            ->scalar();
        if (is_string($value) && self::isWellFormed($value)) {
            return $value;
        }
        return $this->regenerate();
    }

    public function regenerate(): string
    {
        $key = self::generate();
        $db = Craft::$app->getDb();
        $exists = (new Query())
            ->from(Plugin::TABLE_SETTINGS)
            ->where(['name' => self::SETTING_NAME])
            ->exists();
        if ($exists) {
            Db::update(Plugin::TABLE_SETTINGS, ['value' => $key], ['name' => self::SETTING_NAME], [], true, $db);
        } else {
            Db::insert(Plugin::TABLE_SETTINGS, ['name' => self::SETTING_NAME, 'value' => $key], $db);
        }
        return $key;
    }

    /** Constant-time check of a presented key. */
    public function verify(?string $presented): bool
    {
        if ($presented === null || $presented === '') {
            return false;
        }
        $value = (new Query())
            ->select(['value'])
            ->from(Plugin::TABLE_SETTINGS)
            ->where(['name' => self::SETTING_NAME])
            ->scalar();
        if (!is_string($value) || !self::isWellFormed($value)) {
            return false;
        }
        return hash_equals($value, $presented);
    }
}
