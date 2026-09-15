<?php

namespace justinholtweb\yarn;

use Craft;
use craft\base\Element;
use craft\base\Plugin as BasePlugin;
use craft\events\DefineHtmlEvent;
use craft\events\ElementEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\log\MonologTarget;
use craft\services\Elements;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use justinholtweb\yarn\models\Node;
use justinholtweb\yarn\models\Settings;
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

    public const LOG_CATEGORY = 'yarn';

    public string $schemaVersion = '1.0.0';
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

        // Both of these render or read inside the control panel only, and both cost something on
        // every element save. Nothing here should load on a front-end request.
        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $this->registerElementPanel();
        }

        $this->registerDeleteLog();
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

                Craft::info(sprintf(
                    'Deleting %s #%d (%s) — still used by %d element(s): %s',
                    $kind,
                    $element->id,
                    (string)$element,
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
                            ],
                        ],
                    ],
                ];
            }
        );
    }
}
