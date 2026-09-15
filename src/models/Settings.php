<?php

namespace justinholtweb\yarn\models;

use Craft;
use craft\base\Model;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Plugin-wide settings. Project config only — Yarn has no tables of its own.
 *
 * Nothing here is marked `required`. A `required` rule fails `savePluginSettings()` wholesale, so
 * one unfilled box would block saving every other setting on a fresh install.
 */
class Settings extends Model
{
    /** Edge sources, in the order they run. `relations` is not optional — it is the plugin. */
    public const SOURCE_RELATIONS = 'relations';
    public const SOURCE_REF_TAGS = 'refTags';
    public const SOURCE_STRUCTURE = 'structure';
    public const SOURCE_URLS = 'urls';

    public const OPTIONAL_SOURCES = [
        self::SOURCE_REF_TAGS,
        self::SOURCE_STRUCTURE,
        self::SOURCE_URLS,
    ];

    public const GUARD_OFF = 'off';
    public const GUARD_LOG = 'log';

    // ------------------------------------------------------------------ what counts as a thread

    /**
     * Optional edge sources to run, on top of the `relations` table.
     *
     * `refTags` scans content for `{entry:42:url}`. `structure` adds parent-to-child edges.
     * `urls` looks for hard-coded links to pages and assets — off by default because it is the
     * only source that has to read and parse every field value on the site.
     *
     * @var string[]
     */
    public array $sources = [self::SOURCE_REF_TAGS];

    /**
     * Collapse nested elements — Matrix entries, entries embedded in CKEditor — into the element
     * that owns them.
     *
     * On, which is what almost everyone wants: an asset related from inside a Matrix block shows
     * as used by the *page*, which is the thing you were going to open. Off, every block is its
     * own node and the map gets honest and unreadable in equal measure.
     */
    public bool $rollUpNested = true;

    /** Include elements that are disabled, or disabled for the site being mapped. */
    public bool $includeDisabled = true;

    /**
     * Relation fields to ignore, by handle.
     *
     * The usual reason is a field that relates everything to everything — a "related posts"
     * field filled by an automation — which drowns the map without saying anything.
     *
     * @var string[]
     */
    public array $ignoredFields = [];

    /**
     * Sources to leave out entirely, as group keys: `section:3`, `volume:1`, `categoryGroup:2`.
     *
     * @var string[]
     */
    public array $excludedGroups = [];

    // ------------------------------------------------------------------------------ performance

    /**
     * How long a built graph is reused, in seconds. 0 rebuilds on every request.
     *
     * Also invalidated whenever an element is saved or deleted, so the default is not a staleness
     * window so much as a ceiling on how long a quiet site goes without a rebuild.
     */
    public int $cacheDuration = 300;

    /**
     * Most nodes the map will draw at once.
     *
     * A force layout is O(n²) per tick in the browser. Past a few hundred nodes it stops being a
     * picture of the site and starts being a grey disc, so the map asks for a filter instead.
     */
    public int $maxNodes = 400;

    /** Rows read per batch when scanning content for reference tags and URLs. */
    public int $scanBatchSize = 500;

    // -------------------------------------------------------------------------------- reporting

    /**
     * Node kinds the orphan check reports on.
     *
     * Users and tags are left out by default: a user nothing relates to is a normal user, and
     * every site has hundreds of them.
     *
     * @var string[]
     */
    public array $orphanKinds = [Node::KIND_ENTRY, Node::KIND_ASSET, Node::KIND_CATEGORY];

    /**
     * Skip elements that have a URL of their own when reporting orphans.
     *
     * An entry nothing links to is still reachable from search and the sitemap. Turn this on when
     * the question is "what is unreachable"; leave it off when it is "what is unused".
     */
    public bool $orphansIgnoreRoutable = false;

    /** Most cycles the findings screen will chase before it stops. */
    public int $cycleLimit = 20;

    // ------------------------------------------------------------------------- in the wider CP

    /** Add a Relations panel to the sidebar of every element edit screen. */
    public bool $showElementPanel = true;

    /**
     * Whether to record what an element was still being used by, at the moment it was deleted.
     *
     * `log` writes the dependents to `storage/logs/yarn.log` as the delete goes through. It does
     * not — cannot — stop the delete: `Elements::EVENT_BEFORE_DELETE_ELEMENT` is not cancellable,
     * and the cancellable `Element::EVENT_BEFORE_DELETE` fires *after* Matrix has already deleted
     * the element's nested entries, so vetoing there keeps the element and destroys its content.
     *
     * Prevention belongs on the sidebar panel, which says what is using the element while there
     * is still time to reconsider. This setting is for afterwards, when somebody asks why a page
     * went blank last Thursday.
     */
    public string $deleteGuard = self::GUARD_OFF;

    /** Node kinds the delete log applies to. Assets are the ones that hurt. */
    public array $deleteGuardKinds = [Node::KIND_ASSET, Node::KIND_ENTRY];

    public string $logLevel = 'info';

    // ------------------------------------------------------------------------------------ rules

