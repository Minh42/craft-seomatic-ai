<?php

namespace seomatic\craftai\helpers;

use Craft;
use craft\elements\Entry;
use craft\models\Section;
use craft\models\Site;

/**
 * The few places where Craft 4 and Craft 5 differ for this plugin.
 */
class Compat
{
    /** @return Section[] */
    public static function allSections(): array
    {
        $entries = Craft::$app->getEntries();
        if (method_exists($entries, 'getAllSections')) {
            // Craft 5
            return $entries->getAllSections();
        }
        // Craft 4
        return Craft::$app->getSections()->getAllSections();
    }

    public static function sectionByHandle(string $handle): ?Section
    {
        $entries = Craft::$app->getEntries();
        if (method_exists($entries, 'getSectionByHandle')) {
            return $entries->getSectionByHandle($handle);
        }
        return Craft::$app->getSections()->getSectionByHandle($handle);
    }

    public static function sectionById(int $id): ?Section
    {
        $entries = Craft::$app->getEntries();
        if (method_exists($entries, 'getSectionById')) {
            return $entries->getSectionById($id);
        }
        return Craft::$app->getSections()->getSectionById($id);
    }

    /** Sections enabled for the given site. @return Section[] */
    public static function sectionsForSite(int $siteId): array
    {
        $out = [];
        foreach (self::allSections() as $section) {
            $siteSettings = $section->getSiteSettings();
            if (isset($siteSettings[$siteId])) {
                $out[] = $section;
            }
        }
        return $out;
    }

    /** The entry's section, or null for a nested (Matrix) entry on Craft 5. */
    public static function entrySection(Entry $entry): ?Section
    {
        if (empty($entry->sectionId)) {
            return null;
        }
        return self::sectionById((int)$entry->sectionId);
    }

    public static function editionName(): string
    {
        try {
            $app = Craft::$app;
            if (method_exists($app, 'getEditionName')) {
                return strtolower((string)$app->getEditionName());
            }
            $edition = $app->edition ?? null;
            if (is_object($edition) && property_exists($edition, 'name')) {
                return strtolower((string)$edition->name);
            }
        } catch (\Throwable $e) {
            Craft::warning('SEOmatic AI could not read the Craft edition: ' . $e->getMessage(), __METHOD__);
        }
        return 'solo';
    }

    /**
     * Resolves the `siteId` parameter: absent = the primary site. Returns
     * null when an id is given that does not exist.
     */
    public static function resolveSite(mixed $siteId): ?Site
    {
        $sites = Craft::$app->getSites();
        if ($siteId === null || $siteId === '') {
            return $sites->getPrimarySite();
        }
        if (is_string($siteId) && ctype_digit($siteId)) {
            $siteId = (int)$siteId;
        }
        if (!is_int($siteId) || $siteId <= 0) {
            return null;
        }
        return $sites->getSiteById($siteId);
    }
}
