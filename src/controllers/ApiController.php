<?php

namespace seomatic\craftai\controllers;

use Craft;
use craft\elements\Asset;
use craft\web\Controller;
use seomatic\craftai\helpers\ApiException;
use seomatic\craftai\helpers\Compat;
use seomatic\craftai\Plugin;
use seomatic\craftai\services\Robots;
use seomatic\craftai\services\SchemaStore;
use Throwable;
use yii\web\Response;

/**
 * The JSON API SEOmatic calls: {site}/actions/seomatic-ai/api/{action}.
 * See integrations/craft/API.md for the contract.
 *
 * Anonymous (no Craft session) and CSRF-free; every action is
 * authenticated by the `X-Seomatic-Key` header instead.
 */
class ApiController extends Controller
{
    // Key-authenticated, so it also answers while the system is offline.
    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE | self::ALLOW_ANONYMOUS_OFFLINE;
    public $enableCsrfValidation = false;

    private const GET_ACTIONS = ['info', 'sections', 'entries', 'entry', 'redirects', 'robots', 'schema'];
    private const POST_ACTIONS = ['save-entry', 'create-entry', 'delete-entry', 'asset-alt', 'redirect-save', 'redirect-delete', 'robots-save', 'schema-save'];

    private ?array $body = null;

    /**
     * An unknown action still answers JSON with a boolean `ok` (the app
     * treats any answer without one as "not the plugin").
     */
    public function createAction($id)
    {
        $action = parent::createAction($id);
        if ($action === null) {
            $action = new \yii\base\InlineAction($id, $this, 'actionUnknown');
        }
        return $action;
    }

    public function actionUnknown(): Response
    {
        return $this->handle('unknown', function () {
            throw new ApiException('not_found', 'Unknown action.', 404);
        });
    }

    public function beforeAction($action): bool
    {
        // JSON for everything, including errors raised before the action.
        $this->response->format = Response::FORMAT_JSON;
        $this->response->getHeaders()->set('Cache-Control', 'no-store, private');
        $this->response->getHeaders()->set('X-Robots-Tag', 'noindex, nofollow');
        return parent::beforeAction($action);
    }

    /**
     * Wraps every action: method check, key check, JSON body, error mapping.
     */
    private function handle(string $id, callable $fn): Response
    {
        try {
            $method = $this->request->getMethod();
            if (in_array($id, self::GET_ACTIONS, true) && !in_array($method, ['GET', 'HEAD'], true)) {
                throw new ApiException('invalid', 'Use GET for this action.', 405);
            }
            if (in_array($id, self::POST_ACTIONS, true) && $method !== 'POST') {
                throw new ApiException('invalid', 'Use POST with a JSON body for this action.', 405);
            }
            $key = $this->request->getHeaders()->get('X-Seomatic-Key');
            if (!is_string($key) || !Plugin::$plugin->keys->verify($key)) {
                throw new ApiException('unauthorized', 'Missing or wrong X-Seomatic-Key.', 401);
            }
            if ($method === 'POST') {
                $this->body = $this->parseBody();
            }
            $data = $fn();
            return $this->asJson(['ok' => true] + $data);
        } catch (ApiException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->status, $e->extra);
        } catch (Throwable $e) {
            Craft::error('SEOmatic AI API ' . $id . ' failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString(), __METHOD__);
            return $this->error('server_error', 'The plugin hit an error; details are in the Craft logs.', 500);
        }
    }

    private function error(string $code, string $message, int $status, array $extra = []): Response
    {
        $response = $this->asJson(['ok' => false, 'error' => ['code' => $code, 'message' => $message] + $extra]);
        $response->setStatusCode($status);
        return $response;
    }

    private function parseBody(): array
    {
        $raw = (string)$this->request->getRawBody();
        if (trim($raw) === '') {
            return [];
        }
        if (strlen($raw) > 8 * 1024 * 1024) {
            throw new ApiException('invalid', 'The request body is larger than 8 MB.', 413);
        }
        $data = json_decode($raw, true, 64);
        if (!is_array($data) || ($data !== [] && SchemaStore::isList($data))) {
            throw new ApiException('invalid', 'The body must be a JSON object.', 400);
        }
        return $data;
    }

    private function param(string $name): mixed
    {
        if ($this->body !== null) {
            return $this->body[$name] ?? null;
        }
        return $this->request->getQueryParam($name);
    }

    private function site(): \craft\models\Site
    {
        $site = Compat::resolveSite($this->param('siteId'));
        if (!$site) {
            throw new ApiException('not_found', 'No site with that siteId.', 404);
        }
        return $site;
    }

