# Asset registry

> AdminKit ships its CSS as ~30 small files dispatched conditionally per context and per WP admin screen. Each file is declared once via `AdminKit_Assets::register()`; the dispatcher decides what loads where.

---

## Contexts

| Context    | WP hook                          | When it fires                                                |
| ---------- | -------------------------------- | ------------------------------------------------------------ |
| `admin`    | `admin_enqueue_scripts`          | Every wp-admin page load.                                    |
| `login`    | `login_enqueue_scripts`          | wp-login.php only.                                           |
| `frontend` | `wp_enqueue_scripts`             | Frontend, **only when an admin bar is showing**.             |
| `editor`   | `enqueue_block_editor_assets`    | Block / site / widgets / navigation editors.                 |

All four dispatchers run at priority `9999` (last) so AdminKit's CSS cascades after WP's own.

---

## Declaring an asset

```php
AdminKit_Assets::register( array(
    'handle'    => 'adminkit-themes',
    'src'       => 'assets/css/screens/themes.css',
    'deps'      => array( AdminKit_Assets::TOKENS_HANDLE ),
    'context'   => 'admin',
    'section'   => 'pages',     // optional — defaults to handle minus `adminkit-` prefix
    'condition' => static function ( $screen ) {
        return $screen && in_array( $screen->id, array( 'themes', 'theme-install' ), true );
    },
) );
```

| Field        | Required | Meaning                                                                                                  |
| ------------ | -------- | -------------------------------------------------------------------------------------------------------- |
| `handle`     | yes      | WP style handle, e.g. `adminkit-themes`.                                                                 |
| `src`        | yes      | Path **relative to ADMINKIT_PATH** (the plugin root). Lets integration files live outside `assets/css/`. |
| `deps`       | no       | Array of WP style handles this depends on. Usually `array( AdminKit_Assets::TOKENS_HANDLE )`.            |
| `context`    | no       | `admin` (default) \| `login` \| `frontend` \| `editor`.                                                  |
| `section`    | no       | Section name for the `adminkit/enqueue_{section}` back-compat filter. Defaults to handle minus prefix.   |
| `condition`  | no       | `null` (always-load) or `callable(WP_Screen|null): bool` — return true to enqueue.                      |

Every `register()` call adds an entry. Multiple entries with the same handle in the same context are allowed (`wp_enqueue_style` is idempotent on the handle).

---

## Three gates per asset

When a context dispatches, each registered entry is asked three questions in order:

1. **`adminkit/enqueue_{section}` filter** — back-compat per-section bail (e.g. `adminkit/enqueue_forms` skips all three of `adminkit-inputs` / `adminkit-buttons` / `adminkit-tables` because all three declare `section => 'forms'`).
2. **`adminkit/enqueue_{handle}` filter** — per-asset bail (1.1+; e.g. `adminkit/enqueue_adminkit-themes`).
3. **`condition` closure** — receives the current `WP_Screen` (or `null` outside admin) and returns a boolean.

If any of the three returns false, the asset doesn't enqueue.

---

## Where AdminKit's own assets are registered

| File                                       | What it registers                                                                  |
| ------------------------------------------ | ---------------------------------------------------------------------------------- |
| [`inc/core/class-chrome.php`](../inc/core/class-chrome.php)             | All admin-context CSS — core chrome, components, screens, third-party adapters.    |
| [`inc/core/class-login.php`](../inc/core/class-login.php)               | `login.css` in the `login` context.                                                |
| [`inc/integrations/gutenberg/class-gutenberg.php`](../inc/integrations/gutenberg/class-gutenberg.php) | `editor.css` + `dark-mode-map.css` in the `editor` context.                       |
| Other integrations                                                       | Each integration registers its own CSS via `register_assets()`.                    |

The dispatcher itself (`AdminKit_Assets::init()`) only wires the four hooks. It does not know about any specific asset.

---

## File layout

```
assets/css/
├── tokens.css                  # always loaded (foundation)
├── core/                       # always loaded in admin
│   ├── chrome.css              # sidebar, postboxes, notices, footer, tabs
│   ├── links.css               # global link colors
│   └── adminbar.css            # #wpadminbar (admin + frontend)
├── components/                 # always loaded in admin (legacy section: forms)
│   ├── inputs.css              # text inputs, textarea, select, checkbox, radio
│   ├── buttons.css             # .button, .button-primary, file selector, sizes
│   ├── tables.css              # .wp-list-table, tablenav, Quick Edit
│   └── form-table.css          # .form-table layout (settings + profile + taxonomy forms)
├── screens/                    # per-screen conditional (legacy section: pages)
│   ├── themes.css              # themes.php + theme-install.php
│   ├── theme-install.css       # theme installer + full-page preview
│   ├── media.css               # upload.php + media modal
│   ├── profile.css             # profile.php + user-edit.php + user-new.php
│   ├── nav-menus.css           # nav-menus.php
│   ├── plugins.css             # plugins.php + plugin-install.php + plugin info modal
│   ├── plugin-editor.css       # plugin-editor.php
│   ├── code-mirror.css         # CodeMirror dark theme (plugin-editor + theme-editor)
│   ├── update-core.css         # update-core.php
│   ├── import.css              # import.php
│   ├── site-health.css         # site-health.php + options-privacy.php + privacy-policy-guide.php
│   ├── wp-components.css       # @wordpress/components React UI — broad usage, always-loaded
│   ├── wpds.css                # WordPress Design System — small, always-loaded
│   ├── font-library.css        # Font Library page — small, always-loaded
│   └── _shared/                # small components used across several screens, always-loaded
│       ├── wp-filter.css       # `.wp-filter` bar (media / themes / plugins)
│       ├── thickbox.css        # ThickBox modal shell
│       ├── notification-dialog.css  # post-lock, file-editor warning, filesystem creds
│       └── cards.css           # generic `.card` (tools, settings)
└── login.css                   # wp-login.php

inc/integrations/{slug}/css/    # integration-specific CSS, registered by each integration class
                                # (Bricks admin pages, Gutenberg editor, Admin Menu Editor, ...)
```

---

## Adding a new screen-specific file

1. Drop the CSS at `assets/css/screens/{name}.css`.
2. Add a `self::register_screen( '{name}', array( '{screen-id}', ... ) )` line in [`inc/core/class-chrome.php`](../inc/core/class-chrome.php) — done.

The helper wraps the registry call with the right `section => 'pages'` legacy filter and a `condition` closure that matches the given screen IDs.

---

## Dynamic CSS injection

The `adminkit/tokens_enqueued` action fires right after `adminkit-tokens` is enqueued for a given context. Hook here with `wp_add_inline_style( AdminKit_Assets::TOKENS_HANDLE, $css )` to inject runtime CSS variable overrides. `AdminKit_Settings::apply_inline_tokens()` uses this to apply the user's chosen primary color — see [SETTINGS.md](SETTINGS.md).
