# Working on AdminKit

AdminKit is a standalone WordPress plugin without runtime Composer or npm dependencies.
Keep changes simple, explicit, predictable and compatible with PHP 7.4+ / WordPress 6.0+ (including SQLite / WordPress Playground and Multisite).

## Structure & Ownership

- **`adminkit.php` & `inc/class-plugin.php`**: `adminkit.php` hooks `AdminKit_Plugin::init()` onto `plugins_loaded` (priority `5`, with an idempotent immediate fallback if already fired) so standard plugins can attach `adminkit/*` filters before modules boot. `AdminKit_Plugin::MODULES` is the single module registry: it requires every file first, then runs non-null boot callables in registry order. Every `.php` file under `inc/` (outside `inc/integrations/`) must be registered in `MODULES` (`dev/check-modules.php` enforces this).
- **`inc/wp-core/`**: Enhances existing WordPress screens (`class-chrome.php`, `class-login.php`, `class-branding.php`, `class-menu-icons.php`, `class-profile-account.php`, `class-list-table-chrome.php`, `class-admin-bar.php`, `class-options-general.php`, `class-reading-layout.php`, `class-options-discussion.php`, `class-post-previews.php`, `class-user-quick-edit.php`, `class-username-changer.php`). Native fields, forms, capabilities, nonces and submission handlers remain owned by WordPress.
- **`inc/features/`**:
  - **Menu (`inc/features/menu/`)**: `AdminKit_Menu_Manager` (public facade, hooks, REST), `AdminKit_Menu_Config` (URL/item normalization, composite `parent::slug` keys, `seen_order`), `AdminKit_Menu_Snapshot` (pristine `$menu`/`$submenu` capture across all 5 AdminKit screens), `AdminKit_Menu_Renderer` (badge-preserving application, cross-parent `$_parent_pages`/`$_registered_pages` hook remapping with role fallback, custom icons/tooltips), and `AdminKit_Menu_Store` (InnoDB & SQLite transactional table storage).
  - **Toolbar (`inc/features/toolbar/`)**: `AdminKit_Toolbar_Manager` (public facade), `AdminKit_Toolbar_Config`, `AdminKit_Toolbar_Snapshot` (hash-deduplicated transient capture + `my-account` anonymization), and `AdminKit_Toolbar_Renderer` (`<head>` CSS hydration from saved config with zero FOUC).
  - **Notifications (`inc/features/notifications/`)**: `AdminKit_Notification_Center` (buffers notice hooks into `#adminkit-nc-origin`; `notification-center.js` moves non-feedback notices into an accessible side drawer with background `inert`).
- **`inc/integrations/{plugins,themes}/{slug}/`**: Auto-discovered via a memoized manifest in `AdminKit_Plugin`. `AdminKit_Integration_Base::maybe_init()` centrally enforces `host_within_tested_range()` (major/minor `version_compare`) before `register_assets()`, `boot()` and `print_menu_wordmark()`, and surfaces a *Skin paused* badge in *Design system → Plugins* when a host exceeds its tested range. Element Plus hosts share `AdminKit_Assets::ELEMENT_PLUS_HANDLE` (`assets/css/vendor/element-plus.css`); FunnelKit hosts share `AdminKit_FunnelKit_Integration` (`inc/integrations/trait-funnelkit-integration.php`).
- **`inc/class-assets.php` & `inc/class-design-system.php`**: Owns style registration, `enqueue_script()`, and `print_prepaint()` anti-FOUC inline scripts. Respects `adminkit/should_load` everywhere and emits **zero markup, scripts or CSS on anonymous public views** (`!is_admin_bar_showing()`). Remote Google Fonts are gated behind explicit opt-in (`remote_fonts_enabled`, default `false`) and never load on `wp-login.php`.
- **`inc/class-icons.php` & `inc/generated/lucide-paths.php`**: `AdminKit_Icons` validates custom SVGs (canonical XML via `DOMDocument::saveXML()`), Dashicon glyph tables, and reads the OPcache-compiled `inc/generated/lucide-paths.php` map (2,275 Lucide icons). The Icon Picker fetches the catalog lazily via `/adminkit/v1/icons` (`assets/icons/lucide.json`).
- **`inc/settings/` & `assets/js/settings/`**: `AdminKit_Settings` (schema), `AdminKit_Settings_Catalog`, `AdminKit_Settings_Gate` (memoized per-screen & cached `get_plugins()`), `AdminKit_Settings_I18n` (localized UI dictionary), and `AdminKit_Settings_Page` (5 native WP submenu pages: Overview, Features, Design system, Navigation, Configuration). When `AdminKitData.pages` is present, only the active page's JS factories and boot data slices are loaded; when `pages === null` (legacy/offline test host), the full payload is provided.
- **Tokens (`tokens/`)**: Source palettes live in `tokens/palettes/waaskit.json` and `tokens/adminkit.json`. Run `php tokens/build.php` to regenerate `assets/css/waaskit-tokens.css`, `assets/css/waaskit-toolbar-tokens.css` and `assets/css/tokens.css`. Never hand-edit generated CSS.

## Non-Negotiable Guardrails

