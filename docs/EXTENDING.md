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

Both in `inc/class-theme-toggle.php`. AdminKit's toggle is **authoritative** — it
always flips its own attribute and persists, so dark mode works standalone with no
provider. When Bricks is active its adapter adds an **additive** bridge on top
(adopts Bricks's mode on load, then mirrors AdminKit's flips into Bricks via
`data-brx-theme` + `brx_mode`, guarded against loops); AdminKit never hands the
toggle over to the host.

## Branding

| Hook | Type | Signature | Purpose |
| --- | --- | --- | --- |
| `adminkit/brand_logo` | filter | `('' \| string \| array)` | Brand logo fallback when the Branding settings are empty. Return a URL string, or `array( 'light' => …, 'dark' => …, 'preloader' => … )`. Drives the brand mark at the site-name node **and** the Bricks builder; the settings win over the filter. |

Resolved by `AdminKit_Settings::brand_logo( $mode )`. Logos are normally set
no-code on the AdminKit Dashboard branding card, alongside the `wp_logo` mode for the
brand mark rendered at the site-name node (next to the site title; the top-left
WordPress logo is always hidden): `logo` (the brand logo), `favicon` (the site
icon). `logo` falls back to `favicon`, then to a bare site title when
nothing is set; `inc/wp-core/class-branding.php`. The login screen has its own
`login_logo` setting (`logo` | `favicon`), so it can show a square favicon while
the bar shows a wide logo, or
vice versa; resolved in `inc/wp-core/class-login.php`.

### Accent — and the WordPress palette baseline

The Design tab's source picker switches between **three personalities** for the
admin UI via the `accent_source` setting:

| Source | Accent emission | Result |
| --- | --- | --- |
| `'adminkit'` (default when Bricks is not active) | inline `<style>` with `#3858E9` (dual block, light + dark) | WordPress Block Editor blue across the admin in both modes. |
| `'bricks'` (default when Bricks is active) | none — Bricks's stylesheet cascades naturally | Bricks `--accent` in both modes. |
| `'custom'` | inline `<style>` with the user's `brand_accent` hex (dual block, light + dark) | AdminKit's WP-neutral palette + the user's accent. |

A `''` stored value resolves to "auto" at read time — `'bricks'` if Bricks is
active, `'adminkit'` otherwise — via `AdminKit_Settings::accent_source()`. Use
that helper everywhere; never read the raw setting directly.

#### Cascade order

Set in `AdminKit_Assets::enqueue_tokens()` via the `deps` array of each handle:

