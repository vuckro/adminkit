# Extension points

Every place you can change AdminKit's behaviour **without editing core** — the
hooks, the integration contract, and the external files you adjust. This is the
map a maintainer reaches for when a WordPress or host-plugin update shifts
something and a mapping needs tuning (see [Where to fix when a host
changes](#where-to-fix-when-a-host-changes)).

Conventions: hooks are namespaced `adminkit/…`; integration hooks namespace under
the host (`adminkit/foo/…`). `{…}` in a name is a dynamic segment.

## Asset loading

| Hook | Type | Signature | Purpose |
| --- | --- | --- | --- |
| `adminkit/should_load` | filter | `(bool, string $context)` | Master switch for a context (`admin`/`login`/`frontend`/`editor`/`builder`). Return false to disable all AdminKit styling there — e.g. integrations use it to bypass a host's full-screen UI. |
| `adminkit/enqueue_{context}` | filter | `(bool)` | Per-context gate, e.g. `adminkit/enqueue_login`. The `login` + `editor` ones are wired to the feature toggles. |
| `adminkit/enqueue_{section}` | filter | `(bool)` | Per-section gate (section = handle minus `adminkit-`), e.g. `adminkit/enqueue_forms` drops inputs+buttons+tables together. |
| `adminkit/enqueue_{handle}` | filter | `(bool)` | Per-asset gate, e.g. `adminkit/enqueue_adminkit-themes`. |
| `adminkit/enqueue_baseline` | filter | `(bool, string $context)` | Ship the WaasKit baseline tokens. `__return_false` drops it (ride the provider, or neutral fallbacks). |
| `adminkit/enqueued_{context}` | action | `(string $context)` | Fires after a context finishes dispatching. |

All in `inc/class-assets.php`. The three-gate model (section → handle → condition)
is in [ARCHITECTURE.md](ARCHITECTURE.md#asset-registry-css--js).

## Tokens & providers

| Hook | Type | Signature | Purpose |
| --- | --- | --- | --- |
| `adminkit/extra_tokens_handle` | filter | `(string\|null, string $context)` | Return a stylesheet handle to inject as a dependency of `adminkit-tokens` — how a **provider** feeds its live palette (Bricks). |
| `adminkit/tokens_enqueued` | action | `(string $context)` | Fires right after `adminkit-tokens` is enqueued. Hook with `wp_add_inline_style( AdminKit_Assets::TOKENS_HANDLE, $css )` to inject runtime token overrides. |

## Settings

| Hook | Type | Signature | Purpose |
| --- | --- | --- | --- |
| `adminkit/setting/{key}` | filter | `(mixed $value)` | Final value of any setting, applied last in `AdminKit_Settings::get()` — override env-specifically without the UI. |
| `adminkit/providers` | filter | `(array)` | The provider list on the settings page; each `{ id, label, status, detected }`. |
| `adminkit/integration_enabled` | filter | `(bool, string $slug)` | Gate a specific integration (e.g. `'bricks'`). |
| `adminkit/dashboard` | filter | `(array)` | The settings Dashboard tab payload (cards / next-up). |

`adminkit/setting/{key}` is in `inc/class-settings.php`; the rest in
`inc/class-settings-page.php`.

## Theme / dark mode

| Hook | Type | Signature | Purpose |
| --- | --- | --- | --- |
| `adminkit/theme_attribute` | filter | `(string)` | The HTML attribute carrying the mode (default `data-adminkit-theme`). |
| `adminkit/theme_storage_key` | filter | `(string)` | The localStorage key (default `adminkit-theme`). |

Both in `inc/class-theme-toggle.php`.

## Branding

| Hook | Type | Signature | Purpose |
| --- | --- | --- | --- |
| `adminkit/brand_logo` | filter | `('' \| string \| array)` | Brand logo fallback when the Branding settings are empty. Return a URL string, or `array( 'light' => …, 'dark' => …, 'preloader' => … )`. Drives the admin-bar logo **and** the Bricks builder; the settings win over the filter. |

Resolved by `AdminKit_Settings::brand_logo( $mode )`. Logos are normally set
no-code in Settings → Features → Branding, alongside the `wp_logo` mode for the
admin-bar logo slot: `logo` (the brand logo), `favicon` (the site icon), or
`hide`. `logo` falls back to `favicon` then WordPress's own when nothing is set;
`inc/wp-core/class-branding.php`.

## Icons

The "AdminKit icons" feature (`replace_icons_enabled`, opt-in) swaps WordPress's
native dashicons for a cohesive set. Both maps are filterable — that's how an
integration ships its plugin's icon, or how you override/remove any entry.

| Hook | Type | Signature | Purpose |
| --- | --- | --- | --- |
| `adminkit/menu_icons` | filter | `(array)` | Admin-menu map, **dashicon-class ⇒ SVG markup** (e.g. `'dashicons-admin-post' => '<svg…>'`). Return `''` for a key to skip it. |
| `adminkit/toolbar_icons` | filter | `(array)` | Admin-bar map, **node-id ⇒ SVG markup** (e.g. `'wp-admin-bar-comments' => '<svg…>'`). |
| `adminkit/toolbar_icon_ab_item_nodes` | filter | `(array)` | Map of **node-id ⇒ bool** marking toolbar nodes whose icon must be painted on `> .ab-item::before` instead of a child `.ab-icon` span. Set this for a node that renders a dashicon-font glyph or plain text rather than an `.ab-icon` child (core `edit`/`customize`, Bricks `edit_with_bricks`/`editor_mode`). A node not listed is assumed to carry an `.ab-icon`. |

Keyed by dashicon class / node id so only items still using a stock icon are
touched — a custom icon (Admin Menu Editor, a plugin's own image) is never
overridden. The SVG is painted via CSS `mask` + `currentColor` (no `!important`),
so it inherits the menu/toolbar colour. `inc/wp-core/class-menu-icons.php`.

Example — register a text-only toolbar node so AdminKit paints its glyph on the
link itself:

```php
add_filter( 'adminkit/toolbar_icon_ab_item_nodes', function ( $map ) {
    $map['wp-admin-bar-my-plugin'] = true;
    return $map;
} );
```

## Post previews

All in `inc/wp-core/class-post-previews.php`:

| Hook | Signature | Purpose |
| --- | --- | --- |
| `adminkit/post_previews/enabled` | `(bool)` | Master on/off for the preview column. |
| `adminkit/post_previews/provider` | `(string `mshots`\|`featured`)` | Screenshot source; the "Live screenshots" toggle forces `featured` when off. |
| `adminkit/post_previews/post_types` | `(string[])` | Which list tables get the column. |
| `adminkit/post_previews/thumb_size` / `full_size` | `(int[2] [w,h])` | Column thumbnail / hover sizes. |
| `adminkit/post_previews/refresh_interval` | `(int $seconds)` | Screenshot cache window (0 = pin). |
| `adminkit/post_previews/thumb_url` / `full_url` | `(string, WP_Post, $w, $h)` | Override the screenshot URL. |

## Lifecycle

| Hook | Type | Purpose |
| --- | --- | --- |
| `adminkit/loaded` | action | Fires after all modules + integrations init (`inc/class-plugin.php`). Late setup. |

## The integration contract

Subclassing `AdminKit_Integration_Base` is itself the main extension mechanism —
drop a folder under `inc/integrations/{plugins\|themes}/{slug}/` and it auto-loads.
The overridable methods (`slug`, `is_active`, `owns_screen`, `register_assets`,
`boot`, `host_version`, `max_tested_host_version`) are documented in
[INTEGRATIONS.md](INTEGRATIONS.md). New settings, dashboard widgets and provider
tokens are all added from an integration's `boot()` via the hooks above.

## Where to fix when a host changes

When `dev/adapter-drift.php` reports drift (a host renamed a variable/class or
rebranded — see [TOKENS.md → Drift detection](TOKENS.md#drift-detection-keeping-adapters-alive)),
the mapping lives in **external, per-adapter files** — no core edit:

1. **`inc/integrations/{type}/{slug}/css/admin.css`** — the host→token mapping. A
   removed Tier A variable or a renamed Tier B selector is fixed here.
2. **`owns_screen()` / `is_active()`** in the adapter class — if the host changed
   its screen id or version constant.
3. **`max_tested_host_version()`** — bump after re-verifying on a new host major
   (or let the gate fall back to the host's native UI).
4. **`baseline.json`** — re-freeze with `php dev/adapter-drift.php --slug={slug} --update`
   once reconciled.

For WP core itself, the same flow applies via `--wp-core`; the core CSS targets
live in `assets/css/wp-core/`, `wp-components/`, and `wp-screens/`.