    public function attributeLabels(): array
    {
        return [
            'sources' => Craft::t('yarn', 'Extra relation sources'),
            'rollUpNested' => Craft::t('yarn', 'Roll up nested elements'),
            'includeDisabled' => Craft::t('yarn', 'Include disabled elements'),
            'ignoredFields' => Craft::t('yarn', 'Ignored fields'),
            'excludedGroups' => Craft::t('yarn', 'Excluded sources'),
            'cacheDuration' => Craft::t('yarn', 'Cache duration'),
            'maxNodes' => Craft::t('yarn', 'Map node limit'),
            'scanBatchSize' => Craft::t('yarn', 'Scan batch size'),
            'orphanKinds' => Craft::t('yarn', 'Report orphans for'),
            'orphansIgnoreRoutable' => Craft::t('yarn', 'Ignore elements with their own URL'),
            'cycleLimit' => Craft::t('yarn', 'Cycle limit'),
            'showElementPanel' => Craft::t('yarn', 'Show the Relations panel'),
            'deleteGuard' => Craft::t('yarn', 'Record deletions of elements in use'),
            'deleteGuardKinds' => Craft::t('yarn', 'Watch these element kinds'),
            'logLevel' => Craft::t('yarn', 'Log level'),
        ];
    }

    protected function defineRules(): array
    {
        return [
            [['rollUpNested', 'includeDisabled', 'orphansIgnoreRoutable', 'showElementPanel'], 'boolean'],
            [['cacheDuration'], 'integer', 'min' => 0, 'max' => 86400],
            [['maxNodes'], 'integer', 'min' => 20, 'max' => 5000],
            [['scanBatchSize'], 'integer', 'min' => 50, 'max' => 5000],
            [['cycleLimit'], 'integer', 'min' => 1, 'max' => 500],
            [['deleteGuard'], 'in', 'range' => [self::GUARD_OFF, self::GUARD_LOG]],
            [['logLevel'], 'in', 'range' => ['debug', 'info', 'warning', 'error']],
            [['sources'], 'validateSources', 'skipOnEmpty' => false],
            [['orphanKinds', 'deleteGuardKinds'], 'validateKinds', 'skipOnEmpty' => false],
        ];
    }

    public function validateSources(string $attribute): void
    {
        foreach ($this->$attribute as $source) {
            if (!in_array($source, self::OPTIONAL_SOURCES, true)) {
                $this->addError($attribute, Craft::t('yarn', '“{name}” is not a relation source Yarn knows.', [
                    'name' => $source,
                ]));
            }
        }
    }

    public function validateKinds(string $attribute): void
    {
        $known = array_values(Node::KINDS_BY_TYPE);

        foreach ($this->$attribute as $kind) {
            if (!in_array($kind, $known, true)) {
                $this->addError($attribute, Craft::t('yarn', '“{name}” is not an element kind Yarn knows.', [
                    'name' => $kind,
                ]));
            }
        }
    }

    // -------------------------------------------------------------------------------- normalise

    public function uses(string $source): bool
    {
        return in_array($source, $this->sources, true);
    }

    /**
     * A short string that changes whenever a setting changes what a built graph would contain.
     *
     * Part of the cache key, so switching reference tags on does not hand back the graph built
     * without them. Deliberately excludes `maxNodes`, `cacheDuration` and everything under
     * "reporting" — none of those change the graph, only what is done with it.
     */
    public function graphFingerprint(): string
    {
        $relevant = [
            $this->sources,
            $this->rollUpNested,
            $this->includeDisabled,
            $this->ignoredFields,
            $this->excludedGroups,
        ];

        return substr(md5(json_encode($relevant)), 0, 12);
    }

    /**
     * Craft's control panel posts everything as a string, editable tables post a blank trailing
     * row, and `config/yarn.php` is written by hand. All three arrive here.
     *
     * @param mixed $values
     */
    public function setAttributes($values, $safeOnly = true): void
    {
        parent::setAttributes(is_array($values) ? $this->normalize($values) : $values, $safeOnly);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function normalize(array $values): array
    {
        foreach (['ignoredFields', 'excludedGroups'] as $key) {
            if (isset($values[$key])) {
                $values[$key] = self::flatten($values[$key]);
            }
        }

        foreach (['sources', 'orphanKinds', 'deleteGuardKinds'] as $key) {
            if (isset($values[$key])) {
                // A checkbox group with nothing ticked posts `['']`, not `[]`.
                $values[$key] = array_values(array_filter(
                    is_array($values[$key]) ? $values[$key] : [$values[$key]],
                    fn($value) => is_string($value) && $value !== '',
                ));
            }
        }

        foreach ($values as $name => $value) {
            if (!property_exists($this, $name) || is_array($value)) {
                continue;
            }

            $cast = self::cast($name, $value);

            if ($cast === null) {
                unset($values[$name]);
                continue;
            }

            $values[$name] = $cast[0];
        }

        return $values;
    }

    /**
     * @return array{0: mixed}|null Null when the posted value cannot be cast and the default
     *                              should stand — an empty string in a number box, most often,
     *                              which would otherwise be a TypeError against a typed int.
     */
    private static function cast(string $property, mixed $value): ?array
    {
        $type = (new ReflectionProperty(self::class, $property))->getType();

        if (!$type instanceof ReflectionNamedType) {
            return [$value];
        }

        return match ($type->getName()) {
            // Craft's lightswitch posts the strings '1' and '' — and 'false' from a YAML config.
            'bool' => [(bool)$value && $value !== 'false'],
            'int' => is_numeric($value) ? [(int)$value] : null,
            'string' => is_scalar($value) ? [(string)$value] : null,
            default => [$value],
        };
    }

    /**
     * Editable-table rows, or a hand-written flat list, down to a flat list of trimmed strings.
     *
     * @return string[]
     */
    private static function flatten(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $flat = [];

        foreach ($rows as $row) {
            $value = trim((string)(is_array($row) ? reset($row) : $row));

            if ($value !== '') {
                $flat[] = $value;
            }
        }

        return array_values(array_unique($flat));
    }
}
