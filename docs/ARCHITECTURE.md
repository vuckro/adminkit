# Architecture

How AdminKit fits together, end to end — the mental model plus the two APIs you
touch most (the **asset registry** and the **settings registry**). Token
specifics live in [TOKENS.md](TOKENS.md); integration patterns in
[INTEGRATIONS.md](INTEGRATIONS.md); every hook in [EXTENDING.md](EXTENDING.md).

## The shape of it

AdminKit is **standalone** — it ships a complete look with no dependencies — and
optionally takes brand colours from a **provider** (a theme/framework like
Bricks). Everything visual flows through CSS custom properties (`--ak-*`), so one
indirection drives both dark mode and provider theming.

```
adminkit.php
  └─ AdminKit_Plugin::init()
       ├─ AdminKit_Assets::init()          wire the 4 dispatch hooks
       ├─ wp-core modules ::init()         chrome, login, profile, admin bar,
       │                                   list-table, post-previews (CSS + JS bricks)
       ├─ Settings + Theme toggle init
       ├─ boot_integrations()              glob inc/integrations/*/*/class-*.php,
       │                                   queue each maybe_init() on after_setup_theme
       └─ do_action( 'adminkit/loaded' )
```

`after_setup_theme` runs after the active theme loads, so host constants
(`BRICKS_VERSION`, `WC_VERSION`, …) are reliable when an integration checks
`is_active()`. Class names (`AdminKit_*`) are stable; the folder grouping is
organizational only — integration discovery derives the class from the file
basename (see [INTEGRATIONS.md](INTEGRATIONS.md)).

## Asset registry (CSS + JS)

CSS is **declared**, not enqueued directly. Each module calls
`AdminKit_Assets::register([ … ])`; a dispatcher enqueues the matching entries
once per request, per **context**:

| Context | WP hook | When (priority 9999, after WP) |
| --- | --- | --- |
| `admin` | `admin_enqueue_scripts` | every wp-admin page |
| `login` | `login_enqueue_scripts` | wp-login.php |
| `frontend` | `wp_enqueue_scripts` | only when the admin bar shows |
| `editor` | `enqueue_block_editor_assets` | block / site / widgets / nav editors |

```php
AdminKit_Assets::register( array(
    'handle'    => 'adminkit-themes',
    'src'       => 'assets/css/wp-screens/themes.css', // relative to ADMINKIT_PATH
    'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
    'context'   => 'admin',
    'section'   => 'pages',  // optional; defaults to handle minus `adminkit-`
    'condition' => static function ( $screen ) {        // null = always-load
        return $screen && in_array( $screen->id, array( 'themes', 'theme-install' ), true );
    },
) );
```

**Three gates** decide whether a registered entry enqueues, in order — any false
skips it: `adminkit/enqueue_{section}` (per-section bail), `adminkit/enqueue_{handle}`
(per-asset bail), then the `condition` closure. `filemtime` cache-busts every
file (edit CSS/JS → no version bump). All bail filters are in [EXTENDING.md](EXTENDING.md).

**JS bricks.** Feature behaviour (profile tabs, post-preview hover + click-to-recapture,
list-table polish, local avatars) ships as `assets/js/wp-core/*.js`; per-screen
behaviour lives in `assets/js/wp-screens/*.js` (`options.js`, which tabs the
six built-in Settings screens — splitting each at its `<h2>` section and
"exploding" the Discussion `indent-children` table into one tab per `<th>` row,
while every field stays in the one submitting form). All are enqueued in the footer via
`AdminKit_Assets::enqueue_script( $handle, $src, $deps, $data )` — same
`filemtime` cache-bust as CSS, with PHP data passed as a `before` inline
bootstrap (`window.AdminKit*`). The one exception is the **theme pre-paint
script** in `class-theme-toggle.php`: it stays inline in `<head>` so dark/light
applies before first paint (no FOUC). `assets/js/settings.js` is the settings SPA.

**Where AdminKit registers its own assets:** `inc/wp-core/class-chrome.php` (all
admin CSS — chrome, components, screens), `class-login.php` (login), the wp-core
feature classes (their CSS + JS), and each integration's `register_assets()`.
The dispatcher itself knows about no specific asset.

