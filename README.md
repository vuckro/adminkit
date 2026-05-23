# AdminKit

A clean, modern restyle of the WordPress admin built on a token-based design system. Standalone — optional adapters layer in design-system providers (Bricks today, more later).

> Status: **v1.1.0** — registry-based assets, per-screen conditional loading, integration scaffolding.

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

That's it. No settings page yet — see [`docs/SETTINGS.md`](docs/SETTINGS.md) for the registry that the future UI will plug into.

---

## How it works

### Tokens

`assets/css/tokens.css` declares every color, surface, border and font as a CSS variable. Each one resolves through a fallback chain — the provider's semantic role first, then a self-contained default:

```css
--ak-primary: var(--accent, var(--primary, var(--neutral-l-8, hsl(0, 0%, 32%))));
              /*  provider    provider       provider          standalone
                  accent      brand          neutral ramp      fallback */
```

The dark-mode block redeclares the semantic surface / border / text tokens for `[data-adminkit-theme="dark"]` — brand colors stay constant across modes.

### Theme toggle

The admin bar carries a sun/moon button. Clicking it flips `<html data-adminkit-theme="dark|light">` and stores the choice in `localStorage.adminkit-theme`. An inline `<head>` script applies the saved theme **before paint** so there's no flash of wrong colors.

### Asset registry

CSS is declared via `AdminKit_Assets::register()` and dispatched per context (admin / login / frontend / editor) and per WP screen. The dashboard loads ~900 lines of CSS; the themes page loads ~900 + screens/themes.css; the plugin editor loads ~900 + screens/plugin-editor.css + screens/code-mirror.css. See [`docs/ASSETS.md`](docs/ASSETS.md) for the full API.

### Integrations

Optional adapters live in `inc/integrations/{slug}/`. Each one self-detects its host and silently does nothing if the host isn't present. **They auto-load** — drop a `class-{slug}.php` file inside its slug folder and the orchestrator wires it on `after_setup_theme`.

The **Bricks** adapter, when active:

- Enqueues the Bricks-generated tokens (`/uploads/bricks/css/style-manager.min.css`) so a color changed in the Bricks builder propagates to wp-admin on the next page load.
- Bypasses every restyle inside the Bricks Builder UI itself.

The **Gutenberg** adapter ships token-mapped header / sidebar / publish-button polish for the block, site, widgets, and navigation editors via the `enqueue_block_editor_assets` hook (NOT `admin_enqueue_scripts`) so the CSS only enters editor surfaces.

AdminKit also ships adapters for **WooCommerce**, **ACF**, the **Fluent** suite (CRM, Forms, SMTP, Booking, Cart), **Slim SEO**, **HappyFiles**, **FlyingPress**, **WP Migrate**, and **Admin Menu Editor**. Each self-detects its host and stays dormant when the host isn't installed. They split into two flavors: *Tier A* adapters remap the host's own CSS variables (zero `!important`, dark mode for free); *Tier B* adapters override the host's selectors because it hardcodes its colors — run `php bin/adapter-audit.php` to see each adapter's override budget.

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
├── docs/
│   ├── INTEGRATIONS.md                   Guide for writing host adapters
│   ├── ASSETS.md                         Asset registry API
│   └── SETTINGS.md                       Settings registry (no UI yet)
├── inc/
│   ├── class-plugin.php                  Boot orchestrator + integration auto-discovery
│   ├── class-assets.php                  Asset registry + dispatcher
│   ├── class-screen.php                  get_current_screen() helpers
│   ├── class-settings.php                Settings registry (registers primary_color)
│   ├── class-dashboard.php               Dashboard widget registry (dormant until used)
│   ├── class-theme-toggle.php            Dark / light toggle + login logo
│   ├── core/
│   │   ├── class-chrome.php              Registers every admin/frontend CSS file
│   │   ├── class-login.php               Registers login.css
│   │   ├── class-profile-accordion.php   Profile screen → accordion (DOM move, no override)
│   │   └── class-list-table-chrome.php   List-table toolbar / pagination polish
│   └── integrations/                    Host adapters — auto-discovered, drop a folder
│       ├── abstract-integration.php      AdminKit_Integration_Base
│       ├── bricks/                       Token provider + Bricks Builder bypass
│       ├── gutenberg/                    Block / site / widgets / nav editor restyle
│       ├── woocommerce/                  wc-admin (React) + classic-screen restyle
│       ├── acf/                          ACF 6.x field UI
│       ├── fluent-smtp/ · fluentform/ · fluent-booking/
│       ├── slim-seo/ · happyfiles/ · flying-press/ · wp-migrate-db-pro/
│       └── admin-menu-editor/            Admin Menu Editor + Choices.js overrides
└── assets/css/
    ├── tokens.css                        Design tokens (always loaded)
    ├── core/                             Always-loaded chrome (sidebar, postboxes, ...)
    ├── components/                       Always-loaded primitives (inputs, buttons, tables)
    ├── screens/                          Per-screen polish (loaded conditionally)
    │   └── _shared/                      Small components shared across screens
    └── login.css                         wp-login.php
```

---

## Writing an integration

1. Create a folder at `inc/integrations/{slug}/` and drop a class extending `AdminKit_Integration_Base` into `class-{slug}.php`.
2. Implement `slug()` + `is_active()`. Override `register_assets()` and/or `boot()` as needed.
3. Put any CSS at `inc/integrations/{slug}/css/` and register it via `AdminKit_Assets::register()`.

That's it — the boot orchestrator picks the folder up automatically on `after_setup_theme`. The Bricks integration at [`inc/integrations/bricks/class-bricks.php`](inc/integrations/bricks/class-bricks.php) is the reference implementation; the full guide is in [`docs/INTEGRATIONS.md`](docs/INTEGRATIONS.md).

---

## Roadmap

- **Settings page.** Toggle individual sections (chrome, forms, pages, editor) without touching code; pick a primary color when Bricks isn't active.
- **Custom dashboard.** Widgets registered via `AdminKit_Dashboard::register_widget()` (the registry exists; widgets land with the WooCommerce / FluentCart integrations).
- **Provider adapters.** Oxygen, Breakdance, Elementor, GeneratePress — same pattern as Bricks.
- **Theme variants.** Beyond light/dark — sepia, high-contrast.
- **Per-role visibility.** Show certain admin chrome only to specific roles.

---

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
