<?php
/**
 * The scheduled findings digest: settings, schedule, the durable marker, the email, the queue
 * fallback and the console command.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-yarn/tests/integration/digest.php
 *
 * Self-cleaning. Settings are swapped in memory; the marker row is captured and put back; mail
 * goes to a null transport and is captured from the mailer's own event. Nothing here triggers
 * garbage collection — the digest does not hang off it, and in the shared harness it would run
 * every other plugin's clean-up too.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\mail\Mailer as CraftMailer;
use justinholtweb\yarn\events\DigestEvent;
use justinholtweb\yarn\jobs\SendDigest;
use justinholtweb\yarn\models\Settings;
use justinholtweb\yarn\Plugin;
use justinholtweb\yarn\services\Digest;
use Symfony\Component\Mailer\Transport\NullTransport;
use yii\base\Event;
use yii\mail\BaseMailer;
use yii\mail\MailEvent;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();
$digest = $plugin->digest;
$original = $plugin->getSettings()->toArray();
$originalRow = (new Query())->from([Digest::TABLE])->where(['handle' => Digest::HANDLE])->one() ?: null;
$tz = new DateTimeZone(Craft::$app->getTimeZone());

/** Swaps in a settings shape, in memory only. */
function configure(array $overrides = []): Settings
{
    global $original;

    $plugin = Plugin::getInstance();
    $settings = new Settings();
    $settings->setAttributes(array_merge($original, $overrides), false);
    $plugin->setSettings($settings->toArray());
    $plugin->graph->invalidate();

    return $plugin->getSettings();
}

function at(string $when): DateTimeImmutable
{
    global $tz;

    return new DateTimeImmutable($when, $tz);
}

function resetMarker(): void
{
    Db::delete(Digest::TABLE, ['handle' => Digest::HANDLE]);
}

// Mail: a null transport, so nothing leaves the harness, and the mailer's own event to see it.
$sent = [];
$refuse = false;
Craft::$app->getMailer()->setTransport(new NullTransport());
Event::on(CraftMailer::class, BaseMailer::EVENT_BEFORE_SEND, function(MailEvent $event) use (&$sent, &$refuse) {
    if ($refuse) {
        $event->isValid = false;
        return;
    }

    $sent[] = $event->message;
});

/** @return craft\mail\Message[] What was sent since the last call. */
function drain(): array
{
    global $sent;

    $out = $sent;
    $sent = [];

    return $out;
}

// ============================================================================ settings

section('Settings');

check('a bare install is valid — nothing about the digest is required', function() {
    $settings = new Settings();

    return ($settings->validate() && $settings->digestEnabled === false && $settings->digestRecipients === '')
        ?: json_encode($settings->getErrors());
});

check('recipients split on commas, semicolons and new lines, de-duplicated', function() {
    $settings = new Settings();
    $settings->setAttributes(['digestRecipients' => "a@example.test, b@example.test;\nb@example.test  c@example.test"], false);

    return $settings->recipientList() === ['a@example.test', 'b@example.test', 'c@example.test']
        ?: json_encode($settings->recipientList());
});

check('a recipient that is not an email address fails validation, by name', function() {
    $settings = new Settings();
    $settings->setAttributes(['digestRecipients' => 'ok@example.test, not-an-address'], false);
    $settings->validate();
    $errors = $settings->getErrors('digestRecipients');

    return (count($errors) === 1 && str_contains($errors[0], 'not-an-address')) ?: json_encode($errors);
});

check('recipients can come from an environment variable', function() {
    putenv('YARN_DIGEST_TEST_RECIPIENTS=env1@example.test,env2@example.test');
    $_SERVER['YARN_DIGEST_TEST_RECIPIENTS'] = 'env1@example.test,env2@example.test';
    $settings = new Settings();
    $settings->setAttributes(['digestRecipients' => '$YARN_DIGEST_TEST_RECIPIENTS'], false);

    return $settings->recipientList() === ['env1@example.test', 'env2@example.test'] ?: json_encode($settings->recipientList());
});

check('hour, weekday and frequency are range-checked; posted strings are cast', function() {
    $settings = new Settings();
    $settings->setAttributes(['digestHour' => '9', 'digestWeekday' => '3', 'digestEnabled' => '1'], false);
    $cast = $settings->digestHour === 9 && $settings->digestWeekday === 3 && $settings->digestEnabled === true;

    $bad = new Settings();
    $bad->setAttributes(['digestHour' => '24', 'digestWeekday' => '0', 'digestFrequency' => 'hourly'], false);
    $bad->validate();

    return ($cast && $bad->hasErrors('digestHour') && $bad->hasErrors('digestWeekday') && $bad->hasErrors('digestFrequency'))
        ?: json_encode($bad->getErrors());
});

