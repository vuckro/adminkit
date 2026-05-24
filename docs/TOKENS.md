# Tokens

AdminKit restyles wp-admin entirely through CSS custom properties. Every AdminKit
stylesheet reads `--ak-*` tokens and never raw colors — that single indirection
flips the whole admin between light and dark and lets an optional **token
provider** (Bricks today; ACSS / Core Framework later) repaint everything from one
source of truth. Source: [`assets/css/tokens.css`](../assets/css/tokens.css).

AdminKit keeps a **small, two-tier** set:

- **Tier 1 — WaasKit semantics, mirrored 1:1.** Surfaces, borders, text (+ muted),
  accent (+ hover / on / subtle), input, focus, overlay, and the notification set
  (success / warning / error / info, each with a `-subtle` fill) — WaasKit's locked
  **23-token** semantic layer ([WAASKIT-DESIGN-SYSTEM.md](WAASKIT-DESIGN-SYSTEM.md)).
- **Tier 2 — AdminKit additions.** Roles WaasKit doesn't expose as a token but
  wp-admin needs: secondary text, the hover tint, the elevation shadow, geometry /
  type. Tagged `+ AdminKit addition` in `tokens.css`.

No element-specific tokens (no `table-*`, `adminmenu-*`): those are surfaces,
picked by role. Inverse text isn't a token — it's a `.scheme-*` scope class.

## Layers & build

```
0. WaasKit source     tokens/palettes/*.json                 committed source of truth
1. Shipped baseline   assets/css/waaskit-tokens.css :root{…}  GENERATED from (0); always loaded
2. Provider override  --neutral-l-*, --surface, --accent …    Bricks live CSS, optional; overrides (1)
3. --ak-* layer       assets/css/tokens.css                   consumed by all CSS; owns dark
```

AdminKit ships the full WaasKit layer (every primitive + the 23 semantics) as the
**generated** `assets/css/waaskit-tokens.css`, enqueued before `tokens.css`, so a
fresh install is fully on-brand with no provider. Each `--ak-*` still ends in a
self-contained literal that only fires if the baseline is missing:

```css
--ak-surface: var(--surface, hsl(0, 0%, 96%));
/*                 │                └ emergency literal (baseline absent)
                   └ WaasKit semantic — from the baseline, or a provider override */
```

**The build** (`tokens/` is build-time only; nothing there loads at runtime):

```bash
php tokens/build.php          # regenerate assets/css/waaskit-tokens.css from palettes/*.json
php tokens/build.php --check  # exit 1 if the committed CSS is stale (drift gate)
php tokens/build.php --print  # print to stdout, write nothing
```

Edit a palette JSON (or re-export from Bricks into the same shape), rebuild, and
commit **both** the JSON and the regenerated CSS. The palettes: `neutre.json`
(neutral ramps), `marque.json` (brand `--primary`/`--secondary`),
`notifications.json` (status), `semantique.json` (the semantic layer).

> **Guardrail:** `waaskit-tokens.css` is GENERATED. Never hand-edit it — change the
> palette JSON and rebuild, or `--check` fails.

## Mapping

### Tier 1 — WaasKit semantics (1:1)

`--ak-*` → WaasKit semantic → primitive it resolves from → standalone fallback. The
`-subtle` fills are **opaque** (the `l-9` ramp); the dark block re-maps them onto `d-9`.

| Group | AdminKit token | WaasKit semantic | → primitive | Fallback (light) |
| --- | --- | --- | --- | --- |
| **Surface** | `--ak-bg` | `--background` | `--neutral-l-1` | `hsl(0 0% 98%)` |
| | `--ak-surface` | `--surface` | `--neutral-l-2` | `hsl(0 0% 96%)` |
| | `--ak-elevated` | `--elevated` | `--neutral-l-3` | `hsl(0 0% 93%)` |
| | `--ak-input-bg` | `--input` | `--neutral-l-1` | white |
| **Border** | `--ak-border` | `--border` | `--neutral-l-4` | `hsl(0 0% 89%)` |
| | `--ak-border-strong` | `--border-strong` | `--neutral-l-5` | `hsl(0 0% 82%)` |
| **Text** | `--ak-heading` | `--heading` | `--neutral-l-9` | `hsl(0 0% 18%)` |
| | `--ak-text` | `--text` | `--neutral-l-8` | `hsl(0 0% 32%)` |
| | `--ak-text-muted` | `--text-muted` | `--neutral-l-7` | `hsl(0 0% 50%)` |
| **Accent** | `--ak-primary` | `--accent` | `--primary` | `hsl(0 0% 32%)` |
| | `--ak-primary-hover` | `--accent-hover` | `--primary-d-1` | `color-mix` darken |
| | `--ak-primary-subtle` | `--accent-subtle` | `--primary-l-9` | `--ak-primary` @ 12% |
| | `--ak-on-accent` | `--accent-on` | `--primary-d-9` | `hsl(0 0% 98%)` |
| **State** | `--ak-focus` | `--focus` | `--primary` | `--ak-primary` @ 27% |
| **Overlay** | `--ak-overlay` | `--overlay` | `--black-t-7` | `rgba(0 0 0 / .5)` |
| **Status** | `--ak-success`(+`-subtle`) | `--success`(+`-subtle`) | `--success`(+`-l-9`) | `#11b76b` / mix |
| | `--ak-warning`(+`-subtle`) | `--warning`(+`-subtle`) | `--warning`(+`-l-9`) | `#ffa100` / mix |
| | `--ak-error`(+`-subtle`) | `--error`(+`-subtle`) | `--error`(+`-l-9`) | `#f04662` / mix |
| | `--ak-info`(+`-subtle`) | `--info`(+`-subtle`) | `--info`(+`-l-9`) | `#2895d4` / mix |

