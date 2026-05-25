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

| Detected (computed colour) | Tag | Token |
| --- | --- | --- |
| neutral light background | `.ak-auto-surface` | `--ak-surface` |
| …same, nested in another surface | `.ak-auto-elevated` | `--ak-elevated` |
| **pale** tinted background, by hue | `.ak-auto-{info\|success\|warning\|error}` | `--ak-*-subtle` |
| near-black text | `.ak-auto-text` | `--ak-text` |
| mid-grey text | `.ak-auto-muted` | `--ak-text-muted` |
| light / translucent border | `.ak-auto-bd` | `--ak-border` |
| light box-shadow on a darkened surface | `.ak-auto-noshadow` | `box-shadow:none` |
| the host's **brand** colour (fill/text/border) | `.ak-auto-brand-{bg\|fg\|bd}` | `--ak-primary` |

Classification uses three cheap measures of the parsed `rgb()`: **luminance**
(light↔dark), **saturation** (grey↔colour) and **hue** (which colour). All
thresholds live in one table (`T`) at the top of the JS, made for calibration.

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

- **Interactive controls' surfaces are never restyled.** Buttons, links-as-buttons,
  `input`/`select`/`textarea`, `.button`/`.btn`, `.MuiButton-root`, `.ant-btn` and
  their contents are left alone (only their brand colour, if any, is unified). This
  is the hard guarantee a CTA's colours can't be broken.
- **Only PALE backgrounds are recoloured** (luminance ≥ 218) — vivid brand fills
  are out of reach of the surface pass entirely.
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
