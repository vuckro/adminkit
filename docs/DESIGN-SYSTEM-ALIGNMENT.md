# AdminKit ⇄ WaasKit — Design-System Alignment

> **Living record.** This is the traceability log for keeping AdminKit calibrated to
> the **WaasKit Design System**. Read it at the start of every design iteration and
> append to the [Iteration log](#iteration-log) at the end. The goal: never lose the
> work already done, and be able to evolve fast without drifting.

| | |
|---|---|
| **Source of truth** | [`WAASKIT-DESIGN-SYSTEM.md`](WAASKIT-DESIGN-SYSTEM.md) (locked, v1.0.0) |
| **Token reference** | [`TOKENS.md`](TOKENS.md) · runtime: [`../assets/css/tokens.css`](../assets/css/tokens.css) |
| **`#design` tab data** | `AdminKit_Settings::color_map()` in [`../inc/class-settings.php`](../inc/class-settings.php) |
| **Last reviewed** | 2026-05-24 |
| **Status** | Token spine ✅ · on-accent sweep ✅ · shipped WaasKit baseline ✅ · status-tint cleanup pending ⏳ |

---

## 1. How the two systems connect

```
design-system/palettes/*.json   →  assets/css/waaskit-tokens.css  →  (opt.) Bricks override  →  --ak-*  →  wp-admin
  (committed source of truth)        (GENERATED baseline, shipped)      style-manager.min.css      tokens.css    every AK rule
        ⚙️🎨🔔🏷️                       :root{ --neutral-l-1; --accent… }   live, wins when active
```

- **Shipped baseline (the primary connection).** AdminKit ships the FULL WaasKit token
  layer — every primitive + the 23 semantics — as `assets/css/waaskit-tokens.css`,
  **generated** from the committed `design-system/palettes/*.json` by the
  `design-system/build-tokens.php` generator. `enqueue_tokens()` (class-assets.php) enqueues it as the
  leading dep of `adminkit-tokens`, so the brand design system is always present — even
  with no provider. It emits each token's light-context value (what Bricks feeds wp-admin);
  no dark block — AdminKit owns dark via `--ak-*`.
- **Where WaasKit lives at runtime.** When Bricks is active, it writes every variable
  (primitives **and** the `🏷️ Sémantique` palette) to
  `/uploads/bricks/css/style-manager.min.css`. The Bricks adapter
  ([`class-bricks.php`](../inc/integrations/themes/bricks/class-bricks.php)) enqueues that file
  and pins it as a dependency of `adminkit-tokens`, so the semantics resolve before
  `tokens.css` reads them. Verified present in the generated file: `--accent`,
  `--accent-on`, `--accent-subtle`, `--input`, `--focus`, `--success-subtle` … (see
  [Verification](#verification)).
- **The golden rule (WaasKit §3).** A component never reads a primitive; it reads a
  semantic. AdminKit honours this by funnelling **everything** through `--ak-*`, which
  in turn read the WaasKit semantics. Component/integration CSS reads only `--ak-*`.
- **Dark mode.** Bricks' own dark toggle never fires in wp-admin, so AdminKit owns dark
  via `:root[data-adminkit-theme="dark"]`. It re-maps `--ak-*` onto the **inverse
  primitive ramp** (`--neutral-d-*`, `--primary-d-10`, `--*-d-9`) — exactly the
  mechanism WaasKit prescribes for a forced scheme (`.scheme-dark`, WaasKit §11).

---

## 2. Canonical mapping (WaasKit semantic → AdminKit token)

This table is the contract. `tokens.css`, `color_map()` and `TOKENS.md` must all agree
with it.

| WaasKit semantic | → primitive (light) | AdminKit token | Notes |
|---|---|---|---|
| `--background` | `--neutral-l-1` | `--ak-bg` | flip in dark |
| `--surface` | `--neutral-l-2` | `--ak-surface` | flip |
| `--elevated` | `--neutral-l-3` | `--ak-elevated` | flip |
| `--overlay` | `--black-t-7` | `--ak-overlay` | both modes |
| `--border` | `--neutral-l-4` | `--ak-border` | flip |
| `--border-strong` | `--neutral-l-5` | `--ak-border-strong` | flip |
| `--accent` | `--primary` | `--ak-primary` | brand, no flip |
| `--accent-hover` | `--primary-d-1` | `--ak-primary-hover` | dark→`l-1` |
| `--accent-on` | `--primary-d-10` | `--ak-on-accent` | **dark text on yellow** |
| `--accent-subtle` | `--primary-l-10` (opaque) | `--ak-primary-subtle` | dark→`--primary-d-10` |
| `--input` | `--neutral-l-1` | `--ak-input-bg` | flip→`--neutral-d-3` |
| `--focus` | `--primary-t-5` ⚠️ | `--ak-focus` | see open item F1 |
| `--success` | `--success` | `--ak-success` | |
| `--success-subtle` | `--success-l-9` | `--ak-success-subtle` | dark→`--success-d-9` |
| `--warning` | `--warning` | `--ak-warning` | |
| `--warning-subtle` | `--warning-l-9` | `--ak-warning-subtle` | dark→`--warning-d-9` |
| `--error` | `--error` | `--ak-error` | |
| `--error-subtle` | `--error-l-9` | `--ak-error-subtle` | dark→`--error-d-9` |
| `--info` | `--info` | `--ak-info` | |
| `--info-subtle` | `--info-l-9` | `--ak-info-subtle` | dark→`--info-d-9` |
| `--heading` | `--neutral-l-9` | `--ak-heading` | flip |
| `--text` | `--neutral-l-8` | `--ak-text` | flip |
| `--text-muted` | `--neutral-l-7` | `--ak-text-muted` | flip |

**AdminKit-only** (no WaasKit token — legitimately additions): `--ak-secondary`
(consumes the `--secondary` primitive), `--ak-hover-bg` (WaasKit has no `--hover`),
`--ak-text-inverse` / `--ak-heading-inverse` (WaasKit inverts via `.scheme-*`),
`--ak-shadow-elevated`, and geometry/type (`--ak-radius-*`, `--ak-text-*`,
`--ak-font-body`).

---

## 3. Decision log

- **D1 — `-subtle` is opaque, dark-remapped.** WaasKit §7.3 locked `--accent-subtle`
  to an *opaque* pale (`--primary-l-10`), not the old transparent `--primary-t-1`.
  AdminKit consumes the opaque value in light and, in its own dark scope, re-maps each
  `-subtle` onto the `d-9`/`d-10` ramp — WaasKit's own `.scheme-dark` mechanism (§11).
  Rationale: faithful to the locked doc **and** correct on AdminKit's dark UI.
- **D2 — on-accent is dark.** The brand is a light yellow (`#fed53e`); text on it must
  be dark. `--ak-on-accent` now reads `--accent-on` (`--primary-d-10`). Fixes white/pale
  text on yellow buttons (was the most visible defect).
- **D3 — `--input`, `--text-muted`, `--accent-on` are NOT AdminKit-own anymore.** The
  finalized WaasKit layer defines them, so they bridge the semantic (previously they
  fell back to primitives / wrong provider names).
- **D4 — status-subtle set completed.** Added `--ak-success-subtle` / `-warning-` /
  `-info-` (was only `--ak-error-subtle`) because 3+ integrations rolled their own
  `color-mix(--ak-X, --ak-surface)` ad hoc. One canonical token each instead.
- **D5 — keep `--ak-*` namespace.** AdminKit does not consume the raw semantic names
  directly; it keeps its `--ak-*` indirection so it can (a) add the few roles WaasKit
  lacks and (b) own dark mode. The `--ak-*` layer maps 1:1 onto WaasKit per §2.

---

## 4. Audit findings (baseline, 2026-05-24)

**Token spine:** 3 provider names AdminKit read did **not** exist in the generated
WaasKit output and silently fell back — now fixed:
`--field-bg`→`--input`, `--accent-bg`→`--accent-subtle`, `--on-accent`→`--accent-on`.

**Component / integration CSS literal census** (52 files):

- `var(--ak-*)` references: **3157** · raw color literals: **831**.
- Of the literals, ~800 are **comments / false positives** (`white-space`, ACF id
  selectors, attribute-selector neutralizers). Real code-level literals: **~140**.
- **Real drift ≈ 78** · legit (fallbacks, scrims, color-mix progressive-enhancement
  pairs, syntax-highlight palette) ≈ 62.
- **Dominant drift (~55 of 78): `color:#fff` hard-coded as the foreground on an
  `--ak-primary` / status fill → should be `--ak-on-accent`** (or stay white on a
  status fill — needs per-spot classification). No raw *brand* colours leak into
  declarations (third-party blues/greens are routed to `--ak-primary` via attribute
  selectors = legit).
- **Direct primitive reads in components** (golden-rule violations): only **6** found;
  4 fixed this iteration, 2 left as documented exceptions (B6, B7).
- **Files essentially 100 % clean:** `bricks/frontend-mode.css`, `slim-seo/*`,
  `theme-install.css`, plus all comment-only heavy files (`woocommerce/admin.css`,
  `acf/admin.css`, `gutenberg/dark-mode-map.css`, `query-monitor/*`).

---

## 5. Iteration log

### Iteration 1 — Token-spine realignment · 2026-05-24
**Goal:** make every `--ak-*` token read the *finalized* WaasKit vocabulary so the whole
admin inherits the design system from one source.

Changed:
- `assets/css/tokens.css` — `--ak-input-bg`→`--input`; `--ak-primary-subtle`→
  `--accent-subtle` (opaque); `--ak-on-accent`→`--accent-on` (contrast fix);
  `--ak-primary-hover` fallback→`--primary-d-1`; status block now reads
  `--success-subtle`/`-warning-`/`-error-`/`-info-` and **adds** the three missing
  `--ak-*-subtle` tokens; dark block re-maps all `-subtle` onto the `d-9`/`d-10` ramp;
  header/comments refreshed.
- `inc/class-settings.php` — `color_map()` updated so the `#design` reference table
  shows the real bridges + primitives + `own` flags; added the four `*-subtle` status
  rows; docstring refreshed.
- `inc/integrations/themes/bricks/css/frontend-mode.css` — `--field-bg`→`--input`.
- `inc/integrations/themes/bricks/css/admin.css` — message-box info/success/warning now use
  `--ak-*-subtle` (danger already did) → uniform + validates the new tokens.
- `assets/css/wp-components/buttons.css` — primary-button label was hard-pinned to
  `--primary-l-10` (pale cream on yellow); now `var(--ak-on-accent)`.
- `assets/css/wp-screens/plugins.css` — plugin-update highlight → `--ak-warning-subtle`.
- `inc/integrations/plugins/flying-press/css/admin.css` — `.bg-green-50` / `.bg-yellow-50`
  → `--ak-success-subtle` / `--ak-warning-subtle`.
- `inc/integrations/plugins/gutenberg/css/dark-mode-map.css` — stale `--error-t-2` comment.
- `docs/TOKENS.md` — rewritten to the 23-token vocabulary.
- `docs/WAASKIT-DESIGN-SYSTEM.md` — added (copy of the locked source of truth).

Verified: `php -l` clean on both settings classes; no residual old provider names in
code; new `-subtle` tokens defined **and** consumed; `tokens.css` paren/brace balance OK.

Not done (browser QA): visual confirmation of the opaque `-subtle` fills and the new
on-accent contrast on a running admin (no browser was connected this iteration).

### Iteration 2 — On-accent foreground sweep · 2026-05-24
**Goal:** finish the brand-contrast fix — convert every `color:#fff` that sits on a
**brand-accent** fill to `--ak-on-accent`, leaving status-fill whites alone.

Method: a block-aware transform (`/tmp/ak_sweep.py`, reviewed dry-run first) that
rewrites `color:#fff*`→`var(--ak-on-accent)` **only** inside a rule whose body contains
`--ak-primary` and **no** status token. 24 blocks converted across 14 files; verified
every converted block's background is `--ak-primary`/`-hover`, brace balance intact.

Converted (count): `fluent-smtp/admin.css` (6), `fluentform/admin.css` (4),
`fluent-booking/admin.css` (3), `admin-menu-editor/choices.css` (2),
`happyfiles/sidebar.css` (2), `wpds.css` (2), `login.css` (2),
`wp-migrate-db-pro/admin.css` (1), `admin-menu-editor/admin.css` (1), `themes.css` (1)
— plus Iteration-1's flagship buttons (`bricks/edit-button.css`, `gutenberg/editor.css`,
`bricks/admin.css`).

Intentionally **kept white** (22): status fills (`--ak-warning/-error/-success/-info`
backgrounds — alerts, tags, danger/warning buttons), the `--ak-secondary` update-count
bubble (`chrome.css`), and non-accent contexts (`media.css`, `tables.css`,
`fluent-smtp` time-value, `wp-migrate` :505). WaasKit defines no `*-on` status token
(§13 future).

Not done (browser QA): visual confirmation on the converted plugin screens.

### Palette note — 2026-05-24
Provider-side fix (WaasKit `⚙️ Neutre`): the `--black` / `--white` families were
corrected — pure black/white (no stray dark flip) + proper **light** transparent
ramps (`--black-t-*`, `--white-t-*` at 9→91 %) instead of dark-only stops. Confirmed
live in `style-manager.min.css`. AdminKit needs no change; `--ak-overlay`
(→ `--black-t-7` = .64) and the dark hover (→ `--white-t-2` = .18) now resolve to real
provider values instead of their built-in fallbacks.

### Iteration 3 — Shipped WaasKit baseline + single source of truth · 2026-05-24
**Goal:** make the WaasKit design system a first-class, self-contained part of the
plugin — brand-complete without Bricks — with one versioned source and zero drift.
(Decisions: ship the full brand by default; source = committed JSON + generator.)

Added / changed:
- `design-system/palettes/*.json` — the 6 WaasKit Bricks exports committed as the
  **source of truth** (were unversioned in `~/Downloads`). The 4 colour palettes feed
  the generator; `variables.json` / `theme-style-waaskit.json` are kept for traceability.
- `design-system/build-tokens.php` (tracked generator; a thin `.claude` skill wraps it
  for discoverability) — transcribes the 4 colour palettes → `assets/css/waaskit-tokens.css`
  (309 tokens). Emits each token's light-context value; skips divider rows and no-op
  self-aliases (`--success: var(--success)`). `--check` is the drift gate.
- `assets/css/waaskit-tokens.css` — **GENERATED, committed** baseline (`:root{…}`).
- `inc/class-assets.php` — new `WAASKIT_HANDLE` / `WAASKIT_SRC`; `enqueue_tokens()`
  enqueues the baseline as the leading dep of `adminkit-tokens`.
- `inc/integrations/themes/bricks/class-bricks.php` — `provide_tokens()` now depends on
  `WAASKIT_HANDLE`, so a live Bricks palette loads after and overrides the baseline.
- `assets/css/tokens.css` — header: inline hsl/hex are now an emergency fallback (the
  baseline is the normal source).
- **F1 resolved at source:** `design-system/palettes/semantique.json` `--focus` →
  `var(--primary)` (opaque, per the locked doc). ⚠️ The user must also fix `--focus` in
  the live Bricks palette (or re-import this JSON), else a future Bricks export reverts
  it and, while Bricks is active, its translucent `--primary-t-5` still wins.

Cascade now: `WP core → waaskit-tokens.css (baseline) → optional Bricks → --ak-*`.

Verified: generator `--check` green (309 tokens); status bases resolve to real colours
(`--success: #11b76b`, parity with the live Bricks file); `php -l` clean on the new/edited
PHP. Not done: browser QA (no-Bricks vs Bricks, light + dark).

### Iteration 4 — Appearance tab polish · 2026-05-24
**Goal:** fix the Appearance (`#design`) page's display bugs and improve legibility.

- **Colour swatch bug.** `.ak-tbl__sw` set `width/height` but had **no `background`** and
  no `flex:0 0 auto`, in a 34px column with 12px padding — so each swatch rendered as a
  squashed, colourless sliver. Now: `display:block; flex:0 0 auto; 26×26`, the token
  colour painted over a faint checker (so translucent tokens — overlay/hover/focus —
  still read), centred in a wider **52px** column. (`assets/css/settings.css`)
- **URL hash.** The Appearance tab id `design` → **`apparence`** so the URL reads
  `#apparence`. Updated the dashboard shortcut card (`class-settings-page.php` `'tab'`)
  to match. (`assets/js/settings.js`, `inc/class-settings-page.php`)
- **Comprehension.** Added a one-line legend at the top of the tab explaining the
  read-only mapping notation (`--ak-* ← semantic · primitive`). (`assets/js/settings.js`)

SPA assets are filemtime-cache-busted, so a refresh picks the changes up. Browser QA of
the rendered swatches still pending.

---

## 6. Backlog (next iterations, prioritized)

1. ~~**`#fff` → `--ak-on-accent` sweep.**~~ ✅ **Done in Iteration 2** — 24 brand-accent
   foregrounds converted; status-fill / `--ak-secondary` / dark-context whites (22)
   intentionally kept. Still wants browser QA on the converted screens.
2. **`wpds.css` status weak-tints (~19).** `rgba(40,149,212,.06)` etc. → `color-mix`
   off `--ak-info`/`-success`/`-warning`/`-error` (or the `-subtle` tokens at low mix).
3. **Ad-hoc `color-mix(--ak-X, --ak-surface)` → `--ak-*-subtle`** across
   `woocommerce/admin.css`, `query-monitor/admin.css`, `fluent-booking/admin.css` for
   consistency now that the tokens exist.
4. **`--wp-admin-theme-color--rgb` hard-codes `255,214,79`** in `tokens.css` (the WP
   blue→brand bridge). It assumes the yellow brand and can't be derived from a var.
   Decide: leave (documented), or recompute per brand at runtime in PHP.

---

## 7. Open items needing a decision

- **F1 — Focus translucency.** ✅ Fixed in the committed source
  (`semantique.json` `--focus` → `var(--primary)`, opaque, per the locked doc), so the
  shipped baseline is correct. **Still needs a one-time Bricks-side fix:** set `--focus`
  to `var(--primary)` in the live Bricks palette (or re-import the corrected JSON), else
  (a) a future Bricks export reverts the committed JSON, and (b) while Bricks is active
  its translucent `--primary-t-5` overrides the baseline.
- **`*-on` status tokens** (`--success-on`, …): only needed if vivid-fill notices (light
  text on a solid status colour) are introduced. WaasKit §13 lists them as future.

---

## 8. Verification

Re-run after any design change:

```bash
# 1. No stale provider names in code (expect: clean)
grep -rnE -e '--field-bg' -e '--accent-bg' -e '--on-accent' -e '--error-t-2' \
  --include='*.css' --include='*.php' . | grep -v '/.git/'

# 2. Every --ak-*-subtle is defined and consumed
for t in ak-success-subtle ak-warning-subtle ak-info-subtle ak-error-subtle ak-primary-subtle; do
  grep -rlE "var\(\s*--$t\b" --include='*.css' assets inc | grep -v tokens.css; done

# 3. Direct primitive reads in components (golden-rule violations; expect only documented exceptions)
grep -rnE 'var\(--(neutral|primary|secondary|success|warning|error|info|black|white)-(l|d|t)-[0-9]' \
  --include='*.css' assets inc | grep -v '/tokens.css:' | grep -v '/frontend-mode.css:' | grep -v -- '--ak-'

# 4. Ground truth — which WaasKit semantics the live Bricks output emits
grep -oE -e '--accent-on: [^;]+' -e '--accent-subtle: [^;]+' -e '--input: [^;]+' -e '--focus: [^;]+' \
  ../../../uploads/bricks/css/style-manager.min.css
```

**Browser QA (when a browser is connected):** open
`/wp-admin/admin.php?page=adminkit#design`, then a few real screens (Plugins, a
post-edit, a Bricks settings page) in **both** light and dark; confirm primary buttons
read dark-on-yellow, subtle fills stay subtle in dark, and focus rings are visible.

### Documented exceptions

- **B6** — `gutenberg/editor.css` snackbar uses `--neutral-l-9` (a deliberately
  always-dark chip; WP convention; no inverse-surface token exists).
- **B7** — `chrome.css:92` `var(--white-t-1, …)` translucent white scrim.
- **about.css** header re-pins `--text` over a fixed marketing banner image (context-locked).
