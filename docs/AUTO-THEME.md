# Runtime auto-theming (the "safety net")

How AdminKit gives a **dark mode to plugins it has no adapter for** — automatically,
without a hand-written connector per plugin. This is the generic layer that backs
the **Generic** badge in the Plugins tab.

## Why it exists

AdminKit themes what it can target by selector: WP core (the `wp-core/` +
`wp-components/` sheets) and per-host **adapters** (`inc/integrations/`). But many
modern plugins paint their admin UI with **hardcoded colours no stylesheet can
reach**:

- **CSS-in-JS** (emotion / styled-components) → hashed, run-time-injected class
  names (`.css-1q2w3e`). Elementor 4's MUI-based Home hardcodes
  `background:#fff` on `.MuiPaper-root` this way.
- **inline styles** (`style="background:#fff"`).
- unknown bespoke class names we can't predict.

No CSS AdminKit ships can override those. The only thing that can is code that
reads the **rendered** result and reacts to it — so the auto-theme engine does
exactly that, at runtime, in the browser.

## The pipeline

```
scan the DOM ──▶ read each element's *computed* colour ──▶ classify into a role
   ──▶ add a .ak-auto-* class ──▶ a dark-only stylesheet maps the class to --ak-*
```

Two files do the work:

| File | Role |
| --- | --- |
| `assets/js/wp-core/auto-theme.js` | the detector — scans, classifies, **tags** |
| `assets/css/wp-core/auto-theme.css` | maps each `.ak-auto-*` tag → `--ak-*`, **dark mode only** |
| `inc/wp-core/class-auto-theme.php` | registers the toggles + enqueues (perf-gated) |

It **tags, it doesn't restyle.** The JS only adds classes; the CSS only applies
under `html[data-adminkit-theme="dark"]`. So in light mode nothing changes, a flip
to dark is instant, and there is **zero cleanup** to undo.

## What it maps (dark mode only)

| Property | Detected (computed colour) | Tag | Token |
| --- | --- | --- | --- |
| background | light neutral | `.ak-auto-surface` | `--ak-surface` |
| background | …nested in another surface, or light-grey | `.ak-auto-elevated` | `--ak-elevated` |
| background | **pale** tint, by hue | `.ak-auto-{info\|success\|warning\|error}` | `--ak-*-subtle` |
| background | **pale** near-neutral tint | `.ak-auto-primary-sub` | `--ak-primary-subtle` |
| text | near-black | `.ak-auto-heading` | `--ak-heading` |
| text | dark | `.ak-auto-text` | `--ak-text` |
| text | mid-grey | `.ak-auto-muted` | `--ak-text-muted` |
| border | light | `.ak-auto-bd` | `--ak-border` |
| border | medium / strong | `.ak-auto-bd-strong` | `--ak-border-strong` |
| background | light box-shadow on a darkened surface | `.ak-auto-noshadow` | `box-shadow:none` |
| any | the host's **brand** colour (fill/text/border) | `.ak-auto-brand-{bg\|fg\|bd}` | `--ak-primary` |

The classifier **mirrors `dev/css-scan.php`'s `ak_classify()`** — the same logic
that backs every hand-tuned adapter — so the runtime mapping matches the adapters'
semantics. It branches on the **property** the colour paints (background / border
/ text) and three cheap measures of the parsed `rgb()`: HSL **lightness**, the
**absolute chroma** (`max−min` channels — a far more reliable "is this grey?"
signal than HSL saturation, which balloons for near-white/near-black neutrals),
and **hue**. Only LIGHT surfaces / borders and DARK text are remapped; already-dark
fills, light text (white-on-fill) and vivid hued fills are left alone.

## Brand detection

The host's primary colour is the one that **recurs across its buttons** (a link
colour is a weaker second signal). The detector:

1. samples filled buttons (`button`, `.button-primary`, `.MuiButton-contained`…)
   and links;
2. keeps only **clearly-hued** colours (not grey/white/black);
3. **excludes `--ak-primary`** — core `.button-primary`s that AdminKit already
   remapped — so what's left is the plugin's *own* brand;
