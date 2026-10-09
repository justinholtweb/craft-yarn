<?php

namespace justinholtweb\yarn\controllers;

use Craft;
use justinholtweb\yarn\models\Finding;
use justinholtweb\yarn\Plugin;
use yii\web\Response;

/**
 * Everything the graph has to complain about, worst first.
 */
class FindingsController extends BaseController
{
    public const PER_PAGE = 100;

    public function actionIndex(): Response
    {
        $site = $this->site();
        $graph = $this->graph($site);
        $service = Plugin::getInstance()->findings;

        $all = $service->run($graph);

        $check = (string)$this->request->getParam('check', '');
        $severity = (string)$this->request->getParam('severity', '');
        $page = max(1, (int)$this->request->getParam('page', 1));

        $filtered = array_values(array_filter($all, function(Finding $finding) use ($check, $severity) {
            return ($check === '' || $finding->check === $check)
                && ($severity === '' || $finding->severity === $severity);
        }));

        $total = count($filtered);
        $canDigest = Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_DIGEST);
        $pages = max(1, (int)ceil($total / self::PER_PAGE));
        $page = min($page, $pages);

        return $this->renderTemplate('yarn/findings/index', [
            'site' => $site,
            'siteOptions' => $this->siteOptions(),
            'graph' => $graph,
            'findings' => array_slice($filtered, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'check' => $check,
            'severity' => $severity,
            'tally' => $service->tally($all),
            'severities' => $service->severities($all),
            'names' => $service->names(),
            // Only read for someone who will see the digest panel; it is one row, but it is a row.
            'digestState' => $canDigest ? Plugin::getInstance()->digest->state() : null,
            'digestNext' => $canDigest ? Plugin::getInstance()->digest->nextDueAt() : null,
        ]);
    }
}