    private static function intParam(mixed $v, string $name, bool $required = true): ?int
    {
        if ($v === null || $v === '') {
            if ($required) {
                throw new ApiException('invalid', "`$name` is required.", 400);
            }
            return null;
        }
        if (is_string($v) && preg_match('/^\d{1,10}$/', $v)) {
            $v = (int)$v;
        }
        if (!is_int($v) || $v < 0) {
            throw new ApiException('invalid', "`$name` must be a non-negative integer.", 400);
        }
        return $v;
    }

    // ---------------------------------------------------------------------

    public function actionInfo(): Response
    {
        return $this->handle('info', function () {
            $plugin = Plugin::$plugin;
            $sites = [];
            foreach (Craft::$app->getSites()->getAllSites() as $site) {
                $sites[] = [
                    'id' => (int)$site->id,
                    'handle' => (string)$site->handle,
                    'baseUrl' => $site->getBaseUrl(),
                    'primary' => (bool)$site->primary,
                    'language' => (string)$site->language,
                ];
            }
            $bridge = $plugin->seomaticBridge;
            $primary = Craft::$app->getSites()->getPrimarySite();
            return [
                'plugin' => ['version' => (string)$plugin->getVersion(), 'apiVersion' => Plugin::API_VERSION],
                'craft' => ['version' => (string)Craft::$app->getVersion(), 'edition' => Compat::editionName()],
                'php' => PHP_VERSION,
                'sites' => $sites,
                'seomatic' => ['installed' => $bridge->isEnabled(), 'version' => $bridge->isEnabled() ? $bridge->version() : null],
                'capabilities' => [
                    'entries' => true,
                    'meta' => true,
                    'schema' => true,
                    'redirects' => true,
                    'robots' => true,
                    'altText' => true,
                    'featuredImage' => function_exists('curl_init'),
                ],
                'robots' => [
                    'servedBy' => $plugin->robots->servedBy((int)$primary->id),
                    'staticFile' => $plugin->robots->staticFile(),
                ],
            ];
        });
    }

    public function actionSections(): Response
    {
        return $this->handle('sections', function () {
            return ['sections' => Plugin::$plugin->entries->describeSections($this->site())];
        });
    }

    public function actionEntries(): Response
    {
        return $this->handle('entries', function () {
            $site = $this->site();
            $offset = self::intParam($this->param('offset'), 'offset', false) ?? 0;
            $limit = self::intParam($this->param('limit'), 'limit', false) ?? 100;
            if ($limit < 1 || $limit > 500) {
                throw new ApiException('invalid', '`limit` must be between 1 and 500.', 400);
            }
            $section = $this->param('section');
            $slug = $this->param('slug');
            if ($section !== null && (!is_string($section) || $section === '')) {
                throw new ApiException('invalid', '`section` must be a section handle.', 400);
            }
            if ($slug !== null && (!is_string($slug) || $slug === '')) {
                throw new ApiException('invalid', '`slug` must be a string.', 400);
            }
            return Plugin::$plugin->entries->list($site, $section, $slug, $offset, $limit);
        });
    }

    public function actionEntry(): Response
    {
        return $this->handle('entry', function () {
            $site = $this->site();
            $id = self::intParam($this->param('id'), 'id');
            $entry = Plugin::$plugin->entries->find($id, (int)$site->id);
            if (!$entry) {
                throw new ApiException('not_found', 'No entry with that id on this site.', 404);
            }
            return ['entry' => Plugin::$plugin->entries->full($entry)];
        });
    }

    public function actionSaveEntry(): Response
    {
        return $this->handle('save-entry', function () {
            $site = $this->site();
            $id = self::intParam($this->param('id'), 'id');
            $entries = Plugin::$plugin->entries;
            $entry = $entries->find($id, (int)$site->id);
            if (!$entry) {
                throw new ApiException('not_found', 'No entry with that id on this site.', 404);
            }
            return $entries->save($entry, $this->body ?? []);
        });
    }

    public function actionCreateEntry(): Response
    {
        return $this->handle('create-entry', function () {
            return Plugin::$plugin->entries->create($this->site(), $this->body ?? []);
        });
    }

    public function actionDeleteEntry(): Response
    {
        return $this->handle('delete-entry', function () {
            $id = self::intParam($this->param('id'), 'id');
            // scope "site": remove only the given site's version.
            $scope = $this->param('scope');
            if ($scope !== null && $scope !== 'all' && $scope !== 'site') {
                throw new ApiException('invalid', '`scope` must be "all" or "site".', 400);
            }
            $siteOnly = $scope === 'site' ? (int)$this->site()->id : null;
            return ['outcome' => Plugin::$plugin->entries->delete($id, $siteOnly)];
        });
    }

