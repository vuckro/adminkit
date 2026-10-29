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
       ├─ wp-core modules ::init()         chrome, login, profile, admin bar, list-table,
       │                                   post-previews + custom-dashboard, online-users,
       │                                   notification-center, footer, help-button, plugins-list
       ├─ feature modules ::init()         inc/features/{menu,stats}/ — self-contained tools,
       │                                   each self-registers its own AdminKit subpage
       ├─ Settings + Theme toggle init
       ├─ boot_integrations()              glob inc/integrations/*/*/class-*.php,
       │                                   queue each maybe_init() on after_setup_theme
       └─ do_action( 'adminkit/loaded' )
```

Two tiers under `inc/`: **wp-core/** restyles surfaces WordPress already renders;
**features/** adds NEW AdminKit surfaces (the menu editor, the analytics pages), each its own
focused admin subpage. The AdminKit menu has three subpages — **Settings** (tabbed: Brand ·
Features · Plugins), **Menu**, **Statistics** (read-only) — all driven by one build-free
vanilla-DOM engine (`assets/js/settings.js`) mounted per-screen via a `data-screen` host
attribute; `inc/settings/class-settings-page.php` exposes the shared `render_host()` / `enqueue_app()` /
`boot_data($screen)` helpers each subpage calls. Dark mode is design-time CSS only — no runtime
auto-theme engine.

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
list-table polish, local avatars) ships as `assets/js/wp-core/*.js`. All are
enqueued in the footer via
`AdminKit_Assets::enqueue_script( $handle, $src, $deps, $data )` — same
`filemtime` cache-bust as CSS, with PHP data passed as a `before` inline
bootstrap (`window.AdminKit*`). The one exception is the **theme pre-paint
script** in `class-theme-toggle.php`: it stays inline in `<head>` so dark/light
applies before first paint (no FOUC). `assets/js/settings.js` is the shared host for the screen modules.

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
    ├── settings.js                # shared screen host
    └── wp-core/                   # footer bricks: profile-account, post-previews, list-table-chrome, user-quick-edit, username-changer
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

## The token cascade

The complete WaasKit snapshot is `tokens/palettes/waaskit.json`. Role declarations
live in `tokens/adminkit.json`. `tokens/appearance.css` owns custom-state rules,
and `tokens/wordpress.css` owns native aliases. The PHP build follows both themes' dependencies and emits
only required WaasKit variables. Missing or circular references fail the build.

Generated outputs: `waaskit-tokens.css` (document/preview),
`waaskit-toolbar-tokens.css` (public toolbar scope), and `tokens.css` (AdminKit roles
and WordPress mappings). The full upstream snapshot is preserved in source control.
The public toolbar receives no WaasKit declarations on the site's root element.

The default accent is WaasKit monochrome, with separate light/dark roles; WaasKit blue is explicit. Explicit custom
colors and the opt-in Bricks provider add overrides through the existing hook.
A custom color uses whichever of pure black or white offers the higher contrast;
the browser preview and PHP output apply the same WCAG calculation.
See [TOKENS.md](TOKENS.md).

## Provider model

A provider is just an integration that supplies tokens at runtime. Bricks
(`inc/integrations/themes/bricks/class-bricks.php`) is the reference:
`provide_tokens()` (on `adminkit/extra_tokens_handle`) enqueues the host's live
token sheet **as a dependency of the baseline**, so it loads after and wins;
returns the existing handle when the host hasn't generated one, leaving
AdminKit's baseline as the visible fallback. The settings page exposes connected
provider state through `AdminKit_Settings_Catalog`.

**To add a provider:** create an integration that returns its token handle from
`adminkit/extra_tokens_handle`. No core changes. Future palette-sync work should
plug into the same seams — `adminkit/extra_tokens_handle`,
`AdminKit_Settings_Catalog`, the `adminkit/setting/{key}` filter, and REST save.

## Settings

`AdminKit_Settings_Page` owns the host, capability checks and REST settings save.
The shared `assets/js/settings.js` host receives one screen factory:

| Pages | JavaScript | Styles |
| --- | --- | --- |
| Brand / Features / Plugins | `settings/main.js` | `settings/main.css` |
| Menu / Toolbar | `settings/navigation.js` | `settings/navigation.css` |
| Statistics | `settings/stats.js` | `settings/stats.css` |

Paths are relative to `assets/js/` and `assets/css/`. Every page also loads the
small shared host and `settings.css`. WordPress script dependencies guarantee
that its factory exists before the host mounts. No framework or build is required.

Factories receive shared data, state and helpers explicitly. They return their
tabs and, for an editable page, one request builder. The host normalizes the route,
posts through `wp-api-fetch` and displays the save result. There is no multi-endpoint
save batch: menu, toolbar and settings save only their own data.

Main tabs mount on first selection. Tab panels retain stable ARIA relationships
and keyboard navigation; single-panel pages have no orphaned tab labels. Statistics
fetch immediately on their dedicated page; the obsolete hidden Stats-tab observer
has been removed. Statistics tracking remains opt-in.

Boot data and translations are filtered per screen. The media frame loads only
for Brand controls. Existing schema/defaults, settings sanitization, WordPress
capabilities, REST nonces and separate menu/toolbar routes remain in place.

Settings are stored in `adminkit_settings`. Menu layouts use the existing per-site
rows in the shared menu table; toolbar layouts use `adminkit_toolbar`. Full feature
catalogs and defaults live in `inc/settings/class-settings.php` and
`class-settings-catalog.php`, rather than a duplicate list in this document.

## Verification

Run `php tokens/build.php --check`, `php dev/check.php`, PHP/JS lint and the adapter
audit. Browser-check the affected screens, actual save/reload and unauthorized REST
writes. The packaging tool excludes development sources, docs and agent instructions
and packages `HEAD` by default.

See [EXTENDING.md](EXTENDING.md) for hooks, [INTEGRATIONS.md](INTEGRATIONS.md) for
adapter contracts and [INSTALL.md](INSTALL.md) for packaging.

## Native list tables

The list-table skin follows the [WaasKit table component](https://design.waaskit.site/composants/tableaux/): 16px card radius, sunken 40px column headers, fine row dividers, soft hover and checked-row accent state. Native rows retain their content-driven height and WordPress controls.

`list-table-chrome.js` groups the existing top/bottom navigation bars and scroll container into one card on native Posts, Pages, Media-list, Users, Terms, Comments and Plugins screens. Controls stay in their original form with their existing IDs and event handlers. Third-party screens retain their existing wrappers. The redundant table footer headings are visually hidden inside a card; top sorting/selection headers and both navigation bars remain available. Mobile row expansion and Quick/Bulk Edit retain their native behavior.

This presentation writes no options or user metadata.

## CSS ownership

Generated tokens own color values. Appearance variants are attribute-scoped; components consume the role appropriate to the surface, including separate accent ink and on-fill ink. PHP and JavaScript inject custom values only, so live preview and saved settings share one stylesheet implementation. Native list tables and generic widefat tables have separate owners, and chrome does not reset table borders. Settings content is bounded by WaasKit’s wide container and choice controls wrap at mobile widths.

Run `php dev/css-contract.php` alongside token drift, contrast and browser checks. Do not add a global reset or another override layer to repair a local component.
