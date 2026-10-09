# Yarn — Craft CMS 5 Plugin

## Project Overview

Yarn shows every relation on a Craft site, from both ends: what points at what, what nothing points
at, and what breaks if you delete it. Distributed as `justinholtweb/craft-yarn`. **Free, single
edition**, everything switched on.

The gap it fills: Craft records relations perfectly well and has no screen anywhere that answers
*“is anything still using this?”* — which is the question that matters at the exact moment somebody
is about to press Delete.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, Yii2, Twig
- **One small table, no runtime dependencies, no build step.** Settings live in project config;
  the graph is assembled in memory and cached in Craft's data cache. The only table,
  `yarn_digests`, holds the findings digest's "last sent" marker (schema 1.1.0) — state that must
  survive a cache clear, or every clear would resend the week's digest.
- The map is hand-written: a force-directed layout and an SVG renderer in
  `src/web/assets/cp/dist/yarn-cp.js`, about 200 lines of arithmetic. Shipping a graph library to
  draw it would be a megabyte of somebody else's release schedule.

## Architecture

### Namespace & package

- Namespace: `justinholtweb\yarn`
- Package: `justinholtweb/craft-yarn`
- Handle: `yarn`

### Sources → edges → graph

`services\Sources` builds a list of `EdgeSourceInterface` implementations from one `BuildContext`
(site, settings, and the nested-ownership map), fires `RegisterEdgeSourcesEvent`, and
`services\Graph::build()` drains their generators into a `models\Graph`.

Four built-in sources:

| Class | Edge kind | Default |
| --- | --- | --- |
| `RelationsSource` | `field` | always on |
| `NestedSource` | `nested` | only when roll-up is **off** |
| `ContentSource` | `ref`, `url` | `refTags` on, `urls` off |
| `StructureSource` | `structure` | off |

`ContentSource` is one class for two settings on purpose: both matchers need the same pass over
every field value on the site, and running them separately reads it twice.

### Roll-up is the load-bearing idea

`BuildContext::rollUp()` resolves a nested element (a Matrix entry, an entry embedded in CKEditor)
to its top-level owner, and reports the field on that owner it came through. On by default.

Without it, a Matrix-heavy site produces a graph in which almost no node is anything a person has
ever seen in the control panel. With it, "where is this image used" answers with pages.

`BuildContext` loads the whole of `elements_owners`, which is right for one whole-site build and
ruinous for a sidebar panel — so `services\Relations::ownersOf()` does the same job by walking up a
level at a time, querying only the ids still in play.

### Two paths to "what uses this", deliberately

- `Relations::view()` reads the assembled graph and knows about everything.
- `Relations::directUsages()` goes straight to the relations table for one element.

The second exists because the sidebar panel renders on **every** element edit screen on the site.
Building a whole-site graph to answer "is this in use" would put seconds on the load of a page
nobody opened Yarn to look at. It counts relation fields only; the panel links to the full picture.

### Adjacency stores edge indexes, not node ids

`models\Graph` keeps `out`/`in` as `id => int[]` of **edge indexes**. Two elements joined by several
distinct relations — an asset in a hero field and in three Matrix blocks — is four edges, and a map
of `from => to[]` loses all but the first. That is what makes "used four times" answerable.

### Drafts and revisions are excluded everywhere

A revision's relations are a snapshot of what an entry *used* to point at. Counting them means
every asset ever swapped out still reads as in use — the single most misleading thing a relations
report can say, because it is the one that stops you cleaning up. Every query joins `elements` and
filters `draftId`/`revisionId`/`archived`.

### Permissions are applied after the cache, never before

The cached graph is the whole site and is shared by every user. `BaseController::graph()` passes it
through `services\Graph::forUser()`, which swaps any node the user can't view (by section, volume,
category group, `viewUsers`, editable global sets) for a copy labelled "Restricted element #id". The
node keeps its edges, so a restricted page still counts as a usage. Hiding it would make that
page's assets read as unused. Tags, nested entries and `type:*` nodes aren't masked: tags have no
view permission, and checking `type:*` means loading every element of that type. Admins skip all of
this.

### Caching

