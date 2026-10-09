<?php

namespace justinholtweb\yarn;

use Craft;
use craft\base\Element;
use craft\base\Plugin as BasePlugin;
use craft\elements\Asset;
use craft\elements\conditions\ElementCondition;
use craft\elements\Entry;
use craft\elements\User;
use craft\events\DefineAttributeHtmlEvent;
use craft\events\DefineHtmlEvent;
use craft\events\ElementEvent;
use craft\events\RegisterConditionRulesEvent;
use craft\events\RegisterElementTableAttributesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\log\MonologTarget;
use craft\services\Elements;
use craft\services\UserPermissions;
use craft\web\Application as WebApplication;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use justinholtweb\yarn\conditions\IsUsedConditionRule;
use justinholtweb\yarn\models\Node;
use justinholtweb\yarn\models\Settings;
use justinholtweb\yarn\services\Digest;
use justinholtweb\yarn\services\Export;
use justinholtweb\yarn\services\Findings;
use justinholtweb\yarn\services\Graph;
use justinholtweb\yarn\services\Relations;
use justinholtweb\yarn\services\Sources;
use justinholtweb\yarn\variables\YarnVariable;
use Throwable;
use yii\base\Event;

/**
 * Yarn — every relation on the site, from both ends.
 *
 * @property-read Graph $graph
 * @property-read Relations $relations
 * @property-read Findings $findings
 * @property-read Export $export
 * @property-read Sources $sources
 * @property-read Digest $digest
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    /** Read the map, the browsers and the findings. */
    public const PERMISSION_VIEW = 'yarn:view';

    /** Download the graph. */
    public const PERMISSION_EXPORT = 'yarn:export';

    /** Send a test findings digest from the control panel. */
    public const PERMISSION_DIGEST = 'yarn:sendDigest';

    /** The element index column, for assets and entries. */
    public const TABLE_ATTRIBUTE = 'yarnUsage';

    /** Element types that get the "Used by" column and the "Is used" condition rule. */
    public const USAGE_TYPES = [Asset::class, Entry::class];

    public const LOG_CATEGORY = 'yarn';

    public string $schemaVersion = '1.1.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'graph' => Graph::class,
                'relations' => Relations::class,
                'findings' => Findings::class,
                'export' => Export::class,
                'sources' => Sources::class,
                'digest' => Digest::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerLogging();
        $this->registerCpUrlRules();
        $this->registerPermissions();
        $this->registerTwigVariable();
        $this->registerCacheInvalidation();
        $this->registerUsageColumn();
        $this->registerConditionRules();

        // Both of these render or read inside the control panel only, and both cost something on
        // every element save. Nothing here should load on a front-end request.
        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $this->registerElementPanel();
        }

        $this->registerDeleteLog();

        if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->registerDigestFallback();
        }
    }

    /**
     * The digest's fallback trigger, for sites with no cron job running `yarn/digest/send`.
     *
     * At the end of a web request rather than on `Gc::EVENT_RUN`: garbage collection runs on a
     * one-in-a-million dice roll by default, which makes for a schedule nobody can predict. What
     * runs here is a cache read; the schedule is consulted at most every five minutes and the
     * work happens in a queue job.
     */
    private function registerDigestFallback(): void
    {
        /** @var Settings $settings */
        $settings = $this->getSettings();

        if (!$settings->digestEnabled || !$settings->digestWebTrigger) {
            return;
        }

        Event::on(WebApplication::class, WebApplication::EVENT_AFTER_REQUEST, function() {
            try {
                // Before the migration that adds the marker table has run, there is nowhere to
                // read the schedule from.
                if (!Craft::$app->getIsInstalled() || Craft::$app->getPlugins()->isPluginUpdatePending($this)) {
                    return;
                }

                $this->digest->queueIfDue();
            } catch (Throwable $e) {
                Craft::warning('Could not check the digest schedule: ' . $e->getMessage(), self::LOG_CATEGORY);
            }
        });
    }

    /**
     * A "Used by" column for the Assets and Entries indexes.
     *
     * Counted for the whole page at once: every element a query returns carries the full result
     * in `elementQueryResult`, so the first row's cell runs one query for all of them and the rest
     * read the memo. A per-row count would be a query per row.
     */
    private function registerUsageColumn(): void
    {
        $counts = [];

        foreach (self::USAGE_TYPES as $type) {
            Event::on(
                $type,
                Element::EVENT_REGISTER_TABLE_ATTRIBUTES,
                function(RegisterElementTableAttributesEvent $event) {
                    $event->tableAttributes[self::TABLE_ATTRIBUTE] = [
                        'label' => Craft::t('yarn', 'Used by'),
                    ];
                }
            );

            Event::on(
                $type,
                Element::EVENT_DEFINE_ATTRIBUTE_HTML,
                function(DefineAttributeHtmlEvent $event) use (&$counts) {
                    if ($event->attribute !== self::TABLE_ATTRIBUTE) {
                        return;
                    }

                    /** @var Element $element */
                    $element = $event->sender;

                    if ($element->id === null) {
                        $event->html = '';
                        return;
                    }

                    $key = $element->siteId . ':' . $element->id;

                    if (!isset($counts[$key])) {
                        $page = array_filter(
                            $element->elementQueryResult ?? [$element],
                            fn($other) => $other->id !== null && $other->siteId === $element->siteId,
                        );
                        $ids = array_map(fn($other) => $other->id, $page);
                        $ids[] = $element->id;

                        foreach ($this->relations->usageCounts($ids, $element->siteId) as $id => $count) {
                            $counts[$element->siteId . ':' . $id] = $count;
                        }
                    }

                    $event->html = $this->usageCellHtml($element, $counts[$key] ?? 0);
                }
            );
        }
    }

    private function usageCellHtml(Element $element, int $count): string
    {
        $label = Craft::$app->getFormatter()->asInteger($count);

        if ($count === 0 || !Craft::$app->getUser()->checkPermission(self::PERMISSION_VIEW)) {
            return Html::tag('span', $label, $count === 0 ? ['class' => 'light'] : []);
        }

        return Html::a($label, UrlHelper::cpUrl("yarn/element/{$element->id}", ['site' => $element->getSite()->handle]), [
            'title' => Craft::t('yarn', 'See what uses this in Yarn'),
        ]);
    }

    /**
     * "Is used" for asset and entry conditions — the index filter, custom sources, and anything
     * else Craft builds from an element condition.
     */
    private function registerConditionRules(): void
    {
        Event::on(
            ElementCondition::class,
            ElementCondition::EVENT_REGISTER_CONDITION_RULES,
            function(RegisterConditionRulesEvent $event) {
                /** @var ElementCondition $condition */
                $condition = $event->sender;

                if (in_array($condition->elementType, self::USAGE_TYPES, true)) {
                    $event->conditionRules[] = IsUsedConditionRule::class;
                }
            }
        );
    }

    private function registerTwigVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('yarn', YarnVariable::class);
            }
        );
    }

    private function registerLogging(): void
    {
        /** @var Settings $settings */
        $settings = $this->getSettings();

        Craft::getLogger()->dispatcher->targets[] = new MonologTarget([
            'name' => self::LOG_CATEGORY,
            'categories' => [self::LOG_CATEGORY],
            'level' => $settings->logLevel,
            'logContext' => false,
            'allowLineBreaks' => true,
            'maxFiles' => 10,
        ]);
    }

    /**
     * A cached graph that outlives the content it describes is worse than no cache at all — it
     * reports relations that were removed an hour ago as current.
     */
    private function registerCacheInvalidation(): void
    {
        $invalidate = function() {
            $this->graph->invalidate();
        };

        Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, $invalidate);
        Event::on(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, $invalidate);
        Event::on(Elements::class, Elements::EVENT_AFTER_RESTORE_ELEMENT, $invalidate);
    }

    /**
     * The Relations panel in the sidebar of every element edit screen.
     *
     * Attached to `craft\base\Element` rather than to each element class: Yii matches class-level
     * handlers up the inheritance chain, so one handler covers entries, assets, categories, users
     * and anything a plugin has invented.
     */
    private function registerElementPanel(): void
    {
        /** @var Settings $settings */
        $settings = $this->getSettings();

        if (!$settings->showElementPanel) {
            return;
        }

        Event::on(
            Element::class,
            Element::EVENT_DEFINE_SIDEBAR_HTML,
            function(DefineHtmlEvent $event) {
                /** @var Element $element */
                $element = $event->sender;

                // Drafts and revisions relate to whatever their canonical does; saying so twice
                // in two different places would be noise, and a provisional draft's own relation
                // rows are a work in progress by definition.
                if (
                    $element->id === null
                    || $element->getIsDraft()
                    || $element->getIsRevision()
                    || !Craft::$app->getUser()->checkPermission(self::PERMISSION_VIEW)
                ) {
                    return;
                }

                try {
                    $event->html .= $this->elementPanelHtml($element);
                } catch (Throwable $e) {
                    // A panel is not worth a white screen on the edit page of every element on the
                    // site. Log it and render the screen without it.
                    Craft::warning('Could not render the relations panel: ' . $e->getMessage(), self::LOG_CATEGORY);
                }
            }
        );
    }

    private function elementPanelHtml(Element $element): string
    {
        $siteId = $element->siteId ?? Craft::$app->getSites()->getCurrentSite()->id;
        $usages = $this->relations->directUsages($element->id, $siteId);
        $uses = $this->relations->directUses($element->id, $siteId);

        return Craft::$app->getView()->renderTemplate('yarn/_partials/panel', [
            'element' => $element,
            'usages' => $usages,
            'uses' => $uses,
            'yarnUrl' => UrlHelper::cpUrl("yarn/element/{$element->id}", ['site' => $siteId]),
        ], View::TEMPLATE_MODE_CP);
    }

    /**
     * Writes down what an element was being used by, at the moment it went.
     *
     * Deliberately not an attempt to stop the delete. `Elements::EVENT_BEFORE_DELETE_ELEMENT` is
     * not cancellable, and the cancellable event on the element itself fires after Matrix has
     * already deleted the nested entries — so a veto there keeps the element and loses its
     * content. What is left is a record, which is what anyone asking "why did the homepage go
     * blank on Thursday" actually needs.
     */
    private function registerDeleteLog(): void
    {
        /** @var Settings $settings */
        $settings = $this->getSettings();

        if ($settings->deleteGuard !== Settings::GUARD_LOG) {
            return;
        }

        Event::on(
            Elements::class,
            Elements::EVENT_BEFORE_DELETE_ELEMENT,
            function(ElementEvent $event) use ($settings) {
                $element = $event->element;
                $kind = Node::KINDS_BY_TYPE[$element::class] ?? Node::KIND_OTHER;

                if ($element->id === null || !in_array($kind, $settings->deleteGuardKinds, true)) {
                    return;
                }

                try {
                    $usages = $this->relations->directUsages($element->id, $element->siteId);
                } catch (Throwable $e) {
                    Craft::warning('Could not read relations before a delete: ' . $e->getMessage(), self::LOG_CATEGORY);
                    return;
                }

                if ($usages === []) {
                    return;
                }

                // A user's string form is often their email address, which has no business in a
                // log file. Their id identifies them as well.
                Craft::info(sprintf(
                    'Deleting %s #%d%s — still used by %d element(s): %s',
                    $kind,
                    $element->id,
                    $element instanceof User ? '' : ' (' . $element . ')',
                    count($usages),
                    implode(', ', array_map(fn(array $u) => '#' . $u['id'], $usages)),
                ), self::LOG_CATEGORY);
            }
        );
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $user = Craft::$app->getUser();

        $item['subnav'] = [
            'map' => ['label' => Craft::t('yarn', 'Map'), 'url' => 'yarn'],
            'browse' => ['label' => Craft::t('yarn', 'Browse'), 'url' => 'yarn/browse'],
            'assets' => ['label' => Craft::t('yarn', 'Assets'), 'url' => 'yarn/assets'],
            'globals' => ['label' => Craft::t('yarn', 'Globals'), 'url' => 'yarn/globals'],
            'findings' => ['label' => Craft::t('yarn', 'Findings'), 'url' => 'yarn/findings'],
        ];

        if ($user->getIsAdmin()) {
            $item['subnav']['settings'] = ['label' => Craft::t('yarn', 'Settings'), 'url' => 'yarn/settings'];
        }

        return $item;
    }

    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('yarn/settings'));
    }

    private function registerCpUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['yarn'] = 'yarn/map/index';
                $event->rules['yarn/map/data'] = 'yarn/map/data';
                $event->rules['yarn/browse'] = 'yarn/browse/index';
                $event->rules['yarn/assets'] = 'yarn/browse/assets';
                $event->rules['yarn/globals'] = 'yarn/browse/globals';
                $event->rules['yarn/element/<elementId:\d+>'] = 'yarn/element/index';
                $event->rules['yarn/findings'] = 'yarn/findings/index';
                $event->rules['yarn/export'] = 'yarn/export/index';
                $event->rules['yarn/settings'] = 'yarn/settings/index';
            }
        );
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('yarn', 'Yarn'),
                    'permissions' => [
                        self::PERMISSION_VIEW => [
                            'label' => Craft::t('yarn', 'See the site’s relations'),
                            'nested' => [
                                self::PERMISSION_EXPORT => [
                                    'label' => Craft::t('yarn', 'Export the graph'),
                                ],
                                self::PERMISSION_DIGEST => [
                                    'label' => Craft::t('yarn', 'Send a test findings digest'),
                                ],
                            ],
                        ],
                    ],
                ];
            }
        );
    }
}
