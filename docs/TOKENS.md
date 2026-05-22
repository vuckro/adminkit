# Design tokens

> AdminKit restyles wp-admin entirely through CSS custom properties. Every AdminKit stylesheet reads `--ak-*` tokens and never raw colors. That single indirection is what flips the whole admin between light and dark, and what lets an optional design-system **provider** (Bricks today; ACSS / Core Framework later) repaint everything from one source of truth.

Source: [`assets/css/tokens.css`](../assets/css/tokens.css).

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

`--ak-*` token → provider semantic consumed → standalone fallback (light).

| Group | AdminKit token | Provider semantic | Fallback (light) |
| ----- | -------------- | ----------------- | ---------------- |
| **Surface** | `--ak-bg` | `--background` | `hsl(0 0% 98%)` |
| | `--ak-surface` | `--surface` | `hsl(0 0% 96%)` |
| | `--ak-elevated` | `--elevated` | `hsl(0 0% 93%)` |
| | `--ak-surface-alt` | `--white` | `#ffffff` |
| | `--ak-surface-accent` | — (mix of surface/elevated) | `hsl(0 0% 95%)` |
| **Border** | `--ak-border` | `--border` | `hsl(0 0% 89%)` |
| | `--ak-border-strong` | `--border-strong` | `hsl(0 0% 82%)` |
| **Overlay** | `--ak-overlay` | `--overlay` | `rgba(0 0 0 / .5)` |
| **Text** | `--ak-text` | `--text` | `hsl(0 0% 32%)` |
| | `--ak-text-muted` | `--text-muted` | `hsl(0 0% 50%)` |
| | `--ak-heading` | `--heading` | `hsl(0 0% 18%)` |
| **Accent** | `--ak-primary` | `--accent` → `--primary` | neutral gray `hsl(0 0% 32%)` |
| | `--ak-primary-dark` | `--primary-d-2` | darken `--ak-primary` |
| | `--ak-primary-subtle` | `--accent-bg` → `--primary-t-1` | `--ak-primary` @ 9% |
| | `--ak-primary-soft` | `--primary-t-2` | `--ak-primary` @ 18% |
| | `--ak-on-accent` | `--on-accent` | `#ffffff` |
| | `--ak-secondary` | `--secondary` | `hsl(0 0% 50%)` |
| **State** | `--ak-hover-bg` | `--hover` | `rgba(0 0 0 / .04)` |
| | `--ak-focus` | `--focus` | `--ak-primary` @ 27% |
| | `--ak-focus-ring` | — (`0 0 0 3px var(--ak-focus)`) | — |
| **Status** | `--ak-success` / `--ak-warning` / `--ak-error` / `--ak-info` | `--success` / `--warning` / `--error` / `--info` | `#11b76b` / `#ffa100` / `#f04662` / `#2895d4` |
| | `--ak-error-subtle` | `--error-t-2` | `rgba(240 70 98 / .18)` |
| **Field** | `--ak-field-bg` | — (`--ak-surface-alt`) | — |
| | `--ak-field-border` | — (`--ak-border`) | — |
| | `--ak-field-border-hover` | — (`--ak-border-strong`) | — |

`--ak-table-*`, `--ak-adminmenu-*` and `--ak-adminbar-hover-bg` are derived chrome-state surfaces (built from the tokens above). Geometry (`--ak-radius-*`) and type (`--ak-text-*`, `--ak-font-body`) are intentionally **not** taken from the provider — see [Conventions](#conventions).

---

## Light / dark

Tokens live on `:root` only, so they inherit through `<body>` down to `#wpadminbar` and `#adminmenu`. (Declaring them on `<body>` too would let a body-scoped provider sheet freeze the value and block the dark flip.)

Dark mode is **AdminKit's own** `data-adminkit-theme="dark"` on `<html>`, toggled from the admin bar ([`inc/class-theme-toggle.php`](../inc/class-theme-toggle.php)).

The subtlety: a provider's own dark mode (Bricks' `data-brx-theme`, etc.) **never fires inside wp-admin**, so provider primitives sit at their *light-context* values there. The dark block therefore re-maps onto the provider **inverse ramp** `--neutral-d-*`, whose light-context value already equals the matching `--neutral-l-*` dark value:

```
--neutral-d-2 (light context) === --neutral-l-2 (dark value) === 11%
```

This gives correct dark surfaces without depending on the provider's toggle. The dark ramp lifts one step versus light (brighter = more elevated) so cards stay readable on near-black. Tokens that don't change between modes — accent, `--ak-on-accent`, `--ak-overlay`, `--ak-focus`, the field aliases — are declared once on `:root` and carry over.

A provider that exposes no inverse ramp simply falls back to AdminKit's built-in dark values: **the provider drives light mode; AdminKit owns dark mode** unless the provider supplies `--neutral-d-*`.

---

## Conventions

- **Flat by design.** No shadow tokens. `--ak-overlay` (modal scrim) is the only depth primitive. Plugin shadows are *removed*, not themed.
- **px, not the provider's rem scale.** Radii and type stay in px because provider scales are calibrated for a `1rem ≈ 10px` frontend reset that renders too large in admin.
- **Derived tints use `color-mix` off `--ak-primary`**, so a custom primary (from settings or a provider) carries through to hovers, focus rings and subtle fills. Each `color-mix` token ships a static first line for old engines, then the `color-mix` line overrides it (progressive enhancement).
- **The accent foreground is a token, never `#fff`.** Use `--ak-on-accent` for text/icons on a brand fill — a yellow brand needs dark text, which the provider's `--on-accent` supplies.

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

Consumed names: `--background`, `--surface`, `--elevated`, `--white`, `--border`, `--border-strong`, `--overlay`, `--text`, `--text-muted`, `--heading`, `--accent`, `--accent-bg`, `--on-accent`, `--primary`, `--primary-d-2`, `--primary-t-2`, `--secondary`, `--hover`, `--focus`, `--success`, `--warning`, `--error`, `--error-t-2`, `--info`. For dark mode, optionally the inverse ramp `--neutral-d-2…9` and `--white-t-2`; otherwise AdminKit's built-in dark values apply.

Register the adapter sheet as a dependency of `adminkit-tokens` via `adminkit/extra_tokens_handle` so it loads first — see the Bricks integration for the pattern.
