---
title: Troubleshooting
slug: troubleshooting
order: 60
summary: An empty map, stale graphs, noisy findings, memory limits, a missing panel and why deletes aren’t blocked.
---

# Troubleshooting

## The map is empty, or says “Loading…” forever

Open the browser console. If the request to `yarn/map/data` returned an error, the message is in
the response.

A graph with no relations at all is also possible and looks the same: check **Yarn → Map**’s stat
cards. `RELATIONS 0` on a site that certainly has relation fields usually means every relation on
it belongs to a draft or revision — which Yarn excludes on purpose.

## “Not scanned: …” under the map

The sources listed are switched off. **Settings → Extra relation sources**.

It says this rather than silently showing nothing, because “no reference tags found” and “never
looked for reference tags” are very different answers.

## Findings are full of things that are not problems

Three settings do most of the tuning:

- **`orphanKinds`** — drop asset or category if you do not want those reported.
- **`orphansIgnoreRoutable`** — on, entries with their own URL are not orphans.
- **`excludedGroups`** — exclude a section, a volume, or an element type from another plugin
  entirely.

For anything finer, `DefineFindingsEvent` lets you filter the list in code. See
[extending.md](extending.md).

## A reference tag is reported as broken but the page renders fine

Check whether the tag names its element by **id or UID**. Yarn only reports those; a tag that
resolves by slug, filename or a plugin’s own handle is skipped rather than guessed at.

If the tag does name an id and the element exists, check whether the element is in the site being
mapped — a tag with an `@site` qualifier can resolve somewhere Yarn is not currently looking.

## The graph is stale

It is discarded on every element save, delete and restore. If it is stale anyway:

- **Rebuild** in the toolbar, or `php craft yarn/map/flush`.
- Check that content is not being written by something that bypasses `Elements::saveElement()` —
  a direct SQL import fires no events and Yarn cannot know about it.

## Yarn says it is not caching the graph

```
Not caching the graph for site 1: 11.2 MB exceeds the 4 MB ceiling.
```

Deliberate. Memcached refuses an item over 1 MB by default and says so only in a log nobody reads;
Redis accepts a 60 MB item and then makes every other request wait for it.

Shrink the graph rather than raising the ceiling: exclude element types that do not relate to
anything (addresses are usually the big one), exclude sections you do not care about, and switch
off sources you are not using.

## Memory exhausted while building

Raise `memory_limit`, or lower `scanBatchSize` if the site has very large rich-text fields.

If it is the sheer number of elements, exclude what you do not need under **Excluded sources** —
a site with 200,000 addresses is building 200,000 nodes it will never look at.

## The Relations panel is missing from element edit screens

- Check `showElementPanel` is on.
- The user needs the **See the site’s relations** permission.
- It is deliberately not shown on drafts or revisions: they relate to whatever their canonical
  does, and a provisional draft’s relation rows are a work in progress by definition.

## Deleting an element still isn’t blocked

It never will be, and that is not a bug. See the FAQ entry — Craft has no hook that allows it
safely. Use the sidebar panel to see what is at stake beforehand, and `deleteGuard: log` to record
it afterwards.

## Console commands say “No site with the handle …”

`--site` takes a site **handle**, not a name or an id. `php craft sites/list` if you are not sure.
