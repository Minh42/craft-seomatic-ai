<?php

namespace seomatic\craftai;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\TemplateEvent;
use craft\helpers\UrlHelper;
use craft\web\UrlManager;
use craft\web\View;
use seomatic\craftai\services\Entries;
use seomatic\craftai\services\HeadRenderer;
use seomatic\craftai\services\ImageImporter;
use seomatic\craftai\services\Keys;
use seomatic\craftai\services\MetaStore;
use seomatic\craftai\services\PageCaches;
use seomatic\craftai\services\Redirects;
use seomatic\craftai\services\Robots;
use seomatic\craftai\services\SchemaStore;
use seomatic\craftai\services\SeomaticBridge;
use Throwable;
use yii\base\Event;
use yii\web\Response;

/**
 * SEOmatic AI: connects a Craft site to the SEOmatic SEO agent.
 *
 * Every hook this plugin installs on the front end is wrapped so that a
 * failure is logged and the customer's page is served unchanged.
 *
 * @property-read Keys $keys
 * @property-read Entries $entries
 * @property-read MetaStore $meta
 * @property-read SchemaStore $schema
 * @property-read Redirects $redirects
 * @property-read Robots $robots
 * @property-read SeomaticBridge $seomaticBridge
 * @property-read HeadRenderer $head
 * @property-read ImageImporter $images
 * @property-read PageCaches $pageCaches
 */
class Plugin extends BasePlugin
{
    public const API_VERSION = 1;

    public const TABLE_SETTINGS = '{{%seomatic_ai_settings}}';
    public const TABLE_META = '{{%seomatic_ai_meta}}';
    public const TABLE_SCHEMA = '{{%seomatic_ai_schema}}';
    public const TABLE_REDIRECTS = '{{%seomatic_ai_redirects}}';
    public const TABLE_ROBOTS = '{{%seomatic_ai_robots}}';

    /** Cache tag invalidated on every write the API makes. */
    public const CACHE_TAG = 'seomatic-ai';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static ?Plugin $plugin = null;

    public static function config(): array
    {
        return [
            'components' => [
                'keys' => Keys::class,
                'entries' => Entries::class,
                'meta' => MetaStore::class,
                'schema' => SchemaStore::class,
                'redirects' => Redirects::class,
                'robots' => Robots::class,
                'seomaticBridge' => SeomaticBridge::class,
                'head' => HeadRenderer::class,
                'images' => ImageImporter::class,
                'pageCaches' => PageCaches::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        // Craft 4 reads components from config() only on Craft 4.4+; make
        // sure they exist on every 4.x and 5.x.
        foreach (self::config()['components'] as $id => $class) {
            if (!$this->has($id)) {
                $this->set($id, $class);
            }
        }

        try {
            $request = Craft::$app->getRequest();
            if ($request->getIsConsoleRequest()) {
                return;
            }
            $this->relaxJsonParsingForApi($request);
            if ($request->getIsCpRequest()) {
                $this->registerCpRoutes();
                return;
            }
            if ($request->getIsSiteRequest()) {
                $this->registerSiteHooks();
            }
        } catch (Throwable $e) {
            Craft::error('SEOmatic AI could not register its hooks: ' . $e->getMessage(), __METHOD__);
        }
    }

    public function getCpNavItem(): ?array
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || !$user->admin) {
            return null;
        }
        $item = parent::getCpNavItem();
        $item['label'] = 'SEOmatic AI';
        $item['url'] = 'seomatic-ai';
        return $item;
    }

    /**
     * The plugin keeps no project-config settings (the key must never be
     * committed to git), so the Settings > Plugins link opens the plugin's
     * own screen, which also works when allowAdminChanges is off.
     */
    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('seomatic-ai'));
    }

    /**
     * Craft parses a JSON body itself and answers malformed JSON with an
     * HTML 400 page before any controller runs. For this plugin's API the
     * controller parses the raw body and answers `invalid` as JSON instead.
     */
    private function relaxJsonParsingForApi(\craft\web\Request $request): void
    {
        $url = (string)$request->getUrl();
        if (!str_contains($url, 'seomatic-ai/api/') && !str_contains($url, 'seomatic-ai%2Fapi%2F')) {
            return;
        }
        $parsers = $request->parsers;
        foreach (['application/json', 'text/json'] as $type) {
            $parsers[$type] = ['class' => \yii\web\JsonParser::class, 'throwException' => false];
        }
        $request->parsers = $parsers;
    }

    private function registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function (RegisterUrlRulesEvent $event) {
                $event->rules['seomatic-ai'] = 'seomatic-ai/settings/index';
            }
        );
    }

    private function registerSiteHooks(): void
    {
        // Redirects: answered before Craft routes the request.
        Event::on(
            \yii\base\Application::class,
            \yii\base\Application::EVENT_BEFORE_REQUEST,
            function () {
                $this->redirects->handleRequest();
            }
        );

        // robots.txt: our route when we serve it, or lines appended to
        // nystudio107 SEOmatic's own robots.txt.
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                try {
                    if ($this->robots->shouldServeRoute()) {
                        $event->rules['robots.txt'] = 'seomatic-ai/robots/index';
                    }
                } catch (Throwable $e) {
                    Craft::error('SEOmatic AI robots route failed: ' . $e->getMessage(), __METHOD__);
                }
            }
        );
        Craft::$app->getResponse()->on(Response::EVENT_BEFORE_SEND, function (Event $event) {
            try {
                /** @var Response $response */
                $response = $event->sender;
                $this->robots->appendToSeomaticResponse($response);
            } catch (Throwable $e) {
                Craft::error('SEOmatic AI robots append failed: ' . $e->getMessage(), __METHOD__);
            }
        });

        // Head output: meta overrides and JSON-LD.
        Event::on(
            View::class,
            View::EVENT_AFTER_RENDER_PAGE_TEMPLATE,
            function (TemplateEvent $event) {
                try {
                    $event->output = $this->head->apply($event->output);
                } catch (Throwable $e) {
                    Craft::error('SEOmatic AI head rendering failed: ' . $e->getMessage(), __METHOD__);
                }
            }
        );
    }

    /** Clears every cached lookup the front-end hooks use. */
    public static function invalidateCaches(): void
    {
        try {
            \yii\caching\TagDependency::invalidate(Craft::$app->getCache(), self::CACHE_TAG);
        } catch (Throwable $e) {
            Craft::error('SEOmatic AI cache invalidation failed: ' . $e->getMessage(), __METHOD__);
        }
    }
}
