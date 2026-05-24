# Onboarding a new plugin

> A repeatable runbook for skinning a third-party plugin's admin UI into AdminKit.
> The scanner does the mechanical ~80% (find the colors, map them to `--ak-*`
> tokens, write the folder); you do the ~20% that needs judgment (the surface
> split, scope, fine-tuning). Background: [INTEGRATIONS.md](INTEGRATIONS.md)
> (the contract) and [TOKENS.md](TOKENS.md) (the token vocabulary).

---

## The happy path (5 steps)

```bash
# from the adminkit plugin root
# 1. scan — see what the host paints (admin AND frontend css)
php .claude/skills/adminkit-adapter-scan/adapter-scan.php ../<host>/assets --slug=<slug>

# 2. generate — write the live (but inert) integration folder
php .claude/skills/adminkit-adapter-scan/adapter-scan.php ../<host>/assets --slug=<slug> --emit

# 3. fill the 2–3 TODOs in inc/integrations/<slug>/class-<slug>.php
# 4. fine-tune inc/integrations/<slug>/css/admin.css   (the 20% checklist below)
# 5. verify in Chrome (light + dark), then:
php .claude/skills/adminkit-adapter-audit/adapter-audit.php
```

That's it. The sections below explain each step.

---

## 1. Discover the host

Three things must be confirmed by hand — they're host-specific and not reliably
in the CSS. All quick:

**Version constant** (for `is_active()` / the Tier B gate):
```bash
grep -rn "define.*_VERSION" ../<host> --include=*.php
```
Most plugins define one (`WC_VERSION`, `ACF_VERSION`, `FLUENTFORM_VERSION`). Some
define it indirectly (`$this->define('WC_VERSION', …)`) — the name still shows.
If there's none, gate on `class_exists()` / `function_exists()` instead.

**Screen scope** (for `owns_screen()` and the CSS scope): open the host's admin
page in Chrome and inspect `<body class="…">`. Copy either:
- the host's own wrapper class (`woocommerce-admin-page`, `acf-admin-page`), or
- the screen-id class (`toplevel_page_<slug>`, `<parent>_page_<slug>`).

A single-page app keeps one slug fragment across all its sub-pages, so a
`strpos( $screen->id, '<slug>' )` substring usually covers the whole app
(FluentForm `fluent_forms`, WooCommerce `wc-admin`).

> Trust order: screen-id (high) > version constant when it's a literal (high) >
> body class (good) > anything guessed from the CSS (unreliable — framework
> classes like `.v-row` dominate a Vue/Element app).

