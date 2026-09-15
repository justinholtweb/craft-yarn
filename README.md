<p align="center"><img src="src/icon.svg" width="96" alt="Yarn"></p>

<h1 align="center">Yarn</h1>

<p align="center">See every relation on a Craft site — what points at what, what nothing points at, and what breaks if you delete it.</p>

---

Craft knows perfectly well which entry uses which image. It just never tells you. There is no
screen anywhere in the control panel that answers *“is anything still using this?”*, and the
answer matters most at exactly the moment you are about to press Delete.

Yarn reads the relations Craft already stores, plus the ones it doesn’t — reference tags in
rich text, hard-coded links, relations buried inside Matrix blocks — and draws the result: as a
map, as lists you can filter, and as a panel on every element edit screen.

**Free. One edition. Everything switched on.**

## What it does

- **A map of the whole site.** Every element, every relation, laid out and colour-coded by kind.
  Click one to light up its threads; double-click to see only its neighbourhood.
- **Both directions for any element.** What it points at, what points at it, grouped by the field
  each relation came through — and how many other things break at one, two and three removes.
- **Asset usage.** Every asset with a count and the pages that use it, filterable down to just the
  ones nothing uses at all.
- **Globals.** The blind spot. Nothing in Craft tells you that the image you are deleting is in
  the footer of every page on the site.
- **Findings.** Relations into the trash, relations to elements that do not exist in this site,
  live pages pointing at things that are not live, reference tags that resolve to nothing,
  orphans, unused assets, circular relations, and elements related to themselves.
- **A panel on every element edit screen** that says what is using this, before you delete it.
- **Console commands** for all of it, with an exit code you can fail a deploy on.
- **Export** to JSON, CSV, Graphviz DOT or Mermaid.

## Where relations come from

| Source | What it reads | Default |
| --- | --- | --- |
| Relation fields | Craft’s `relations` table — Entries, Assets, Categories, Tags, Users fields | Always on |
| Reference tags | `{entry:42:url}` and friends, anywhere in a field value | On |
| Structure hierarchy | Parent-to-child in structure sections and category groups | Off |
| Hard-coded links | `href` and `src` values that resolve to an element on this site | Off |

The last two are off by default because they cost something: the link scanner reads every field
value on the site. Turn them on when the question is worth the scan.

Relations held inside a Matrix block are **rolled up** onto the page that owns them, so an image
used in the third block of a page reads as used by the *page*. That is a setting; switch it off
and every block becomes a node of its own.

Another plugin can contribute its own relations — a link field, a menu builder, anything that
stores references in its own table — through `RegisterEdgeSourcesEvent`. See
[docs/extending.md](docs/extending.md).

## Templates

```twig
{% if craft.yarn.isUsed(asset) %}
    <p>Used on {{ craft.yarn.usageCount(asset) }} pages.</p>
{% endif %}

<h2>Also mentioned in</h2>
{% for other in craft.yarn.usedBy(entry) %}
    <a href="{{ other.url }}">{{ other.title }}</a>
{% endfor %}
```

`usedBy()` and `uses()` read the relations table directly and are cheap enough for a front-end
page. `craft.yarn.graph()` and `craft.yarn.findings()` assemble the whole site and are not —
those belong in a report.

## Console

```sh
php craft yarn/map                      # the numbers
php craft yarn/element 1234             # both directions for one element
php craft yarn/findings --fail-on=error # audit; exits non-zero for CI
php craft yarn/path 1234 5678           # how one element reaches another
php craft yarn/export --format=dot > site.dot
php craft yarn/map/flush                # throw away cached graphs
```

Every command takes `--site=<handle>` and `--fresh`.

## Requirements

Craft CMS 5.3+, PHP 8.2+. No database tables, no runtime dependencies, no build step.

## Installation

```sh
composer require justinholtweb/craft-yarn
php craft plugin/install yarn
```

## Documentation

- [Installation](docs/installation.md)
- [Configuration](docs/configuration.md)
- [Usage](docs/usage.md)
- [Findings](docs/findings.md)
- [Extending](docs/extending.md)
- [FAQ](docs/faq.md)
- [Troubleshooting](docs/troubleshooting.md)

## A word about “unused”

Yarn can only see what it scans. An asset referenced from a template, from a CSS background, from
a plugin that stores references in its own table, or from a source you have switched off is
**invisible to it** and will be reported as unused. Every screen that says so says so on the page
as well. Read the caveat before you delete four hundred files.
