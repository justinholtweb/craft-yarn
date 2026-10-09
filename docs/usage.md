---
title: Usage
slug: usage
order: 20
summary: The map, one element’s relations, asset usage, globals, findings, Twig, the console and export.
---

# Usage

## The map

**Yarn → Map** draws the site. Elements are circles, sized by how many relations they have and
coloured by kind; relations are lines, dashed differently depending on where they came from.

- **Drag** the background to pan, **scroll** to zoom about the pointer.
- **Click** an element to light up its relations and dim everything else, and to open a panel with
  its counts and links.
- **Double-click** to focus: the map reloads showing only that element’s neighbourhood.
- **Drag** an element to pin it where you put it. **Re-settle** unpins everything and runs the
  layout again.
- The map frames itself: it fits the whole drawing, labels included, once the layout has settled.
  Pan or zoom and it leaves the view alone; **Fit** frames it again.
- The **legend**, in the bar above the map, doubles as a filter — click a kind to hide it.
- **Show only** filters to particular sections, volumes or groups — pick as many as you like, and
  remove one with its ×. **Find** highlights by name.

**Hide unconnected** is on by default. A site with thirty thousand elements and four hundred
relations is mostly dots with no threads attached; the counts for them are on the cards above, and
the list of them is under Findings.

## One element

**Yarn → Browse**, then any element name — or the **See the whole thread** button in the Relations
panel on an element’s edit screen.

The page gives you:

- A small map of everything within two hops.
- **Points at** — grouped by the field each relation came through.
- **Pointed at by** — the same, in reverse. This is the list that decides whether it is safe to
  delete something.
- **Further downstream** — elements that reach this one through something else, with the number of
  hops. They do not break immediately; the chain to them does.

## Asset usage

**Yarn → Assets** lists every asset with a use count and the pages using it, filterable by volume
and down to just the unused ones.

Read the caveat on that page before acting on it. “Unused” means *nothing Yarn scans reaches it* —
an asset used only from a template, from a CSS background, or from a source you have switched off
looks identical to one nobody wants.

## In Craft’s own indexes

The Assets and Entries indexes get two things:

- a **Used by** column (table view → columns), showing how many elements relate to each row and
  linking to Yarn’s view of it;
- an **Is used** condition rule, in the index filter, custom sources and anywhere else Craft takes an
  asset or entry condition — so “unused images in this volume” is a filter you can combine with
  file kind, date and Craft’s bulk actions.

Both count relation fields only, rolled up like the Relations panel, and both are a single
query for the whole page — the rule is an `EXISTS` subquery, never a scan of the site.

## Globals

**Yarn → Globals** lists each global set, what it points at directly, and how far that reaches.

Globals are the blind spot in every “where is this used” conversation. An image related from a
footer global appears on every page on the site, and nothing in Craft says so.

## Findings

**Yarn → Findings** runs every check and sorts the results worst first. See
[findings.md](findings.md) for what each one means.

New findings can also come to you: the **findings digest** emails what is new since the last one,
daily or weekly. See [Configuration](configuration.md#findings-digest).

## Templates

```twig
{# cheap: reads the relations table for one element #}
{{ craft.yarn.isUsed(asset) }}
{{ craft.yarn.usageCount(asset) }}
{{ craft.yarn.usedBy(entry) }}   {# elements that point at this one #}
{{ craft.yarn.uses(entry) }}     {# elements this one points at #}

{# expensive: assembles the whole site #}
{{ craft.yarn.graph() }}
{{ craft.yarn.findings() }}
{{ craft.yarn.path(entryA, entryB) }}
```

Each takes an optional `siteId` as its last argument.

`usedBy()` and `uses()` return real elements, fetched in one query per element type, and are cheap
enough for a front-end page. On the front end they return enabled elements only; in the control
panel they return disabled ones too. `usageCount()` and `isUsed()` count every relation either way,
because "is anything still using this?" includes things that are switched off:

```twig
{% set mentions = craft.yarn.usedBy(entry) %}
{% if mentions %}
    <aside>
        <h2>Also mentioned in</h2>
        <ul>
            {% for other in mentions %}
                <li><a href="{{ other.url }}">{{ other.title }}</a></li>
            {% endfor %}
        </ul>
    </aside>
{% endif %}
```

`graph()` and `findings()` build the entire site’s graph. Cached, but still a report rather than a
page element.

## Console

```sh
php craft yarn/map                        # nodes, relations, and the busiest elements
php craft yarn/map/flush                  # discard cached graphs
php craft yarn/element 1234               # both directions for one element
php craft yarn/element 1234 --depth=3     # …and three hops of knock-on impact
php craft yarn/findings                   # the audit
php craft yarn/findings --only=brokenTargets,unusedAssets --limit=200
php craft yarn/findings --fail-on=error   # exits non-zero; for CI
php craft yarn/path 1234 5678             # how one element reaches another
php craft yarn/path 1234 5678 --undirected
php craft yarn/export --format=csv --to=/tmp/relations.csv
php craft yarn/export --format=dot | dot -Tsvg > site.svg
```

Every command takes `--site=<handle>` and `--fresh` (ignore the cache).

```sh
php craft yarn/digest/send                # send the findings digest if it is due (for cron)
php craft yarn/digest/send --force        # send now, whatever the schedule says
php craft yarn/digest/status              # last run, last send, next due
```

`yarn/digest/send` is idempotent — one send per period however often cron runs it — and exits 0
unless the digest is on with no valid recipients (78) or the send failed (1; the next run retries).

`--fail-on` takes `error`, `warning`, `notice` or `never` (the default). In a deploy pipeline,
`--fail-on=error` stops a release that would ship a page pointing into the trash.

## Export

From the toolbar on any screen, or from the console.

| Format | Good for |
| --- | --- |
| `csv` | One row per relation, both ends spelled out. Hand it to somebody with a spreadsheet. |
| `json` | Nodes, edges and stats. For anything programmatic. |
| `dot` | Graphviz — a properly laid-out picture of a graph too big for the browser. |
| `mermaid` | A diagram in a README or a wiki. Capped at 300 nodes, and says when it has truncated. |
