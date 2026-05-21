# Settings registry

> A small registry that lets AdminKit (and any integration) declare typed configuration values backed by `wp_options['adminkit_settings']`. There is currently **no admin UI** — values flow in via the registered defaults, filter overrides, or future settings page writes.

---

## Public API

```php
AdminKit_Settings::register( $key, array $args = array() );
AdminKit_Settings::get( $key );
AdminKit_Settings::schema();
AdminKit_Settings::inline_tokens();
```

### `register( $key, $args )`

Declare a setting. Idempotent — last call wins for a given key. `$args` supports:

| Key        | Type    | Default | Meaning                                              |
| ---------- | ------- | ------- | ---------------------------------------------------- |
| `default`  | mixed   | `null`  | Returned when no stored value is present.            |
| `sanitize` | callable| `null`  | Reserved for the future settings UI's save handler. |

### `get( $key )`

Resolution order:

1. `wp_options['adminkit_settings'][$key]` (set by a future settings UI).
2. Schema default.
3. `adminkit/setting/{$key}` filter — always applied last so downstream code can override either of the above.

```php
$primary = AdminKit_Settings::get( 'primary_color' );
// → null on a fresh install
// → user's chosen hex when the future UI has saved one
// → filter result if anyone hooks adminkit/setting/primary_color
```

### `schema()`

Returns the full registered schema. The future settings UI will read this to know which fields to render.

### `inline_tokens()`

Builds the CSS string `:root { --ak-primary: <hex>; }` if `primary_color` is set to a valid hex. Returns an empty string otherwise — used internally by `apply_inline_tokens()` to skip a no-op `wp_add_inline_style` call.

---

## What's declared today

Only one setting:

```php
AdminKit_Settings::register( 'primary_color', array( 'default' => null ) );
```

It's wired in [`inc/class-settings.php`](../inc/class-settings.php) inside `init()`, called once from the plugin orchestrator.

When Bricks is active the `adminkit/extra_tokens_handle` filter already pipes Bricks's `style-manager.min.css` ahead of our tokens — so the primary color from the Bricks builder wins automatically. `primary_color` is the fallback for sites that don't use Bricks (and the value the future settings UI will write to).

---

## How the inline override reaches the page

1. `AdminKit_Settings::init()` calls `add_action( 'adminkit/tokens_enqueued', [__CLASS__, 'apply_inline_tokens'] )`.
2. `AdminKit_Assets::enqueue_tokens()` enqueues `adminkit-tokens`, then fires `adminkit/tokens_enqueued`.
3. `apply_inline_tokens()` calls `wp_add_inline_style( AdminKit_Assets::TOKENS_HANDLE, AdminKit_Settings::inline_tokens() )` — empty string is a no-op.

The override lands on every context that loads tokens (admin / login / frontend / editor), so the frontend admin bar and login page also pick up the user's chosen primary color.

---

## Adding a setting from an integration

```php
// In your integration's boot():
AdminKit_Settings::register( 'foo_density', array(
    'default' => 'comfortable',
) );

// Anywhere you need the value:
$density = AdminKit_Settings::get( 'foo_density' );
```

Override programmatically via filter (handy for environment-specific config):

```php
add_filter( 'adminkit/setting/foo_density', function ( $value ) {
    return defined( 'WP_DEBUG' ) && WP_DEBUG ? 'compact' : $value;
} );
```

---

## What's intentionally absent

- No settings page UI (yet). When that lands it will:
  - Read `AdminKit_Settings::schema()` to render fields.
  - Write to `wp_options['adminkit_settings']`.
  - Use the `sanitize` callable on save.
- No type system. The schema only tracks defaults today. Type/validation arrives with the UI.
- No multisite-aware persistence yet — `get_option`/`update_option` only.