    public function actionAssetAlt(): Response
    {
        return $this->handle('asset-alt', function () {
            $site = $this->site();
            $id = self::intParam($this->param('id'), 'id');
            $alt = $this->param('alt');
            if ($alt !== null && !is_string($alt)) {
                throw new ApiException('invalid', '`alt` must be a string.', 400);
            }
            $alt = $alt === null ? null : trim($alt);
            if ($alt !== null && mb_strlen($alt) > 1000) {
                throw new ApiException('invalid', '`alt` is longer than 1000 characters.', 400);
            }
            $asset = Asset::find()->id($id)->siteId((int)$site->id)->status(null)->one();
            if (!$asset instanceof Asset) {
                throw new ApiException('not_found', 'No asset with that id.', 404);
            }
            $asset->alt = ($alt === '' ? null : $alt);
            if (!Craft::$app->getElements()->saveElement($asset)) {
                throw new ApiException('save_failed', implode(' ', $asset->getFirstErrors()) ?: 'Craft could not save the asset.', 422);
            }
            $fresh = Asset::find()->id($id)->siteId((int)$site->id)->status(null)->one();
            return ['alt' => $fresh instanceof Asset ? $fresh->alt : $asset->alt];
        });
    }

    public function actionRedirects(): Response
    {
        return $this->handle('redirects', function () {
            $siteId = $this->param('siteId');
            return ['redirects' => Plugin::$plugin->redirects->list(
                ($siteId === null || $siteId === '') ? null : (int)$this->site()->id
            )];
        });
    }

    public function actionRedirectSave(): Response
    {
        return $this->handle('redirect-save', function () {
            // siteId absent or null = the redirect applies on every site.
            $siteId = $this->param('siteId');
            $redirect = Plugin::$plugin->redirects->save(
                ($siteId === null || $siteId === '') ? null : $this->site(),
                $this->param('source'),
                $this->param('destination'),
                $this->param('statusCode')
            );
            return ['redirect' => $redirect];
        });
    }

    public function actionRedirectDelete(): Response
    {
        return $this->handle('redirect-delete', function () {
            $id = self::intParam($this->param('id'), 'id');
            return ['deleted' => Plugin::$plugin->redirects->delete($id)];
        });
    }

    public function actionRobots(): Response
    {
        return $this->handle('robots', function () {
            $siteId = (int)$this->site()->id;
            $robots = Plugin::$plugin->robots;
            $lines = $robots->lines($siteId);
            return [
                'servedBy' => $robots->servedBy($siteId),
                'staticFile' => $robots->staticFile(),
                'url' => $robots->url($siteId),
                'hostRoot' => $robots->atHostRoot($siteId),
                'lines' => $lines,
                'body' => $robots->body($siteId, $lines),
            ];
        });
    }

    public function actionRobotsSave(): Response
    {
        return $this->handle('robots-save', function () {
            $siteId = (int)$this->site()->id;
            $lines = Robots::validate($this->param('lines'));
            $robots = Plugin::$plugin->robots;
            $robots->save($siteId, $lines);
            Plugin::$plugin->pageCaches->urlsChanged([$robots->url($siteId)]);
            return [
                'servedBy' => $robots->servedBy($siteId),
                'staticFile' => $robots->staticFile(),
                'url' => $robots->url($siteId),
                'hostRoot' => $robots->atHostRoot($siteId),
                'lines' => $robots->lines($siteId),
            ];
        });
    }

    public function actionSchema(): Response
    {
        return $this->handle('schema', function () {
            $site = $this->site();
            $entryId = self::intParam($this->param('entryId'), 'entryId', false);
            $store = Plugin::$plugin->schema;
            return [
                'entry' => $entryId !== null ? $store->get($entryId, (int)$site->id) : [],
                'site' => $store->get(null, (int)$site->id),
            ];
        });
    }

    public function actionSchemaSave(): Response
    {
        return $this->handle('schema-save', function () {
            $site = $this->site();
            $entryId = self::intParam($this->param('entryId'), 'entryId', false);
            $entry = $entryId !== null ? Plugin::$plugin->entries->find($entryId, (int)$site->id) : null;
            if ($entryId !== null && !$entry) {
                throw new ApiException('not_found', 'No entry with that id on this site.', 404);
            }
            $items = $this->param('items');
            $error = SchemaStore::validate($items);
            if ($error !== null) {
                throw new ApiException('invalid', $error, 400);
            }
            $store = Plugin::$plugin->schema;
            $store->replace($entryId, (int)$site->id, $items);
            if ($entry) {
                Plugin::$plugin->pageCaches->entryChanged($entry);
            } else {
                Plugin::$plugin->pageCaches->siteChanged((int)$site->id);
            }
            return [
                'entryId' => $entryId,
                'siteId' => (int)$site->id,
                'items' => $store->get($entryId, (int)$site->id),
            ];
        });
    }
}
