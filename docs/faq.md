---
title: FAQ
slug: faq
order: 70
summary: Common questions about what Yarn sees, what it changes and why it won’t stop a delete.
---

# FAQ

### Does Yarn add any database tables?

One, small: `yarn_digests`, a single row recording when the findings digest last went and what it
said, so it is sent once per period and can tell you what is new. Everything else Yarn reads from
Craft’s own tables and assembles in memory, cached in whatever cache Craft is configured to use.
Uninstalling drops the table and leaves nothing behind.

### Can I filter the Assets index by “unused”?

Yes. Add the **Is used** rule to the Assets (or Entries) index filter, a custom source, or any
other asset or entry condition, and combine it with Craft’s own filters and bulk actions. There is
also a **Used by** column you can switch on in the index’s table view.

The **Used by** column counts what the Relations panel counts: relation fields, plus reference tags
and hard-coded links when those sources are switched on. The **Is used** rule counts relation
fields only — it runs as one database subquery, and links inside field content can't be checked
that way without guessing. So an asset that is only linked from a Table cell shows **1** in the
column but still passes an “is not used” filter. For the full picture, use **Yarn → Assets**.

### Does Yarn have a GraphQL API?

Yes. Under **GraphQL → Schemas**, tick **Yarn → Query relations, findings and unused assets** on
the schemas that should have it. It is off for every schema until you do. That schema then gets:

| Query | What it answers |
| --- | --- |
| `yarnElement(id, site, depth)` | What points at an element, what it points at, and what deleting it would break |
| `yarnFindings(site, checks, limit)` | The findings list, with a tally per check |
| `yarnUnusedAssets(site, volume, limit)` | Assets nothing points at |
| `yarnPath(fromId, toId, site, direction)` | The shortest chain of relations between two elements |
| `yarnUsageCount(id, site)` | The Relations panel's count, without building the graph |

```graphql
{
  yarnElement(id: 211) {
    element { label health }
    pointedAtBy { field kind elements { via node { id label editUrl } } }
    deleteImpact { direct { id label } }
  }
}
```

Masking follows the schema's scope. An entry in a section the schema can't read, an asset in a
volume it can't read, and so on, comes back as “Restricted element #id” and is still counted. Tags,
global sets and element types Yarn has no special knowledge of are masked unless the schema can
read them. Queries for a site the schema can't read are refused. On Craft 5.12+, requests made with
an OAuth-authenticated user are masked for that user instead, as the control panel would mask them.

Everything except `yarnUsageCount` reads the whole-site graph (cached like the control panel's)
and is priced as CPU-heavy, so a schema's complexity limit applies.

### Can an AI agent use Yarn?

On Craft 5.12 and later, yes. Yarn adds four read-only tools to Craft's built-in MCP server:

| Tool | What it answers |
| --- | --- |
| `yarn_element_relations` | What points at an element, what it points at, and what deleting it would leave pointing at nothing |
| `yarn_findings` | The findings list, optionally for some checks only |
| `yarn_unused_assets` | Assets nothing points at, optionally for one volume |
| `yarn_path` | The shortest chain of relations from one element to another |

Each needs the *See the site’s relations* permission. Each answers as the signed-in user, with the
same “Restricted element” masking as the control panel. On older Craft versions nothing is
registered and nothing changes.

### Is it safe to delete everything on the “unused assets” list?

**No — not without checking.** Yarn can only see what it scans. An asset referenced from a
template, from a CSS background, from a plugin that stores references in its own table, or from a
source you have switched off is invisible to it and appears on that list.

Before a bulk delete: switch on the `urls` source, check whether the assets are referenced from
your templates, and take a backup.

### Why is an image I know is in a Matrix block shown as used by the page?

That is the `rollUpNested` setting, on by default. A relation held inside a Matrix block is
attributed to the page that owns it — which is the thing you would open, and the thing that
breaks. Switch it off to see the blocks themselves.

### Why does the map only show some of my site?

Two reasons, and it says which. **Hide unconnected** is on by default, so elements with no
relations are left out. And past the `Map node limit` the map keeps the busiest elements and says
it has trimmed.

Filter by source, or double-click an element to see just its neighbourhood.

### Can Yarn stop me deleting something that is in use?

No, and nothing can. Craft has no cancellable hook that fires before a field has already deleted
the element’s nested content — `Elements::EVENT_BEFORE_DELETE_ELEMENT` is not cancellable, and
vetoing the one that is keeps the element row while destroying its blocks.

What Yarn does instead is tell you *before*: the Relations panel in the sidebar of every element
edit screen says how many elements point at this one. And with `deleteGuard` set to `log`, it
writes down what was still using an element at the moment it went.

### Does it count relations from drafts and revisions?

No, deliberately. A revision’s relations are a snapshot of what an entry *used* to point at.
Counting them means every asset ever swapped out still reads as “in use” — the single most
misleading thing a relations report can say, because it is the one that stops you cleaning up.

### Why is my user list full of orphans?

It would be, which is why users and tags are not in `orphanKinds` by default. A user nothing
relates to is a normal user.

### What are all these “Address” elements?

Craft’s addresses are elements, so they are in the graph. On a Commerce site there can be tens of
thousands, and none of them relate to anything. Exclude them under **Settings → Excluded sources**,
where they appear as `Element type: Address`.

### How current is the graph?

It is discarded whenever any element is saved, deleted or restored, and otherwise reused for
`cacheDuration` seconds (300 by default). **Rebuild** in the toolbar forces a fresh one.

### Yarn is slow on my site.

Turn off the `urls` source if it is on — it is the only one that reads every field value on the
site. Exclude the sections and element types you do not care about. Raise `cacheDuration`.

If the graph is very large it will not be cached at all; `storage/logs/yarn.log` says so when that
happens. See [troubleshooting.md](troubleshooting.md).

### Can I see relations across sites?

The graph is built per site — that is the only way “does this render here” has an answer. An
element that a relation reaches but which does not exist in the site being mapped is included and
marked **absent**, and the `absentTargets` check reports it.

### Does it work with Commerce, Formie, CKEditor, Hyper…?

Anything that stores its relations in Craft’s `relations` table works with no help at all, which
covers most plugins. Entries embedded in CKEditor are nested elements and are rolled up like any
other. Links stored as reference tags are picked up by the `refTags` source.

A plugin that keeps references in its own table is invisible until somebody writes a source for
it — which is what `RegisterEdgeSourcesEvent` is for. See [extending.md](extending.md).
