<?php

namespace justinholtweb\yarn\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;
use justinholtweb\yarn\services\Findings;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Plugin-wide settings, in project config. The only thing Yarn keeps in a table of its own is the
 * findings digest's "last sent" marker, which is state, not configuration.
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

    public const DIGEST_DAILY = 'daily';
    public const DIGEST_WEEKLY = 'weekly';

    /** What the digest reports by default: the checks that mean something renders wrongly. */
    public const DIGEST_DEFAULT_CHECKS = [
        Findings::CHECK_BROKEN,
        Findings::CHECK_ABSENT,
        Findings::CHECK_DISABLED,
        Findings::CHECK_UNRESOLVED,
        Findings::CHECK_CYCLES,
        Findings::CHECK_SELF,
    ];

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

    // --------------------------------------------------------------------------- findings digest

    /** Email a digest of new findings on a schedule. */
    public bool $digestEnabled = false;

    /** `daily` or `weekly`. */
    public string $digestFrequency = self::DIGEST_WEEKLY;

    /** ISO day of the week for a weekly digest: 1 is Monday, 7 is Sunday. */
    public int $digestWeekday = 1;

    /**
     * Hour of the day, 0–23, in the system time zone (Settings → General), after which the digest
     * for the day or week becomes due. "After", not "at": a site whose cron missed the hour still
     * sends later in the same period, once.
     */
    public int $digestHour = 8;

    /**
     * Who gets it: email addresses separated by commas or new lines, or an environment variable
     * (`$YARN_DIGEST_RECIPIENTS`) holding the same.
     */
    public string $digestRecipients = '';

    /**
     * Send a digest even when there is nothing new since the last one.
     *
     * Off by default. A weekly email that says "nothing to report" fifty weeks a year teaches
     * everybody who gets it to stop opening it, and the two weeks it matters go unread with the rest.
     */
    public bool $digestSendWhenEmpty = false;

    /** The site whose graph the digest reports on, by handle. Empty for the primary site. */
    public string $digestSite = '';

    /**
     * Checks the digest reports. Empty means every check.
     *
     * Orphans and unused assets are left out by default: they are housekeeping, not breakage, and
     * on most sites they would bury the one broken reference tag the digest exists to surface.
     *
     * @var string[]
     */
    public array $digestChecks = self::DIGEST_DEFAULT_CHECKS;

    /**
     * Also check whether a digest is due at the end of web requests (at most every five minutes)
     * and queue it, for sites without a cron job running `yarn/digest/send`.
     */
    public bool $digestWebTrigger = true;

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
            'digestEnabled' => Craft::t('yarn', 'Email a findings digest'),
            'digestFrequency' => Craft::t('yarn', 'How often'),
            'digestWeekday' => Craft::t('yarn', 'Day of the week'),
            'digestHour' => Craft::t('yarn', 'Hour'),
            'digestRecipients' => Craft::t('yarn', 'Recipients'),
            'digestSendWhenEmpty' => Craft::t('yarn', 'Send even when there is nothing new'),
            'digestSite' => Craft::t('yarn', 'Site'),
            'digestChecks' => Craft::t('yarn', 'Checks to report'),
            'digestWebTrigger' => Craft::t('yarn', 'Check from web requests too'),
        ];
    }

    protected function defineRules(): array
    {
        return [
            [['rollUpNested', 'includeDisabled', 'orphansIgnoreRoutable', 'showElementPanel', 'digestEnabled', 'digestSendWhenEmpty', 'digestWebTrigger'], 'boolean'],
            [['digestFrequency'], 'in', 'range' => [self::DIGEST_DAILY, self::DIGEST_WEEKLY]],
            [['digestWeekday'], 'integer', 'min' => 1, 'max' => 7],
            [['digestHour'], 'integer', 'min' => 0, 'max' => 23],
            [['digestRecipients'], 'validateRecipients'],
            [['digestChecks'], 'validateChecks', 'skipOnEmpty' => false],
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

    public function validateRecipients(string $attribute): void
    {
        // An environment variable is checked for what it resolves to here, and again at send time,
        // because the value can differ between this environment and the one that sends.
        foreach ($this->recipientList(true) as $address) {
            $this->addError($attribute, Craft::t('yarn', '“{address}” is not an email address.', [
                'address' => $address,
            ]));
        }
    }

    public function validateChecks(string $attribute): void
    {
        foreach ($this->$attribute as $check) {
            if (!in_array($check, Findings::CHECKS, true)) {
                $this->addError($attribute, Craft::t('yarn', '“{name}” is not a check Yarn knows.', [
                    'name' => $check,
                ]));
            }
        }
    }

    /**
     * The digest's recipients, parsed: environment variable resolved, split on commas, semicolons
     * and whitespace, de-duplicated.
     *
     * @param bool $invalid Return the entries that are *not* email addresses instead.
     * @return string[]
     */
    public function recipientList(bool $invalid = false): array
    {
        $raw = (string)App::parseEnv($this->digestRecipients);
        $parts = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $valid = [];
        $bad = [];

        foreach (array_unique($parts) as $part) {
            if (filter_var($part, FILTER_VALIDATE_EMAIL) !== false) {
                $valid[] = $part;
            } else {
                $bad[] = $part;
            }
        }

        return $invalid ? $bad : $valid;
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

        foreach (['sources', 'orphanKinds', 'deleteGuardKinds', 'digestChecks'] as $key) {
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
