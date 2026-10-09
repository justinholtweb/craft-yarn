---
title: Configuration
slug: configuration
order: 30
summary: What counts as a relation, performance limits and caching, reporting, and per-environment overrides.
---

# Configuration

Settings live at **Yarn → Settings**, and can be overridden per environment with
`config/yarn.php`.

```php
<?php

return [
    'sources' => ['refTags', 'urls'],
    'rollUpNested' => true,
    'cacheDuration' => 900,
    'maxNodes' => 600,
    'orphanKinds' => ['entry', 'asset'],
    'excludedGroups' => ['type:craft\\elements\\Address'],
];
```

## What counts as a relation

### `sources` — extra relation sources

Craft’s relation fields are always read. These are the optional ones:

| Value | What it adds | Cost |
| --- | --- | --- |
| `refTags` | `{entry:42:url}` and friends, anywhere in a field value | Reads every field value on the site |
| `structure` | Parent-to-child edges in structure sections and category groups | One query |
| `urls` | `href` and `src` values that resolve to an element | Shares the `refTags` scan |

`refTags` is on by default. `structure` and `urls` are not: a hierarchy is not a dependency, and
the link scanner produces the most false positives of anything here.

`refTags` and `urls` share a single pass over the content, so switching the second one on costs
almost nothing once the first is on.

### `rollUpNested`

On by default. A relation held inside a Matrix block, or inside an entry embedded in CKEditor, is
reported as belonging to the **page that owns it**.

This is almost always what you want: it is the page you would open, and the page that breaks. Turn
it off and every block becomes a node of its own — honest, and on a Matrix-heavy site, unreadable.

### `includeDisabled`

On by default. Disabled elements still appear, marked as disabled. Turn it off when the map is
only meant to describe what is live.

### `ignoredFields`

Relation fields to skip, by handle. The usual reason is a field that relates everything to
everything — a “related posts” field filled by an automation — which adds thousands of edges and
says nothing.

### `excludedGroups`

Sections, volumes, category and tag groups, users, globals, and element types from other plugins,
by key: `section:3`, `volume:1`, `categoryGroup:2`, `tagGroup:4`, `users`, `globals`,
`type:craft\elements\Address`.

The last shape is worth knowing about. Craft’s addresses are elements, there can be tens of
thousands of them, and none of them relate to anything.

## Performance

### `cacheDuration`

Seconds an assembled graph is reused. Default 300.

The cache is also thrown away whenever any element is saved, deleted or restored, so this is a
ceiling on how long a quiet site goes without a rebuild rather than a staleness window. `0`
rebuilds on every request.

A graph that serialises to more than 4 MB is **not cached at all**, and Yarn writes a line to
`storage/logs/yarn.log` saying so. Rebuilding a graph that big costs seconds; writing it to a
shared cache costs the whole site.

### `maxNodes`

How many elements the map will draw at once. Default 400.

The layout is O(n²) per frame in the browser. Past a few hundred nodes the picture stops being
readable well before the browser stops coping, so the map keeps the busiest `maxNodes` and says
that it has.

### `scanBatchSize`

Rows read at a time when scanning content. Default 500. Lower it on a site with very large
rich-text fields and a small PHP memory limit.

## Reporting

### `orphanKinds`

Which kinds of element to report when nothing points at them. Default entries, assets and
categories.

Users and tags are left out on purpose: a user nothing relates to is a normal user.

### `orphansIgnoreRoutable`

Off by default. On, an element that has a URL of its own is not reported as an orphan — it is
still reachable from search and from the sitemap.

Turn it on when the question is *“what is unreachable”*; leave it off when the question is
*“what is unused”*.

### `cycleLimit`

Stop after this many circular relations. Default 20; the twentieth tells you nothing new.

## Elsewhere in the control panel

### `showElementPanel`

On by default. Adds a **Relations** panel to the sidebar of every element edit screen, saying what
points at this element before you delete it.

The panel reads the relations table directly rather than assembling the whole graph — it renders
on every edit screen on the site, and a graph build is not something to do on the way to an edit
form. It therefore counts relation **fields** only; the full picture is one click away.

### `deleteGuard` and `deleteGuardKinds`

`off` (default) or `log`. On `log`, deleting an element that other elements point at writes what
was still using it to `storage/logs/yarn.log`.

It cannot stop the deletion, and does not pretend to. Craft has no cancellable hook that fires
before a field has already deleted the element’s nested content:
`Elements::EVENT_BEFORE_DELETE_ELEMENT` is not cancellable, and vetoing the one that is
(`Element::EVENT_BEFORE_DELETE`) keeps the element row and destroys its blocks. Prevention belongs
on the sidebar panel, while there is still time to reconsider; this setting is for afterwards,
when somebody asks why a page went blank last Thursday.

## Findings digest

Once a day or once a week, Yarn can email what it has found **since the last digest** — a
reference tag that now points at nothing, a live page relating to an entry somebody switched off —
so content rot is noticed without anybody opening Yarn to look.

```php
'digestEnabled' => true,
'digestFrequency' => 'weekly',          // or 'daily'
'digestWeekday' => 1,                   // ISO: 1 = Monday … 7 = Sunday
'digestHour' => 8,                      // 0–23, system time zone
'digestRecipients' => '$YARN_DIGEST_RECIPIENTS',
'digestSendWhenEmpty' => false,
'digestSite' => '',                     // site handle; empty for the primary site
'digestChecks' => ['brokenTargets', 'absentTargets', 'disabledTargets', 'unresolvedReferences', 'cycles', 'selfReferences'],
'digestWebTrigger' => true,
```

### `digestEnabled`, `digestFrequency`, `digestWeekday`, `digestHour`

Off by default. The digest becomes due at the given hour (in the system time zone, **Settings →
General**) on the given day — every day for `daily` — and is sent by the first run at or after
that moment, **once per day or ISO week**. A site whose cron missed the hour still sends later in
the same period; it never sends twice.

### `digestRecipients`

Email addresses separated by commas or new lines, or an environment variable holding them. Each
recipient gets their own message, so nobody on the list learns who else is on it. Never required:
an empty list just means nothing is sent.

### `digestSendWhenEmpty`

Off by default: a period with nothing new sends nothing (and still counts as that period's
digest). Turn it on if silence is ambiguous for you and you would rather get an "all clear".

### `digestSite` and `digestChecks`

Which site's graph to report on, and which checks. Nothing ticked means every check. Orphans and
unused assets are left out by default — they are housekeeping rather than breakage, and on most
sites they would bury the one broken reference the digest exists to surface.

"New" means a finding that was not in the last digest, matched by element ids, never titles — so
renaming an entry does not make its broken relation news again, and a problem that was fixed and
then comes back is.

### Sending it: cron, or the web fallback

The reliable way is a cron job. It is safe to run as often as you like:

```sh
*/15 * * * * php /path/to/craft yarn/digest/send
```

Without one, leave **`digestWebTrigger`** on: at the end of a web request Yarn looks at the
schedule (at most every five minutes — a cache read the rest of the time) and, when the digest is
due, queues a job to send it. Either route ends in the same decision, recorded in Yarn's one
database table, so cron and the fallback never both send.

Yarn deliberately does not use Craft's garbage-collection event for this: it runs on a dice roll,
which is not a schedule.

**Send a test digest now**, at the bottom of the settings screen and of **Yarn → Findings**,
sends what the next digest would say, marked as a test, to the recipients (or to you, when there
are none). It does not count as the period's digest. It needs the *Send a test findings digest*
permission.

### `logLevel`

`debug`, `info` (default), `warning` or `error`. Yarn logs to `storage/logs/yarn.log`.