1. `assets/css/waaskit-tokens.css` (`adminkit-waaskit`, always loaded) — neutral safety net.
2. Provider tokens (Bricks's stylesheet) — only when source = `'bricks'` AND Bricks is detected.
3. `assets/css/wp-baseline.css` (`adminkit-wp-baseline`) — only when source ∈ {`'adminkit'`, `'custom'`}. Declares WP surfaces, borders, inks, status. Currently shadowed by tokens.css (same `:root{}` specificity, later load wins) — kept as intent-as-code; the visual diff vs. WaasKit's neutrals is imperceptible.
4. `assets/css/tokens.css` (`adminkit-tokens`) — the AdminKit consumer layer. Declares `--ak-*` in BOTH `:root{}` AND `:root[data-adminkit-theme="dark"]{}`.
5. Inline `<style id="ak-accent-preview">` — emitted by `AdminKit_Assets::inject_accent_family()` when source ∈ {`'adminkit'`, `'custom'`}. Dual block: `:root{}` + `:root[data-adminkit-theme="dark"]{}`.

Step 5 is the source of truth for `--ak-primary`, `--ak-primary-hover`,
`--ak-primary-subtle`, `--ak-on-accent`, and `--ak-focus`. It exists because
tokens.css's dark block (`(0,2,0)` specificity) would otherwise beat any
plain `:root{}` declaration on cascade-tie or specificity. The inline style
loads AFTER tokens.css and matches its dark selector specificity, so the
dual block wins everywhere — in both light and dark modes, for both
AdminKit-Blue and Custom.

#### Dual-block formula

Same family in both blocks; two formulas differ between light and dark:

| Token | `:root{}` (light) | `:root[data-adminkit-theme="dark"]{}` (dark) |
| --- | --- | --- |
| `--ak-primary` | `<hex>` | `<hex>` (same) |
| `--ak-primary-hover` | `color-mix(in srgb, <hex> 82%, #000)` | `color-mix(in srgb, <hex> 82%, #fff)` (lighten — readable on dark surfaces) |
| `--ak-primary-subtle` | `color-mix(in srgb, <hex> 12%, var(--ak-surface))` | `color-mix(in srgb, <hex> 22%, var(--ak-surface))` (bumped mix — reads on `#2c2c2c`) |
| `--ak-on-accent` | `contrast_text_for(<hex>)` | (same — mode-independent) |
| `--ak-focus` | `color-mix(in srgb, <hex> 27%, transparent)` | (same — translucent works both modes) |

The PHP emission must mirror the JS `applyPreview()` byte-for-byte so live
preview agrees with the post-save state. `bricks` source short-circuits both
(PHP early-returns, JS removes the `<style>` node).

#### Why `--ak-on-accent` is NOT `color-mix()`

Hover/subtle/focus derivatives follow `--ak-primary` via `color-mix()` chains
automatically — flip one knob, the family follows. But `--ak-on-accent` (the
foreground colour for text/icons on the accent fill) is computed from the
accent's WCAG relative luminance — `AdminKit_Assets::contrast_text_for()`
PHP side, `bestOnAccent()` JS side, byte-for-byte the same algorithm. Without
this, a near-black custom accent would leave white-on-black text invisible.

The constant `AdminKit_Assets::ADMINKIT_BLUE` holds the WP-Blue default —
change it there if you ever need to ship a different out-of-the-box accent.
The JS bootstrap exposes the same value as `D.adminkitBlue`.

## Icons

The "AdminKit icons" feature (`replace_icons_enabled`, on by default) swaps
WordPress's native dashicons for a cohesive set. Both maps are filterable — that's
how an integration ships its plugin's icon, or how you override/remove any entry.

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

A list-table screenshot column (mShots or featured image). The thumbnail re-captures
on demand: clicking an mShots thumbnail rotates its `v` cache key to force a fresh
capture and updates it in place. All hooks are in
`inc/wp-core/class-post-previews.php`:

| Hook | Signature | Purpose |
| --- | --- | --- |
| `adminkit/post_previews/enabled` | `(bool)` | Master on/off for the preview column. |
| `adminkit/post_previews/provider` | `(string `mshots`\|`featured`)` | Screenshot source; the "Live screenshots" toggle forces `featured` when off. |
| `adminkit/post_previews/post_types` | `(string[])` | Which list tables get the column. |
| `adminkit/post_previews/thumb_size` / `full_size` | `(int[2] [w,h])` | Column thumbnail / hover sizes. |
| `adminkit/post_previews/refresh_interval` | `(int $seconds)` | Screenshot cache window (0 = pin). |
| `adminkit/post_previews/thumb_url` / `full_url` | `(string, WP_Post, $w, $h)` | Override the screenshot URL. |

## Custom dashboard

A server-rendered replacement for the wp-admin dashboard (greeting + quick actions
+ stat tiles + recent activity + site-health + storage). Toggle off ⇒ the native
dashboard returns. Hooks in `inc/wp-core/class-custom-dashboard.php`:

| Hook | Signature | Purpose |
| --- | --- | --- |
| `adminkit/dashboard/enabled` | `(bool)` | Master on/off (mirrors the feature toggle). |
| `adminkit/dashboard/greetings` | `(string[], int $hour)` | Greeting templates (`%s` = the styled name span); a fresh one is picked per load. |
| `adminkit/dashboard/quotes` | `(string[])` | The rotating intro line under the greeting. |
| `adminkit/dashboard/quick_actions` | `(array)` | The quick-action buttons. |
| `adminkit/dashboard/stats` | `(array)` | The four stat tiles. |
| `adminkit/dashboard/activity` | `(array)` | Recent-activity rows. |
| `adminkit/dashboard/priorities` | `(array)` | The "priorities" / next-up list. |
| `adminkit/dashboard/site_health` | `(array)` | Site-health card (score, badge, checks). |
| `adminkit/dashboard/storage` | `(array)` | Storage segments — a host can add e.g. a backups row. |
| `adminkit/dashboard/storage_total` | `(int $bytes)` | Total quota in bytes (0 = no quota → no bar / percent). |

## Notifications

A toolbar bell that collects promotional notice "nags" into a right-side drawer,
leaving genuine notices (success, errors, interactive) inline. Client-side
categorization; toggle off ⇒ every notice renders inline. Hooks in
`inc/wp-core/class-notification-center.php`:

| Hook | Signature | Purpose |
| --- | --- | --- |
| `adminkit/notifications/enabled` | `(bool)` | Master on/off (mirrors the feature toggle). |
| `adminkit/notifications/js_allow` | `(string[])` | CSS selectors forced INTO the drawer. |
| `adminkit/notifications/js_deny` | `(string[])` | CSS selectors forced to stay inline. |

## Avatars

One setting (`custom_avatars_enabled`, on by default). With it on, AdminKit
registers **AdminKit Portraits (Generated)** in *Settings → Discussion → Default
Avatar* — the same dropdown WordPress already uses for Wavatar / Identicon /
Retro / MonsterID. Pick it there to give every user a unique generated portrait.

### When AdminKit serves a portrait — the cascade

The filter (`pre_get_avatar_data`) runs three checks, in order:

1. **`$args['url']` already populated** → another filter handled it (Simple
   Local Avatars, WP User Avatar, an OAuth login plugin saving a remote URL,
   etc.). AdminKit bails — never overrides another plugin's URL.
2. **Real Gravatar exists** — `HEAD gravatar.com/avatar/HASH?d=404`. Returns
   200 when the user has uploaded an avatar to Gravatar, 404 otherwise. The
   result is cached forever in the `adminkit_has_gravatar` user meta (`1` or
   `0`) and invalidated on `profile_update` so an email change re-checks.
   First render of a user does one HEAD with a 2s timeout (~200ms typical);
   every later render is a cache hit. → AdminKit bails when 200.
3. **Otherwise** → AdminKit sets `$args['url']` to the DiceBear portrait URL.

### Why `$args['url']` and not `$args['default']`

Setting `$args['default']` (the `d=` fallback) does NOT work — Gravatar proxies
the redirect through Photon (`i2.wp.com`), which **strips every query string**
from `d=`, including our per-user `seed=`. Every user would land on DiceBear's
default image. Setting `$args['url']` directly short-circuits Gravatar so the
seed survives intact.

### What's not here

- No profile-picture upload field. User uploads are Gravatar's job (or a
  dedicated plugin) — AdminKit owns the *generated portrait* slot only.