**Render target — is the panel in a shadow root?** This decides how the CSS gets
*delivered*, so check it before writing any. Newer plugins (Query Monitor 4.0+)
mount their UI inside an **open shadow root**: a normally-enqueued stylesheet
*cannot* reach it (selectors don't cross the boundary), though CSS custom
properties *do* inherit in. Detect it:
```bash
grep -rln "attachShadow" ../<host>     # or: spot "#shadow-root (open)" in DevTools
```
If present, the token remap is identical but ships via a JS bridge, not the asset
registry — see [Special case: the panel is in a shadow root](#special-case-the-panel-is-in-a-shadow-root).

---

## 2. Scan the CSS (admin **and** frontend)

```bash
php .claude/skills/adminkit-adapter-scan/adapter-scan.php ../<host>/assets ../<host>/public --slug=<slug>
```

Pass both the admin and the frontend dirs — a host's `:root` variable layer often
lives in its *frontend* bundle. The report has two halves:

- **TIER A — host CSS variables.** Each `--x: <color>` the host defines, with a
  suggested `--ak-*` token. If this list is non-empty, you're Tier A. The scan
  also reveals the variable *graph*: e.g. Element Plus `--el-*` vars alias down to
  a few `--fc-*` roots, so you only remap the roots and the rest follows.
- **TIER B — hardcoded colors.** Every `#hex` / `rgb()` / `hsl()` ranked by use,
  grouped by the property it sits on (bg / border / text), each classified to a
  token by lightness + chroma + hue.

> **Scanner blind spots.** It reads CSS *files* by regex, so it can't parse
> `light-dark()`, native CSS nesting, or `oklch()` — a host built on those
> (Query Monitor 4.0) reports an empty Tier A and leaks nested declarations as
> garbage selectors. It also can't see shadow DOM. When the scaffold comes back
> wrong, skip it and hand-map straight from the host's `:root` / `.container`
> variable block: the mapping is mechanical once you have the variable list.

---

## 3. Decide the tier

| Signal | Tier | Approach |
| --- | --- | --- |
| Non-empty Tier A table | **A** | Remap the host's vars. Zero `!important`, usually no version gate. The cheapest adapter to maintain. |
| Host hardcodes hex (empty Tier A) | **B** | Override the host's selectors with tokens. Version-gate on the host major so a rename degrades to native UI. |

Mixed is fine (remap what you can, override the rest). The generator picks the
tier automatically from whether the scan found variables.

---

## 4. Generate the adapter

```bash
php .claude/skills/adminkit-adapter-scan/adapter-scan.php ../<host>/assets --slug=<slug> --emit
```

Writes `inc/integrations/<slug>/`:
- `class-<slug>.php` — the integration class, **correctly named** so the loader
  auto-discovers it (no loader edit needed). It's **live but inert**:
  `is_active()` and `owns_screen()` return `false`, so it can't mis-skin anything
  until you fill the TODOs.
- `css/admin.css` — the scaffold (Tier A remap block and/or Tier B starter rules).

Re-running aborts if the folder exists; pass `--force` to overwrite only those two
files (it never deletes a sibling you hand-split off, e.g. a `select2.css`).

Then finish the class:
1. `is_active()` → the constant from step 1.
2. `owns_screen()` → the screen-id / body class from step 1.
3. Tier B only: `host_version()` + `max_tested_host_version()` (the host's current
   major). Tier A: delete those two methods and the gate bail.

---

## 5. Fine-tune `css/admin.css` — the 20%

This is where your judgment goes. The scaffold gets the base right; you refine:

- **The 3-surface split.** The scanner can't tell the page from the lightest card,
  so it guesses. Confirm by role: `--ak-bg` (page background) vs `--ak-surface`
  (cards / inputs / table rows) vs `--ak-elevated` (headers / dropdowns /
  selected). This is the #1 manual fix.
- **Brand vs info blue.** The busiest hued color is auto-promoted to `--ak-primary`.
  If the host's blue is informational chrome, route it to `--ak-info` and keep the
  brand action on `--ak-primary`.
- **Prune & merge the Tier B draft.** The Tier B block is a DRAFT — sample
  selectors, capped per group. Trim to the minimal set, merge duplicates, and
  scope each rule under `body.adminkit.<screen-id-or-wrapper>`. Keep any hardcoded
  host literal in **one** place so a host change is a one-line fix.
- **Don't tokenize fixed logos.** A two-tone brand logo / raster masthead loses
  its light parts on a re-themed bar — leave the host's own bar and skin only the
  content (ACF toolbar precedent).
- **Dark-mode guard.** If the host toggles its *own* dark class on `<body>`,
  redeclare the remapped vars on both `:root` and `body.adminkit.<scope>` so your
  values win the specificity war. Pin any `--*-primary-rgb` the host leaves at a
  stock blue (focus rings / selection tints).
- **Flatten shadows.** AdminKit is shadow-free — set host shadow vars to `none` /
  remove `box-shadow`s rather than theming them.
- **Bail bare-element primitives.** If the host ships its own inputs/buttons
  (Element UI, Tailwind), add `add_filter( 'adminkit/enqueue_forms', '__return_false' )`
  scoped to the host's screens in `boot()` (FluentForm / FlyingPress precedent).

---

## 6. Verify in the browser

Open Chrome at `http://localhost:10003/wp-admin/` and walk every host screen in
**both** light and dark (toggle in the admin bar). Hard-refresh — the CSS version
is the file's mtime, so edits show immediately. Looking for: no stray host blue,
surfaces reading as a clean stack (page < card < header), focus rings on the brand
color, legible text in dark mode.

---

## 7. Audit + gate

```bash
php .claude/skills/adminkit-adapter-audit/adapter-audit.php
```

- **Tier A** must report `!important = 0`. If you needed an `!important`, you
  probably should have remapped a variable instead.
- **Tier B** records its host-forced `!important` ceiling in the script's `$BUDGET`
  map; set `max_tested_host_version()` to the host's current major so a future
  major release falls back to the host's native UI instead of a half-broken skin.

---

## Special case: the panel is in a shadow root

If step 1 found `attachShadow`, the asset registry won't help — it enqueues into
the main document, which the shadow tree never sees, so a `body.adminkit …` rule
matches **nothing**. (This is the trap: everything looks correct and applies
zero styles.) Two facts make the fix small:

- **CSS custom properties inherit across the boundary.** `--ak-*` declared on the
  main document's `:root` reach inside, so the **token remap is unchanged** — only
  the delivery differs. Scope the CSS to the in-shadow id with **no `body.adminkit`
  prefix** (that element doesn't exist inside the shadow); the panel's own id still
  outranks the host's `.container` variable defs.
- **Selectors can't reach out** to `<html>`, so the usual `html[data-adminkit-theme]`
  dark hook can't fire from inside. Mirror AdminKit's mode onto the panel instead —
  set the host's own theme attribute from JS, one-way (AdminKit → host) — and let
  the host's existing `[data-theme]{color-scheme}` rule do the rest.

Deliver it with a tiny bridge: `register_assets()` hooks `admin_enqueue_scripts`
to `wp_enqueue_script` the bridge + `wp_localize_script` the mtime-stamped CSS URL.
The bridge injects the stylesheet as a `<link>` into the host's `shadowRoot`:

```js
( function () {
    var host = document.getElementById( 'host-shadow-container' );
    if ( ! host || ! host.shadowRoot ) { return; }   // poll if the host attaches late
    var link = document.createElement( 'link' );
    link.rel = 'stylesheet';
    link.href = window.myCfg.cssUrl;                  // localized, mtime-stamped
    host.shadowRoot.appendChild( link );             // --ak-* now resolve by inheritance
}() );
```

`attachShadow` fires no MutationObserver, so **poll briefly** for `host.shadowRoot`
when the host builds it from a deferred/module script. The
[query-monitor adapter](../inc/integrations/query-monitor/) is the reference
(bridge + theme-attribute sync + the shadow-scoped remap). Then continue with
Verify and Audit as normal.

---

## Checklist (paste into the PR)

```md
- [ ] Discovered host: version constant `____`, screen scope `____` (body inspect)
- [ ] Checked render target: shadow DOM? → JS-bridge delivery; else asset registry
- [ ] Scanned admin + frontend CSS; decided Tier A / B
- [ ] Generated folder with the adminkit-adapter-scan skill (`--slug=<slug> --emit`)
- [ ] Filled is_active() + owns_screen() (and version gate if Tier B)
- [ ] 3-surface split confirmed (bg / surface / elevated)
- [ ] Brand vs info blue routed correctly
- [ ] Tier B selectors pruned, merged, scoped to the host screen
- [ ] Logos/rasters left alone; shadows flattened; dark-mode guarded
- [ ] Verified every host screen in Chrome — light AND dark
- [ ] `php .claude/skills/adminkit-adapter-audit/adapter-audit.php` clean (Tier A = 0 !important; gate set for Tier B)
```