Keyed on site + `Settings::graphFingerprint()` + a counter bumped by `Graph::invalidate()`, which
is called on every element save, delete and restore. A graph serialising to more than
`Graph::CACHE_MAX_BYTES` (4 MB) is **not cached**, and says so in the log.

The fingerprint deliberately excludes `maxNodes`, `cacheDuration` and everything under reporting —
none of those change the graph, only what is done with it.

### Index column and condition rule

`Plugin::registerUsageColumn()` adds a **Used by** table attribute (`yarnUsage`) to `Asset` and
`Entry`. The first cell rendered reads `$element->elementQueryResult` (Craft 5 sets the whole query
result on every element) and calls `Relations::usageCounts()` once for the page; the rest read a
memo. `conditions\IsUsedConditionRule` adds `EXISTS`/`NOT EXISTS (Relations::usedSubquery())` to
the element query — `andWhere` on an ElementQuery lands in its subquery, where `elements` and
`elements_sites` are the aliases. Both count relation fields only, with the same draft/revision/
trash/ignored-field filters as `directUsages()`. Never the graph: a graph build per index load is
exactly what the performance note on this feature forbade.

## Scheduled digests (reference for the family)

Yarn's findings digest is the **Theme 7 reference** for scheduled email reports. cleanair, csr,
freelogs, my, trackr, waver and yo copy it. There is no shared package: copy the files, rename the
namespace, adapt the marked parts.

### Files to copy

