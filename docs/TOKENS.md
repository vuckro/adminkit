# Design tokens

> AdminKit restyles wp-admin entirely through CSS custom properties. Every AdminKit stylesheet reads `--ak-*` tokens and never raw colors. That single indirection is what flips the whole admin between light and dark, and what lets an optional design-system **provider** (Bricks today; ACSS / Core Framework later) repaint everything from one source of truth.

Source: [`assets/css/tokens.css`](../assets/css/tokens.css).

AdminKit keeps a **small, two-tier** token set:

- **Tier 1 — WaasKit semantics, mirrored 1:1.** Surfaces, borders, text (+ muted), accent (+ hover / on / subtle), input, focus, overlay, and the full notification set (success / warning / error / info, each with a `-subtle` fill). These are exactly WaasKit's locked **23-token** semantic layer — see [`WAASKIT-DESIGN-SYSTEM.md`](WAASKIT-DESIGN-SYSTEM.md).
- **Tier 2 — AdminKit additions.** The handful of roles WaasKit doesn't expose as a token but wp-admin needs: secondary text, the hover tint, the inverse text pair (WaasKit inverts via a `.scheme-*` scope, not tokens), the elevation shadow, and the geometry / type tokens. Tagged `+ AdminKit addition` in `tokens.css`.

There are no element-specific tokens (no `table-*`, `adminmenu-*`): those are just surfaces, picked by role.

---

## Layers

```
0. WaasKit source         design-system/palettes/*.json             committed source of truth
1. Shipped baseline       assets/css/waaskit-tokens.css (:root{…})   GENERATED from (0); always loaded
2. Provider override       --neutral-l-*, --surface, --accent …      Bricks, optional; overrides (1)
3. AdminKit tokens         --ak-*                                    consumed by all CSS
```

AdminKit **ships the full WaasKit token layer** (every primitive + the 23 semantics) as
`assets/css/waaskit-tokens.css`, generated from the committed `design-system/palettes/*`
by `design-system/build-tokens.php` and enqueued before `tokens.css`. So the semantic
roles are *always present* and a fresh install is fully on-brand. A provider (Bricks)
loads after the baseline and overrides it. Each `--ak-*` token still resolves through a
fallback chain, **semantic first**, then a self-contained literal that now only fires if
the baseline file is somehow missing:

```css
--ak-surface: var(--surface, hsl(0, 0%, 96%));
/*                  │                  └ emergency literal (baseline absent)
                    └ WaasKit semantic role — from the shipped baseline, or a provider override */
```

The provider injects its sheet via the `adminkit/extra_tokens_handle` filter (see
[`inc/integrations/themes/bricks/class-bricks.php`](../inc/integrations/themes/bricks/class-bricks.php)),
registered to depend on the baseline so it loads after it. Regenerate the baseline after
any palette change: `php design-system/build-tokens.php` (and
`--check` as a drift gate).

---

## Mapping

### Tier 1 — WaasKit semantics (1:1)

