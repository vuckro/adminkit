# Writing an integration

> An **integration** is a small adapter that makes AdminKit cooperate with another plugin or theme — Bricks today, Gutenberg, WooCommerce, the Fluent suite, Slim SEO tomorrow.
>
> It's optional code. AdminKit ships standalone and looks good without any integration. Removing an integration folder removes its support cleanly.

---

## Conventions

```
inc/integrations/{slug}/class-{slug}.php
    └─ class AdminKit_Integration_{Slug} extends AdminKit_Integration_Base
```

| Folder + file                              | Class name                          |
| ------------------------------------------ | ----------------------------------- |
| `bricks/class-bricks.php`                  | `AdminKit_Integration_Bricks`       |
| `gutenberg/class-gutenberg.php`            | `AdminKit_Integration_Gutenberg`    |
| `woocommerce/class-woocommerce.php`        | `AdminKit_Integration_Woocommerce`  |
| `fluentcart/class-fluentcart.php`          | `AdminKit_Integration_Fluentcart`   |
| `fluentform/class-fluentform.php`          | `AdminKit_Integration_Fluentform`   |
| `slim-seo/class-slim-seo.php`              | `AdminKit_Integration_Slim_Seo`     |

The boot orchestrator in [`inc/class-plugin.php`](../inc/class-plugin.php) globs every `inc/integrations/*/class-*.php`, derives the class name, and queues `maybe_init()` on `after_setup_theme`. **No edits to the loader are needed** — drop the folder and it's live.

`after_setup_theme` runs after the active theme's `functions.php`, so host constants (`BRICKS_VERSION`, `WC_VERSION`, …) are reliably available at detection time.

CSS for the integration lives next to the PHP at `inc/integrations/{slug}/css/`. The asset registry can load files from there directly (path is relative to the plugin root).

---

## The minimum integration

```php
<?php
/**
 * Foo integration — short description of what this adapter does.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Foo extends AdminKit_Integration_Base {

    public static function slug() {
        return 'foo';
    }

    public static function is_active() {
        return defined( 'FOO_VERSION' );
    }
}
```

That's the whole contract. If `is_active()` returns false, the integration silently does nothing — `maybe_init()` short-circuits before any other method runs.

---

## Lifecycle methods

Override what you need; the base class no-ops the rest.

| Method                        | Purpose                                                                              |
| ----------------------------- | ------------------------------------------------------------------------------------ |
| `slug()`                      | REQUIRED — short identifier used in handles and log lines.                          |
| `is_active()`                 | REQUIRED — host detection.                                                          |
| `register_assets()`           | Declare CSS via `AdminKit_Assets::register()` — see [ASSETS.md](ASSETS.md).         |
| `boot()`                      | Wire any non-asset filters/actions.                                                  |
| `owns_screen( $screen )`      | Return true on screens the integration cares about; used inside `condition` closures.|

`maybe_init()` is the public entry point. It calls `is_active → register_assets → boot` in that order and is implemented by the base class — subclasses should not override it.

---

## What integrations typically do

Pick what you need; ignore the rest.

### Inject design tokens

Pipe a third-party CSS file as a dependency of `adminkit-tokens`. Use this when the host already defines design variables you want AdminKit to inherit.

```php
protected static function boot() {
    add_filter( 'adminkit/extra_tokens_handle', array( __CLASS__, 'provide_tokens' ), 10, 2 );
}

public static function provide_tokens( $handle, $context ) {
    wp_enqueue_style( 'my-host-tokens', /* url */, array(), /* version */ );
    return 'my-host-tokens';
}
```

The [Bricks adapter](../inc/integrations/bricks/class-bricks.php) is the canonical example — it pipes Bricks' generated `style-manager.min.css`.

### Bypass restyling in a host's UI

Some hosts ship full-screen UIs that AdminKit's chrome would clash with (Bricks Builder, Fluent CRM dashboards, …). Short-circuit with `adminkit/should_load`:

```php
protected static function boot() {
    add_filter( 'adminkit/should_load', array( __CLASS__, 'bypass' ), 10, 2 );
}

public static function bypass( $should_load, $context ) {
    if ( 'admin' === $context && self::is_inside_host_ui() ) {
        return false;
    }
    return $should_load;
}
```

### Ship host-specific CSS overrides

When the host's own admin UI needs visual harmonization (rounded buttons, themed accents…), register CSS through the asset registry so it inherits the design system and only loads on the right screens.

**Convention:** integration CSS lives at `inc/integrations/{slug}/css/`.

```php
public static function register_assets() {
    AdminKit_Assets::register( array(
        'handle'    => 'adminkit-foo',
        'src'       => 'inc/integrations/foo/css/admin.css',
        'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
        'context'   => 'admin',
        'condition' => static function ( $screen ) {
            return $screen && 'foo' === $screen->parent_base;
        },
    ) );
}
```

See [ASSETS.md](ASSETS.md) for the full registry API.

### Register a dashboard widget

Use `AdminKit_Dashboard::register_widget()` to surface host-specific stats on the WP dashboard. The registry only mounts `wp_dashboard_setup` if at least one widget is registered, so the cost on a vanilla site is zero.

```php
protected static function boot() {
    AdminKit_Dashboard::register_widget(
        'adminkit-foo-stats',
        __( 'Foo — today\'s activity', 'adminkit' ),
        array( __CLASS__, 'render_stats' )
    );
}

public static function render_stats() {
    // echo your widget content
}
```

### Add per-host filters and toggles

Integrations can also expose their own filters for downstream customization. Namespace them under the host: `adminkit/foo/some_option`, not `my_foo_some_option`.

---

## Anti-patterns

- **No third-party JS feedback loops.** A MutationObserver bridging two attributes (e.g. AdminKit's `data-adminkit-theme` and Bricks' `data-brx-theme`) caused a runtime loop that broke the host's interactivity in an earlier iteration. If two systems need to sync, do it one-way at load time and let the user re-click to override.
- **No hard dependencies on the host.** `class_exists()` / `defined()` checks must live in `is_active()`. Removing the host plugin must not break AdminKit.
- **No global state mutation outside `boot()`.** Hooks are wired only when active; class constants are fine; static caches are fine; modifying global options or DB rows from an integration is not.
- **Don't load CSS unconditionally.** Always scope via a `condition` closure. A site running the Fluent plugin globally would otherwise pay the cost on every page.

---

## Reference implementations

- [`inc/integrations/bricks/class-bricks.php`](../inc/integrations/bricks/class-bricks.php) — Bricks adapter, covers token injection + builder bypass.
- [`inc/integrations/gutenberg/class-gutenberg.php`](../inc/integrations/gutenberg/class-gutenberg.php) — block editor restyle, hooks the `editor` asset context.
- The `woocommerce`, `fluentcart`, `fluentform`, and `slim-seo` folders ship empty stubs — flip `is_active()` and fill in the CSS to enable.
