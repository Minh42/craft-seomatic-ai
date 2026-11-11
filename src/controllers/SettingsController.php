<?php

namespace seomatic\craftai\controllers;

use Craft;
use craft\web\Controller;
use seomatic\craftai\Plugin;
use yii\web\Response;

/**
 * The plugin's control panel screen: the connection key, with copy and
 * regenerate. Admins only; works with allowAdminChanges off, since the key
 * is not project config.
 */
class SettingsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requireAdmin(false);
        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::$plugin;
        $primary = Craft::$app->getSites()->getPrimarySite();
        return $this->renderTemplate('seomatic-ai/settings', [
            'key' => $plugin->keys->getKey(),
            'siteUrl' => $primary->getBaseUrl(),
            'apiBase' => rtrim((string)$primary->getBaseUrl(), '/') . '/actions/seomatic-ai/api/',
            'seomaticInstalled' => $plugin->seomaticBridge->isEnabled(),
            'robotsServedBy' => $plugin->robots->servedBy((int)$primary->id),
            'version' => $plugin->getVersion(),
        ]);
    }

    public function actionRegenerateKey(): Response
    {
        $this->requirePostRequest();
        Plugin::$plugin->keys->regenerate();
        Craft::$app->getSession()->setNotice('New key created. Paste it into SEOmatic: the old key no longer works.');
        return $this->redirect(\craft\helpers\UrlHelper::cpUrl('seomatic-ai'));
    }
}
