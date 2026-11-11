<?php

namespace seomatic\craftai\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\web\View;
use seomatic\craftai\helpers\ApiException;
use seomatic\craftai\Plugin;
use Throwable;
use yii\base\Component;
use yii\caching\TagDependency;
use yii\web\Response;

/**
 * Extra robots.txt lines per site.
 *
 * Who answers /robots.txt from Craft (`servedBy`):
 * - `seomatic`: nystudio107 SEOmatic with its robots.txt template switched
 *   on. The plugin appends its lines to SEOmatic's response.
 * - `none`: the site has its own `robots.txt` template; the plugin does not
 *   take that URL over, so its lines are not served.
 * - `seomatic-ai`: otherwise. The plugin answers /robots.txt itself, but
 *   only while it has lines stored: with none stored the site is untouched.
 *
 * A static web/robots.txt file is served by the web server before Craft
 * runs and wins over all of this.
 */
class Robots extends Component
{
    public const MAX_LINES = 200;
    public const MAX_LINE_LENGTH = 2048;

    /** @return string[] */
    public function lines(int $siteId): array
    {
        return array_map('strval', (new Query())
            ->select(['line'])
            ->from(Plugin::TABLE_ROBOTS)
            ->where(['siteId' => $siteId])
            ->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC])
            ->column());
    }

    public function linesCached(int $siteId): array
    {
        $cache = Craft::$app->getCache();
        $key = 'seomatic-ai:robots:' . $siteId;
        $value = $cache->get($key);
        if (!is_array($value)) {
            $value = $this->lines($siteId);
            $cache->set($key, $value, 86400, new TagDependency(['tags' => [Plugin::CACHE_TAG]]));
        }
        return $value;
    }

    /** @return string[] validated lines */
    public static function validate(mixed $lines): array
    {
        if (!is_array($lines) || ($lines !== [] && !SchemaStore::isList($lines))) {
            throw new ApiException('invalid', '`lines` must be an array of strings.', 400);
        }
        if (count($lines) > self::MAX_LINES) {
            throw new ApiException('invalid', 'At most ' . self::MAX_LINES . ' lines.', 400);
        }
        $out = [];
        foreach ($lines as $i => $line) {
            if (!is_string($line)) {
                throw new ApiException('invalid', "Line $i is not a string.", 400);
            }
            if (preg_match('/[\r\n\x00]/', $line) || strlen($line) > self::MAX_LINE_LENGTH) {
                throw new ApiException('invalid', "Line $i must be a single line of at most " . self::MAX_LINE_LENGTH . ' characters.', 400);
            }
            // Verbatim: the app sends its own fence lines and ordering.
            $out[] = $line;
        }
        return $out;
    }

    public function save(int $siteId, array $lines): void
    {
        $db = Craft::$app->getDb();
        $tx = $db->beginTransaction();
        try {
            Db::delete(Plugin::TABLE_ROBOTS, ['siteId' => $siteId]);
            foreach ($lines as $i => $line) {
                Db::insert(Plugin::TABLE_ROBOTS, ['siteId' => $siteId, 'line' => $line, 'sortOrder' => $i]);
            }
            $tx->commit();
        } catch (Throwable $e) {
            $tx->rollBack();
            throw $e;
        }
        Plugin::invalidateCaches();
    }

    public function servedBy(int $siteId): string
    {
        if (Plugin::$plugin->seomaticBridge->servesRobots($siteId)) {
            return 'seomatic';
        }
        if ($this->siteHasRobotsTemplate($siteId)) {
            return 'none';
        }
        return 'seomatic-ai';
    }

    /**
     * True when a robots.txt file sits in the web root. The web server
     * serves it before Craft runs, so neither this plugin nor nystudio107
     * SEOmatic can add to it.
     *
     * The web root is taken from the running request first (the folder of
     * the index.php serving it, then the server's document root) because
     * Craft's starter config pins @webroot to web/, which stays wrong on a
     * site moved to public/, html/ or similar. @webroot is the fallback.
     */
    public function staticFile(): bool
    {
        foreach ($this->webRoots() as $root) {
            try {
                if (is_file($root . DIRECTORY_SEPARATOR . 'robots.txt')) {
                    return true;
                }
            } catch (Throwable $e) {
                // open_basedir or similar: try the next candidate.
            }
        }
        return false;
    }

    /** @return string[] */
    private function webRoots(): array
    {
        $roots = [];
        try {
            $request = Craft::$app->getRequest();
            if (!$request->getIsConsoleRequest()) {
                $script = $request->getScriptFile();
                if (is_string($script) && $script !== '') {
                    $roots[] = dirname($script);
                }
            }
        } catch (Throwable $e) {
        }
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
        if (is_string($docRoot) && $docRoot !== '') {
            $roots[] = $docRoot;
        }
        try {
            $alias = Craft::getAlias('@webroot', false);
            if (is_string($alias) && $alias !== '') {
                $roots[] = $alias;
            }
        } catch (Throwable $e) {
        }
        $out = [];
        foreach ($roots as $root) {
            $root = rtrim($root, '/\\');
            if ($root !== '' && !in_array($root, $out, true)) {
                $out[] = $root;
            }
        }
        return $out;
    }

    /** Where this site's robots.txt (and so its lines) is served. */
    public function url(int $siteId): ?string
    {
        $site = Craft::$app->getSites()->getSiteById($siteId);
        $base = $site ? $site->getBaseUrl() : null;
        return $base ? rtrim($base, '/') . '/robots.txt' : null;
    }

    /**
     * True when the site's base URL is a host root. Crawlers read only
     * /robots.txt at the host root, so lines saved for a site under a path
     * (example.com/fr/) are served at /fr/robots.txt, which crawlers ignore.
     */
    public function atHostRoot(int $siteId): bool
    {
        $site = Craft::$app->getSites()->getSiteById($siteId);
        $base = $site ? (string)$site->getBaseUrl() : '';
        $path = trim((string)parse_url($base, PHP_URL_PATH), '/');
        return $base !== '' && $path === '';
    }

    /** The default rules the plugin starts its own robots.txt with. */
    public function defaultBody(): string
    {
        $disallowAll = false;
        try {
            $disallowAll = (bool)(Craft::$app->getConfig()->getGeneral()->disallowRobots ?? false);
        } catch (Throwable $e) {
        }
        return "User-agent: *\n" . ($disallowAll ? "Disallow: /\n" : "Disallow:\n");
    }

    /**
     * The full robots.txt served (or that would be served) for the site:
     * nystudio107 SEOmatic's output plus the lines when it serves robots.txt,
     * else the plugin's own.
     */
    public function body(int $siteId, ?array $lines = null): string
    {
        $lines ??= $this->lines($siteId);
        $current = Craft::$app->getSites()->getCurrentSite();
        if ((int)$current->id === $siteId && $this->servedBy($siteId) === 'seomatic') {
            $seomatic = Plugin::$plugin->seomaticBridge->renderRobots();
            if ($seomatic !== null) {
                return $lines === [] ? $seomatic : rtrim($seomatic, "\n") . "\n\n" . implode("\n", $lines) . "\n";
            }
        }
        $body = $this->defaultBody();
        if ($lines !== []) {
            $body .= "\n" . implode("\n", $lines) . "\n";
        }
        return $body;
    }

    /** Called while registering site URL rules. */
    public function shouldServeRoute(): bool
    {
        $request = Craft::$app->getRequest();
        if ($request->getIsConsoleRequest() || !$request->getIsSiteRequest()) {
            return false;
        }
        if (strtolower($request->getPathInfo()) !== 'robots.txt') {
            return false;
        }
        $siteId = (int)Craft::$app->getSites()->getCurrentSite()->id;
        if ($this->linesCached($siteId) === []) {
            return false;
        }
        return $this->servedBy($siteId) === 'seomatic-ai';
    }

    /**
     * Response::EVENT_BEFORE_SEND: appends the lines to nystudio107
     * SEOmatic's robots.txt (route seomatic/frontend-template/robots).
     */
    public function appendToSeomaticResponse(Response $response): void
    {
        if (Craft::$app->requestedRoute !== SeomaticBridge::ROBOTS_ROUTE) {
            return;
        }
        if ($response->getStatusCode() !== 200 || !is_string($response->data)) {
            return;
        }
        $siteId = (int)Craft::$app->getSites()->getCurrentSite()->id;
        $lines = $this->linesCached($siteId);
        if ($lines === []) {
            return;
        }
        $response->data = rtrim($response->data, "\n") . "\n\n" . implode("\n", $lines) . "\n";
    }

    private function siteHasRobotsTemplate(int $siteId): bool
    {
        try {
            $view = Craft::$app->getView();
            $oldMode = $view->getTemplateMode();
            $view->setTemplateMode(View::TEMPLATE_MODE_SITE);
            try {
                return $view->doesTemplateExist('robots.txt');
            } finally {
                $view->setTemplateMode($oldMode);
            }
        } catch (Throwable $e) {
            return false;
        }
    }
}