- **Local environment**: Active branch `main`, live WordPress 7.1.4 site at `http://localhost:10013/` (`/Users/vucko/Local Sites/adminkit/app/public`, symlinked `wp-content/plugins/adminkit`, MySQL socket `/Users/vucko/Library/Application Support/Local/run/SIG0ugJnD/mysql/mysqld.sock`). Never wipe the WordPress core database (`wp_users`, `wp_posts`).
- **Versioning & migrations**: Keep `ADMINKIT_VERSION` at `1.0.0` unless a release bump is explicitly approved. **Upgrade 6 in `inc/class-upgrades.php` is frozen** (pinned by SHA-256 in `dev/check-upgrades.php`); add new numbered upgrades for schema/option changes (`STORAGE_VERSION` is currently `9`).
- **Design & CSS tokens**: Monochrome is the default accent; `adminkit` (WaasKit blue), `bricks` and `custom` are explicit choices. Never use raw `#hex` colors in component or adapter CSS (`dev/css-contract.php` and `dev/adapter-audit.php` enforce `--ak-*`, `--ak-on-accent`, `--ak-danger-solid`, `--ak-overlay`).
- **Logical CSS (RTL) & Accessibility (WCAG 2.2 AA)**:
  - Use CSS logical properties (`margin-inline-*`, `padding-inline-*`, `inset-inline-*`, `border-inline-*`, `text-align: start/end`) in `assets/css/**/*.css`. Stylelint (`dev/smoke/.stylelintrc.json`) rejects physical `margin-left`/`right` and `padding-left`/`right`.
  - Keep native `<dialog>` modals (`showModal()`) for pickers, `data-focus-key` focus restoration and parent `<select>` in the menu editor, explicit `#ak-username-unlock` button on profile screens, and `@media (forced-colors: active)` + `@media (prefers-reduced-motion: reduce)` support.
- **Security & capabilities**:
  - Hidden navigation is presentation, never access control. Restricted roles must not overwrite administrator menu ordering on Multisite.
  - Custom menu URLs reject `'`, `"`, `<`, `>`, `\r`, `\n`, `\0` (`AdminKit_Menu_Config::sanitize_menu_url`).
  - SVG uploads require `manage_options` + `unfiltered_html` (`adminkit_svg_upload` context on both `wp_handle_upload_prefilter` and `wp_handle_sideload_prefilter`) and canonical XML rewrite.
  - Username Changer requires `edit_user` + `edit_users` (filterable via `adminkit/username_changer/capability`), checks `validate_username()` and `illegal_user_logins`, prevents email/login collisions, and preserves sessions across double renames.
  - User Quick Edit preserves secondary roles on multi-role accounts and checks `promote_user` before role changes.
  - General Settings saves `logo_url` atomically via `adminkit_general_logo_url` without clobbering concurrent `adminkit_settings` keys.
- **GDPR & WP.org compliance (`dev/check-compliance.php`)**:
  - Never load `fonts.googleapis.com` unless `remote_fonts_enabled` is `true` (and never on `wp-login.php`). Legacy `'classic'` maps to `'geist'`.
  - Never hide third-party plugin action/meta links in `assets/css/wp-screens/plugins.css` (WP.org Guideline 11).

## Unified Quality Gate & Tooling

```sh
npm ci --prefix dev/smoke
php dev/test-all.php          # Full gate: 185 checks in ~7.5s
php dev/test-all.php --fast   # Skip heavy static analysis pass during rapid iteration
```

`php dev/test-all.php` runs in parallel:
1. PHP syntax (`php -l`, 101 files) & JS syntax (`node --check`, 43 files)
2. Token build drift (`php tokens/build.php --check`)
3. All 23 regression & compliance scripts (`dev/check-*.php`, `dev/editor-gate-check.php`)
4. CSS contracts & adapter budgets (`dev/css-contract.php`, `dev/adapter-audit.php`)
5. All 11 JSDOM behavioral suites (`dev/smoke/*.test.js`)
6. Static analysis: **WPCS Security & i18n** (`.phpcs.xml.dist`), **PHPStan level 5** (`phpstan.neon.dist`), **ESLint** (`dev/smoke/eslint.config.js`), and **Stylelint** (`dev/smoke/.stylelintrc.json`)

Live host drift and browser E2E checks:
```sh
php dev/adapter-drift.php --wp-core --host="/Users/vucko/Local Sites/adminkit/app/public"
php dev/adapter-drift.php --host="/Users/vucko/Local Sites/adminkit/app/public"
AK_URL=http://localhost:10013 npm run test:e2e --prefix dev/smoke
```

## i18n and Packaging

All UI copy originates in PHP (`'adminkit'` textdomain; `AdminKit_Settings_I18n` for the Settings SPA). `dev/i18n.php` uses `token_get_all()` to extract only `'adminkit'`-domain gettext calls, preserves `/* translators: */` comments, and supports `_n()` plurals and `_x()` contexts. `dev/check-i18n.php` enforces typographic apostrophes (`’`) and non-breaking spaces (`\u{00A0}`) before `:`, `;`, `?`, `!` and inside `« … »` in `languages/adminkit-fr_FR.po`.

```sh
php dev/i18n.php --missing
php dev/i18n.php --write
php dev/check-i18n.php
```

Commit `languages/adminkit.pot`, `adminkit-fr_FR.po`, `adminkit-fr_FR.mo` and `adminkit-fr_FR.l10n.php` together.

Package verification:
```sh
php dev/package.php --dry-run
php dev/package.php --working-tree --dry-run
php dev/package.php --zip=/tmp/adminkit.zip
```
Never run `php dev/package.php --target=...` against the live development symlink.

[Architecture](docs/ARCHITECTURE.md) · [Hooks](docs/EXTENDING.md) ·
[Installation](docs/INSTALL.md) · [Settings](docs/SETTINGS.md) ·
[Tokens](docs/TOKENS.md) · [Icons](docs/ICONS.md) · [Integrations](docs/INTEGRATIONS.md).
