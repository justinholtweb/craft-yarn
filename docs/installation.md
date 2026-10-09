---
title: Installation
slug: installation
order: 10
summary: Requirements, installing, the permissions Yarn adds, the first run and uninstalling.
---

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

Three, all under **Yarn** in a user group’s permissions:

| Permission | What it allows |
| --- | --- |
| See the site’s relations | Every Yarn screen, and the Relations panel on element edit screens |
| Export the graph | Downloading the graph as JSON, CSV, DOT or Mermaid |
| Send a test findings digest | The **Send a test digest now** button on Findings and Settings |

Settings are admin-only, and respect `allowAdminChanges`.

Yarn only ever shows sites the user can edit. A user with access to one site’s content cannot
read another site’s graph through it.

Inside a site, Yarn respects Craft’s own view permissions. An entry in a section the user can’t
view, an asset in a volume they can’t view, a category group, a global set they can’t edit, or a
user when they lack **View users** appears as “Restricted element #id”. It keeps its relations,
so counts stay accurate and a restricted page still counts as using an image, but its title and
URL are hidden. Admins see everything. Element types from other plugins are shown as they are,
because checking each one would mean loading every element of that type.

## First run

Open **Yarn → Map**. The first load assembles the whole graph, which on a large site takes a few
seconds; after that it is cached until an element is saved or the cache duration runs out.

If the map says *“trimmed to the busiest N”*, that is the `Map node limit` setting doing its job.
Filter by source, or double-click an element to see only its neighbourhood.

## Scheduled digest

If you switch the findings digest on, add a cron job — it sends nothing until the digest is due:

```sh
*/15 * * * * php /path/to/craft yarn/digest/send
```

Without cron, the web fallback queues it instead; see [Configuration](configuration.md#findings-digest).

## Uninstalling

```sh
php craft plugin/uninstall yarn
composer remove justinholtweb/craft-yarn
```

Nothing is left behind: no tables, and the only project config Yarn writes is its own settings,
which go with it.
