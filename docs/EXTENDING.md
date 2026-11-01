# Extension points

Keep public class names and namespaced hooks stable. The implementations under
`inc/` are authoritative; this page describes the currently supported hooks.

## Loading and assets

| Hook | Signature after the value, or action arguments | Contract |
| --- | --- | --- |
| `adminkit/loaded` | action, no arguments | Fires on `plugins_loaded` (priority 5) once module hooks are wired and integrations queued for `after_setup_theme` |
| `adminkit/should_load` | filter `(bool $load, string $context)` | Master context veto; also honored by native markup/behavior callbacks |
| `adminkit/enqueue_{context}` | filter `(bool)` | Context asset gate |
| `adminkit/enqueue_{section}` | filter `(bool)` | Registered stylesheet section gate |
| `adminkit/enqueue_{handle}` | filter `(bool)` | Registered stylesheet handle gate |
| `adminkit/enqueue_baseline` | filter `(bool, string $context)` | WaasKit baseline token loading |
| `adminkit/extra_tokens_handle` | filter `(string|null, string $context)` | An enqueued provider handle to depend on |
| `adminkit/tokens_enqueued` | action `(string $context)` | Add inline token overrides to `AdminKit_Assets::TOKENS_HANDLE` |
| `adminkit/enqueued_{context}` | action, no arguments | Context dispatch completed |

Contexts are `admin`, `login`, `frontend`, `editor`, `customize`. Frontend dispatch
requires the toolbar. `register()` declares CSS with `handle`, relative `src`,
`deps`, `context`, optional `section` and `condition($screen)`. Its per-entry gate
order is section, handle, condition. Script helpers check the master/context gates.

```php
AdminKit_Assets::register( array(
    'handle' => 'adminkit-example',
    'src' => 'assets/css/wp-screens/example.css',
    'deps' => array( AdminKit_Assets::TOKENS_HANDLE ),
    'context' => 'admin',
    'section' => 'pages',
    'condition' => static function ( $screen ) {
        return $screen && 'example' === $screen->id;
    },
) );
```

Use `AdminKit_Assets::enqueue_script()` for runtime JS and its localized `before`
bootstrap. Native modules must gate their controls/prepaint/behavior too: skipping
only a stylesheet can leave enhanced markup or invisible controls behind.

## Settings, integrations and branding

| Hook | Signature | Contract |
| --- | --- | --- |
| `adminkit/setting/{key}` | `(mixed $value)` | Final effective setting after stored/default normalization |
| `adminkit/integration_enabled` | `(bool, string $slug)` | Host adapter gate; the native Gutenberg chrome remains part of the base skin |
| `adminkit/brand_logo` | `(string|array $fallback)` | Used only without `logo_url`; a URL or light/dark URL map |
| `adminkit/theme_attribute` | `(string)` | Document attribute, default `data-adminkit-theme` |
| `adminkit/theme_storage_key` | `(string)` | Preference storage key, default `adminkit-theme` |

Declare settings with `AdminKit_Settings::register($key, $args)` and add feature
rows to the catalog when appropriate. Schema entries declare default/type/group
and a sanitizer. Integration toggles register early from adapter filenames; do
not register them again while rendering/saving a page. Host inventory labels
resolve later, after translations and hosts are available.

A feature row may include `options`, an array of `{key, label, desc}` entries for
nested toggles. Optional translated `onLabel` and `offLabel` strings describe the
boolean choice in the UI; absent labels default to Enabled/Disabled. For example,
a hide-footer option uses Hidden/Visible. These labels do not change boolean
storage, switch semantics or runtime gating. Disabled nested controls prefix the
retained label with Choice: and explain the parent pause separately.

Register each key with type `toggle`, a boolean default and
`rest_sanitize_boolean` before rendering; unregistered/non-toggle entries are
excluded from boot data. The settings host includes their drafts in normal
save/import/export. The consuming module must combine its parent gate with each
option; presentation alone never gates runtime behavior. Do not reuse retired
migration keys for new sub-options.

Adapters live in `inc/integrations/{plugins,themes}/{slug}/class-{slug}.php`, extend
`AdminKit_Integration_Base` and follow its `AdminKit_Integration_{Slug}` class
convention. Keep `label()`, `host_files()`, `is_active()`, `owns_screen()` and asset
registration with the adapter rather than central plugin lists. The orchestrator
auto-discovers them. See [INTEGRATIONS.md](INTEGRATIONS.md).