4. tallies (quantised so near-identical colours group) and takes the **clear
   plurality** (≥ 3 weighted votes). No clear winner → **no change** (never guess).

The winner is then remapped to `--ak-primary`. Buttons stay **strong coloured
controls** (→ primary), never washed out. React/MUI render buttons late, so
detection retries on DOM mutations and, once found, runs a one-shot brand pass.

## Safety guarantees

- **Buttons' surfaces are never restyled.** True buttons (`button`, `a.button`,
  `.button`/`.btn`, `.MuiButton-root`, `.ant-btn`, submit/reset inputs) and special
  controls (checkbox/radio/range/colour inputs) keep their surface — only their
  brand colour, if any, is unified. This is the hard guarantee a CTA can't break.
  Text fields / selects / textareas ARE themed, so no surface or border is missed.
- **Only LIGHT backgrounds are recoloured**, and only **neutral or pale** ones —
  vivid hued fills (brand buttons, status pills) are out of the surface pass; the
  brand pass handles the host's primary colour, status fills keep their meaning.
- **Self-limiting.** It reads *computed* colour, so anything a native adapter or
  AdminKit core already put on a token reads as the dark token value and is
  skipped — it layers on top of adapters, never fights them.
- **Crash-proof.** Every per-element read is wrapped; one odd element can never
  throw, break the page, or abort the scan.
- **Media is never touched** (`img/svg/canvas/video/iframe…`), nor AdminKit's own
  UI (`#adminkit-app`), the admin bar or the admin menu.

## Performance

- **Scoped to plugin admin pages.** The script only loads where the screen id
  carries `_page_` (a plugin's own settings/app screen). WP-core screens
  (dashboard, posts, options, users, CPT/taxonomy tables) and AdminKit's own page
  are skipped entirely — AdminKit already themes those, so running here would be
  pure waste. *(A native-adapter skip is layered on top — see "Roadmap".)*
- **Time-sliced initial scan** via `requestIdleCallback`, so even a huge CSS-in-JS
  app (thousands of nodes) never blocks the main thread.
- **Incremental updates.** A debounced `MutationObserver` scans only newly-added
  subtrees (how the late-mounting React/MUI Home gets themed).
- **Standalone pages (setup wizards).** Some plugins render a full custom `<body>`
  and print only their own stylesheet (Rank Math / WooCommerce setup wizards) — so
  AdminKit's token + paint sheets never load and `body.adminkit` is absent. The
  brick's JS does load there (those pages print all head/footer scripts), so it
  **self-injects** the two sheets it needs when they're missing, and its CSS no
  longer requires `body.adminkit` (it anchors on `:root` + the `.ak-auto-*` class).
  A no-op on normal pages (the already-enqueued `<link>` is detected).

## Controls & extensibility

| Toggle / hook | Effect |
| --- | --- |
| `auto_theme_enabled` (setting, default ON) | the whole engine |
| `auto_theme_brand_enabled` (setting, default ON) | just the brand→primary remap |
| `add_filter( 'adminkit/setting/auto_theme_brand_enabled', '__return_false' )` | disable brand only, keep surfaces |
| `[data-ak-no-auto]` (attribute) | opt an element + its subtree out |
| `adminkit/should_load` (filter) | the global AdminKit veto (per context) |

## Known limits

- **Dark mode only** by design (a light plugin panel on AdminKit light is fine).
- An element **hidden at scan time then shown** without a DOM insertion isn't
  re-evaluated (most plugins insert-on-open, which the observer catches).
- Tuning is empirical: the `T` thresholds are calibrated by testing real plugins
  (open the admin in dark, inspect any wrong element's computed colour, adjust).

## Roadmap (next)

- **Native-adapter skip:** don't even run on screens an active adapter fully
  handles (Elementor opts back in — its MUI app needs the mop-up).
- A **Settings → Features** row for the generic-support toggle.
- Optional **`:root` variable cartography** (an automatic Tier-A for var-driven
  plugins, flash-free).
