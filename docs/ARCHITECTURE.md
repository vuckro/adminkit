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

**JS bricks.** Feature behaviour (profile tabs, post-preview hover, list-table
polish) ships as `assets/js/wp-core/*.js`, enqueued in the footer via
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
    └── wp-core/                   # footer bricks: profile-account, post-previews, list-table-chrome
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
persists via one REST route (`adminkit/v1/settings`). Three tabs: Dashboard,
**Tokens** (a read-only reference of the semantic token map), Features (the only
interactive controls — module toggles).

```php
AdminKit_Settings::register( $key, array $args );  // declare a setting (idempotent)
AdminKit_Settings::get( $key );                    // option → default → adminkit/setting/{key} filter
AdminKit_Settings::schema();                        // registered schema (UI render + save sanitising)
AdminKit_Settings::color_map();                     // semantic token taxonomy the Tokens tab renders
```

What's registered today: the feature toggles (`module_login_enabled`,
`module_editor_enabled`, `theme_toggle_enabled`, `post_previews_mshots`,
`post_previews_enabled`), all boolean, default ON, bound to existing enqueue
filters in `bind_modules()`. Add one from an integration in its `boot()`:

```php
AdminKit_Settings::register( 'foo_density', array( 'default' => 'comfortable' ) );
add_filter( 'adminkit/setting/foo_density', fn( $v ) => $v ); // optional override
$density = AdminKit_Settings::get( 'foo_density' );
```

Saving keeps only registered keys and runs each through its `sanitize` callback.
There is intentionally **no per-token colour editor** — the Tokens tab is a
read-only map; the palette is driven by the provider/baseline cascade.

## Where to go next

[CLAUDE.md](../CLAUDE.md) (task index + guardrails) ·
[INTEGRATIONS.md](INTEGRATIONS.md) · [TOKENS.md](TOKENS.md) ·
[EXTENDING.md](EXTENDING.md)
