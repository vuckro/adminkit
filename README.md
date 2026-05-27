# AdminKit

A clean, modern restyle of the WordPress admin built on CSS tokens. **Standalone** — works on any WordPress site, with no theme or builder required. Ships a polished light + dark mode out of the box.

[![Try AdminKit in WordPress Playground](https://img.shields.io/badge/Try%20it%20live%20in-WordPress%20Playground-3858E9?style=for-the-badge&logo=wordpress&logoColor=white)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/vuckro/adminkit/main/.wordpress-org/blueprints/blueprint.json)
[![Latest release](https://img.shields.io/github/v/release/vuckro/adminkit?label=Latest%20release&style=for-the-badge&color=3858E9)](https://github.com/vuckro/adminkit/releases/latest)
[![License](https://img.shields.io/badge/License-GPL%202.0%2B-brightgreen?style=for-the-badge)](LICENSE)

> Status: **v1.0.0** — first stable release.

---

## Table of contents

This README is split in two on purpose. **Part 1** is for everyone — even if you've never opened GitHub before, you can install AdminKit and try it from there. **Part 2** is for developers who want to read the architecture, hook into the API, or write an integration.

### Part 1 — For users (start here if you just want to use the plugin)

- [What AdminKit does](#what-adminkit-does)
- [Try it in 60 seconds — no install](#try-it-in-60-seconds--no-install)
- [Install it on your own site](#install-it-on-your-own-site)
- [Your first five minutes](#your-first-five-minutes)
- [Light + dark mode](#light--dark-mode)
- [Frequently asked questions](#frequently-asked-questions)

### Part 2 — For developers

- [How it works](#how-it-works)
- [Tokens](#tokens)
- [Theme toggle](#theme-toggle)
- [Asset registry](#asset-registry)
- [Integrations](#integrations)
- [Extension API](#extension-api)
- [File structure](#file-structure)
- [Writing an integration](#writing-an-integration)
- [Documentation index](#documentation-index)
- [Roadmap](#roadmap)
- [Contributing](#contributing)
- [License](#license)

---

# Part 1 — For users

## What AdminKit does

AdminKit is a regular WordPress plugin. Once activated, it gives `wp-admin` (the back-office where you write posts and tweak settings) a modern, calm look — and adds a few quality-of-life features along the way:

- **A new visual style** for the WordPress dashboard, login screen, and admin bar — flat, modern, readable.
- **One-click light / dark mode** — a sun/moon button in the top-right of the admin bar. Your choice is remembered per browser, and the first visit follows your operating system's preference.
- **Generated avatars for users with no photo** — instead of the default "mystery man" silhouette, anyone without a Gravatar gets a unique cartoon face (via [DiceBear](https://www.dicebear.com)) on a pastel background. You can switch this off in *Settings → Discussion → Default Avatar*.
- **Inline Quick Edit on the Users screen** — edit first name, last name, email and role straight from the user list, the way WordPress already lets you edit posts inline.
- **Hand-tuned dark-mode support for 15 popular plugins** — WooCommerce, ACF, Elementor, the Fluent suite, WPCode, Slim SEO, Gutenberg, and more. Anything else gets dark-mode coverage automatically through AdminKit's "safety net" engine.
- **Automatic brand-color pickup** — set the accent color in AdminKit, and every native plugin adapter follows it.

It changes **nothing on the public side of your site**. Your theme, your pages, your shop pages — untouched. AdminKit lives entirely inside `wp-admin`.

## Try it in 60 seconds — no install

**[→ Open AdminKit in a free WordPress sandbox](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/vuckro/adminkit/main/.wordpress-org/blueprints/blueprint.json)**

That link uses [WordPress Playground](https://wordpress.org/playground/) — a free, in-browser WordPress that runs entirely on your computer (no server, no account, no data sent anywhere). The sandbox boots in about 30 seconds with AdminKit pre-installed and logged in as admin. Click around, flip dark mode, break stuff — when you close the tab, everything is gone.

If you like what you see, install it on your own site (next section).

## Install it on your own site

You need a WordPress site running version **6.0 or higher** and **PHP 7.4 or higher** (most modern hosts cover this).

1. **Download the latest release** — head to the [Releases page](https://github.com/vuckro/adminkit/releases/latest) and download the file named `adminkit-1.0.0.zip` (under "Assets"). Do **not** download the "Source code (zip)" — that one contains development files and won't activate cleanly.
2. **Open WordPress admin**, then go to **Plugins → Add New → Upload Plugin** (top of the page).
3. **Choose the ZIP** you just downloaded and click **Install Now**.
4. When it's done, click **Activate Plugin**.
5. AdminKit appears in the left-hand menu as a top-level entry (just after *Settings*). The new dark / light toggle is in the top-right of the admin bar.

That's it. AdminKit works with zero configuration — defaults are sensible, all the optional polish is on, and you can turn anything off later from the **AdminKit → Features** tab.

To **remove** AdminKit cleanly: deactivate then delete it from the *Plugins* page. It does not create database tables, custom post types, or any persistent state outside the plugin folder.

## Your first five minutes

Click the **AdminKit** entry in the left menu. You'll see a three-tab interface:

- **Dashboard** — set your brand. Upload light and dark variants of your logo and favicon (paired in a 2×2 grid so the right one shows up in the right context). Pick your accent colour (defaults to the WordPress block-editor blue, `#3858E9`). Below that, a read-only reference of every design token AdminKit uses — useful when you want a plugin's admin CSS to match.
- **Features** — every optional module has a toggle here. Don't want generated avatars? Off. Don't want the inline Quick Edit on users? Off. Each toggle has a one-line explanation.
- **Plugins** — every plugin installed on your site is listed here, grouped by type. The ones with a **Native** badge have a hand-tuned AdminKit adapter (full pixel-precise dark mode + brand-color pickup). The rest inherit the "generic" auto-theme layer for dark mode. You can disable AdminKit on any individual plugin's screens here.

The native WordPress *Settings* pages (*General*, *Writing*, *Reading*, …) stay as WordPress renders them — AdminKit only adds light CSS polish and small visual fixes, never a redesigned screen.

## Light + dark mode

Click the **sun / moon icon** in the top-right of the admin bar. The whole interface flips. Your choice is stored in your browser (`localStorage`), so it persists across page loads. If you've never picked one, AdminKit honours your operating system's "dark mode" setting.

Dark mode is the headline feature — it works on AdminKit itself, on every native plugin adapter (15 plugins covered hand-by-hand), and on **any other plugin's admin pages** via a runtime "safety net" that scans the page and remaps light surfaces to dark ones automatically. If you find a plugin AdminKit doesn't handle well in dark mode, open an issue — we want to know.

## Frequently asked questions

**Will AdminKit break my site?**
No. AdminKit only loads CSS and a small amount of JavaScript inside `wp-admin`. The public side of your site is unchanged. There is no database migration, no required configuration, and nothing to break.

**Does AdminKit work with my theme?**
Yes — AdminKit doesn't touch your theme. The frontend looks exactly the same after you install AdminKit. The only frontend addition is a small polish on the WordPress admin bar (the toolbar at the top, visible only when you're logged in).

**My plugin's admin page looks weird in dark mode. Is that a bug?**
Maybe. AdminKit ships hand-tuned adapters for the 15 most common plugins. Everything else gets dark mode through an automatic "safety net". If you spot an issue on a non-native plugin, you can disable AdminKit on that plugin's screens from **AdminKit → Plugins**, or open an [issue](https://github.com/vuckro/adminkit/issues).

**Will it slow my admin down?**
No. AdminKit loads its CSS conditionally per screen (the user-edit page doesn't load CSS for the themes page, etc.). The runtime "safety net" only runs on plugin pages, never on core WordPress screens, and is rate-limited via `requestIdleCallback`.

**Does it support multisite?**
Single-site is fully tested. The plugin works on multisite too, but a few features that touch user sessions (the optional username changer) are intentionally single-site-only.

**How do I uninstall?**
*Plugins → Installed Plugins → Deactivate AdminKit → Delete*. That removes every file. AdminKit stores its settings under one option (`adminkit_settings`) which is removed on delete.

**Is it free?**
Yes. GPL v2 or later, no telemetry, no upsell, no paid tier. If you find it useful, ⭐ the repo on GitHub or share it — that's the only reward we ask for.

**I want to contribute / report a bug / suggest a feature.**
[Open an issue](https://github.com/vuckro/adminkit/issues) or send a pull request. AdminKit is maintained on GitHub.

---

# Part 2 — For developers

## How it works

AdminKit's design layer is built on **CSS custom properties** (a.k.a. tokens). Every colour, surface, border, shadow and font-size is declared as a `--ak-*` variable on `:root`. The cascade flows through a defined order — see [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) — and dark mode flips the value of those tokens via a single attribute on `<html>`. There is **no JavaScript painting**, **no inline-style injection from PHP**, **no `<style>` block per page**. Everything is a stylesheet that compiles once.

PHP is responsible for three things only:

1. **Discovering** what to enqueue (which screen, which integration is active).
2. **Registering** the right CSS/JS handles in the right order.
3. **Exposing settings** through a small REST endpoint backed by the `adminkit_settings` option.

All structural rendering — the admin chrome, the login screen, the integration adapters — is plain CSS targeting WordPress's own DOM. AdminKit does **not** rebuild the WordPress admin in React or replace core screens.

## Tokens

`assets/css/tokens.css` declares every colour, surface, border and font as a CSS variable. Each one resolves through a fallback chain — a provider's semantic role first, then AdminKit's shipped **WaasKit baseline**, then a self-contained neutral default:

```css
--ak-primary: var(--accent, var(--primary, var(--neutral-l-8, hsl(0, 0%, 32%))));
              /*  provider    baseline       baseline          standalone
                  accent      brand          neutral ramp      fallback */
```

The baseline (`assets/css/waaskit-tokens.css`, generated from `tokens/palettes/*` by `php tokens/build.php`) ships so AdminKit looks complete with no provider; a provider (Bricks) loads after it and overrides it. Every layer is optional and degrades cleanly — see [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for the cascade order. The dark-mode block redeclares the semantic surface / border / text tokens for `[data-adminkit-theme="dark"]` — brand colours stay constant across modes.

## Theme toggle

The admin bar carries a sun/moon button. Clicking it flips `<html data-adminkit-theme="dark|light">` and stores the choice in `localStorage.adminkit-theme`. An inline `<head>` script applies the saved theme **before paint** so there's no flash of wrong colours.

Dark-mode coverage is layered:

- **WordPress core screens** — hand-styled by AdminKit's `wp-core/*.css`.
- **Native plugin integrations** (the 15 adapters) — each one ships its own CSS so dark mode is pixel-precise on those screens.
- **Everything else** — covered automatically by `assets/js/wp-core/auto-theme.js`, a runtime "safety net" that scans the rendered DOM, classifies elements by HSL lightness / chroma + a few semantic hints (modal containers, heading tags, hovered selectors discovered from the loaded stylesheets), tags them with `.ak-auto-*` classes, and remaps the tags via a dark-only companion sheet. See [`docs/AUTO-THEME.md`](docs/AUTO-THEME.md) for the full classification rules.

## Asset registry

CSS is declared via `AdminKit_Assets::register()` and dispatched per context (admin / login / frontend / editor) and per WP screen. Feature JavaScript (profile tabs, post-preview hover, list-table polish) ships as footer "bricks" under `assets/js/wp-core/`, enqueued via `AdminKit_Assets::enqueue_script()`. See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for the full API.

## Integrations

Optional adapters live in `inc/integrations/plugins/{slug}/` (plugin adapters) and `inc/integrations/themes/{slug}/` (theme adapters). Each one self-detects its host and silently does nothing if the host isn't present. **They auto-load** — drop a `class-{slug}.php` file inside its slug folder and the orchestrator wires it on `after_setup_theme`.

The **Bricks** adapter, when active:

- Enqueues the Bricks-generated tokens (`/uploads/bricks/css/style-manager.min.css`) so a colour changed in the Bricks builder propagates to wp-admin on the next page load.
- Leaves the Bricks Builder UI native by default; an opt-in **Bricks builder** toggle (Features tab) restyles the builder chrome with your tokens, and falls back to AdminKit's shipped baseline if you clear Bricks's own colours — so the builder never loses its look.

The **Gutenberg** adapter ships token-mapped header / sidebar / publish-button polish for the block, site, widgets, and navigation editors via the `enqueue_block_editor_assets` hook (NOT `admin_enqueue_scripts`) so the CSS only enters editor surfaces. The **Gutenberg** toggle (Features tab, on by default) additionally themes the iframed editor canvas — content + native blocks — in light and dark; turn it off to keep the canvas matching your live site exactly.

AdminKit also ships adapters for **WooCommerce**, **ACF**, **Elementor**, the **Fluent** suite (Forms, SMTP, Booking), **WPForms**, **WPCode**, **Query Monitor**, **Slim SEO**, **HappyFiles**, **FlyingPress**, **WP Migrate**, and **Admin Menu Editor**. Each self-detects its host and stays dormant when the host isn't installed. They split into two flavours: *Tier A* adapters remap the host's own CSS variables (zero `!important`, dark mode for free); *Tier B* adapters override the host's selectors because it hardcodes its colours — run `php dev/adapter-audit.php` to see each adapter's override budget.

AdminKit's theme toggle is authoritative and self-contained: it always flips its own attribute (`data-adminkit-theme`) and storage key (`adminkit-theme`), so dark mode works standalone with no provider. When Bricks is present, its adapter adds a bridge on top — it adopts Bricks's mode on load and then mirrors AdminKit's mode into Bricks (`data-brx-theme` + `brx_mode`, guarded against loops) so the front end repaints too. You can repoint or rename the attribute / storage key via the `adminkit/theme_attribute` / `adminkit/theme_storage_key` filters.

**See [`docs/INTEGRATIONS.md`](docs/INTEGRATIONS.md) for the full guide on writing a new integration.**

## Extension API

All hooks are namespaced `adminkit/*`.

### Actions

| Hook | Fires |
| --- | --- |
| `adminkit/loaded` | Once every AdminKit module has registered its hooks. |
| `adminkit/tokens_enqueued` | Right after `adminkit-tokens` is enqueued, with the current context as arg. Hook here for `wp_add_inline_style` injections. |
| `adminkit/enqueued_admin` | After our admin stylesheets are enqueued. |
| `adminkit/enqueued_login` | After our login stylesheet is enqueued. |
| `adminkit/enqueued_frontend` | After our frontend admin-bar stylesheet is enqueued. |
| `adminkit/enqueued_editor` | After our block editor stylesheets are enqueued. |
| `adminkit/provider/resync` | Fires when the user clicks Re-sync from Bricks Builder in the Design tab Actions menu, with the provider slug as arg (currently only `bricks`). Integrations clear cached values here so the next page paints fresh. |

Use `adminkit/enqueued_admin` and declare `adminkit-tokens` as a dependency to inherit the design tokens in your own stylesheet:

```php
add_action( 'adminkit/enqueued_admin', function () {
    wp_enqueue_style(
        'my-acf-overrides',
        plugins_url( 'admin.css', __FILE__ ),
        array( 'adminkit-tokens' ),
        '1.0.0'
    );
} );
```

### Filters

| Hook | Signature | Purpose |
| --- | --- | --- |
| `adminkit/should_load` | `(bool, string $context)` | Global short-circuit per context. |
| `adminkit/enqueue_admin` | `(bool)` | Skip admin enqueue. |
| `adminkit/enqueue_login` | `(bool)` | Skip login enqueue. |
| `adminkit/enqueue_frontend` | `(bool)` | Skip frontend admin-bar enqueue. |
| `adminkit/enqueue_editor` | `(bool)` | Skip block-editor enqueue. |
| `adminkit/enqueue_forms` | `(bool)` | Skip the form components (`inputs` / `buttons` / `tables`). |
| `adminkit/enqueue_pages` | `(bool)` | Skip every screen-specific polish file. |
| `adminkit/enqueue_{$handle}` | `(bool)` | Skip a single asset by handle (1.1+). |
| `adminkit/extra_tokens_handle` | `(string\|null, string $context)` | Integrations return a style handle that becomes a dependency of `adminkit-tokens`. |
| `adminkit/integration_enabled` | `(bool, string $slug)` | Enable/disable one integration — drives the Plugins tab. |
| `adminkit/brand_logo` | `('' \| string \| array)` | Brand-logo fallback when the Branding settings are empty. |
| `adminkit/menu_icons` / `adminkit/toolbar_icons` | `(array)` | Override the native-icon replacement maps (dashicon-class / node-id ⇒ SVG). |
| `adminkit/toolbar_icon_ab_item_nodes` | `(array)` | Mark toolbar nodes (node-id ⇒ bool) whose icon paints on `> .ab-item::before` instead of an `.ab-icon` child — for text/dashicon-font nodes. |
| `adminkit/generated_avatar_style` | `(string $style, int $user_id)` | The DiceBear style slug for generated avatars (default `avataaars` — varied cartoon humans). |
| `adminkit/generated_avatar_url` | `(string $url, int $user_id, int $size)` | The final generated-avatar URL — override to self-host or swap the service. |
| `adminkit/setting/{$key}` | `(mixed)` | Override a registered setting at read time. |
| `adminkit/theme_attribute` | `(string)` | Override the dark/light HTML attribute name. |
| `adminkit/theme_storage_key` | `(string)` | Override the localStorage key. |
| `adminkit/suppress_auto_theme` | `(bool, WP_Screen)` | Suppress the runtime dark-mode safety net on a specific screen — native adapters use this to claim their own screens. |

Example — disable AdminKit on a specific plugin's screens:

```php
add_filter( 'adminkit/should_load', function ( $load, $context ) {
    if ( 'admin' === $context && function_exists( 'get_current_screen' ) ) {
        $screen = get_current_screen();
        if ( $screen && str_starts_with( $screen->id, 'woocommerce_' ) ) {
            return false;
        }
    }
    return $load;
}, 10, 2 );
```

## File structure

```
adminkit/
├── adminkit.php                          Plugin loader
├── CLAUDE.md                             Orientation for AI assistants — start here
├── README.md                             This file
├── readme.txt                            WordPress.org-style plugin readme
├── LICENSE                               GPL-2.0-or-later
├── docs/                                 Guides (see "Documentation index" below)
├── tokens/                               WaasKit baseline source (build-time only)
│   ├── build.php                         Generates assets/css/waaskit-tokens.css
│   └── palettes/                         Committed palette JSON — the source of truth
├── dev/                                  Dev tooling (tracked; excluded from the dist zip via .distignore)
│   ├── css-scan.php                      Shared CSS parser + colour→token classifier
│   ├── adapter-scan.php                  Scaffold a new integration from a host's CSS
│   ├── adapter-audit.php                 Integration !important-debt ratchet
│   ├── adapter-drift.php                 Host / WP-core CSS drift detector
│   ├── auto-theme-calibrate.php          Calibration loop for the auto-theme classifier
│   ├── package.php                       Release zip / install builder (honours .distignore)
│   └── baselines/                        WP-core drift baseline
├── inc/
│   ├── class-plugin.php                  Boot orchestrator + integration auto-discovery
│   ├── class-assets.php                  Asset registry + dispatcher + token cascade
│   ├── class-screen.php                  get_current_screen() helpers
│   ├── class-settings.php                Settings registry + colour map
│   ├── class-settings-page.php           SPA bootstrap (top-level AdminKit menu — Dashboard / Features / Plugins tabs) + REST save
│   ├── class-theme-toggle.php            Dark / light toggle + login logo
│   ├── wp-core/                          AdminKit's restyle of WP-core surfaces
│   │   ├── class-chrome.php              Registers every admin/frontend CSS file
│   │   ├── class-login.php               Registers login.css
│   │   ├── class-branding.php            Site-name brand mark: brand logo / favicon / hide (wp_logo)
│   │   ├── class-menu-icons.php          Opt-in native-icon replacement (menu + toolbar), filterable
│   │   ├── class-profile-account.php     Profile / user-edit / user-new tabbed layout
│   │   ├── class-local-avatars.php       Per-user avatar that replaces Gravatar + generated-avatar fallback (DiceBear)
│   │   ├── class-auto-theme.php          Runtime dark-mode tag-and-paint for unsupported plugin admin screens
│   │   ├── class-post-previews.php       List-table screenshot thumbnails
│   │   ├── class-list-table-chrome.php   List-table toolbar polish + .subsubsub icons + nav-tab icons
│   │   ├── class-user-quick-edit.php     Inline Quick Edit on users.php (first/last/email/role + avatar refresh)
│   │   ├── class-username-changer.php    Opt-in rename of user_login from profile / user-edit (off by default)
│   │   ├── class-options-general.php     Light polish on Settings → General
│   │   └── class-options-discussion.php  2-tab strip + Comments heading on Settings → Discussion
│   └── integrations/                     Host adapters — auto-discovered, drop a folder
│       ├── abstract-integration.php      AdminKit_Integration_Base
│       ├── themes/
│       │   └── bricks/                   Token provider + opt-in Bricks builder theming (with baseline fallback)
│       └── plugins/
│           ├── gutenberg/ · woocommerce/ · acf/ · elementor/
│           ├── fluent-smtp/ · fluentform/ · fluent-booking/
│           ├── wpforms/ · wpcode/ · query-monitor/
│           ├── slim-seo/ · happyfiles/ · flying-press/ · wp-migrate-db-pro/
│           └── admin-menu-editor/        Admin Menu Editor (Choices, settings, search, CPE metabox)
└── assets/
    ├── css/
    │   ├── tokens.css                    AdminKit --ak-* layer (always loaded; owns dark)
    │   ├── waaskit-tokens.css            Generated WaasKit baseline — do not hand-edit
    │   ├── wp-core/                      Always-loaded chrome (sidebar, postboxes, …)
    │   ├── wp-components/                Always-loaded primitives (inputs, buttons, tables)
    │   ├── wp-screens/                   Per-screen polish (loaded conditionally)
    │   │   └── _shared/                  Small components shared across screens
    │   ├── settings.css                  Settings SPA styles
    │   └── login.css                     wp-login.php
    └── js/
        ├── settings.js                   Settings SPA
        └── wp-core/                      Footer behaviour bricks (profile, previews, list-table, user-quick-edit, username-changer, auto-theme)
```

Each integration folder may also carry `css/` and a `baseline.json` (its host CSS snapshot for drift detection).

## Writing an integration

1. Create a folder at `inc/integrations/plugins/{slug}/` (or `inc/integrations/themes/{slug}/` for a theme) and drop a class extending `AdminKit_Integration_Base` into `class-{slug}.php`.
2. Implement `slug()` + `is_active()`. Override `register_assets()` and/or `boot()` as needed.
3. Put any CSS at `…/{slug}/css/` and register it via `AdminKit_Assets::register()`.

That's it — the boot orchestrator picks the folder up automatically on `after_setup_theme`. The Bricks integration at [`inc/integrations/themes/bricks/class-bricks.php`](inc/integrations/themes/bricks/class-bricks.php) is the reference implementation; the full guide is in [`docs/INTEGRATIONS.md`](docs/INTEGRATIONS.md).

## Documentation index

| Doc | Read it for |
| --- | --- |
| [`CLAUDE.md`](CLAUDE.md) | **Start here if you're an AI assistant** — project map, common tasks, guardrails. |
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | How it all fits: bootstrap, the asset registry (CSS + JS), the token cascade, settings. |
| [`docs/INSTALL.md`](docs/INSTALL.md) | The three install paths: release zip, developer clone-and-symlink, packager-built copy. |
| [`docs/INTEGRATIONS.md`](docs/INTEGRATIONS.md) | Writing a host adapter — the contract, the build walkthrough, and the patterns. |
| [`docs/TOKENS.md`](docs/TOKENS.md) | The token system: mapping, build, drift detection, WaasKit alignment. |
| [`docs/EXTENDING.md`](docs/EXTENDING.md) | Every hook + the integration contract — change behaviour without editing core. |
| [`docs/AUTO-THEME.md`](docs/AUTO-THEME.md) | The runtime dark-mode safety net — classification rules, gate logic, perf model. |
| [`docs/WAASKIT-DESIGN-SYSTEM.md`](docs/WAASKIT-DESIGN-SYSTEM.md) | The locked WaasKit colour-system spec that AdminKit's tokens mirror. |

## Roadmap

This README is the single source. (The in-app dashboard widget that used to mirror it has been removed — the maintenance overhead of keeping two surfaces in sync wasn't worth the duplication.)

- **In progress** — universal plugin compatibility (broaden the runtime dark-mode coverage so per-plugin adapters become optional polish, not gap-fillers); custom dashboard page.
- **Next** — more native screens styled; in-app palette editor; colour sync; more provider adapters; accessibility / contrast checks; import / export settings; per-role visibility; admin-bar polish.
- **Planned** — command palette (⌘K); theme variants; per-user theme preference; admin notices manager; native menu editor; white-label & admin footer; custom admin CSS; density / compact mode; typography controls; Bricks dynamic logo tag.

## Contributing

Bug reports and pull requests are welcome on the [issues page](https://github.com/vuckro/adminkit/issues). Before submitting code:

- Read [`CLAUDE.md`](CLAUDE.md) for the project's working principles and pre-merge gates.
- Run `php -l` on any changed PHP file.
- For CSS-debt changes, run `php dev/adapter-audit.php`.
- For host CSS drift, run `php dev/adapter-drift.php`.
- For token changes, run `php tokens/build.php --check` before committing.

The active integration branch is `docs/overhaul`; `main` is promoted to it at clean checkpoints. Releases are tagged on `main`.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