> **Focus** is opaque — `--ak-focus` reads WaasKit's `--focus` (= `--primary`), a
> hard accessibility requirement (WCAG/RGAA). Never route it to a translucent stop.

### Tier 2 — AdminKit additions

| AdminKit token | Consumes | Fallback (light) |
| --- | --- | --- |
| `--ak-secondary` | `--secondary` (primitive) | `hsl(0 0% 50%)` |
| `--ak-hover-bg` | — (mode-aware translucent tint) | `rgba(0 0 0 / .04)` |
| `--ak-focus-ring` | — (`0 0 0 3px var(--ak-focus)`) | — |
| `--ak-shadow-elevated` | — (the one flat-rule exception) | mode-aware drop |

Geometry (`--ak-radius-*`) and type (`--ak-text-*`, `--ak-font-body`) are
intentionally **not** taken from the provider (px, not the provider's rem scale —
admin density).

## Which token for which element

Pick by **role**, not lightness — surface values are tuned per mode and their
order flips in dark (correct for an elevation model). `--ak-bg` is the deepest
layer (the page); `--ak-surface` is everything on it (cards, inputs, table rows,
the menu); `--ak-elevated` is anything raised/distinct (headers, dropdowns,
selected/active).

| WordPress area | AdminKit token | WaasKit role |
| --- | --- | --- |
| Page background (behind everything) | `--ak-bg` | `--background` |
| Postboxes, cards, table rows, notices, the admin menu | `--ak-surface` | `--surface` |
| Section/table headers, dropdowns, selected/active | `--ak-elevated` | `--elevated` |
| Form field background (`input`, `select`, `textarea`) | `--ak-input-bg` | `--input` |
| Field outlines, card edges, dividers | `--ak-border` / `--ak-border-strong` | `--border(-strong)` |
| Headings / body / muted text | `--ak-heading` / `--ak-text` / `--ak-text-muted` | `--heading` / `--text` / `--text-muted` |
| Primary button / links / active | `--ak-primary` (hover `--ak-primary-hover`) | `--accent(-hover)` |
| Text on a brand fill | `--ak-on-accent` | `--accent-on` |
| Soft brand tint (selected row, badge) | `--ak-primary-subtle` | `--accent-subtle` |
| Keyboard focus ring | `--ak-focus` (+ `--ak-focus-ring`) | `--focus` |
| Modal / drawer scrim | `--ak-overlay` | `--overlay` |
| Notices — success/warning/error/info (+ `-subtle` fill) | `--ak-success` … `--ak-info` | `--success` … `--info` |
| Translucent hover tint (menu, rows) | `--ak-hover-bg` | — (AdminKit addition) |

**Forms:** field `--ak-input-bg`, text `--ak-text`, placeholder `--ak-text-muted`,
border `--ak-border` → `--ak-border-strong` + the `--ak-focus` ring on focus.
**Buttons:** primary = filled `--ak-primary`, label `--ak-on-accent`; secondary =
surface fill, **neutral** `--ak-border` (never a brand border); header-action CTAs
= neutral at rest, border accentuates to `--ak-primary` on hover.

## Light / dark

Tokens live on `:root` only (so they inherit down to `#wpadminbar` / `#adminmenu`;
a `<body>`-scoped provider sheet would otherwise freeze the value and block the
flip). Dark mode is **AdminKit's own** `data-adminkit-theme="dark"` on `<html>`,
toggled from the admin bar ([`class-theme-toggle.php`](../inc/class-theme-toggle.php)).

The subtlety: a provider's own dark mode never fires inside wp-admin, so provider
primitives sit at their *light-context* values. The dark block re-maps onto the
**inverse ramp** `--neutral-d-*`, whose light-context value equals the matching
`--neutral-l-*` dark value (`--neutral-d-2` light === `--neutral-l-2` dark === 11%).
So AdminKit's dark surfaces reproduce the provider's dark palette exactly — no
provider toggle. Brand and status hues don't flip. **The provider drives light
mode; AdminKit owns dark** unless the provider supplies `--neutral-d-*`.

## Conventions

- **Small set, no element tokens.** A table header is `--ak-elevated`, not a
  `--ak-table-header-bg`. Fewer tokens = one obvious choice per role.
