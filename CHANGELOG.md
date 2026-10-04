# Release Notes for Yarn

## 5.0.0 — 2026-10-04

Initial release.

- Relation graph assembled from Craft’s `relations` table, reference tags in content, structure
  hierarchy and hard-coded links, with relations inside nested elements rolled up onto their
  owners.
- Map: force-directed, colour-coded by element kind, with filters, search, selection and a
  focus mode for one element’s neighbourhood.
- Browse, Asset usage and Globals screens.
- Per-element view: both directions grouped by field, plus knock-on impact at up to three removes.
- Eight findings checks: relations into the trash, relations to elements missing from the site,
  live elements pointing at things that are not live, references that resolve to nothing,
  orphans, unused assets, circular relations and self-references.
- A Relations panel in the sidebar of every element edit screen.
- `craft.yarn` Twig variable.
- Console commands for the map, an element, findings, paths and export.
- Export to JSON, CSV, Graphviz DOT and Mermaid.
- `RegisterEdgeSourcesEvent`, `BuildGraphEvent` and `DefineFindingsEvent` for plugins that store
  their own references.
