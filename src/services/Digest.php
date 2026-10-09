<?php

namespace justinholtweb\yarn\services;

use Craft;
use craft\base\Component;
use craft\db\Migration;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use craft\models\Site;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use justinholtweb\yarn\events\DigestEvent;
use justinholtweb\yarn\helpers\Mailer;
use justinholtweb\yarn\jobs\SendDigest;
use justinholtweb\yarn\models\Finding;
use justinholtweb\yarn\models\Settings;
use justinholtweb\yarn\Plugin;
use Throwable;
use yii\db\IntegrityException;

/**
 * The scheduled findings digest: once a day or once a week, email what is new since the last one.
 *
 * This class is the family's reference for scheduled email reports (see "Scheduled digests" in
 * CLAUDE.md). Everything above the "What this plugin reports" line is generic — schedule, the
 * durable marker, the claim that makes a send happen once, the fallback trigger. Below it is the
 * part each plugin rewrites: what to collect, how to key it, what to put in the email.
 *
 * Three ways in, one way through:
 *
 * - `php craft yarn/digest/send` from cron — the recommended route, every 15–60 minutes;
 * - the end of a web request, at most every five minutes, which queues {@see SendDigest};
 * - "Send a test digest now" in the control panel, which never touches the marker.
 *
 * All of the scheduled ones end in {@see run()}, and `run()` is idempotent: the first caller to
 * move the marker onto the current period sends, everybody after it finds the period taken.
 * Nothing here hangs off `Gc::EVENT_RUN` — garbage collection runs on a dice roll, and a digest
 * that arrives on a dice roll is not a schedule.
 */
class Digest extends Component
{
    /** @see DigestEvent Cancel it, or change the recipients, subject or variables. */
    public const EVENT_BEFORE_SEND = 'beforeSend';

    public const TABLE = '{{%yarn_digests}}';

    /** One row per digest the plugin sends. Yarn has one. */
    public const HANDLE = 'findings';

    public const RESULT_SENT = 'sent';
    public const RESULT_DISABLED = 'disabled';
    public const RESULT_NO_RECIPIENTS = 'noRecipients';
    public const RESULT_NOT_DUE = 'notDue';
    public const RESULT_ALREADY_SENT = 'alreadySent';
    public const RESULT_NOTHING_NEW = 'nothingNew';
    public const RESULT_CANCELLED = 'cancelled';
    public const RESULT_FAILED = 'failed';

    /** Most findings listed in one email. The rest are a count and a link. */
    public const MAX_ITEMS = 25;

    /** How often the web fallback looks at the schedule at all. */
    public const WEB_CHECK_SECONDS = 300;

    private const WEB_CHECK_KEY = 'yarn:digest:checked';
    private const QUEUED_KEY = 'yarn:digest:queued:';

    // ------------------------------------------------------------------------------- schedule

