<?php

namespace seomatic\craftai\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use Throwable;

/**
 * Clears full-page caches after a write that changes a page's output
 * without saving the entry: meta stored by this plugin, schema, redirects
 * and robots lines.
 *
 * Craft's own caches ({% cache %} tags, element query caches) are cleared
 * with Elements::invalidateCachesForElement(). Blitz does not listen to
 * that: it refreshes only when an element is saved, deleted or restored,
 * and with static file caching the web server serves its HTML file before
 * Craft runs. So when Blitz is installed its refresh service is called
 * directly. Every step is best-effort: a cache that cannot be cleared
 * never fails the write.
 */
class PageCaches extends Component
{
    private const BLITZ = 'putyourlightson\\blitz\\Blitz';

    /** The entry's own page changed (meta, schema). */
    public function entryChanged(Entry $entry): void
    {
        try {
            Craft::$app->getElements()->invalidateCachesForElement($entry);
        } catch (Throwable $e) {
            Craft::warning('SEOmatic AI could not invalidate Craft caches for entry ' . $entry->id . ': ' . $e->getMessage(), __METHOD__);
        }
        $url = null;
        try {
            $url = $entry->getUrl();
        } catch (Throwable $e) {
        }
        if (is_string($url) && $url !== '') {
            $this->refreshBlitzUrls([$url]);
        }
    }

    /** Every page of a site changed (site-wide schema, robots lines). */
    public function siteChanged(int $siteId): void
    {
        try {
            Craft::$app->getElements()->invalidateCachesForElementType(Entry::class);
        } catch (Throwable $e) {
            Craft::warning('SEOmatic AI could not invalidate Craft entry caches: ' . $e->getMessage(), __METHOD__);
        }
        $blitz = $this->blitz();
        if ($blitz === null) {
            return;
        }
        try {
            $blitz->refreshCache->refreshSite($siteId);
        } catch (Throwable $e) {
            Craft::warning('SEOmatic AI could not refresh the Blitz cache for site ' . $siteId . ': ' . $e->getMessage(), __METHOD__);
        }
    }

    /** Pages at these absolute URLs changed (a redirect now answers them). */
    public function urlsChanged(array $urls): void
    {
        $urls = array_values(array_unique(array_filter($urls, 'is_string')));
        if ($urls !== []) {
            $this->refreshBlitzUrls($urls);
        }
    }

    private function refreshBlitzUrls(array $urls): void
    {
        $blitz = $this->blitz();
        if ($blitz === null) {
            return;
        }
        try {
            $blitz->refreshCache->refreshCachedUrls($urls);
        } catch (Throwable $e) {
            Craft::warning('SEOmatic AI could not refresh Blitz-cached pages: ' . $e->getMessage(), __METHOD__);
        }
    }

    /** The Blitz plugin instance when Blitz is installed and enabled. */
    private function blitz(): ?object
    {
        try {
            if (!class_exists(self::BLITZ)) {
                return null;
            }
            $class = self::BLITZ;
            $plugin = $class::$plugin ?? null;
            if (!is_object($plugin) || !isset($plugin->refreshCache)) {
                return null;
            }
            return $plugin;
        } catch (Throwable $e) {
            return null;
        }
    }
}
