# Architecture

How AdminKit fits together, end to end. For task-specific detail follow the
links; this page is the mental model.

## The shape of it

AdminKit is **standalone** — it ships a complete look with no dependencies — and
optionally takes brand colours from a **provider** (a theme/framework like Bricks).
Everything visual flows through CSS custom properties (`--ak-*`), so one
indirection drives both dark mode and provider theming.

```
adminkit.php
  └─ AdminKit_Plugin::init()
       ├─ AdminKit_Assets::init()          wire the 4 dispatch hooks
       ├─ wp-core modules ::register()/init()   chrome, login, profile, admin bar, …
       ├─ Settings + Theme toggle init
       ├─ boot_integrations()              glob inc/integrations/*/*/class-*.php,
       │                                   queue each maybe_init() on after_setup_theme
       └─ do_action( 'adminkit/loaded' )
```

`after_setup_theme` runs after the active theme loads, so host constants
(`BRICKS_VERSION`, `WC_VERSION`, …) are reliable when an integration checks
`is_active()`.

## Asset pipeline

CSS is **declared**, not enqueued directly. Each module calls
`AdminKit_Assets::register([ handle, src, deps, context, section, condition ])`.
A dispatcher then enqueues the matching entries once per request, per **context**:

| Context | WP hook | When |
| --- | --- | --- |
| `admin` | `admin_enqueue_scripts` | wp-admin |
| `login` | `login_enqueue_scripts` | wp-login.php |
| `frontend` | `wp_enqueue_scripts` | only when the admin bar shows |
| `editor` | `enqueue_block_editor_assets` | block / site / widgets / nav editors |

Per-screen files load conditionally via a `condition` closure on `$screen`
(e.g. `wp-screens/themes.css` only on `themes.php`). `filemtime` cache-busts every
file. Bail filters exist at every level (`adminkit/should_load`,
`adminkit/enqueue_{context}`, `adminkit/enqueue_{section}`, `adminkit/enqueue_{handle}`).
Full API: [ASSETS.md](ASSETS.md).

## The token cascade (the core idea)

Every `--ak-*` token resolves through layers, each **optional**, degrading cleanly:

```
provider tokens   (Bricks live CSS at /uploads/bricks/css/…, runtime)   ← optional
      ↓ overrides
WaasKit baseline  (assets/css/waaskit-tokens.css, generated from tokens/) ← optional
      ↓ feeds
--ak-* layer      (assets/css/tokens.css — ALWAYS on; owns the dark flip)
      ↓ final fallback
neutral greys     (hardcoded hsl() at the end of each var() chain)
```

```css
--ak-primary: var(--accent, var(--primary, var(--neutral-l-8, hsl(0,0%,32%))));
              /*  provider    baseline       baseline          standalone */
```

- **Cascade order** is set in `enqueue_tokens()` (class-assets.php): WP core →
  `adminkit-waaskit` (baseline) → provider handle → `adminkit-tokens`. Later
  sheets win, so a provider overrides the baseline; the baseline fills anything
  the provider doesn't set.
- **Dark mode** is AdminKit's: `tokens.css` redeclares semantic surface/border/text
  under `[data-adminkit-theme="dark"]`. Brand colours stay constant across modes.
  The baseline carries no dark block (it's a light-context drop-in).
- **Turning layers off:**
  - provider → its integration toggle (`integration_{slug}_enabled`) /
    `adminkit/integration_enabled`; a disabled/absent provider feeds nothing.
  - baseline → `add_filter( 'adminkit/enqueue_baseline', '__return_false' )`;
    with no provider either, the admin rides the neutral fallbacks.

The baseline source + build live in [tokens/](../tokens/README.md); the token
catalogue + mapping in [TOKENS.md](TOKENS.md).

## Provider model

A provider is just an integration that supplies tokens at runtime. Bricks
(`inc/integrations/themes/bricks/class-bricks.php`) is the reference:

1. `provide_tokens()` (hooked on `adminkit/extra_tokens_handle`) enqueues the
   host's live token sheet **as a dependency of the baseline**, so it loads after
   and wins the cascade. Returns `null` when the host hasn't generated one — then
   AdminKit falls back to the baseline.
2. The settings page lists providers via `providers()` (class-settings-page.php),
   each `{ id, label, status, detected }`.

**To add a provider:** create an integration that returns its token handle from
`adminkit/extra_tokens_handle`. No core changes.

**Future colour sync** (import/sync the active theme's palette) plugs into the
same seams — `adminkit/extra_tokens_handle`, the `providers()` list, the
`adminkit/setting/{key}` value filter, and the REST save in
`class-settings-page.php`. Nothing new in core is required to wire it.

## Settings

`AdminKit_Settings` is the registry (defaults + sanitizers + `color_map()`).
`AdminKit_Settings_Page` mounts a small vanilla-JS SPA (`assets/js/settings.js`)
on the top-level **AdminKit** menu and persists via one REST route. Today the
Appearance tab is a read-only token map plus a palette switch; integration
toggles persist. See [SETTINGS.md](SETTINGS.md).

## Where to go next

[CLAUDE.md](../CLAUDE.md) (task index + guardrails) · [ASSETS.md](ASSETS.md) ·
[INTEGRATIONS.md](INTEGRATIONS.md) · [TOKENS.md](TOKENS.md) · [SETTINGS.md](SETTINGS.md)