check('an unknown check fails validation; an all-unticked group posts [""] and becomes []', function() {
    $bad = new Settings();
    $bad->setAttributes(['digestChecks' => ['brokenTargets', 'nope']], false);
    $bad->validate();

    $empty = new Settings();
    $empty->setAttributes(['digestChecks' => ['']], false);

    return ($bad->hasErrors('digestChecks') && $empty->digestChecks === []) ?: json_encode($bad->getErrors());
});

check('digest settings do not change the graph fingerprint', function() {
    $a = new Settings();
    $b = new Settings();
    $b->setAttributes(['digestEnabled' => true, 'digestRecipients' => 'x@example.test', 'digestHour' => 3], false);

    return $a->graphFingerprint() === $b->graphFingerprint() ?: 'fingerprint moved';
});

// ============================================================================ schedule

section('Schedule');

check('the marker table exists', function() {
    return Craft::$app->getDb()->tableExists(Digest::TABLE) ?: 'no ' . Digest::TABLE;
});

check('a daily period is the date; a weekly one is the ISO week', function() use ($digest) {
    configure(['digestFrequency' => 'daily']);
    $daily = $digest->periodKey(at('2026-10-11 23:30'));
    configure(['digestFrequency' => 'weekly']);
    $sunday = $digest->periodKey(at('2026-10-11 23:30'));
    $monday = $digest->periodKey(at('2026-10-12 00:10'));
    // ISO week-numbering year differs from the calendar year at the turn of the year.
    $newYear = $digest->periodKey(at('2027-01-01 12:00'));

    return [$daily, $sunday, $monday, $newYear] === ['2026-10-11', '2026-W41', '2026-W42', '2026-W53']
        ?: json_encode([$daily, $sunday, $monday, $newYear]);
});

check('a weekly digest is due from its weekday and hour, in the system time zone', function() use ($digest, $tz) {
    configure(['digestFrequency' => 'weekly', 'digestWeekday' => 3, 'digestHour' => 9]);
    $due = $digest->dueAt(at('2026-10-09 15:00'));

    // The same moment expressed in UTC lands in the same period and gets the same answer.
    $utc = (new DateTimeImmutable('2026-10-09 15:00', $tz))->setTimezone(new DateTimeZone('UTC'));
    $sameFromUtc = $digest->dueAt($utc)->format('c') === $due->format('c');

    return ($due->format('Y-m-d H:i') === '2026-10-07 09:00' && $due->getTimezone()->getName() === $tz->getName() && $sameFromUtc)
        ?: $due->format('c');
});

check('switched off: nothing is sent and nothing is recorded', function() use ($digest) {
    resetMarker();
    configure(['digestEnabled' => false, 'digestRecipients' => 'ops@example.test']);
    $result = $digest->run(at('2026-10-12 10:00'));
    $row = (new Query())->from([Digest::TABLE])->where(['handle' => Digest::HANDLE])->one();

    return ($result === Digest::RESULT_DISABLED && drain() === [] && !$row) ?: "$result / " . json_encode($row);
});

check('switched on with nobody to send to says so', function() use ($digest) {
    configure(['digestEnabled' => true, 'digestRecipients' => '']);

    return $digest->run(at('2026-10-12 10:00')) === Digest::RESULT_NO_RECIPIENTS ?: 'sent anyway';
});

check('before the hour it is not due', function() use ($digest) {
    configure(['digestEnabled' => true, 'digestRecipients' => 'ops@example.test', 'digestFrequency' => 'weekly', 'digestWeekday' => 1, 'digestHour' => 8]);

    return ($digest->run(at('2026-10-12 07:59')) === Digest::RESULT_NOT_DUE && !$digest->isDue(at('2026-10-12 07:59')) && drain() === [])
        ?: 'went early';
});

// ============================================================================ runs

section('Runs, against findings made on purpose');

$entrySection = null;

foreach (Craft::$app->getEntries()->getAllSections() as $candidate) {
    if ($candidate->type !== craft\models\Section::TYPE_SINGLE && $candidate->getEntryTypes()) {
        $entrySection = $candidate;
        break;
    }
}

$fieldId = (int)(new Query())->select(['id'])->from([Table::FIELDS])->where(['type' => craft\fields\Entries::class])->scalar();
$made = [];
$relationRows = [];

