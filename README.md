# AdminKit

A clean, modern restyle of the WordPress admin built on CSS tokens. Standalone — optional adapters layer in token providers (Bricks today, more later).

> Status: **v1.1.0** — registry-based assets, per-screen conditional loading, integration scaffolding + host-drift detection.

---

## What it does

- Restyles **wp-admin**, **wp-login.php** and the **frontend admin bar** to a flat, modern look.
- Ships a **light + dark mode** with a sun/moon toggle in the admin bar (and `prefers-color-scheme` on first visit).
- Exposes its design as **CSS custom properties** (`--ak-*`) that any other admin-side stylesheet can consume.
- **Doesn't require any builder.** A site with no theme provider lands on neutral fallbacks. A site with Bricks gets its brand colors automatically.
- Loads CSS **conditionally per screen** — the themes page CSS doesn't run on the dashboard, plugin-editor CSS doesn't run on profile, etc.

---

## Install

1. Download a release zip (or clone this repo into `wp-content/plugins/adminkit/`).
2. Activate "AdminKit" in the WordPress Plugins screen.

That's it — AdminKit works with zero configuration. A settings page (top-level **AdminKit** menu) has a read-only **Tokens** reference and per-integration toggles; the registry behind it is documented in [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).

---

## How it works

### Tokens

`assets/css/tokens.css` declares every color, surface, border and font as a CSS variable (`--ak-*`). Each one resolves through a fallback chain — a provider's semantic role first, then AdminKit's shipped **WaasKit baseline**, then a self-contained neutral default:

```css
--ak-primary: var(--accent, var(--primary, var(--neutral-l-8, hsl(0, 0%, 32%))));
              /*  provider    baseline       baseline          standalone
                  accent      brand          neutral ramp      fallback */
```

The baseline (`assets/css/waaskit-tokens.css`, generated from `tokens/palettes/*` by `php tokens/build.php`) ships so AdminKit looks complete with no provider; a provider (Bricks) loads after it and overrides it. Every layer is optional and degrades cleanly — see [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md). The dark-mode block redeclares the semantic surface / border / text tokens for `[data-adminkit-theme="dark"]` — brand colors stay constant across modes.

### Theme toggle

The admin bar carries a sun/moon button. Clicking it flips `<html data-adminkit-theme="dark|light">` and stores the choice in `localStorage.adminkit-theme`. An inline `<head>` script applies the saved theme **before paint** so there's no flash of wrong colors.

### Asset registry

CSS is declared via `AdminKit_Assets::register()` and dispatched per context (admin / login / frontend / editor) and per WP screen. Feature JavaScript (profile tabs, post-preview hover, list-table polish) ships as footer "bricks" under `assets/js/wp-core/`, enqueued via `AdminKit_Assets::enqueue_script()`. See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for the full API.

### Integrations

Optional adapters live in `inc/integrations/plugins/{slug}/` (plugin adapters) and `inc/integrations/themes/{slug}/` (theme adapters). Each one self-detects its host and silently does nothing if the host isn't present. **They auto-load** — drop a `class-{slug}.php` file inside its slug folder and the orchestrator wires it on `after_setup_theme`.

The **Bricks** adapter, when active:

- Enqueues the Bricks-generated tokens (`/uploads/bricks/css/style-manager.min.css`) so a color changed in the Bricks builder propagates to wp-admin on the next page load.
- Bypasses every restyle inside the Bricks Builder UI itself.

The **Gutenberg** adapter ships token-mapped header / sidebar / publish-button polish for the block, site, widgets, and navigation editors via the `enqueue_block_editor_assets` hook (NOT `admin_enqueue_scripts`) so the CSS only enters editor surfaces.

AdminKit also ships adapters for **WooCommerce**, **ACF**, the **Fluent** suite (CRM, Forms, SMTP, Booking, Cart), **Slim SEO**, **HappyFiles**, **FlyingPress**, **WP Migrate**, and **Admin Menu Editor**. Each self-detects its host and stays dormant when the host isn't installed. They split into two flavors: *Tier A* adapters remap the host's own CSS variables (zero `!important`, dark mode for free); *Tier B* adapters override the host's selectors because it hardcodes its colors — run `php dev/adapter-audit.php` to see each adapter's override budget.

AdminKit's theme toggle owns its own attribute (`data-adminkit-theme`) and storage key (`adminkit-theme`) — never shared with the host. Users who want a sync bridge can build one via the `adminkit/theme_attribute` / `adminkit/theme_storage_key` filters.

**See [`docs/INTEGRATIONS.md`](docs/INTEGRATIONS.md) for the full guide on writing a new integration.**

---

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
| `adminkit/setting/{$key}` | `(mixed)` | Override a registered setting at read time. |
| `adminkit/theme_attribute` | `(string)` | Override the dark/light HTML attribute name. |
| `adminkit/theme_storage_key` | `(string)` | Override the localStorage key. |

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

---

## File structure

