# Design tokens

> AdminKit restyles wp-admin entirely through CSS custom properties. Every AdminKit stylesheet reads `--ak-*` tokens and never raw colors. That single indirection is what flips the whole admin between light and dark, and what lets an optional design-system **provider** (Bricks today; ACSS / Core Framework later) repaint everything from one source of truth.

Source: [`assets/css/tokens.css`](../assets/css/tokens.css).

AdminKit keeps a **small, two-tier** token set:

- **Tier 1 — provider semantics (15 roles), mirrored 1:1.** Three surfaces, two borders, four text roles (incl. inverse), accent + hover + subtle, hover, focus, overlay. These are exactly the provider's definitive semantic layer.
- **Tier 2 — AdminKit additions.** The few roles wp-admin needs that the semantic layer doesn't define — muted text, on-accent, input fill, secondary, the status set (success / warning / error / info) — built on the provider's *primitives*. Tagged `+ AdminKit addition` in `tokens.css`.

There are no element-specific tokens (no `table-*`, `adminmenu-*`): those are just surfaces, picked by role.

---

## Three layers

```
1. Provider primitives   --neutral-l-*, --primary, --white-t-* …   raw ramps
2. Provider semantics     --surface, --text, --accent, --hover …    named roles
3. AdminKit tokens        --ak-*                                     consumed by all CSS
```

Each `--ak-*` token resolves through a fallback chain, **semantic first**, then a self-contained default:

```css
--ak-surface: var(--surface, hsl(0, 0%, 96%));
/*                  │                  └ standalone default — no provider present
                    └ provider semantic role — wins when the provider sheet loads */
```

A provider feeds its variables in ahead of `tokens.css` via the `adminkit/extra_tokens_handle` filter (see [`inc/integrations/bricks/class-bricks.php`](../inc/integrations/bricks/class-bricks.php)). With no provider, AdminKit is a complete, calm gray palette on its own.

---

## Mapping

### Tier 1 — provider semantics (1:1)

`--ak-*` token → provider semantic consumed → standalone fallback (light). One declaration each: `color-mix` is only the no-provider fallback (the provider's `--accent-hover` / `--accent-bg` / `--focus` win when present).

| Group | AdminKit token | Provider semantic | Fallback (light) |
| ----- | -------------- | ----------------- | ---------------- |
| **Surface** | `--ak-bg` | `--background` | `hsl(0 0% 98%)` |
| | `--ak-surface` | `--surface` | `hsl(0 0% 96%)` |
| | `--ak-elevated` | `--elevated` | `hsl(0 0% 93%)` |
| **Border** | `--ak-border` | `--border` | `hsl(0 0% 89%)` |
| | `--ak-border-strong` | `--border-strong` | `hsl(0 0% 82%)` |
| **Text** | `--ak-heading` | `--heading` | `hsl(0 0% 18%)` |
| | `--ak-text` | `--text` | `hsl(0 0% 32%)` |
| | `--ak-heading-inverse` | `--heading-inverse` | `--neutral-d-9` |
| | `--ak-text-inverse` | `--text-inverse` | `--neutral-d-8` |
| **Accent** | `--ak-primary` | `--accent` → `--primary` | neutral gray `hsl(0 0% 32%)` |
| | `--ak-primary-hover` | `--accent-hover` → `--primary-l-2` | `color-mix` darken |
| | `--ak-primary-subtle` | `--accent-bg` → `--primary-t-1` | `--ak-primary` @ 9% |
| **State** | `--ak-hover-bg` | `--hover` | `rgba(0 0 0 / .04)` |
| | `--ak-focus` | `--focus` | `--ak-primary` @ 27% |
| **Overlay** | `--ak-overlay` | `--overlay` | `rgba(0 0 0 / .5)` |

### Tier 2 — AdminKit additions

Roles the semantic layer doesn't define; they consume the provider's *primitives* (or stand alone). Tagged `+ AdminKit addition` in `tokens.css`.

| AdminKit token | Consumes | Fallback (light) |
| -------------- | -------- | ---------------- |
| `--ak-text-muted` | `--neutral-l-7` (no semantic) | `hsl(0 0% 50%)` |
| `--ak-on-accent` | `--on-accent` if present | off-white `hsl(0 0% 98%)` |
| `--ak-input-bg` | `--field-bg` if present | white |
| `--ak-secondary` | `--secondary` (primitive) | `hsl(0 0% 50%)` |
| `--ak-success` / `--ak-warning` / `--ak-error` / `--ak-info` | `--success` / `--warning` / `--error` / `--info` | `#11b76b` / `#ffa100` / `#f04662` / `#2895d4` |
| `--ak-error-subtle` | `--error-t-2` | `rgba(240 70 98 / .18)` |
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
| Status fill / text | `--ak-success` · `--ak-warning` · `--ak-error` · `--ak-info` (+ `--ak-error-subtle`) |

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

**Tier 1 — the 15 semantics (required vocabulary):** `--background`, `--surface`, `--elevated`, `--border`, `--border-strong`, `--heading`, `--text`, `--heading-inverse`, `--text-inverse`, `--accent`, `--accent-hover`, `--accent-bg`, `--hover`, `--focus`, `--overlay`.

**Tier 2 — primitives the AdminKit additions consume (optional but recommended):** the neutral ramp `--neutral-l-1…10` + inverse `--neutral-d-1…10` (surfaces, muted text, dark mode), the primary ramp `--primary-l-1…10` / `--primary-d-1…10` / `--primary-t-1` (accent derivations + the **Branded** palette), `--secondary`, and the notification primitives `--success` / `--warning` / `--error` / `--error-t-2` / `--info`. Anything absent falls back to AdminKit's built-in values.

Register the adapter sheet as a dependency of `adminkit-tokens` via `adminkit/extra_tokens_handle` so it loads first — see the Bricks integration for the pattern.