- No style / URL / palette filters. Constants are inlined (style `avataaars`,
  10-colour pastel palette as a solid backdrop). To swap, extend the class via
  a must-use plugin or open a hook here when a real need surfaces.

### Generator

[DiceBear](https://www.dicebear.com) hosted HTTP API (`api.dicebear.com`), style
`avataaars` (cartoon Memoji-like portraits with skin tones, hair, accessories
varied per seed). Each URL carries a non-PII seed (md5 of the user_login —
never the raw email) and `backgroundColor=` (a 10-colour palette DiceBear picks
one solid colour from per seed) — so every user reads as a distinct card.

Example — self-host the generated avatars instead of calling DiceBear:

```php
add_filter( 'adminkit/generated_avatar_url', function ( $url, $user_id, $size ) {
    return home_url( "/avatars/{$user_id}-{$size}.png" );
}, 10, 3 );
```

## Lifecycle

| Hook | Type | Purpose |
| --- | --- | --- |
| `adminkit/loaded` | action | Fires after all modules + integrations init (`inc/class-plugin.php`). Late setup. |
| `adminkit/username_changed` | action | Fires after the **Username changer** feature renames `user_login`. Args: `(int $user_id, string $old_login, string $new_login)`. Use to log the rename in an audit / activity log, or to sync external systems that key on `user_login`. |

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
