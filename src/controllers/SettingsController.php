<?php

namespace justinholtweb\yarn\controllers;

use Craft;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\Tag;
use craft\elements\User;
use craft\fields\BaseRelationField;
use craft\helpers\UrlHelper;
use justinholtweb\yarn\models\Node;
use justinholtweb\yarn\models\Settings;
use justinholtweb\yarn\Plugin;
use yii\web\Response;

/**
 * The settings screen.
 *
 * Its own control panel page rather than the plugin settings modal, so the source toggles can sit
 * next to what they cost — and because the excluded-sources list needs the room.
 */
class SettingsController extends BaseController
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // `false`: with admin changes off this screen still opens, read-only, rather than 403ing.
        // Saving is what needs them, and `actionSave()` asks for that itself.
        $this->requireAdmin(false);

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('yarn/settings/index', array_merge($this->options(), [
            'settings' => Plugin::getInstance()->getSettings(),
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
        ]));
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $plugin = Plugin::getInstance();
        $posted = $this->request->getBodyParam('settings', []);

        /** @var Settings $settings */
        $settings = $plugin->getSettings();
        $settings->setAttributes($posted, false);

        if (!$settings->validate()) {
            $this->setFailFlash(Craft::t('yarn', 'Couldn’t save settings.'));
            Craft::$app->getUrlManager()->setRouteParams(['settings' => $settings]);

            return $this->renderTemplate('yarn/settings/index', array_merge($this->options(), [
                'settings' => $settings,
                'readOnly' => false,
            ]));
        }

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())) {
            $this->setFailFlash(Craft::t('yarn', 'Couldn’t save settings.'));

            return $this->redirect(UrlHelper::cpUrl('yarn/settings'));
        }

        // Every setting on this screen either changes what the graph contains or how much of it
        // is drawn, and a cached graph built under the old ones would quietly contradict the
        // screen that just saved.
        $plugin->graph->invalidate();

        $this->setSuccessFlash(Craft::t('yarn', 'Settings saved.'));

        return $this->redirect(UrlHelper::cpUrl('yarn/settings'));
    }

    /**
     * Everything the settings screen offers a choice from, plus the digest's current state.
     *
     * @return array<string, mixed>
     */
    private function options(): array
    {
        $plugin = Plugin::getInstance();
        $digest = $plugin->digest;

        $checkOptions = [];

        foreach ($plugin->findings->names() as $id => $name) {
            $checkOptions[] = ['label' => $name, 'value' => $id];
        }

        $siteOptions = [['label' => Craft::t('yarn', 'Primary site'), 'value' => '']];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteOptions[] = ['label' => $site->name, 'value' => $site->handle];
        }

        $weekdayOptions = [];

        foreach (range(1, 7) as $day) {
            // 2024-01-01 was a Monday, so day N of that week is ISO weekday N.
            $weekdayOptions[] = [
                'label' => Craft::$app->getFormatter()->asDate("2024-01-0$day", 'php:l'),
                'value' => $day,
            ];
        }

        $hourOptions = [];

        foreach (range(0, 23) as $hour) {
            $hourOptions[] = ['label' => sprintf('%02d:00', $hour), 'value' => $hour];
        }

        return [
            'sourceOptions' => $this->sourceOptions(),
            'kindOptions' => $this->kindOptions(),
            'groupOptions' => $this->groupOptions(),
            'fieldOptions' => $this->fieldOptions(),
            'checkOptions' => $checkOptions,
            'digestSiteOptions' => $siteOptions,
            'weekdayOptions' => $weekdayOptions,
            'hourOptions' => $hourOptions,
            'timeZone' => Craft::$app->getTimeZone(),
            'digestState' => $digest->state(),
            'digestNext' => $digest->nextDueAt(),
        ];
    }

    /** @return array<int, array{label: string, value: string}> */
    private function sourceOptions(): array
    {
        return [
            [
                'label' => Craft::t('yarn', 'Reference tags in content'),
                'value' => Settings::SOURCE_REF_TAGS,
            ],
            [
                'label' => Craft::t('yarn', 'Structure hierarchy (parent to child)'),
                'value' => Settings::SOURCE_STRUCTURE,
            ],
            [
                'label' => Craft::t('yarn', 'Hard-coded links in content'),
                'value' => Settings::SOURCE_URLS,
            ],
        ];
    }

    /** @return array<int, array{label: string, value: string}> */
    private function kindOptions(): array
    {
        return [
            ['label' => Craft::t('yarn', 'Entries'), 'value' => Node::KIND_ENTRY],
            ['label' => Craft::t('yarn', 'Assets'), 'value' => Node::KIND_ASSET],
            ['label' => Craft::t('yarn', 'Categories'), 'value' => Node::KIND_CATEGORY],
            ['label' => Craft::t('yarn', 'Tags'), 'value' => Node::KIND_TAG],
            ['label' => Craft::t('yarn', 'Users'), 'value' => Node::KIND_USER],
            ['label' => Craft::t('yarn', 'Globals'), 'value' => Node::KIND_GLOBAL],
        ];
    }

    /**
     * Everything that can be left out of the graph, by the key the graph uses for it.
     *
     * @return array<int, array{label: string, value: string}>
     */
    private function groupOptions(): array
    {
        $options = [];

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $options[] = [
                'label' => Craft::t('yarn', 'Section: {name}', ['name' => $section->name]),
                'value' => "section:$section->id",
            ];
        }

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            $options[] = [
                'label' => Craft::t('yarn', 'Volume: {name}', ['name' => $volume->name]),
                'value' => "volume:$volume->id",
            ];
        }

        foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
            $options[] = [
                'label' => Craft::t('yarn', 'Categories: {name}', ['name' => $group->name]),
                'value' => "categoryGroup:$group->id",
            ];
        }

        foreach (Craft::$app->getTags()->getAllTagGroups() as $group) {
            $options[] = [
                'label' => Craft::t('yarn', 'Tags: {name}', ['name' => $group->name]),
                'value' => "tagGroup:$group->id",
            ];
        }

        $options[] = ['label' => Craft::t('yarn', 'Users'), 'value' => 'users'];
        $options[] = ['label' => Craft::t('yarn', 'Globals'), 'value' => 'globals'];

        // Everything else that is an element: a plugin's own type, or Craft's addresses. These
        // are the ones most often worth excluding — thousands of rows, no relations, and nothing
        // anyone thinks of as content.
        $known = [Entry::class, Asset::class, Category::class, Tag::class, User::class, GlobalSet::class];

        foreach (Craft::$app->getElements()->getAllElementTypes() as $type) {
            if (in_array($type, $known, true)) {
                continue;
            }

            $parts = explode('\\', $type);

            $options[] = [
                'label' => Craft::t('yarn', 'Element type: {name}', ['name' => end($parts)]),
                'value' => "type:$type",
            ];
        }

        return $options;
    }

    /**
     * Relation fields only — the ones that can produce an edge worth ignoring.
     *
     * @return array<int, array{label: string, value: string}>
     */
    private function fieldOptions(): array
    {
        $options = [];

        foreach (Craft::$app->getFields()->getAllFields() as $field) {
            if (!$field instanceof BaseRelationField) {
                continue;
            }

            $options[] = ['label' => "$field->name ($field->handle)", 'value' => $field->handle];
        }

        usort($options, fn(array $a, array $b) => $a['label'] <=> $b['label']);

        return $options;
    }
}