- **Flat by design.** Depth is shown by surface tone, not shadows. Plugin shadows
  are *removed*, not themed (the lone exception: `--ak-shadow-elevated`).
- **px, not rem.** Provider rem scales render too large in admin.
- **Derived tints use `color-mix` off `--ak-primary`**, so a custom primary (from a
  provider) carries through to hovers, focus rings and subtle fills. Each ships a
  static first line for old engines, then the `color-mix` override.
- **The accent foreground is a token, never `#fff`.** Use `--ak-on-accent` (a
  light/yellow accent needs dark text).

## Drift detection (keeping adapters alive)

Tokens make a *fresh* adapter correct; **drift detection keeps it correct over
time.** A host plugin (or WP core) can rename a CSS variable, drop a class or
rebrand a color on update — and a Tier A remap quietly does nothing, or a Tier B
override stops matching. `dev/adapter-drift.php` freezes a **baseline** of the
host's CSS surface (its variables + dominant colors, scanned with the same engine
as `adapter-scan.php`) next to each adapter, and re-scans on demand to report what
changed.

```bash
php dev/adapter-drift.php                                   # diff every baseline vs its installed host
php dev/adapter-drift.php --slug=acf                        # one integration
php dev/adapter-drift.php --wp-core                         # WP admin CSS
php dev/adapter-drift.php --slug=acf --host=../acf --update # (re)capture after reconciling
php dev/adapter-drift.php --md                              # markdown report for an issue/PR
```

- **Where baselines live:** `inc/integrations/{type}/{slug}/baseline.json` (one per
  adapter, committed so drift is comparable across machines) and
  `dev/baselines/wp-core.json`. Both are stripped from the distributed zip
  (`.distignore`).
- **What it reports, per target:** host variables removed/changed (breaking — a Tier
  A remap dies), the brand color shifting (breaking), and new colors/vars to map
  (opportunities). It **exits non-zero on breaking drift** — a pre-update / CI gate.
- **Where to fix when it fires:** the adapter's `css/admin.css` and the relevant
  extension points (see [EXTENDING.md](EXTENDING.md)); then `--update` to re-freeze.
- **Coverage:** a baseline exists only where the host is installed; capture more as
  hosts are added (the tool skips absent hosts).

## Alignment & decisions

AdminKit tracks the locked **WaasKit Design System**. The golden rule (WaasKit §3):
a component never reads a primitive, only a semantic — AdminKit honours it by
funnelling everything through `--ak-*`, which read the WaasKit semantics. Key
decisions behind the current mapping:

- **`-subtle` is opaque on the `-9` step**, dark-remapped onto `d-9` (WaasKit §7.3 +
  §11). `l-10` read too pale once swatches were visible.
- **`--ak-on-accent` is dark** (`--accent-on` → `--primary-d-9`): the brand is a
  light yellow, so text on it must be dark.
- **`--input` / `--text-muted` / `--accent-on` bridge the semantic**, not primitives
  (the finalized WaasKit layer defines them).
- **One canonical `*-subtle` per status** (was ad-hoc `color-mix` in integrations).
- **Keep the `--ak-*` namespace** (don't consume raw semantics) so AdminKit can add
  the roles WaasKit lacks and own dark mode.

**Verify after any token change:**

```bash
php tokens/build.php --check                       # generated baseline is fresh
# no stale provider names leaked into code (expect clean):
grep -rnE -e '--field-bg' -e '--accent-bg' -e '--on-accent' --include='*.css' --include='*.php' assets inc
# golden-rule check — direct primitive reads in components (expect only documented exceptions):
grep -rnE 'var\(--(neutral|primary|success|warning|error|info)-(l|d|t)-[0-9]' --include='*.css' assets inc \
  | grep -v '/tokens.css:' | grep -v -- '--ak-'
```

**Documented exceptions:** `gutenberg/editor.css` snackbar (always-dark chip),
`chrome.css` translucent white scrim.

## Adding a provider

AdminKit consumes a fixed **semantic vocabulary**. A new provider only has to make
these names resolve — configure the framework to emit them, or ship a thin adapter
sheet mapping its own names onto them, registered as a dependency of
`adminkit-tokens` via `adminkit/extra_tokens_handle` (see the Bricks integration).

**Required (Tier 1):** `--background`, `--surface`, `--elevated`, `--overlay`,
`--border`, `--border-strong`, `--accent`, `--accent-hover`, `--accent-on`,
`--accent-subtle`, `--input`, `--focus`, `--heading`, `--text`, `--text-muted`, and
`--success` / `--warning` / `--error` / `--info` (each + `-subtle`).

**Recommended (Tier 2 primitives):** the neutral ramp `--neutral-l-1…10` + inverse
`--neutral-d-1…10`, the primary ramp `--primary-l-*` / `--primary-d-*`,
`--secondary`, and the notification `l-9` / `d-9` ramps. Anything absent falls back
to AdminKit's built-in values.
