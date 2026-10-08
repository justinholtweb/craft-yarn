# Release Notes for Yarn

## 5.0.1 — 2026-10-08

### Fixed

- **The map no longer leaves elements and their names off the edge of the canvas** ([#1](https://github.com/justinholtweb/craft-yarn/issues/1)). It was framed once, a second after loading, while the layout was still spreading out, and the framing measured the dots but not their labels — so the outer elements drifted off the canvas and their names ran off it, some of them under the legend. The map now frames itself again when the layout settles (unless you've panned or zoomed since), takes the labels into account, keeps clear of the Fit / Re-settle buttons, and zooms out as far as a large graph needs.
- **“Show only” is a proper multi-select** ([#1](https://github.com/justinholtweb/craft-yarn/issues/1)). It was a bare browser list — “0 selected” in Chrome, a two-row scrolling box in Firefox. It's now Craft's own multi-select: type to find a source, remove one with its ×. So are the Ignored fields and Excluded sources settings.
- The legend and status line sit in a bar above the map instead of floating over it, where they hid whatever the layout put underneath.
- The Volume, Source and Check filters on Asset usage, Browse and Findings are Craft's styled selects rather than bare browser controls.
- “Hide unconnected” and “Only the unused ones” read as checkboxes, not as uppercase field headings.
- On a computer set to dark mode, the map's legend, detail panel and labels turned dark on the light control panel. The control panel has no dark mode, so that styling has gone.

### Changed

- The control panel styles use Craft's own colour variables throughout, and the templates no longer carry inline styles.
- The map zooms in at most 1.5× when it frames itself, so a graph of two or three elements isn't blown up to headline size. Scrolling still zooms further.

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