| File | Generic? | What to adapt |
| --- | --- | --- |
| `src/services/Digest.php` | Everything above "What this plugin reports" | `TABLE`, `HANDLE`, cache key prefixes (`yarn:digest:*`), `LOG_CATEGORY`; below the line rewrite `collect()` (items keyed by a stable id-based key), `key()`, `site()` (drop if not per-site), `subject()`, `variables()` |
| `src/jobs/SendDigest.php` | Yes | Namespace, description string |
| `src/helpers/Mailer.php` | Yes | Namespace only — `send($recipients, $subject, 'handle/_emails/x', $vars)` renders `x.twig` + `x.txt.twig` in CP mode, one message per recipient, returns how many were accepted |
| `src/console/controllers/DigestController.php` | Yes | Messages; keep the exit codes (0 for every non-fault, 78 no recipients, 1 failed) |
| `src/controllers/DigestController.php` | Yes | Permission constant; base controller (Yarn's requires CP + view) |
| `src/events/DigestEvent.php` | Yes | Namespace |
| `src/templates/_emails/digest.twig`, `digest.txt.twig` | No | The content. HTML autoescapes; text uses `autoescape false`. Keep the "test" banner |
| `src/templates/_partials/digest.twig` | Mostly | Permission name, plugin handle in `getPlugin()` |
| `Digest::createTable()` + `migrations/Install.php` + `m261009_000000_digests.php` | Yes | Table name; call `createTable()` from the plugin's own Install and a new migration; bump `schemaVersion` |
| `models/Settings.php` — the `digest*` properties, rules, `recipientList()`, `validateRecipients()`, `'digestChecks'` in the `['']` filter | Yes | `digestChecks`/`digestSite` are Yarn's; replace with whatever selects *what* to report |
| `Plugin::registerDigestFallback()`, the `digest` component, `PERMISSION_DIGEST` | Yes | — |
| Settings template "Findings digest" section; the partial included **after** the settings form | Yes | Labels |
| `tests/integration/digest.php`, `digest-http.php` | Mostly | The "make findings on purpose" part |

### The rules the pattern exists to keep

- **Settings:** enabled, `daily`/`weekly`, ISO weekday 1–7, hour 0–23 in `Craft::$app->getTimeZone()`,
  recipients as a string (commas/new lines, or `$ENV_VAR` via `App::parseEnv`), send-when-empty.
  **Never `required`.**
- **Period key** `Y-m-d` or ISO `o-\WW` (note `o`, not `Y`: 2027-01-01 is `2026-W53`). Due when
  `now >= dueAt(now)` and the marker's `period` differs — "at or after", so a missed hour still sends
  later in the same period.
- **Claim, don't check-then-send:** `UPDATE … SET period = :new WHERE handle = :h AND period = :old`
  (`period => null` becomes `IS NULL`); exactly one racer gets 1 affected row. On send failure or an
  event cancel, `release()` puts `:old` back so the next run retries.
- **"Something new"** = current keys minus the `seen` JSON list stored with the last run. Keys come
  from ids, never titles. A nothing-new period still records the period (no email unless
  send-when-empty).
- **Triggers:** cron → `run()` inline; web fallback → `EVENT_AFTER_REQUEST`, `cache->add()` throttle
  (5 min), `isDue()`, `cache->add()` per-period queued flag, push `SendDigest` (which calls `run()`).
  Skip while `isPluginUpdatePending()` (table may not exist yet). **Never `Gc::EVENT_RUN`**, and never
  trigger GC in tests.
- **Test send:** POST + CSRF (Craft) + its own permission + 30s per-user cooldown; sends to the
  recipients or, when none, the current user; **never touches the marker**.
- **Tests:** mail through `NullTransport` (`getMailer()->setTransport(...)`) and capture with
  `BaseMailer::EVENT_BEFORE_SEND`; set `isValid = false` there to simulate a failed send. Drive
  `run($now)` with explicit `DateTimeImmutable`s, not the real clock.

## Traps found while building this

- **`Elements::EVENT_BEFORE_DELETE_ELEMENT` is not cancellable**, and the cancellable
  `Element::EVENT_BEFORE_DELETE` fires *after* Matrix has already deleted the element's nested
  entries — so vetoing there keeps the element row and destroys its content. There is no safe
  "don't delete this" hook. The `deleteGuard` setting therefore logs and does not block; this is
  documented in the settings screen, the docs and the FAQ, because "why won't it stop me" is the
  obvious question. See `[[craft-visor-gotchas]]`.
- **`Elements::REF_TAG_PATTERN` is `@since 5.10`** and Yarn supports 5.3, so the grammar is copied
  into `ContentSource::REF_TAG_PATTERN`. `tests/unit/RefTagTest.php` pins the shapes it has to keep
  matching, because a copy that drifts is a bug.
- **A reference Yarn did not try to resolve is not a broken reference.** Craft lets any element type
  resolve a ref by whatever it likes — `{legs:pricing-table:render(compact)}` is another plugin's
  own grammar. Yarn resolves ids and UIDs and *skips* everything else. Reporting them turned every
  such tag on the harness into a red error row.
- **A numeric ref still has to be checked.** `{entry:8842:url}` where 8842 is gone renders as the
  tag itself, braces and all, in the middle of the page — so ids are verified against `elements`
  per batch, not trusted.
- **Handing a json column a `json_encode()`d string double-encodes it.** What lands is a JSON
  *string* holding JSON, read back with every `/` spelled `\/`, so a link matcher silently finds
  nothing. Found in the test helper; `ContentSource::strings()` now decodes up to twice as
  insurance. Same family as the Schedulr note in `[[craft-schedulr-gotchas]]`.
- **Content must be decoded before matching, never regexed raw**, for the same reason.
- **A depth-first cycle search that copies the path into every frame is O(n²) memory**: a
  20,000-node chain took 5.4 GB and most of a minute. One shared path array pushed and popped with
  the frame stack is O(n) — 48 MB and 0.05s. The unit suite's deep-chain test is what caught it.
- **`['like', 'type', 'craft\\fields\\Entries']` matches nothing.** Between PHP's string escaping
  and Yii's LIKE escaping the backslashes double twice. Match class names exactly.
- **The map's filters live above the canvas, not inside it.** Scoping `querySelector` to the map
  element silently ignored every filter the reader set. The renderer keeps `root` for the SVG and
  `scope` (`root.closest('.yarn')`) for the controls.
- **Fit when the layout settles, not on a timer** (5.0.1, GitHub #1). The old single fit at 1.2s
  framed a layout still spreading, so outer nodes drifted off the canvas — and it measured dots,
  not labels. `fit()` now counts `node.labelWidth` (measured once in `draw()`), keeps clear of
  `.yarn-map-controls`, and `start()` refits on settle unless `userMoved` (set by pan, wheel, drag).
- **Nothing floats over the drawing.** The legend and status live in `.yarn-map-bar` above
  `.yarn-map-canvas`; an overlay hides whatever nodes the layout puts under it.
- **Craft's multi-select (selectize) announces changes with jQuery's `trigger('change')`**, which
  native `addEventListener` never hears. `bindChrome()` binds `[data-yarn-reload]` through jQuery
  when it's there. `forms.multiselect` is a bare `<select multiple>` — "0 selected" in Chrome, a
  two-row list in Firefox — so use `forms.selectize({multi: true})`.
- **The CP has no dark mode.** A `prefers-color-scheme: dark` block only ever painted dark panels on
  a light control panel. Colours are Craft palette variables (`var(--indigo-600)`…); custom
  properties resolve `var()` at computed-value time, so `readColours()` still gets real colours.
- **`requestAnimationFrame` is throttled to about a frame a second in a background tab**, so a
  layout that settles in four seconds on screen is still wandering minutes later on a tab nobody is
  looking at — and then a click lands where a node used to be. There is a hard tick budget
  (`YarnMap.MAX_TICKS`) as well as the alpha floor.
- **Skipping `from === to` after roll-up has to check that roll-up did it.** A Matrix block linking
  to its own page becomes a page→page edge, which is noise. A page whose own content references
  itself is the self-reference finding. Skipping both meant that finding could never fire from
  content.
- **`Assert::matches()` is final in PHPUnit 10**, so a test helper called `matches()` is a fatal at
  class load, not a failing test.
- **Element types Yarn has no special knowledge of need a group key too** (`type:<class>`).
  Without one they collapse into a single unfilterable heap, and on a Commerce site that heap is
  tens of thousands of addresses.

See also `[[craft-plugin-gotchas]]` for family-wide traps — the typed-int `''` TypeError,
lightswitches posting strings, `required` settings blocking fresh installs, `fieldlayoutfields`
being gone in Craft 5 — all of which apply and are handled in `models\Settings::setAttributes()`.

## Testing

No local PHP on this Mac. PHP runs inside the plugin-testing container.

```sh
cd ~/Sites/plugin-testing
ddev exec php /var/www/craft-yarn/tests/integration/checks.php     # 83 checks
ddev exec php /var/www/craft-yarn/tests/integration/cp-ui.php      # 15: every screen renders, Craft controls, no inline styles/hex
ddev exec php /var/www/craft-yarn/tests/integration/digest.php     # digest: settings, schedule, marker, email, fallback, console
ddev exec php /var/www/craft-yarn/tests/integration/digest-http.php # test-send endpoint: POST/CSRF/permission, no nested forms
ddev exec php /var/www/craft-yarn/tests/integration/usage.php      # Used by column + Is used rule against real queries
node --test ~/Sites/craft-yarn/tests/js/map.test.mjs                # 8: map framing, settle refit, selectize wiring (on the Mac)
ddev exec bash -c 'find /var/www/craft-yarn/src -name "*.php" -print0 | xargs -0 -n1 php -l'
ddev exec -d /var/www/craft-yarn vendor/bin/phpunit                # 42 unit tests
ddev exec -d /var/www/craft-yarn vendor/bin/phpstan analyse --memory-limit=1G   # level 5
ddev exec -d /var/www/craft-yarn vendor/bin/ecs check             # craftcms/ecs CRAFT_CMS_4 set
```

The unit suite is pure PHP — graph algorithms, export escaping, the reference tag grammar. The
integration checks create their own entries and relation rows, break them on purpose, and clean up
after themselves; settings are swapped in memory only, so nothing is written to project config.

`ddev exec php craft clear-caches/cp-resources` after editing anything under
`src/web/assets/*/dist`, or Craft goes on serving the previously published copy.

## Coding conventions

- `Craft::t('yarn', '…')` for user-facing strings
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template
- Never mark plugin settings `required`
- Every finding carries a **detail sentence saying what to do**, not just a complaint
- Every screen that reports "unused" repeats the caveat that Yarn can only see what it scans
