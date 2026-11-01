# AGENTS.md — Quick Orientation

Short orientation for AI coding assistants (Claude, Antigravity, Cursor, Codex). Complete engineering rules and invariants live in [CLAUDE.md](CLAUDE.md); full system design lives in [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Mental Model

AdminKit skins `wp-admin`, `wp-login.php` and the logged-in toolbar through `--ak-*` CSS tokens and adds opt-in workflow tools with **zero runtime Composer/npm dependencies** (PHP 7.4–8.4 / WordPress 6.0+ / SQLite & InnoDB / Multisite compatible) and **zero public frontend footprint** (`0` bytes emitted for anonymous visitors).

| Path | Responsibility | Key Files |
| --- | --- | --- |
| `inc/class-plugin.php` | Ordered `MODULES` registry + memoized integration manifest; boots on `plugins_loaded` (priority 5) | `adminkit.php`, `inc/class-plugin.php` |
| `inc/settings/` | Schema (`class-settings.php`), catalog, memoized gate (`class-settings-gate.php`), i18n dictionary (`class-settings-i18n.php`), 5 native WP submenu pages | `class-settings-page.php` |
| `inc/wp-core/` | Restyle or enhance native WordPress screens while keeping WP form/nonce/capability ownership | `class-chrome.php`, `class-branding.php`, `class-profile-account.php`, `class-user-quick-edit.php`, `class-username-changer.php` |
| `inc/features/menu/` | `AdminKit_Menu_Manager` facade + `Config`, `Snapshot`, `Renderer`, `Store` | `class-menu-manager.php`, `class-menu-renderer.php` |
| `inc/features/toolbar/` | `AdminKit_Toolbar_Manager` facade + `Config`, `Snapshot`, `Renderer` (`<head>` icon CSS) | `class-toolbar-manager.php`, `class-toolbar-renderer.php` |
| `inc/features/notifications/` | Output-buffered admin notice collector + accessible side drawer (`inert` background) | `class-notification-center.php` |
| `inc/integrations/{plugins,themes}/<slug>/` | 23 auto-discovered host adapters (`maybe_init()` enforces `host_within_tested_range()`) | `abstract-integration.php`, `trait-funnelkit-integration.php` |
| `assets/css/`, `assets/js/` | Logical-property (RTL-ready) token stylesheets (`vendor/element-plus.css` shared bridge) + build-free ES5 modules | `assets/js/settings/`, `assets/css/wp-core/` |
| `tokens/` & `inc/generated/` | Source palettes (`php tokens/build.php`) & OPcache Lucide path map (`lucide-paths.php`) | `tokens/build.php`, `inc/class-icons.php` |
| `dev/` | Parallel quality gate (`php dev/test-all.php`, 185 checks), static analysis, JSDOM & Playwright suites (excluded via `.distignore`) | `dev/test-all.php`, `dev/check-compliance.php` |

## Common Workflows

- **Add/split an `inc/` PHP file** → register it in `AdminKit_Plugin::MODULES` (`inc/class-plugin.php`; `null` boot for helpers).
- **Add/split a JS module** → wire `deps` in `AdminKit_Settings_Page` or `AdminKit_Assets::enqueue_script()`, and update `dev/smoke/*.test.js` + `dev/check-navigation-assets.php`.
- **Skin an Element Plus or FunnelKit plugin** → depend on `AdminKit_Assets::ELEMENT_PLUS_HANDLE` (`assets/css/vendor/element-plus.css`) or `use AdminKit_FunnelKit_Integration`.
- **Add/edit UI strings** → localize in PHP (`'adminkit'` domain; use `_n()` for plurals and `_x()` for gendered/ambiguous terms), translate in `languages/adminkit-fr_FR.po` (typographic `’` and NBSP `\u{00A0}` before `: ; ? !` and inside `« … »`), and run `php dev/i18n.php --write && php dev/check-i18n.php`.
- **Edit design tokens** → edit `tokens/*` and run `php tokens/build.php`; never hand-edit generated CSS or `inc/generated/lucide-paths.php`.

## Non-Negotiable Guardrails

1. **Always run `php dev/test-all.php`** (185 checks: PHP/JS syntax, token drift, 23 regression/compliance checks, CSS/adapter contracts, 11 JSDOM suites, WPCS, PHPStan level 5, ESLint, Stylelint) before committing.
2. **Zero raw hex colors** in component/adapter CSS (use `--ak-*`, `--ak-on-accent`, `--ak-danger-solid`, `--ak-overlay`) and **zero physical directional spacing** (`margin-left`/`right`, `padding-left`/`right`) in `assets/css/**/*.css` (use `margin-inline-*`, `padding-inline-*`).
3. **GDPR & WP.org compliance**: Google Fonts stay disabled unless `remote_fonts_enabled` is opted in (and never load on `wp-login.php`); third-party plugin links in `plugins.php` are never hidden.
4. **Frozen invariants**: Keep `ADMINKIT_VERSION` at `1.0.0` unless instructed, and never edit frozen `Upgrade 6` in `inc/class-upgrades.php`.
5. **Local live environment**: `http://localhost:10013/` (`/Users/vucko/Local Sites/adminkit/app/public`, symlinked to this repo, MySQL socket `/Users/vucko/Library/Application Support/Local/run/SIG0ugJnD/mysql/mysqld.sock`).
