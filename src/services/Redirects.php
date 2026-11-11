<?php

namespace seomatic\craftai\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\models\Site;
use DateTime;
use seomatic\craftai\helpers\ApiException;
use seomatic\craftai\Plugin;
use Throwable;
use yii\base\Component;
use yii\caching\TagDependency;
use yii\db\Expression;

/**
 * Redirects per site, answered on front-end GET/HEAD requests before Craft
 * routes them. Exact match on the normalized source.
 */
class Redirects extends Component
{
    public const STATUS_CODES = [301, 302, 307, 410];
    public const MAX_LENGTH = 2048;

    /**
     * Normalized source: path only (a full URL is reduced to its path and
     * query), leading slash, no trailing slash except "/", path lower-cased,
     * query kept as given. Returns null when it is not a usable path.
     */
    public static function normalizeSource(string $source): ?string
    {
        $source = trim($source);
        if ($source === '' || strlen($source) > self::MAX_LENGTH || preg_match('/[\s\x00-\x1f\x7f]/', $source)) {
            return null;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $source)) {
            $p = parse_url($source);
            if (!is_array($p)) {
                return null;
            }
            $path = $p['path'] ?? '/';
            $query = $p['query'] ?? '';
        } else {
            $hashPos = strpos($source, '#');
            if ($hashPos !== false) {
                $source = substr($source, 0, $hashPos);
            }
            $qPos = strpos($source, '?');
            $path = $qPos === false ? $source : substr($source, 0, $qPos);
            $query = $qPos === false ? '' : substr($source, $qPos + 1);
        }
        $path = '/' . ltrim($path, '/');
        $path = preg_replace('#/{2,}#', '/', $path) ?? $path;
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }
        $path = mb_strtolower($path);
        return $query !== '' ? $path . '?' . $query : $path;
    }

    /** Validates a destination: a site path ("/new") or an http(s) URL. */
    public static function validateDestination(mixed $destination): ?string
    {
        if (!is_string($destination)) {
            return null;
        }
        $destination = trim($destination);
        if ($destination === '' || strlen($destination) > self::MAX_LENGTH || preg_match('/[\s\x00-\x1f\x7f]/', $destination)) {
            return null;
        }
        if (str_starts_with($destination, '/')) {
            // "//host" would be protocol-relative: refuse it.
            return str_starts_with($destination, '//') ? null : $destination;
        }
        return Entries::isHttpUrl($destination) ? $destination : null;
    }

    /**
     * All redirects, or (with a site id) those that apply to that site:
     * its own plus the every-site ones (siteId null).
     */
    public function list(?int $siteId): array
    {
        $query = (new Query())
            ->select(['id', 'siteId', 'source', 'destination', 'statusCode', 'hits', 'lastHit', 'dateCreated'])
            ->from(Plugin::TABLE_REDIRECTS)
            ->orderBy(['id' => SORT_ASC]);
        if ($siteId !== null) {
            $query->where(['or', ['siteId' => $siteId], ['siteId' => null]]);
        }
        $rows = $query->all();
        return array_map([$this, 'present'], $rows);
    }

    public function present(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'siteId' => $row['siteId'] !== null ? (int)$row['siteId'] : null,
            'source' => (string)$row['source'],
            'destination' => $row['destination'] !== null && $row['destination'] !== '' ? (string)$row['destination'] : null,
            'statusCode' => (int)$row['statusCode'],
            'hits' => (int)$row['hits'],
            'lastHit' => self::isoDate($row['lastHit'] ?? null),
            'dateCreated' => self::isoDate($row['dateCreated'] ?? null),
        ];
    }

    /** @param Site|null $site null = the redirect applies on every site */
    public function save(?Site $site, mixed $source, mixed $destination, mixed $statusCode): array
    {
        if ($statusCode === null) {
            $statusCode = 301;
        }
        if (is_string($statusCode) && ctype_digit($statusCode)) {
            $statusCode = (int)$statusCode;
        }
        if (!is_int($statusCode) || !in_array($statusCode, self::STATUS_CODES, true)) {
            throw new ApiException('invalid', '`statusCode` must be 301, 302, 307 or 410.', 400);
        }
        $normalized = is_string($source) ? self::normalizeSource($source) : null;
        if ($normalized === null) {
            throw new ApiException('invalid', '`source` must be a path such as /old-page.', 400);
        }
        $dest = null;
        if ($statusCode !== 410) {
            $dest = self::validateDestination($destination);
            if ($dest === null) {
                throw new ApiException('invalid', '`destination` must be a path starting with / or an http(s) URL.', 400);
            }
            $sites = $site ? [$site] : Craft::$app->getSites()->getAllSites();
            $loops = false;
            foreach ($sites as $candidate) {
                $loops = $loops || $this->pointsToItself($candidate, $normalized, $dest);
            }
            if ($loops) {
                throw new ApiException('invalid', 'A redirect cannot point to its own source.', 400);
            }
        } elseif ($destination !== null && $destination !== '') {
            $dest = self::validateDestination($destination);
        }

        $hash = sha1($normalized);
        $where = ['siteId' => $site ? (int)$site->id : null, 'sourceHash' => $hash];
        $id = (new Query())->select(['id'])->from(Plugin::TABLE_REDIRECTS)->where($where)->scalar();
        $columns = ['source' => $normalized, 'destination' => $dest, 'statusCode' => $statusCode];
        if ($id) {
            Db::update(Plugin::TABLE_REDIRECTS, $columns, ['id' => (int)$id]);
        } else {
            Db::insert(Plugin::TABLE_REDIRECTS, $columns + $where);
        }
        Plugin::invalidateCaches();
        // A page cache (Blitz) may still hold the old page at this path
        // and serve it before the redirect can run.
        $urls = [];
        foreach ($site ? [$site] : Craft::$app->getSites()->getAllSites() as $s) {
            $base = (string)$s->getBaseUrl();
            if ($base !== '') {
                $urls[] = self::origin($base, '') . $normalized;
            }
        }
        Plugin::$plugin->pageCaches->urlsChanged($urls);
        $row = (new Query())->from(Plugin::TABLE_REDIRECTS)->where($where)->one();
        return $this->present($row);
    }

    public function delete(int $id): bool
    {
        $n = Db::delete(Plugin::TABLE_REDIRECTS, ['id' => $id]);
        Plugin::invalidateCaches();
        return $n > 0;
    }

    /**
     * True when the destination is the source itself. Paths are from the
     * host root, so only the host is compared for absolute URLs.
     */
    private function pointsToItself(Site $site, string $source, string $dest): bool
    {
        if (str_starts_with($dest, '/')) {
            return self::normalizeSource($dest) === $source;
        }
        $destHost = strtolower((string)parse_url($dest, PHP_URL_HOST));
        $siteHost = strtolower((string)parse_url((string)$site->getBaseUrl(), PHP_URL_HOST));
        if ($destHost === '' || ($siteHost !== '' && $destHost !== $siteHost)) {
            return false;
        }
        $destPath = (string)parse_url($dest, PHP_URL_PATH);
        $q = parse_url($dest, PHP_URL_QUERY);
        return self::normalizeSource($destPath . ($q ? '?' . $q : '')) === $source;
    }

    /**
     * The redirects that apply on a site (its own and every-site ones) as
     * [normalized source => [id, destination, statusCode]], cached until the
     * next write.
     */
    public function map(int $siteId): array
    {
        $cache = Craft::$app->getCache();
        $key = 'seomatic-ai:redirects:' . $siteId;
        $map = $cache->get($key);
        if (!is_array($map)) {
            $map = [];
            $rows = (new Query())
                ->select(['id', 'siteId', 'source', 'destination', 'statusCode'])
                ->from(Plugin::TABLE_REDIRECTS)
                ->where(['or', ['siteId' => $siteId], ['siteId' => null]])
                // Every-site rows first, so a site's own rule wins.
                ->orderBy(new Expression('CASE WHEN [[siteId]] IS NULL THEN 0 ELSE 1 END, [[id]]'))
                ->all();
            foreach ($rows as $r) {
                $map[(string)$r['source']] = [(int)$r['id'], $r['destination'], (int)$r['statusCode']];
            }
            $cache->set($key, $map, 86400, new TagDependency(['tags' => [Plugin::CACHE_TAG]]));
        }
        return $map;
    }

    /**
     * EVENT_BEFORE_REQUEST handler. Never throws: a failure is logged and
     * the request continues to Craft as usual.
     */
    public function handleRequest(): void
    {
        $match = null;
        $response = null;
        try {
            $request = Craft::$app->getRequest();
            if ($request->getIsConsoleRequest()
                || !$request->getIsSiteRequest()
                || $request->getIsCpRequest()
                || $request->getIsActionRequest()
                || !in_array($request->getMethod(), ['GET', 'HEAD'], true)
                || $request->getToken() !== null
            ) {
                return;
            }
            $site = Craft::$app->getSites()->getCurrentSite();
            $map = $this->map((int)$site->id);
            if ($map === []) {
                return;
            }
            // The path from the host root, as in the address bar: on a site
            // whose base URL has a path (example.com/fr/), /fr/old stays
            // /fr/old (Craft's pathInfo would drop the /fr).
            $path = self::normalizeSource('/' . $request->getFullPath()) ?? '/';
            $query = (string)$request->getQueryStringWithoutPath();
            // Exact source first (with its query string), then the path.
            if ($query !== '' && isset($map[$path . '?' . $query])) {
                $match = $map[$path . '?' . $query];
            } elseif (isset($map[$path])) {
                $match = $map[$path];
            }
            if ($match === null) {
                return;
            }
            [$id, $destination, $statusCode] = $match;
            $response = Craft::$app->getResponse();
            if ($statusCode === 410) {
                $response->setStatusCode(410);
                $response->format = \yii\web\Response::FORMAT_RAW;
                $response->data = '';
                $response->content = '';
            } else {
                // A destination path is from the host root of the site's
                // configured base URL (scheme, host and port).
                $location = str_starts_with((string)$destination, '/')
                    ? self::origin((string)$site->getBaseUrl(), $request->getHostInfo()) . $destination
                    : (string)$destination;
                $response->redirect($location, $statusCode);
            }
            $response->getHeaders()->set('X-Redirect-By', 'SEOmatic AI');
        } catch (Throwable $e) {
            Craft::error('SEOmatic AI redirect lookup failed: ' . $e->getMessage(), __METHOD__);
            return;
        }

        // Send first, count after: a failed hit counter never affects the visitor.
        try {
            $response->send();
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
        } catch (Throwable $e) {
            Craft::error('SEOmatic AI redirect send failed: ' . $e->getMessage(), __METHOD__);
        }
        $this->countHit((int)$match[0]);
        Craft::$app->end();
    }

    /** scheme://host[:port] of a URL, or the fallback. */
    private static function origin(string $url, string $fallback): string
    {
        $p = parse_url($url);
        if (!is_array($p) || empty($p['host'])) {
            return rtrim($fallback, '/');
        }
        return ($p['scheme'] ?? 'https') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    private function countHit(int $id): void
    {
        try {
            Craft::$app->getDb()->createCommand()->update(
                Plugin::TABLE_REDIRECTS,
                ['hits' => new Expression('[[hits]] + 1'), 'lastHit' => Db::prepareDateForDb(new DateTime())],
                ['id' => $id],
                [],
                false
            )->execute();
        } catch (Throwable $e) {
            Craft::error('SEOmatic AI could not count a redirect hit: ' . $e->getMessage(), __METHOD__);
        }
    }

    private static function isoDate(mixed $v): ?string
    {
        if (!$v) {
            return null;
        }
        try {
            // Craft stores dates in UTC.
            return (new DateTime((string)$v, new \DateTimeZone('UTC')))->format(DATE_ATOM);
        } catch (Throwable $e) {
            return null;
        }
    }
}