    /** Now, in the system time zone — the one the digest hour is set in. */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(Craft::$app->getTimeZone()));
    }

    /**
     * The period a moment falls in: `2026-10-09` for a daily digest, `2026-W41` (ISO week) for a
     * weekly one. One send per key, ever.
     */
    public function periodKey(DateTimeInterface $now): string
    {
        $now = $this->inSystemTime($now);

        return $this->settings()->digestFrequency === Settings::DIGEST_DAILY
            ? $now->format('Y-m-d')
            : $now->format('o-\WW');
    }

    /** When the digest for the period containing `$now` becomes due. */
    public function dueAt(DateTimeInterface $now): DateTimeImmutable
    {
        $settings = $this->settings();
        $now = $this->inSystemTime($now);

        if ($settings->digestFrequency === Settings::DIGEST_WEEKLY) {
            $now = $now->setISODate((int)$now->format('o'), (int)$now->format('W'), $settings->digestWeekday);
        }

        return $now->setTime($settings->digestHour, 0);
    }

    /** Whether a scheduled run at `$now` would send (or at least try to). */
    public function isDue(?DateTimeInterface $now = null): bool
    {
        $settings = $this->settings();
        $now = $this->inSystemTime($now ?? $this->now());

        return $settings->digestEnabled
            && $settings->recipientList() !== []
            && $now >= $this->dueAt($now)
            && $this->state()['period'] !== $this->periodKey($now);
    }

    /**
     * When the next scheduled digest will go — for the settings screen. A digest that is due now
     * and has not gone yet answers with now.
     */
    public function nextDueAt(?DateTimeInterface $now = null): ?DateTimeImmutable
    {
        $settings = $this->settings();

        if (!$settings->digestEnabled) {
            return null;
        }

        $now = $this->inSystemTime($now ?? $this->now());

        if ($this->state()['period'] !== $this->periodKey($now)) {
            $due = $this->dueAt($now);

            return $due > $now ? $due : $now;
        }

        $next = $now->modify($settings->digestFrequency === Settings::DIGEST_DAILY ? '+1 day' : '+1 week');

        return $this->dueAt($next);
    }

    // --------------------------------------------------------------------------- the triggers

    /**
     * The scheduled send. Idempotent: call it as often as you like, it sends once per period.
     *
     * @param bool $force Ignore the schedule and the "already sent" marker — `--force` on the
     *                    console command. Still records the send, so the next scheduled one
     *                    reports what is new since *this*.
     * @return string One of the `RESULT_*` constants.
     */
    public function run(?DateTimeInterface $now = null, bool $force = false): string
    {
        $settings = $this->settings();
        $now = $this->inSystemTime($now ?? $this->now());

        if (!$force && !$settings->digestEnabled) {
            return self::RESULT_DISABLED;
        }

        $recipients = $settings->recipientList();

        if ($recipients === []) {
            return self::RESULT_NO_RECIPIENTS;
        }

        if (!$force && $now < $this->dueAt($now)) {
            return self::RESULT_NOT_DUE;
        }

        $period = $this->periodKey($now);
        $state = $this->state();

        if (!$force && ($state['period'] === $period || !$this->claim($period, $state['period']))) {
            return self::RESULT_ALREADY_SENT;
        }

        try {
            $current = $this->collect();
        } catch (Throwable $e) {
            $this->release($period, $state['period']);
            Craft::error('Could not assemble the findings digest: ' . $e->getMessage(), Plugin::LOG_CATEGORY);

            return $this->record(null, null, self::RESULT_FAILED);
        }

        $new = array_diff_key($current, array_flip($state['seen']));

        if ($new === [] && !$settings->digestSendWhenEmpty) {
            return $this->record($period, $current, self::RESULT_NOTHING_NEW);
        }

        $delivered = $this->deliver($recipients, $current, $new, false);

        if ($delivered === null) {
            $this->release($period, $state['period']);

            return $this->record(null, null, self::RESULT_CANCELLED);
        }

        if ($delivered === 0) {
            // Give the period back, so the next run tries again rather than the week going by
            // without a digest because the mail server was down for five minutes on Monday.
            $this->release($period, $state['period']);

            return $this->record(null, null, self::RESULT_FAILED);
        }

        return $this->record($period, $current, self::RESULT_SENT, count($new));
    }

    /**
     * "Send a test digest now". Sends what the next digest would say, marked as a test, and
     * leaves the marker alone — a test must never stop the real one going out.
     *
     * @param string[] $recipients
     * @return int How many recipients it was delivered to.
     */
    public function sendTest(array $recipients): int
    {
        $current = $this->collect();
        $new = array_diff_key($current, array_flip($this->state()['seen']));

        return (int)$this->deliver($recipients, $current, $new, true);
    }

    /**
     * The fallback for sites with no cron job: called at the end of web requests, looks at the
     * schedule at most every {@see WEB_CHECK_SECONDS}, and queues {@see SendDigest} when due.
     *
     * Cheap on purpose — one cache read on almost every request, one row read every five
     * minutes. The job does the work; `run()` decides, so a duplicate job is a no-op.
     */
    public function queueIfDue(?DateTimeInterface $now = null): bool
    {
        $settings = $this->settings();

        if (!$settings->digestEnabled || !$settings->digestWebTrigger) {
            return false;
        }

        $cache = Craft::$app->getCache();

        // `add()` only writes when the key is absent, so of several requests landing together
        // one goes on to look at the schedule.
        if (!$cache->add(self::WEB_CHECK_KEY, 1, self::WEB_CHECK_SECONDS)) {
            return false;
        }

        $now = $this->inSystemTime($now ?? $this->now());

        if (!$this->isDue($now)) {
            return false;
        }

        if (!$cache->add(self::QUEUED_KEY . $this->periodKey($now), 1, 3600)) {
            return false;
        }

        Craft::$app->getQueue()->push(new SendDigest());

        return true;
    }

    // ------------------------------------------------------------------------ durable marker

    /**
     * The marker: which period was last claimed, what was in the last digest, and how it went.
     *
     * @return array{period: string|null, seen: string[], lastRunAt: \DateTime|null, lastSentAt: \DateTime|null, lastResult: string|null, lastCount: int}
     */
    public function state(): array
    {
        $row = $this->row();

        if ($row === null) {
            try {
                Db::insert(self::TABLE, ['handle' => self::HANDLE, 'lastCount' => 0]);
            } catch (IntegrityException) {
                // Somebody else made it between our read and our write. Theirs will do.
            }

            $row = $this->row() ?? [];
        }

        $seen = json_decode((string)($row['seen'] ?? ''), true);

        return [
            'period' => isset($row['period']) && $row['period'] !== '' ? (string)$row['period'] : null,
            'seen' => is_array($seen) ? array_values(array_filter($seen, 'is_string')) : [],
            'lastRunAt' => DateTimeHelper::toDateTime($row['lastRunAt'] ?? null) ?: null,
            'lastSentAt' => DateTimeHelper::toDateTime($row['lastSentAt'] ?? null) ?: null,
            'lastResult' => $row['lastResult'] ?? null,
            'lastCount' => (int)($row['lastCount'] ?? 0),
        ];
    }

    /**
     * Moves the marker onto `$period`, but only if it still says `$previous`. Of two runs racing
     * for the same period, the database lets exactly one of them through.
     */
    private function claim(string $period, ?string $previous): bool
    {
        return Db::update(
            self::TABLE,
            ['period' => $period],
            ['handle' => self::HANDLE, 'period' => $previous],
        ) === 1;
    }

    /** Hands a claimed period back after a failure, so the next run tries again. */
    private function release(string $period, ?string $previous): void
    {
        Db::update(self::TABLE, ['period' => $previous], ['handle' => self::HANDLE, 'period' => $period]);
    }

    /**
     * Writes down how a run went. `$period` and `$current` are null when nothing should change
     * but the result — a failure leaves the period and the "seen" list as they were.
     *
     * @param array<string, mixed>|null $current
     */
    private function record(?string $period, ?array $current, string $result, ?int $count = null): string
    {
        $now = new DateTimeImmutable();
        $columns = [
            'lastRunAt' => Db::prepareDateForDb($now),
            'lastResult' => $result,
        ];

        if ($period !== null) {
            $columns['period'] = $period;
        }

        if ($current !== null) {
            $columns['seen'] = json_encode(array_keys($current));
        }

        if ($result === self::RESULT_SENT) {
            $columns['lastSentAt'] = Db::prepareDateForDb($now);
            $columns['lastCount'] = (int)$count;
        }

        Db::update(self::TABLE, $columns, ['handle' => self::HANDLE]);

        if ($result === self::RESULT_FAILED) {
            Craft::warning('The findings digest was not sent; the next run will try again.', Plugin::LOG_CATEGORY);
        } else {
            Craft::info("Findings digest: $result.", Plugin::LOG_CATEGORY);
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    private function row(): ?array
    {
        return (new Query())->from([self::TABLE])->where(['handle' => self::HANDLE])->one() ?: null;
    }

    /** Shared by `Install` and the migration that adds the table to an existing install. */
    public static function createTable(Migration $migration): void
    {
        $migration->createTable(self::TABLE, [
            'id' => $migration->primaryKey(),
            'handle' => $migration->string(64)->notNull(),
            // The period last claimed: `2026-10-09` or `2026-W41`. Null until the first run.
            'period' => $migration->string(32),
            // JSON list of the keys reported last time, so the next digest can say what is new.
            'seen' => $migration->mediumText(),
            'lastRunAt' => $migration->dateTime(),
            'lastSentAt' => $migration->dateTime(),
            'lastResult' => $migration->string(32),
            'lastCount' => $migration->integer()->notNull()->defaultValue(0),
            'dateCreated' => $migration->dateTime()->notNull(),
            'dateUpdated' => $migration->dateTime()->notNull(),
            'uid' => $migration->uid(),
        ]);

        $migration->createIndex(null, self::TABLE, ['handle'], true);
    }

    // ---------------------------------------------------------------------------- the email

    /**
     * Renders and sends. Null when an event handler cancelled it.
     *
     * @param string[] $recipients
     * @param array<string, Finding> $current
     * @param array<string, Finding> $new
     */
    private function deliver(array $recipients, array $current, array $new, bool $test): ?int
    {
        $site = $this->site();
        $variables = $this->variables($site, $current, $new, $test);
        $subject = $this->subject($site, count($new), $test);

        $event = new DigestEvent([
            'recipients' => $recipients,
            'subject' => $subject,
            'variables' => $variables,
            'isTest' => $test,
        ]);
        $this->trigger(self::EVENT_BEFORE_SEND, $event);

        if (!$event->isValid) {
            return null;
        }

        return Mailer::send($event->recipients, $event->subject, 'yarn/_emails/digest', $event->variables);
    }

    // ===================================================================== What this plugin reports
    //
    // Everything below is Yarn's. A port rewrites collect(), key(), subject() and variables(),
    // and the two templates under templates/_emails/.

    /**
     * Everything worth reporting right now, keyed by something that stays the same from one run
     * to the next for as long as the problem does.
     *
     * @return array<string, Finding>
     */
    public function collect(): array
    {
        $plugin = Plugin::getInstance();
        $site = $this->site();

        // The whole-site graph, unmasked. Recipients are chosen by an admin, and a digest that
        // says "Restricted element #42 points into the trash" is no use to anybody.
        $graph = $plugin->graph->get($site->id);
        $findings = $plugin->findings->run($graph, $this->settings()->digestChecks);

        $keyed = [];

        foreach ($findings as $finding) {
            $keyed[$this->key($finding)] = $finding;
        }

        return $keyed;
    }

    /**
     * Ids, never titles: renaming an entry does not make its broken relation news.
     */
    public function key(Finding $finding): string
    {
        $cycle = $finding->context['cycle'] ?? null;

        if (is_array($cycle)) {
            // A cycle found starting from a different element is the same cycle.
            $cycle = array_values(array_unique(array_map('intval', $cycle)));
            sort($cycle);
        }

        return substr(md5((string)json_encode([
            $finding->check,
            $finding->subject?->id,
            $finding->object?->id,
            $finding->context['reference'] ?? null,
            $finding->context['field'] ?? null,
            $cycle,
        ])), 0, 16);
    }

    public function site(): Site
    {
        $sites = Craft::$app->getSites();
        $handle = $this->settings()->digestSite;

        return ($handle !== '' ? $sites->getSiteByHandle($handle) : null) ?? $sites->getPrimarySite();
    }

    private function subject(Site $site, int $newCount, bool $test): string
    {
        $subject = $newCount > 0
            ? Craft::t('yarn', '{count, plural, =1{One new finding} other{# new findings}} on {site}', [
                'count' => $newCount,
                'site' => $site->name,
            ])
            : Craft::t('yarn', 'Nothing new on {site}', ['site' => $site->name]);

        return ($test ? Craft::t('yarn', '[Test]') . ' ' : '') . 'Yarn: ' . $subject;
    }

    /**
     * @param array<string, Finding> $current
     * @param array<string, Finding> $new
     * @return array<string, mixed>
     */
    private function variables(Site $site, array $current, array $new, bool $test): array
    {
        $plugin = Plugin::getInstance();
        $names = $plugin->findings->names();
        $items = [];

        foreach (array_slice($new, 0, self::MAX_ITEMS) as $finding) {
            $items[] = [
                'severity' => $finding->severity,
                'check' => $names[$finding->check] ?? $finding->check,
                'title' => $finding->title,
                'detail' => $finding->detail,
                'url' => $finding->subject?->cpEditUrl(),
            ];
        }

        $state = $this->state();

        return [
            'siteName' => $site->name,
            'isTest' => $test,
            'items' => $items,
            'newCount' => count($new),
            'more' => max(0, count($new) - self::MAX_ITEMS),
            'totalCount' => count($current),
            'severities' => $plugin->findings->severities(array_values($current)),
            'since' => $state['lastSentAt'],
            'findingsUrl' => UrlHelper::cpUrl('yarn/findings', ['site' => $site->handle]),
            'settingsUrl' => UrlHelper::cpUrl('yarn/settings'),
        ];
    }

    // ---------------------------------------------------------------------------------- misc

    private function inSystemTime(DateTimeInterface $moment): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($moment)
            ->setTimezone(new DateTimeZone(Craft::$app->getTimeZone()));
    }

    private function settings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }
}
