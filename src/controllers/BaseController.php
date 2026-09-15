<?php

namespace justinholtweb\yarn\controllers;

use Craft;
use craft\models\Site;
use craft\web\Controller;
use justinholtweb\yarn\models\Graph as GraphModel;
use justinholtweb\yarn\Plugin;
use yii\web\ForbiddenHttpException;

/**
 * Shared plumbing: the permission gate, and which site we are looking at.
 */
abstract class BaseController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_VIEW);

        return true;
    }

    /**
     * The site being mapped: `?site=handle`, falling back to the one the control panel is on.
     *
     * Editable sites only. A relations map is a map of content, and showing it for a site the
     * user has no business editing would hand them a list of every title in it.
     *
     * @throws ForbiddenHttpException
     */
    protected function site(): Site
    {
        $sites = Craft::$app->getSites();
        $handle = $this->request->getParam('site');
        $site = $handle ? $sites->getSiteByHandle($handle) : null;
        $site ??= $sites->getCurrentSite();

        $editable = $sites->getEditableSiteIds();

        if (!in_array($site->id, $editable, true)) {
            $fallback = $editable[0] ?? null;

            if ($fallback === null) {
                throw new ForbiddenHttpException('You are not permitted to edit any sites.');
            }

            $site = $sites->getSiteById($fallback);
        }

        return $site;
    }

    protected function graph(?Site $site = null): GraphModel
    {
        $site ??= $this->site();

        return Plugin::getInstance()->graph->get($site->id, !$this->request->getParam('rebuild'));
    }

    /**
     * The site switcher's options, in the shape the control panel's own selects want.
     *
     * @return array<int, array{label: string, value: string}>
     */
    protected function siteOptions(): array
    {
        $options = [];

        foreach (Craft::$app->getSites()->getEditableSites() as $site) {
            $options[] = ['label' => $site->name, 'value' => $site->handle];
        }

        return $options;
    }
}
