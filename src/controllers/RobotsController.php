<?php

namespace seomatic\craftai\controllers;

use Craft;
use craft\web\Controller;
use seomatic\craftai\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Serves /robots.txt when the plugin is the one answering it (the route is
 * registered only then, and only while lines are stored).
 */
class RobotsController extends Controller
{
    protected array|bool|int $allowAnonymous = true;

    public function actionIndex(): Response
    {
        $siteId = (int)Craft::$app->getSites()->getCurrentSite()->id;
        $robots = Plugin::$plugin->robots;
        $lines = $robots->linesCached($siteId);
        if ($lines === []) {
            throw new NotFoundHttpException();
        }
        $this->response->getHeaders()->set('Content-Type', 'text/plain; charset=utf-8');
        return $this->asRaw($robots->body($siteId, $lines));
    }
}
