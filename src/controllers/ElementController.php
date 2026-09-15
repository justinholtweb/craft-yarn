<?php

namespace justinholtweb\yarn\controllers;

use Craft;
use craft\helpers\UrlHelper;
use justinholtweb\yarn\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * One element, both directions, plus what deleting it would cost.
 */
class ElementController extends BaseController
{
    public function actionIndex(int $elementId): Response
    {
        $site = $this->site();
        $graph = $this->graph($site);
        $relations = Plugin::getInstance()->relations;

        $view = $relations->view($graph, $elementId);

        if ($view['node'] === null) {
            // Either it does not exist, or it is filtered out of this site's graph. Both read the
            // same from here, and both mean there is nothing to show.
            throw new NotFoundHttpException(Craft::t('yarn', 'That element is not in this site’s graph.'));
        }

        $depth = max(1, min(4, (int)$this->request->getParam('depth', 2)));

        return $this->renderTemplate('yarn/element/index', [
            'site' => $site,
            'siteOptions' => $this->siteOptions(),
            'graph' => $graph,
            'node' => $view['node'],
            'outgoing' => $view['outgoing'],
            'incoming' => $view['incoming'],
            'outCount' => $view['outCount'],
            'inCount' => $view['inCount'],
            'impact' => $relations->impact($graph, $elementId),
            'depth' => $depth,
            'dataUrl' => UrlHelper::actionUrl('yarn/map/data'),
        ]);
    }
}
