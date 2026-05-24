# How wp-admin renders through the design system

> The plain-language answer to *"when I open a WordPress admin page, where does each
> colour come from?"* Read this to understand how AdminKit turns your **WaasKit** design
> system into the actual look of wp-admin, wp-login and the frontend admin bar.

## The chain (one idea)

Every colour in the admin travels the same path:

```
WaasKit semantic        AdminKit token          a WordPress element
--surface          →    --ak-surface       →    a card / postbox / table row
   (your palette)         (the adapter)            (what you see)
```

1. **WaasKit** defines *what a role means* (`--surface` = "panel background"), and
   points it at a raw colour (`--neutral-l-2`). This is your locked design system.
2. **AdminKit** never reads a WordPress element's colour directly. Every AdminKit rule
   reads an **`--ak-*` token**, and each `--ak-*` token reads **one WaasKit semantic**.
   That single indirection is what makes dark mode and re-branding work from one place.
3. So restyling a WordPress component = pointing it at the right `--ak-*` token.

Where the WaasKit values come from at runtime: AdminKit **ships the whole WaasKit palette**
(`assets/css/waaskit-tokens.css`, generated from `tokens/palettes/*`), so it looks right
with no provider. If **Bricks** is active, its live palette loads after and overrides the
baseline — so a brand change in Bricks flows straight into wp-admin. (Details:
[ARCHITECTURE.md](ARCHITECTURE.md) · [TOKENS.md](TOKENS.md).)

---

## What each part of wp-admin gets

This is the map. "WaasKit role" is the semantic your palette controls; "AdminKit token"
is what the CSS actually reads.

| WordPress area | AdminKit token | WaasKit role | → primitive |
|---|---|---|---|
| **Page background** (body, behind everything) | `--ak-bg` | `--background` | `--neutral-l-1` |
| **Surfaces** — postboxes, metaboxes, cards, table rows, notices, the admin menu | `--ak-surface` | `--surface` | `--neutral-l-2` |
| **Raised / recessed** — section & table headers, dropdowns, the selected/active item | `--ak-elevated` | `--elevated` | `--neutral-l-3` |
| **Form fields** — `input`, `select`, `textarea` background | `--ak-input-bg` | `--input` | `--neutral-l-1` |
| **Borders** — field outlines, card edges, dividers | `--ak-border` | `--border` | `--neutral-l-4` |
| **Strong borders** — field focus outline, emphasised separators | `--ak-border-strong` | `--border-strong` | `--neutral-l-5` |
| **Headings** (`h1`–`h6`, titles) | `--ak-heading` | `--heading` | `--neutral-l-9` |
| **Body text** | `--ak-text` | `--text` | `--neutral-l-8` |
| **Muted text** — descriptions, placeholders, captions | `--ak-text-muted` | `--text-muted` | `--neutral-l-7` |
| **Primary button / links / active item** | `--ak-primary` | `--accent` | `--primary` |
| **Primary hover** | `--ak-primary-hover` | `--accent-hover` | `--primary-d-1` |
| **Text on a brand fill** (label inside a yellow button) | `--ak-on-accent` | `--accent-on` | `--primary-d-9` |
| **Soft brand tint** — selected row, badge, header-CTA hover fill | `--ak-primary-subtle` | `--accent-subtle` | `--primary-l-9` |
| **Keyboard focus ring** | `--ak-focus` (+ `--ak-focus-ring`) | `--focus` | `--primary-t-5` |
| **Modal / drawer scrim** | `--ak-overlay` | `--overlay` | `--black-t-7` |
| **Notices / alerts** — success, warning, error, info (text/icon + a `-subtle` fill) | `--ak-success` … `--ak-info` (+ `*-subtle`) | `--success` … `--info` (+ `*-subtle`) | the notification ramps |

### The three surfaces, by role (not by lightness)
`--ak-bg` is the deepest layer (the page). `--ak-surface` is everything that sits on it
(cards, table rows, the menu). `--ak-elevated` is anything that should read as raised or
distinct (a table header, a dropdown, the selected item). Pick by **role** — the
lightness order flips in dark mode, which is correct for an elevation model.

### Forms
A field is `--ak-input-bg` (lifted slightly above cards so it reads as editable), text in
`--ak-text`, placeholder in `--ak-text-muted`, border `--ak-border` at rest →
`--ak-border-strong` + the `--ak-focus` ring on focus.

### Buttons (how the brand stays controlled)
- **Primary** (`Add New`, `Publish`): filled `--ak-primary`, label `--ak-on-accent`
  (dark text on the yellow), hover `--ak-primary-hover`.
- **Secondary** (form buttons): surface fill, **neutral** `--ak-border` border at rest,
  `--ak-border-strong` on hover. *Never* a brand border — that would read as primary.
- **Header-action CTAs** (Import / Sets / Labels next to a primary): neutral at rest, but
  the border **accentuates to `--ak-primary` on hover** — they're prominent actions.

---

## Dark mode

Dark mode is **AdminKit's own** (`data-adminkit-theme="dark"`, the sun/moon toggle) — a
provider's dark toggle never fires inside wp-admin. AdminKit's dark block re-points the
neutral `--ak-*` onto the **inverse primitive ramp** (`--neutral-d-*`), and the brand /
status `-subtle` fills onto their `d-*` ramp. Result: dark mode reproduces what the
palette *would* look like dark, with no provider involvement. Brand and status hues don't
flip (they're not neutral). See [TOKENS.md → Light / dark](TOKENS.md#light--dark).

---

## What AdminKit adds on top of WaasKit (and why)

WaasKit is a **23-token colour system**. wp-admin needs a few things WaasKit doesn't name
as a token — these are the **"AdminKit" additions** (badged on the Appearance tab where
they're colours):

| Addition | What it is | Why it's not a WaasKit token |
|---|---|---|
| `--ak-secondary` | secondary-emphasis colour | WaasKit ships `--secondary` only as a *primitive*, not a semantic role; AdminKit exposes it as one. |
| `--ak-hover-bg` | mode-aware translucent hover tint (menu items, rows) | WaasKit has no `--hover` role; a translucent tint reads correctly over *any* surface in both modes. |
| `--ak-shadow-elevated` | the single elevation shadow (admin-bar dropdowns) | WaasKit is flat by design; this is the one sanctioned lift, inverted black→white per mode. |
| `--ak-radius-*` | corner radii, in **px** | WaasKit's radius scale is rem-based for the frontend; px keeps admin density right. |
| `--ak-text-*`, `--ak-font-body` | admin type sizes, in **px** | same reason — the provider's rem scale renders too large in wp-admin. |

Not a token at all: **inverse text** (a dark chip in a light page) is done with a
`.scheme-*` scope class, exactly as WaasKit prescribes — so there's no `--ak-*-inverse`.

Everything else on the Appearance tab **maps 1:1 to a WaasKit semantic** (the rows
*without* an "AdminKit" badge).

---

## Third-party plugins (integrations)

A host plugin (WooCommerce, ACF, the Fluent suite…) paints its own admin UI. An AdminKit
**integration** re-points that UI at the same `--ak-*` tokens, two ways:
- **Tier A** — remap the host's own CSS variables to `--ak-*` (zero `!important`, dark
  mode for free).
- **Tier B** — override the host's hardcoded colours where it has no variables.

So a third-party screen ends up reading the very same design system as core wp-admin. See
[INTEGRATIONS.md](INTEGRATIONS.md) and [ADD-AN-INTEGRATION.md](ADD-AN-INTEGRATION.md).
