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
| `adminkit/should_load` | filter | `(bool, string $context)` | Master switch for a context (`admin`/`login`/`frontend`/`editor`). Return false to disable all AdminKit styling there — integrations use it to bypass a host's full-screen UI. |
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

## Post previews

All in `inc/wp-core/class-post-previews.php`:

| Hook | Signature | Purpose |
| --- | --- | --- |
| `adminkit/post_previews/enabled` | `(bool)` | Master on/off for the preview column. |
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