```
adminkit/
├── adminkit.php                          Plugin loader
├── CLAUDE.md                             Orientation for AI assistants — start here
├── README.md                            This file
├── docs/                                Guides (see "Documentation" below)
├── tokens/                              WaasKit baseline source (build-time only)
│   ├── build.php                        Generates assets/css/waaskit-tokens.css
│   └── palettes/                        Committed palette JSON — the source of truth
├── dev/                                 Dev tooling (tracked; excluded from the dist zip via .distignore)
│   ├── css-scan.php                     Shared CSS parser + colour→token classifier
│   ├── adapter-scan.php                 Scaffold a new integration from a host's CSS
│   ├── adapter-audit.php                Integration !important-debt ratchet
│   ├── adapter-drift.php                Host / WP-core CSS drift detector
│   └── baselines/                       WP-core drift baseline
├── inc/
│   ├── class-plugin.php                 Boot orchestrator + integration auto-discovery
│   ├── class-assets.php                 Asset registry + dispatcher + token cascade
│   ├── class-screen.php                 get_current_screen() helpers
│   ├── class-settings.php               Settings registry + color map
│   ├── class-settings-page.php          Settings SPA (admin menu) + REST save
│   ├── class-dashboard.php              Dashboard widget registry (dormant until used)
│   ├── class-theme-toggle.php           Dark / light toggle + login logo
│   ├── wp-core/                         AdminKit's restyle of WP-core surfaces
│   │   ├── class-chrome.php             Registers every admin/frontend CSS file
│   │   ├── class-login.php              Registers login.css
│   │   ├── class-account-bar.php        Admin-bar account menu
│   │   ├── class-profile-account.php    Profile / user-edit screen layout
│   │   ├── class-post-previews.php      List-table screenshot thumbnails
│   │   └── class-list-table-chrome.php  List-table toolbar / pagination polish
│   └── integrations/                    Host adapters — auto-discovered, drop a folder
│       ├── abstract-integration.php     AdminKit_Integration_Base
│       ├── themes/
│       │   └── bricks/                  Token provider + Bricks Builder bypass
│       └── plugins/
│           ├── gutenberg/ · woocommerce/ · acf/
│           ├── fluent-smtp/ · fluentform/ · fluent-booking/
│           ├── slim-seo/ · happyfiles/ · flying-press/ · wp-migrate-db-pro/
│           └── admin-menu-editor/       Admin Menu Editor + Choices.js overrides
└── assets/
    ├── css/
    │   ├── tokens.css                   AdminKit --ak-* layer (always loaded; owns dark)
    │   ├── waaskit-tokens.css           Generated WaasKit baseline — do not hand-edit
    │   ├── wp-core/                     Always-loaded chrome (sidebar, postboxes, ...)
    │   ├── wp-components/               Always-loaded primitives (inputs, buttons, tables)
    │   ├── wp-screens/                  Per-screen polish (loaded conditionally)
    │   │   └── _shared/                 Small components shared across screens
    │   ├── settings.css                 Settings SPA styles
    │   └── login.css                    wp-login.php
    └── js/
        ├── settings.js                  Settings SPA
        └── wp-core/                     Footer behaviour bricks (profile, previews, list-table)
```

Each integration folder may also carry `css/` and a `baseline.json` (its host CSS
snapshot for drift detection).

---

## Writing an integration

1. Create a folder at `inc/integrations/plugins/{slug}/` (or `inc/integrations/themes/{slug}/` for a theme) and drop a class extending `AdminKit_Integration_Base` into `class-{slug}.php`.
2. Implement `slug()` + `is_active()`. Override `register_assets()` and/or `boot()` as needed.
3. Put any CSS at `…/{slug}/css/` and register it via `AdminKit_Assets::register()`.

That's it — the boot orchestrator picks the folder up automatically on `after_setup_theme`. The Bricks integration at [`inc/integrations/themes/bricks/class-bricks.php`](inc/integrations/themes/bricks/class-bricks.php) is the reference implementation; the full guide is in [`docs/INTEGRATIONS.md`](docs/INTEGRATIONS.md).

---

## Documentation

| Doc | Read it for |
| --- | --- |
| [`CLAUDE.md`](CLAUDE.md) | **Start here if you're an AI assistant** — project map, common tasks, guardrails. |
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | How it all fits: bootstrap, the asset registry (CSS + JS), the token cascade, settings. |
| [`docs/INTEGRATIONS.md`](docs/INTEGRATIONS.md) | Writing a host adapter — the contract, the build walkthrough, and the patterns. |
| [`docs/TOKENS.md`](docs/TOKENS.md) | The token system: mapping, build, drift detection, WaasKit alignment. |
| [`docs/EXTENDING.md`](docs/EXTENDING.md) | Every hook + the integration contract — change behaviour without editing core. |
| [`docs/WAASKIT-DESIGN-SYSTEM.md`](docs/WAASKIT-DESIGN-SYSTEM.md) | The locked WaasKit colour-system spec that AdminKit's tokens mirror. |

---

## Roadmap

- **Settings UI — colour editing.** The settings page ships today (a read-only token map + integration toggles); per-token colour pickers are a future direction.
- **Colour sync.** Import / live-sync colours from the active provider or theme (Bricks today; the provider hooks already exist — see [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)).
- **More provider adapters.** Oxygen, Breakdance, Elementor, GeneratePress, ACSS / Core Framework — same pattern as Bricks.
- **Custom dashboard.** Widgets registered via `AdminKit_Dashboard::register_widget()` (the registry exists; widgets land with the WooCommerce / FluentCart integrations).
- **Theme variants.** Beyond light/dark — sepia, high-contrast.
- **Per-role visibility.** Show certain admin chrome only to specific roles.

---

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