```
assets/
├── css/
│   ├── tokens.css                 # the --ak-* layer, ALWAYS on; owns dark mode
│   ├── waaskit-tokens.css         # GENERATED baseline (see TOKENS.md — do not edit)
│   ├── wp-core/                   # always in admin: chrome, links, adminbar
│   ├── wp-components/             # always in admin (section `forms`): inputs, buttons, tables, form-table, post-previews
│   ├── wp-screens/                # per-screen (section `pages`) + _shared/ + a few broad always-on (wp-components, wpds, font-library, media)
│   ├── login.css                  # login context
│   └── settings.css               # AdminKit settings page
└── js/
    ├── settings.js                # settings SPA
    ├── wp-core/                   # footer bricks: profile-account, local-avatars, post-previews, list-table-chrome
    └── wp-screens/                # per-screen footer bricks: options (Settings-screen tabs)
inc/integrations/{plugins|themes}/{slug}/css/   # integration CSS, registered by each class
```

**Add a per-screen file:** drop `assets/css/wp-screens/{name}.css`, then add a
`self::register_screen( '{name}', array( '{screen-id}', … ) )` line in
`inc/wp-core/class-chrome.php`. The helper wraps the registry call with the
`section => 'pages'` filter and a screen-matching `condition`.

**Dynamic CSS:** the `adminkit/tokens_enqueued` action fires right after
`adminkit-tokens` is enqueued for a context — hook it with
`wp_add_inline_style( AdminKit_Assets::TOKENS_HANDLE, $css )` to inject runtime
token overrides (e.g. a provider/integration applying live values).

## The token cascade (the core idea)

Every `--ak-*` token resolves through layers, each **optional**, degrading cleanly:

```
provider tokens   (Bricks live CSS at /uploads/bricks/css/…, runtime)    ← optional
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

Cascade order is set in `enqueue_tokens()` (class-assets.php): WP core →
`adminkit-waaskit` (baseline) → provider handle → `adminkit-tokens`. Later sheets
win. Dark mode is AdminKit's: `tokens.css` redeclares semantic surface/border/text
under `[data-adminkit-theme="dark"]`; brand colours stay constant. Turn the
baseline off with `add_filter( 'adminkit/enqueue_baseline', '__return_false' )`;
a provider off via its integration toggle. Full catalogue + build: [TOKENS.md](TOKENS.md).

## Provider model

A provider is just an integration that supplies tokens at runtime. Bricks
(`inc/integrations/themes/bricks/class-bricks.php`) is the reference:
`provide_tokens()` (on `adminkit/extra_tokens_handle`) enqueues the host's live
token sheet **as a dependency of the baseline**, so it loads after and wins;
returns `null` when the host hasn't generated one (AdminKit falls back to the
baseline). The settings page lists providers via `providers()`.

**To add a provider:** create an integration that returns its token handle from
`adminkit/extra_tokens_handle`. No core changes. A future palette-sync feature
plugs into the same seams — `adminkit/extra_tokens_handle`, the `providers()`
list, the `adminkit/setting/{key}` filter, and the REST save.

## Settings

`AdminKit_Settings` is the registry; `AdminKit_Settings_Page` mounts a small
vanilla-JS SPA (`assets/js/settings.js`) on the top-level **AdminKit** menu and
persists via one REST route (`adminkit/v1/settings`). Four tabs: **Dashboard**;
**Design** (leads with the interactive Branding block — the brand logo plus the
`wp_logo` / `login_logo` modes for the site-name and login brand marks — then a
read-only reference of the semantic token map; the `design` i18n key, formerly
"Tokens"); **Features** (the module toggles — the `features` key, formerly
"Settings"); and **Plugins** (every installed plugin
plus AdminKit's active theme adapter, each carrying a **Native** badge when
AdminKit ships a tuned adapter for it — a per-host enable toggle plus dark mode —
while everything else inherits AdminKit's **generic** base token styling
automatically. Rows are grouped (Plugins, Themes) with a count pill per group
title, and AdminKit itself appears as a locked **System** row (always on, not
toggleable here). The Native badge tracks whether an adapter exists, not whether
the plugin is currently active).

```php
AdminKit_Settings::register( $key, array $args );  // declare a setting (idempotent)
AdminKit_Settings::get( $key );                    // option → default → adminkit/setting/{key} filter
AdminKit_Settings::schema();                        // registered schema (UI render + save sanitising)
AdminKit_Settings::color_map();                     // semantic token taxonomy the Design tab renders
```

What's registered today. AdminKit ships **fully-featured** — every feature toggle
defaults ON, so the plugin looks complete on activation — and each stays
individually switch-off-able:

- **On by default** (bound to existing enqueue filters / providers in
  `bind_modules()`): `module_login_enabled`, `theme_toggle_enabled`,
  `post_previews_enabled`, `post_previews_mshots`.
- **On by default** (feature modules, each individually disable-able):
  `editor_content_theme` (themes the Gutenberg block-editor canvas — content +
  native blocks — in light/dark; off keeps the canvas matching the live site),
  `replace_icons_enabled` (swaps native menu/toolbar dashicons for AdminKit's set
  — `inc/wp-core/class-menu-icons.php`, filterable via `adminkit/menu_icons` /
  `adminkit/toolbar_icons`; non-destructive — only stock dashicons),
  and `custom_avatars_enabled` (`inc/wp-core/class-local-avatars.php`) registers
  "AdminKit Portraits (Generated)" in *Settings → Discussion → Default Avatar*
  via the core `avatar_defaults` filter, and intercepts `pre_get_avatar_data`
  with a three-step cascade — bail if another filter already set `$args['url']`,
  bail if the user has a real Gravatar (`d=404` HEAD probe cached in
  `adminkit_has_gravatar` user meta, invalidated on `profile_update`), otherwise
  set `$args['url']` directly to a unique DiceBear portrait. Setting `url`
  (rather than `default`) is deliberate: Gravatar's Photon proxy strips query
  strings from the `d=` fallback, which would erase the per-user seed. Non-PII
  seed (md5 of the login) + solid pastel backdrop per user so a fresh users
  list reads as distinct cards. See [EXTENDING.md → Avatars](EXTENDING.md#avatars).
- **Off by default** (opt-in): `bricks_builder_enabled` (restyles a third-party
  builder's own UI, so it stays inert until asked for; only shown when Bricks is
  active).
- **Asset gate**: the `adminkit/should_load` filter wraps *every* context, so any
  veto (an integration bypassing a host's full-screen UI, say) pauses AdminKit's
  styling there while the plugin stays active.
- **Branding**: `logo_light`, `logo_dark`, `wp_logo` (`logo` | `favicon` | `hide`
  — the brand mark at the site-name node; the top-left WordPress logo is always
  hidden) and `logo_size` (px, 16–32) — resolved by
  `AdminKit_Settings::brand_logo()` / `AdminKit_Core_Branding`. `login_logo`
  (`logo` | `favicon` | `hide`, default `favicon`) independently drives the wp-login.php mark
  via `AdminKit_Core_Login` (a legacy `''` = inherit `wp_logo` is still honoured, but
  the UI no longer offers it). `brand_accent` (sanitised hex, default `''`) is the
  user's accent override: when set, `AdminKit_Assets::inject_brand_accent()` hooks
  into `adminkit/tokens_enqueued` and emits a single inline `:root{--ak-primary:…}`
  rule on the tokens stylesheet — the derived tokens (`--ak-primary-hover`,
  `--ak-primary-subtle`, `--ak-on-accent`, focus ring) recalculate automatically
  through their CSS `color-mix()` fallbacks. Empty = the cascade wins (Bricks
  provider → WaasKit baseline → neutral default), no inline style emitted.
- **Integration gates**: one `integration_{slug}_enabled` per discovered adapter
  (default ON), bound to `adminkit/integration_enabled` and driven by the Plugins
  tab.

Add a setting from an integration in its `boot()`:

```php
AdminKit_Settings::register( 'foo_density', array( 'default' => 'comfortable' ) );
add_filter( 'adminkit/setting/foo_density', fn( $v ) => $v ); // optional override
$density = AdminKit_Settings::get( 'foo_density' );
```

Saving keeps only registered keys and runs each through its `sanitize` callback.
There is intentionally **no per-token colour editor** — the Design tab's token
map is a read-only reference (the palette is driven by the provider/baseline
cascade); its only interactive controls are the Branding block at the top.

## Where to go next

[CLAUDE.md](../CLAUDE.md) (task index + guardrails) ·
[INTEGRATIONS.md](INTEGRATIONS.md) · [TOKENS.md](TOKENS.md) ·
[EXTENDING.md](EXTENDING.md)