`--ak-*` token → WaasKit semantic consumed → the primitive it resolves from → standalone fallback (light). One declaration each: `color-mix` / the `l-*` ramp are only the no-provider fallback (the provider's `--accent-hover` / `--accent-subtle` / `--focus` win when present). The `-subtle` fills are **opaque** per WaasKit (the `l-9` ramp); the dark block re-maps them onto the `d-9` ramp (see [Light / dark](#light--dark)).

| Group | AdminKit token | WaasKit semantic | → primitive | Fallback (light) |
| ----- | -------------- | ---------------- | ----------- | ---------------- |
| **Surface** | `--ak-bg` | `--background` | `--neutral-l-1` | `hsl(0 0% 98%)` |
| | `--ak-surface` | `--surface` | `--neutral-l-2` | `hsl(0 0% 96%)` |
| | `--ak-elevated` | `--elevated` | `--neutral-l-3` | `hsl(0 0% 93%)` |
| | `--ak-input-bg` | `--input` | `--neutral-l-1` | white |
| **Border** | `--ak-border` | `--border` | `--neutral-l-4` | `hsl(0 0% 89%)` |
| | `--ak-border-strong` | `--border-strong` | `--neutral-l-5` | `hsl(0 0% 82%)` |
| **Text** | `--ak-heading` | `--heading` | `--neutral-l-9` | `hsl(0 0% 18%)` |
| | `--ak-text` | `--text` | `--neutral-l-8` | `hsl(0 0% 32%)` |
| | `--ak-text-muted` | `--text-muted` | `--neutral-l-7` | `hsl(0 0% 50%)` |
| **Accent** | `--ak-primary` | `--accent` | `--primary` | neutral gray `hsl(0 0% 32%)` |
| | `--ak-primary-hover` | `--accent-hover` | `--primary-d-1` | `color-mix` darken |
| | `--ak-primary-subtle` | `--accent-subtle` | `--primary-l-9` | `--ak-primary` @ 12% over surface |
| | `--ak-on-accent` | `--accent-on` | `--primary-d-10` | off-white `hsl(0 0% 98%)` |
| **State** | `--ak-focus` | `--focus` | `--primary-t-5` ⚠️ | `--ak-primary` @ 27% |
| **Overlay** | `--ak-overlay` | `--overlay` | `--black-t-7` | `rgba(0 0 0 / .5)` |
| **Status** | `--ak-success` | `--success` | `--success` | `#11b76b` |
| | `--ak-success-subtle` | `--success-subtle` | `--success-l-9` | mix over surface |
| | `--ak-warning` | `--warning` | `--warning` | `#ffa100` |
| | `--ak-warning-subtle` | `--warning-subtle` | `--warning-l-9` | mix over surface |
| | `--ak-error` | `--error` | `--error` | `#f04662` |
| | `--ak-error-subtle` | `--error-subtle` | `--error-l-9` | mix over surface |
| | `--ak-info` | `--info` | `--info` | `#2895d4` |
| | `--ak-info-subtle` | `--info-subtle` | `--info-l-9` | mix over surface |

> ⚠️ **Focus.** WaasKit's locked doc says `--focus` must be **opaque** (`--primary`); the current exported Bricks palette emits `--primary-t-5` (translucent). AdminKit's ring reads `--focus` as-is, so it inherits whichever the palette ships. See the alignment log's open items.

### Tier 2 — AdminKit additions

Roles WaasKit doesn't expose as a token; they consume a *primitive* or stand alone. Tagged `+ AdminKit addition` in `tokens.css`.

| AdminKit token | Consumes | Fallback (light) |
| -------------- | -------- | ---------------- |
| `--ak-secondary` | `--secondary` (primitive) | `hsl(0 0% 50%)` |
| `--ak-hover-bg` | — (mode-aware translucent tint; WaasKit has no `--hover`) | `rgba(0 0 0 / .04)` |
| `--ak-heading-inverse` | `--neutral-d-9` (WaasKit inverts via `.scheme-*`, not a token) | `--neutral-d-9` |
| `--ak-text-inverse` | `--neutral-d-8` | `--neutral-d-8` |
| `--ak-focus-ring` | — (`0 0 0 3px var(--ak-focus)`) | — |
| `--ak-shadow-elevated` | — (the one flat-rule exception) | mode-aware drop |

Geometry (`--ak-radius-*`) and type (`--ak-text-*`, `--ak-font-body`) are intentionally **not** taken from the provider — see [Conventions](#conventions).

---

## Which token do I use?

Pick by **role**, not by lightness — surface values are tuned per mode and their relative order flips in dark (that's correct for an elevation model), so choosing a token by its number will break one mode.

| Element | Token |
| ------- | ----- |
| Page background, behind everything | `--ak-bg` |
| Card, panel, postbox, input, table row, notice | `--ak-surface` |
| Raised / distinct: section & table header, hover-down, dropdown, selected, chrome state | `--ak-elevated` |
| Translucent hover tint (menu, rows, chrome) | `--ak-hover-bg` |
| Default / emphasis border | `--ak-border` / `--ak-border-strong` |
| Body / muted / heading text | `--ak-text` / `--ak-text-muted` / `--ak-heading` |
| Brand fill / brand hover / soft brand tint | `--ak-primary` / `--ak-primary-hover` / `--ak-primary-subtle` |
| Text/icon on a brand fill | `--ak-on-accent` |
| Focus ring | `--ak-focus-ring` |
| Status fill / subtle bg | `--ak-success` · `--ak-warning` · `--ak-error` · `--ak-info` (+ each `*-subtle`) |

**Three surfaces, by role.** `--ak-bg` is the deepest layer; `--ak-surface` is everything that sits on it (cards, panels, inputs, table rows); `--ak-elevated` is anything that should read as raised or distinct (headers, dropdowns, hover-down, the selected/active state). A "recessed" element (a table header, a well) is just `--ak-elevated` — distinct from the surface it sits on. The lightness direction of that distinction flips between modes, which is expected and correct.

---

## Light / dark

Tokens live on `:root` only, so they inherit through `<body>` down to `#wpadminbar` and `#adminmenu`. (Declaring them on `<body>` too would let a body-scoped provider sheet freeze the value and block the dark flip.)

Dark mode is **AdminKit's own** `data-adminkit-theme="dark"` on `<html>`, toggled from the admin bar ([`inc/class-theme-toggle.php`](../inc/class-theme-toggle.php)).

The subtlety: a provider's own dark mode (Bricks' `data-brx-theme`, etc.) **never fires inside wp-admin**, so provider primitives sit at their *light-context* values there. The dark block therefore re-maps onto the provider **inverse ramp** `--neutral-d-*`, whose light-context value already equals the matching `--neutral-l-*` dark value:

```
--neutral-d-2 (light context) === --neutral-l-2 (dark value) === 11%
```

Because the semantic surfaces map to that same ramp (`--background`=`--neutral-l-1`, `--surface`=`--neutral-l-2`, `--elevated`=`--neutral-l-3`), AdminKit's dark surfaces **reproduce the provider's dark palette exactly** — no provider toggle, no hand-tuned offset. Only tokens that differ from `:root` are re-declared; everything else (accent, `--ak-on-accent`, `--ak-focus`, status) carries over.

A provider that exposes no inverse ramp simply falls back to AdminKit's built-in dark values: **the provider drives light mode; AdminKit owns dark mode** unless the provider supplies `--neutral-d-*`.

---

## Neutral / Branded palette

The Design system tab offers a global **palette mode** (stored as `palette_mode`):

- **Neutral** (default) — surfaces and borders use the neutral ramp above.
- **Branded** — surfaces + borders are remapped onto the provider's **primary** ramp, so the whole admin picks up the brand tint straight from the provider primitives (no computed colours). Text stays neutral for legibility.

The mapping lives in one place — `AdminKit_Settings::branded_surface_map()` — and is emitted as an inline override on top of `tokens.css`: admin-wide via `inline_tokens()`, and mirrored in the settings live preview.

```css
/* Branded, light */
--ak-bg:       var(--primary-l-10, var(--neutral-l-1));
--ak-surface:  var(--primary-l-9,  var(--neutral-l-2));
--ak-elevated: var(--primary-l-8,  var(--neutral-l-3));
/* …borders → --primary-l-7 / l-6 ; dark → --primary-d-10 … d-6 */
```

Each declaration falls back to the matching neutral step, so Branded degrades gracefully when the provider exposes no primary ramp.

---

## Conventions

- **Small set, no element tokens.** Everything is a surface / border / text / accent / state / status role. An element that needs to look different uses the role that matches (a table header is `--ak-elevated`, not a `--ak-table-header-bg`). Fewer tokens = one obvious choice per role and nothing to keep in sync.
- **Flat by design.** No shadow or scrim tokens — depth is shown by surface tone, never elevation shadows. Plugin shadows are *removed*, not themed.
- **px, not the provider's rem scale.** Radii and type stay in px because provider scales are calibrated for a `1rem ≈ 10px` frontend reset that renders too large in admin.
- **Derived tints use `color-mix` off `--ak-primary`**, so a custom primary (from settings or a provider) carries through to hovers, focus rings and subtle fills. Each `color-mix` token ships a static first line for old engines, then the `color-mix` line overrides it (progressive enhancement).
- **The accent foreground is a token, never `#fff`.** Use `--ak-on-accent` for text/icons on a brand fill — it follows the provider's `--on-accent`, which is tuned per brand (a light/yellow accent needs dark text; a dark accent needs light text).

---

## Custom primary (no provider)

`AdminKit_Settings` injects `:root { --ak-primary: <hex>; }` inline ([SETTINGS.md](SETTINGS.md)). Because hovers, rings and tints are `color-mix`-derived from `--ak-primary`, setting that one value re-tints the whole accent system. When Bricks is active its `--accent` / `--primary` win upstream, so the stored value is effectively the non-provider fallback.

---

## Adding a provider

AdminKit consumes a fixed **semantic vocabulary**. A new provider adapter only has to make these names resolve — either by configuring the framework to emit them, or with a thin adapter sheet that maps the framework's own names onto them:

```css
/* example adapter: Framework X → AdminKit's semantic vocabulary */
:root {
  --surface: var(--fx-card);
  --text:    var(--fx-body);
  --accent:  var(--fx-action);
  /* … */
}
```

**Tier 1 — the WaasKit semantics (required vocabulary):** `--background`, `--surface`, `--elevated`, `--overlay`, `--border`, `--border-strong`, `--accent`, `--accent-hover`, `--accent-on`, `--accent-subtle`, `--input`, `--focus`, `--heading`, `--text`, `--text-muted`, and the notification set `--success` / `--success-subtle` / `--warning` / `--warning-subtle` / `--error` / `--error-subtle` / `--info` / `--info-subtle`.

**Tier 2 — primitives the AdminKit additions consume (optional but recommended):** the neutral ramp `--neutral-l-1…10` + inverse `--neutral-d-1…10` (surfaces, muted text, dark mode, inverse text), the primary ramp `--primary-l-1…10` / `--primary-d-1…10` (accent derivations + the **Branded** palette), `--secondary`, and the notification `l-9` / `d-9` ramps the `-subtle` fills fall back to. Anything absent falls back to AdminKit's built-in values.

Register the adapter sheet as a dependency of `adminkit-tokens` via `adminkit/extra_tokens_handle` so it loads first — see the Bricks integration for the pattern.
