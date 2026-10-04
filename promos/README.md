# Plugin Store promo images

Eight 1920×1080 JPEGs for the Yarn listing on the Craft Plugin Store, rendered in the same theme as
the plugin's marketing page at
[justinholt.com/plugins/craft-yarn](https://justinholt.com/plugins/craft-yarn).

They live in the plugin repo, not beside a site, because Yarn is one of the plugins that lives as a
page on justinholt.com rather than on its own domain. `/promos` is `export-ignore`d in
`../.gitattributes`, so none of this ships in the Composer package.

## Building

```bash
./build.sh          # all slides
./build.sh "2 5"    # just slides 2 and 5
```

Output lands in `out/` as `yarn-promo-N.jpg`, 1920×1080 (rendered at 2× in headless Chrome, then
downsampled and converted in one `sips` pass). **Promos ship as JPEG, never PNG** — Chrome can only
write PNG, so the build drops each PNG once the JPEG exists. Bee's deck predates that rule and
still writes PNG; don't copy that part back.

## Slides

| # | Slide | Shows |
|---|-------|-------|
| 1 | Cover — name, tagline, app icon, `Free` badge | — |
| 2 | `fields · reference tags · links · Matrix blocks` → *Is anything still using this?* | the whole pitch, in one line |
| 3 | The map screen, one entry selected | `shots/crop-map.png` |
| 4 | One element from both ends, plus knock-on | points at · pointed at by · knock-on if deleted |
| 5 | The eight findings, with their severities | each says what to do |
| 6 | An entry edit screen with the Relations panel | `shots/yarn-panel.png` |
| 7 | `yarn/findings --fail-on=error` in a terminal, exiting 65 | then `yarn/export --format=mermaid` |
| 8 | Three events and one Twig call, as four cards | for plugins and templates |

An asset-usage card was built and dropped: eight is already one over the family's seven, and
the findings slide says "what is unused" better than a table does at 950px.
`shots/yarn-assets.png` is still captured, though the marketing page cannot take it either: its
screenshots field caps at four, and the map, element, findings and panel shots fill it.

The numbers on slide 4 are the real ones for *Harbour terminal moves to a single scheduling window*
on the test install (see below). The terminal on slide 7 is real output, with the fourth line
truncated by hand and the notices left out — `65` is `ExitCode::DATAERR`, which is what
`FindingsController::exitCodeFor()` returns. If the exit code ever changes, so does this slide.

Slide 5 lists the checks by their real names from `Findings::names()`, error-first. Severities are
the defaults: a disabled target is a *notice* rather than a warning when the source is off too,
and an unresolved plain link is a notice where an unresolved reference tag is an error. The slide
shows the worse of each.

## The cover badge quotes the price

`Free`. Yarn is a single free edition. **If that ever changes it has to reach four places:** this
badge, the justinholt.com page seed (`scripts/seed/plugin-pages/craft-yarn.json`), this repo's
`README.md`, and the edition at `id.craftcms.com`.

## Notes

`assets/icon.svg` **is** a straight copy of `../src/icon.svg`. Like Bee's, Yarn's icon is
hand-authored: a real `<rect>` tile and a group of strokes, so there is no full-canvas frame path
to strip and no padding to crop. Copy it over whole when the icon changes.

`assets/watermark.svg` is `../src/icon-mask.svg` with **`stroke="currentColor"` switched to
`#ffffff`** — not `fill`, as in the rest of the family. Yarn's mask is the only stroked one: a ring,
three wound strands and a loose end, `fill="none"` throughout. Changing `fill` does nothing and
leaves a watermark that is invisible for the wrong reason. **Three files, one geometry** — change
the icon and change all three.

**The watermark needed to go lower than Bee's.** At 1080px the mask's 1.6-unit stroke is a 72px
line, and the JPEG encode lifts the dark ground just enough to show it. 0.020 (the family's
filled-mark opacity) drew an obvious ball of yarn behind every headline; 0.012 still showed the
loose end curling out under the copy; 0.008 was still a visible ring on the cover and the centred
statement slide once it had been through the JPEG encode. **0.005** puts it under the threshold.

**`.gradient-text` starts at `#7068E6`, not at the accent.** `#4F46B8` is the icon tile colour and
sits too close to the indigo ground to carry a 250px headline; it stays the accent for rules,
chips and the hairline.

Two layout rules inherited from the rest of the family:

- **One line per bullet, about 55 characters at 25px.** The copy column is 780px inside a 130px
  gutter and the panel starts at `left: 900px`, so a bullet that wraps runs at the panel and lands
  on top of it. "Filter by check and severity, export as CSV or JSON" is 51 characters and ends
  about ten pixels short of the column — it is the longest one there should be.
- **`.points li` is a flex container.** An inline `<span class="mono">` left loose in the text
  becomes a sibling flex item and picks up the 16px gap on both sides. Every point keeps its text
  in one `<span class="t">`.

**`.place .chip` drops `text-transform: uppercase`.** On slide 8 the chips carry PHP constant names
and a Twig call, and case is the point of both.

**The map is a crop, the other screenshots are not.** `shots/crop-map.png` is
`shots/yarn-map-selected.png` cut down to the stats and the canvas, so it fits the panel whole
without `.cpc-clip`. The edit screen keeps `.cpc-clip` and fades out at 600px.
Cropping the edit screen down to its sidebar was tried and dropped: a Relations panel next to half
an empty form does not read as anything.

`fonts.css` is generated by `build.sh`, which base64-inlines the two woff2 files in `assets/` —
Chrome's `file://` origin rules block them otherwise. It is gitignored; don't edit it.

## Where the screenshots come from

`shots/` holds real captures of a real install, not mockups: `~/Sites/plugin-testing`, at a
1600px-wide viewport and 2× device scale, cropped to `#main` with the other plugins' banners,
toolbars and floating widgets hidden.

The test install's own content was too sparse for a map — a few dozen news articles and a pile of
other plugins' fixtures — so `~/Sites/plugin-testing/_seed/yarn.php` adds seventeen cross-linked
entries (news articles and three hub pages), every one with a slug starting `yarn-demo-`. It also
plants the faults the findings screen needs: a dead reference tag, a live page pointing at a
disabled one, a link into the trash and two cycles.

```bash
cd ~/Sites/plugin-testing
ddev exec php _seed/run.php yarn.php                          # create or refresh
YARN_DEMO=teardown ddev exec php _seed/run.php yarn.php       # remove every yarn-demo- entry
```

| File | Screen |
|------|--------|
| `yarn-map.png` | Yarn → Map, settled, filtered to news, pages, images, categories and tags |
| `yarn-map-selected.png` | The same, with *South quay scheduling: the plan* clicked |
| `yarn-element.png` | Yarn view of *Harbour terminal moves to a single scheduling window* |
| `yarn-findings.png` | Findings, top of the list |
| `yarn-panel.png` | Edit screen for *Riverside Library Reopening*, other plugins' sidebar panels hidden |
| `yarn-assets.png` | Asset usage, with all but the last four unused rows hidden so the used ones show |

The map was captured once its node positions stopped changing between two polls half a second
apart, four times running. `YarnMap.MAX_TICKS` guarantees that happens; waiting a fixed few seconds
does not, because the layout is still drifting when it looks finished. The map's group filter had
to be set by hand: other plugins' test fixtures (Glue's smoke sections especially) otherwise sit
in the middle of the graph with names like *Topic One 0b4cb3*.