`logo_url` is edited in General Settings, shared with login and automatic toolbar
branding. Monochrome is the default accent; `adminkit` is explicit WaasKit blue,
`bricks` uses provider variables and `custom` activates saved hex overrides.
`AdminKit_Design_System::css()` compiles validated foundations. Do not add a second
palette engine, unscoped frontend CSS or per-screen appearance persistence.

## Feature gates and backward compatibility

| Hook | Signature | Contract |
| --- | --- | --- |
| `adminkit/clean_chrome/enabled` | `(bool)` | Unified footer/help baseline |
| `adminkit/hide_footer/enabled` | `(bool)` | Historical per-footer override, applied after master and footer sub-option |
| `adminkit/hide_help_button/enabled` | `(bool)` | Historical per-help override, applied after master and help sub-option |
| `adminkit/active_items_first/enabled` | `(bool)` | Shared content/plugin priority feature |
| `adminkit/plugins_active_first/enabled` | `(bool)` | Additional plugin-list gate |
| `adminkit/menu_manager/enabled` | `(bool)` | Menu editor application/hooks/routes |
| `adminkit/toolbar_manager/enabled` | `(bool)` | Toolbar editor application/hooks/routes |
| `adminkit/toolbar/capability` | `(string)` | Toolbar edit capability, default `manage_options` |
| `adminkit/toolbar/protected_nodes` | `(string[])` | Nodes that cannot be hidden; native or `wp-admin-bar-` prefixed IDs |
| `adminkit/username_changer/capability` | `(string)` | Capability required alongside `edit_user` to rename a login, default `edit_users` |
| `adminkit/username_changed` | action `(int $user_id, string $old, string $new)` | A validated rename actually succeeded |

Upgrade 7 converts old chrome options into the unified setting and removes their
stored keys. Their filters remain separate and effective, including an explicit
false veto or true override. The immutable upgrade-6 block must not be edited;
new persistent transformations need a new versioned migration and failure tests.

## Icons

| Hook | Value shape |
| --- | --- |
| `adminkit/menu_icons` | Dashicon class → SVG map |
| `adminkit/menu_slug_icons` | Menu slug → SVG map |
| `adminkit/toolbar_icons` | Toolbar DOM node ID → SVG map |
| `adminkit/toolbar_icon_ab_item_nodes` | Toolbar DOM node ID → bool map for icons on the anchor itself |

The bundled Lucide names and role aliases are stable. Shared menu/toolbar custom
input goes through `AdminKit_Icons::sanitize_icon()` and `icon_mask()`. The curated
`WP_DASHICONS` now belongs to Icons; `AdminKit_Menu_Manager::WP_DASHICONS` remains
a compatibility alias. Safe vector SVGs become canonical data masks; styles,
external references, events and embedded content are rejected, including old
stored values at output time. PHP DOM is required to validate custom SVG input.

## Notices and previews

| Hook | Signature | Purpose |
| --- | --- | --- |
| `adminkit/notifications/enabled` | `(bool)` | Notification drawer gate |
| `adminkit/notifications/js_allow` | `(string[])` | Selectors explicitly grouped into the drawer |
| `adminkit/notifications/js_deny` | `(string[])` | Selectors retained inline |
| `adminkit/post_previews/enabled` | `(bool)` | Preview feature gate |
| `adminkit/post_previews/provider` | `(string)` | Default `featured`; `mshots` explicitly enables screenshots |
| `adminkit/post_previews/post_types` | `(string[])` | Target content lists |
| `adminkit/post_previews/thumb_size` | `(int[2])` | Thumbnail dimensions, default 160×120 |
| `adminkit/post_previews/full_size` | `(int[2])` | Large preview dimensions, default 1200×800 |
| `adminkit/post_previews/refresh_interval` | `(int $seconds)` | Screenshot refresh interval; 0 pins it |
| `adminkit/post_previews/thumb_url` | `(string $url, WP_Post $post, int $w, int $h)` | Thumbnail URL override |
| `adminkit/post_previews/full_url` | `(string $url, WP_Post $post, int $w, int $h)` | Large preview URL override |

Notice authors can use `data-ak-nc-group` or `data-ak-nc-keep`; node movement
preserves native handlers. Inline action confirmations stay with their forms.

## Verification

Run `php dev/test-all.php` after installing `dev/smoke` dependencies. For UI copy,
add translated PHP bootstrap values, update French PO entries, then run
`php dev/i18n.php --write`. The checker rejects dead/missing catalog entries,
stale compiled MO/PHP output and unlocalized JS UI literals. Browser-test affected
screens and native submission behavior; the offline suite does not replace host
or account/session integration checks.
