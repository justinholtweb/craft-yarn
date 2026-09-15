# Installation

## Requirements

- Craft CMS 5.3 or later
- PHP 8.2 or later

Yarn creates no database tables, has no runtime dependencies and needs no build step.

## Install

```sh
composer require justinholtweb/craft-yarn
php craft plugin/install yarn
```

A **Yarn** item appears in the control panel navigation, with Map, Browse, Assets, Globals and
Findings beneath it.

## Permissions

Two, both under **Yarn** in a user group’s permissions:

| Permission | What it allows |
| --- | --- |
| See the site’s relations | Every Yarn screen, and the Relations panel on element edit screens |
| Export the graph | Downloading the graph as JSON, CSV, DOT or Mermaid |

Settings are admin-only, and respect `allowAdminChanges`.

Yarn only ever shows sites the user can edit. A user with access to one site’s content cannot
read another site’s graph through it.

## First run

Open **Yarn → Map**. The first load assembles the whole graph, which on a large site takes a few
seconds; after that it is cached until an element is saved or the cache duration runs out.

If the map says *“trimmed to the busiest N”*, that is the `Map node limit` setting doing its job.
Filter by source, or double-click an element to see only its neighbourhood.

## Uninstalling

```sh
php craft plugin/uninstall yarn
composer remove justinholtweb/craft-yarn
```

Nothing is left behind: no tables, and the only project config Yarn writes is its own settings,
which go with it.
