---
title: Findings
slug: findings
order: 40
summary: The eight checks Yarn runs, what each one means and what to do about it.
---

# Findings

Eight checks. Each says what is wrong **and** what to do about it, because “orphaned entry” on its
own is a shrug.

## Errors

### Relations into the trash — `brokenTargets`

An element points at something that has been deleted. The relation survives until the trash is
emptied, and then it is gone for good.

Restore the element, or clear the field. This is the one finding that is never a false positive.

### References that resolve to nothing — `unresolvedReferences`

A reference tag such as `{entry:412:url}` whose element does not exist. Craft renders an
unresolvable reference tag **as the tag itself**, braces and all, in the middle of the page.

Yarn only reports tags it actually tried to resolve — ones naming an element by id or by UID. A tag
that names something by slug or handle, including another plugin’s own reference grammar, is
skipped rather than called broken.

Links in the same check are notices, not errors: an internal link Yarn could not tie to an element
is often a routed template or a file outside the volumes.

## Warnings

### Relations to elements missing from this site — `absentTargets`

The target has no content in the site being mapped, so the relation renders as nothing here.
Usually a section or volume that was never propagated to this site.

### Live elements pointing at things that are not — `disabledTargets`

A live page relating to something disabled, pending or expired. The front end renders a gap unless
the template checks.

When both ends are off, this drops to a notice — nothing is rendering wrongly, but it is worth
knowing when either one is switched back on.

### Circular relations — `cycles`

A points at B points back at A, at any length. Harmless until a template follows relations
recursively, at which point it is an infinite loop.

Reported once per cycle regardless of where you enter it, and capped by the `cycleLimit` setting.

### Elements related to themselves — `selfReferences`

Same problem, shortest possible loop. Almost always a mis-click in a relation field.

## Notices

### Nothing points at these — `orphans`

No element on the site relates to it.

An element that has a URL of its own is still reachable from search and from the sitemap, and the
finding says so. One that has no URL **and** nothing relating to it has no route from the front end
at all — that is the interesting case, and `orphansIgnoreRoutable` filters down to it.

Which kinds are reported is the `orphanKinds` setting.

### Unused assets — `unusedAssets`

Nothing reaches this asset. Reported separately from orphans, because it is the check most people
install a relations plugin for — and the one that most needs its caveat read.

**Yarn can only see what it scans.** An asset referenced from a template, from a CSS background, or
from a source that is switched off is invisible to it and appears here. Check before deleting four
hundred files.

## In the console

```sh
php craft yarn/findings --only=brokenTargets
php craft yarn/findings --fail-on=warning
```

Exit code is 0 unless `--fail-on` is set and something at or above that severity was found.

## By email

The findings digest sends what is new since the last one, daily or weekly. See
[Configuration](configuration.md#findings-digest).

## Adding your own

`DefineFindingsEvent` fires after every built-in check, with the findings they produced. Add your
own, or drop the ones your site has decided it does not care about — a site that deliberately keeps
a library of unused stock imagery does not want to be told about it every month. See
[extending.md](extending.md).