function makeEntry(string $title): Entry
{
    global $made, $entrySection;

    $entry = new Entry();
    $entry->sectionId = $entrySection->id;
    $entry->typeId = $entrySection->getEntryTypes()[0]->id;
    $entry->title = $title;

    if (!Craft::$app->getElements()->saveElement($entry)) {
        throw new RuntimeException('could not save ' . $title . ': ' . json_encode($entry->getErrors()));
    }

    return $made[] = $entry;
}

/** A relation from a live entry to one in the trash: a `brokenTargets` finding. */
function breakRelation(string $label): Entry
{
    global $fieldId, $relationRows;

    $source = makeEntry("Yarn digest — $label source <i>marked</i>");
    $target = makeEntry("Yarn digest — $label target");

    Db::insert(Table::RELATIONS, [
        'fieldId' => $fieldId,
        'sourceId' => $source->id,
        'sourceSiteId' => null,
        'targetId' => $target->id,
        'sortOrder' => 1,
    ]);
    $relationRows[] = [$source->id, $target->id];

    Craft::$app->getElements()->deleteElement($target);
    Plugin::getInstance()->graph->invalidate();

    return $source;
}

if ($entrySection === null || !$fieldId) {
    echo "  ! skipped — no channel/structure section or no Entries field to borrow\n";
} else {
    $weekly = [
        'digestEnabled' => true,
        'digestRecipients' => 'ops@example.test, lead@example.test',
        'digestFrequency' => 'weekly',
        'digestWeekday' => 1,
        'digestHour' => 8,
        'digestChecks' => ['brokenTargets'],
        'digestSendWhenEmpty' => false,
    ];

    $first = breakRelation('first');

    check('the first due run sends, one message per recipient, everything counted as new', function() use ($digest, $weekly, $first) {
        resetMarker();
        configure($weekly);
        $result = $digest->run(at('2026-10-12 08:05'));
        $messages = drain();
        $state = $digest->state();

        if ($result !== Digest::RESULT_SENT) {
            return "result $result";
        }

        $to = array_map(fn($m) => array_key_first($m->getTo()), $messages);
        sort($to);

        return ($to === ['lead@example.test', 'ops@example.test']
            && $state['period'] === '2026-W42'
            && $state['lastSentAt'] !== null
            && $state['lastCount'] >= 1
            && str_contains($messages[0]->getSubject(), 'new finding'))
            ?: json_encode(['to' => $to, 'state' => $state, 'subject' => $messages[0]->getSubject()]);
    });

    check('the email has an HTML and a text part, and the HTML escapes titles', function() use ($digest, $weekly) {
        resetMarker();
        configure($weekly);
        $digest->run(at('2026-10-12 08:05'));
        $message = drain()[0] ?? null;

        if ($message === null) {
            return 'nothing sent';
        }

        $symfony = $message->getSymfonyEmail();
        $html = (string)$symfony->getHtmlBody();
        $text = (string)$symfony->getTextBody();

        return (str_contains($html, '&lt;i&gt;marked&lt;/i&gt;')
            && !str_contains($html, '<i>marked</i>')
            && str_contains($text, '<i>marked</i>')
            && str_contains($html, 'yarn/findings')
            && str_contains($text, 'Yarn can only see what it scans'))
            ?: 'html: ' . substr(strip_tags($html), 0, 200);
    });

    check('a second run in the same period does nothing — idempotent', function() use ($digest) {
        $results = [$digest->run(at('2026-10-12 09:00')), $digest->run(at('2026-10-18 23:59'))];

        return ($results === [Digest::RESULT_ALREADY_SENT, Digest::RESULT_ALREADY_SENT] && drain() === []) ?: json_encode($results);
    });

    check('the SendDigest job is the same decision — a duplicate job is a no-op', function() {
        // Whatever the real clock says, the second of two back-to-back jobs sends nothing.
        (new SendDigest())->execute(Craft::$app->getQueue());
        drain();
        (new SendDigest())->execute(Craft::$app->getQueue());

        return drain() === [] ?: 'the duplicate sent';
    });

    check('next period with nothing new: no email, but the period is used up', function() use ($digest, $weekly) {
        resetMarker();
        configure($weekly);
        $digest->run(at('2026-10-12 08:05'));
        drain();

        $result = $digest->run(at('2026-10-19 08:05'));
        $state = $digest->state();

        return ($result === Digest::RESULT_NOTHING_NEW && drain() === [] && $state['period'] === '2026-W43' && $state['lastResult'] === 'nothingNew')
            ?: "$result " . json_encode($state);
    });

    $second = breakRelation('second');

    check('next period with a new finding: only the new one is listed', function() use ($digest, $second) {
        $result = $digest->run(at('2026-10-26 08:05'));
        $messages = drain();
        $text = $messages ? (string)$messages[0]->getSymfonyEmail()->getTextBody() : '';

        return ($result === Digest::RESULT_SENT
            && str_contains($text, 'second source')
            && !str_contains($text, 'first source')
            && str_contains($messages[0]->getSubject(), 'One new finding'))
            ?: "$result / " . substr($text, 0, 400);
    });

    check('"send even when empty" sends a nothing-new digest', function() use ($digest, $weekly) {
        configure(['digestSendWhenEmpty' => true] + $weekly);
        $result = $digest->run(at('2026-11-02 08:05'));
        $messages = drain();

        return ($result === Digest::RESULT_SENT && $messages && str_contains($messages[0]->getSubject(), 'Nothing new'))
            ?: $result;
    });

    check('a refused send gives the period back, and the next run sends', function() use ($digest, $weekly) {
        global $refuse;

        configure(['digestSendWhenEmpty' => true] + $weekly);
        $before = $digest->state()['period'];

        $refuse = true;
        $failedResult = $digest->run(at('2026-11-09 08:05'));
        $refuse = false;
        $after = $digest->state();

        $retry = $digest->run(at('2026-11-09 09:05'));
        drain();

        return ($failedResult === Digest::RESULT_FAILED && $after['period'] === $before && $after['lastResult'] === 'failed' && $retry === Digest::RESULT_SENT)
            ?: json_encode([$failedResult, $before, $after['period'], $retry]);
    });

    check('an event handler can cancel it, and the period is given back', function() use ($digest) {
        $before = $digest->state()['period'];
        $handler = function(DigestEvent $event) {
            $event->isValid = false;
        };
        Event::on(Digest::class, Digest::EVENT_BEFORE_SEND, $handler);
        $result = $digest->run(at('2026-11-16 08:05'));
        Event::off(Digest::class, Digest::EVENT_BEFORE_SEND, $handler);

        return ($result === Digest::RESULT_CANCELLED && $digest->state()['period'] === $before && drain() === []) ?: $result;
    });

    check('an event handler can change the recipients', function() use ($digest) {
        $handler = function(DigestEvent $event) {
            $event->recipients = ['someone-else@example.test'];
        };
        Event::on(Digest::class, Digest::EVENT_BEFORE_SEND, $handler);
        $digest->run(at('2026-11-16 08:05'));
        Event::off(Digest::class, Digest::EVENT_BEFORE_SEND, $handler);
        $messages = drain();

        return (count($messages) === 1 && array_key_first($messages[0]->getTo()) === 'someone-else@example.test') ?: count($messages) . ' sent';
    });

    check('of two runs racing for one period, exactly one claims it', function() use ($digest) {
        $claim = new ReflectionMethod(Digest::class, 'claim');
        $previous = $digest->state()['period'];

        $a = $claim->invoke($digest, '2099-W01', $previous);
        $b = $claim->invoke($digest, '2099-W01', $previous);
        Db::update(Digest::TABLE, ['period' => $previous], ['handle' => Digest::HANDLE]);

        return ($a === true && $b === false) ?: json_encode([$a, $b]);
    });

    check('--force sends inside a period that has already gone, and records it', function() use ($digest, $weekly) {
        configure(['digestSendWhenEmpty' => true] + $weekly);
        $digest->run(at('2026-11-23 08:05'));
        drain();
        $result = $digest->run(at('2026-11-23 10:00'), true);

        return ($result === Digest::RESULT_SENT && count(drain()) === 2 && $digest->state()['period'] === '2026-W48') ?: $result;
    });

    check('a test digest sends, is marked as one, and leaves the marker alone', function() use ($digest) {
        $before = (new Query())->from([Digest::TABLE])->where(['handle' => Digest::HANDLE])->one();
        $count = $digest->sendTest(['tester@example.test']);
        $after = (new Query())->from([Digest::TABLE])->where(['handle' => Digest::HANDLE])->one();
        $messages = drain();

        return ($count === 1 && $before == $after && str_starts_with($messages[0]->getSubject(), '[Test]')
            && str_contains((string)$messages[0]->getSymfonyEmail()->getHtmlBody(), 'This is a test'))
            ?: json_encode([$count, $before == $after, $messages[0]->getSubject() ?? null]);
    });

    check('keys survive a rename: the same broken relation is not news twice', function() use ($digest, $first) {
        $before = $digest->collect();
        $keysBefore = array_keys($before);
        sort($keysBefore);
        // Straight to the column: saving the entry would let its own relation field rewrite the
        // relations table and drop the row this test inserted by hand.
        Db::update(Table::ELEMENTS_SITES, ['title' => 'Yarn digest — renamed source'], ['elementId' => $first->id]);
        Plugin::getInstance()->graph->invalidate();
        $after = $digest->collect();
        $keysAfter = array_keys($after);
        sort($keysAfter);

        return $keysBefore === $keysAfter ?: json_encode([
            'gone' => array_map(fn($f) => [$f->check, $f->title, $f->context], array_values(array_diff_key($before, $after))),
            'new' => array_map(fn($f) => [$f->check, $f->title, $f->context], array_values(array_diff_key($after, $before))),
        ]);
    });

    section('The web fallback');

    check('switched off, the fallback queues nothing', function() use ($digest, $weekly) {
        configure(['digestWebTrigger' => false] + $weekly);

        return $digest->queueIfDue(at('2026-12-07 08:05')) === false ?: 'queued';
    });

    check('due: it queues one SendDigest job, then stays quiet for five minutes', function() use ($digest, $weekly) {
        configure($weekly);
        $cache = Craft::$app->getCache();
        $cache->delete('yarn:digest:checked');
        $cache->delete('yarn:digest:queued:2026-W50');

        $first = $digest->queueIfDue(at('2026-12-07 08:05'));
        $second = $digest->queueIfDue(at('2026-12-07 08:06'));

        $jobs = (new Query())->select(['id'])->from(['{{%queue}}'])
            ->where(['description' => Craft::t('yarn', 'Sending the Yarn findings digest')])
            ->column();

        foreach ($jobs as $id) {
            Craft::$app->getQueue()->release((string)$id);
        }

        $cache->delete('yarn:digest:checked');
        $cache->delete('yarn:digest:queued:2026-W50');

        return ($first === true && $second === false && count($jobs) === 1) ?: json_encode([$first, $second, $jobs]);
    });

    check('not due: the fallback queues nothing', function() use ($digest, $weekly) {
        configure($weekly);
        Craft::$app->getCache()->delete('yarn:digest:checked');
        $queued = $digest->queueIfDue(at('2026-12-07 07:00'));
        Craft::$app->getCache()->delete('yarn:digest:checked');

        return $queued === false ?: 'queued early';
    });

    check('nothing in the digest goes near garbage collection', function() {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/src/services/Digest.php')
            . file_get_contents(dirname(__DIR__, 2) . '/src/Plugin.php');

        // Mentioned in comments, never used: no import, no handler.
        return (!str_contains($source, 'craft\\services\\Gc') && !preg_match('/Event::on\(\s*Gc::/', $source)) ?: 'hooks Gc';
    });

    // ------------------------------------------------------------------------- clean up

    section('Cleanup');

    check('the relation rows and entries are removed', function() use (&$made, &$relationRows) {
        foreach ($relationRows as [$s, $t]) {
            Db::delete(Table::RELATIONS, ['sourceId' => $s, 'targetId' => $t]);
        }

        foreach ($made as $entry) {
            $fresh = Entry::find()->id($entry->id)->status(null)->trashed(null)->one();

            if ($fresh) {
                Craft::$app->getElements()->deleteElement($fresh, true);
            }
        }

        return true;
    });
}

// ============================================================================ console

section('Console');

check('`yarn/digest/send` runs and exits cleanly with the saved settings', function() {
    exec('php craft yarn/digest/send 2>&1', $output, $code);
    $text = implode("\n", $output);

    // Whatever the harness's saved settings are, this is not a fault: off, not due, gone, or nothing new.
    return in_array($code, [0, 78], true) ?: "exit $code: $text";
});

check('`yarn/digest/status` prints the schedule', function() {
    exec('php craft yarn/digest/status 2>&1', $output, $code);
    $text = implode("\n", $output);

    return ($code === 0 && str_contains($text, 'Next due') && str_contains($text, 'Last sent')) ?: "exit $code: $text";
});

check('the marker and settings are restored', function() use ($plugin, $original, $originalRow) {
    resetMarker();

    if ($originalRow) {
        unset($originalRow['id']);
        Db::insert(Digest::TABLE, $originalRow);
    }

    $plugin->setSettings($original);

    return true;
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
