# AGENTS.md — Quick Orientation

Short orientation for AI coding assistants (Claude, Antigravity, Cursor, Codex). Complete engineering rules and invariants live in [CLAUDE.md](CLAUDE.md); full system design lives in [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Mental Model

AdminKit skins `wp-admin`, `wp-login.php` and the logged-in toolbar through `--ak-*` CSS tokens and adds opt-in workflow tools with **zero runtime Composer/npm dependencies** (PHP 8.3–8.5 / WordPress 6.0+ / SQLite & InnoDB / Multisite compatible) and **zero public frontend footprint** (`0` bytes emitted for anonymous visitors).

| Path | Responsibility | Key Files |
| --- | --- | --- |
| `inc/class-plugin.php`; data-only `LAZY_MODULES` | Ordered `MODULES` + `ADMIN_ONLY_MODULES` registries & generated `integrations-manifest.php`; boots on `plugins_loaded` (priority 5) | `adminkit.php`, `inc/class-plugin.php`, `inc/generated/integrations-manifest.php` |
| `inc/settings/` | Schema (`class-settings.php`), catalog, memoized gate (`class-settings-gate.php`), i18n dictionary (`class-settings-i18n.php`), one settings SPA (`GET /adminkit/v1/icons`) and hidden legacy URLs | `class-settings-page.php` |
| `inc/wp-core/` | Restyle or enhance native WordPress screens while keeping WP form/nonce/capability ownership | `class-chrome.php`, `class-branding.php`, `class-profile-account.php`, `class-user-quick-edit.php`, `class-username-changer.php` |
| `inc/features/menu/` | `AdminKit_Menu_Manager` facade + `Config`, `Snapshot`, `Renderer`, `Store` | `class-menu-manager.php`, `class-menu-renderer.php` |
| `inc/features/toolbar/` | `AdminKit_Toolbar_Manager` facade + `Config`, `Snapshot`, `Renderer` (`<head>` icon CSS) | `class-toolbar-manager.php`, `class-toolbar-renderer.php` |
| `inc/features/notifications/` | Output-buffered admin notice collector + accessible side drawer (`inert` background) | `class-notification-center.php` |
| `inc/integrations/{plugins,themes}/<slug>/` | 28 manifest-indexed host adapters (`maybe_init()` enforces `host_within_tested_range()`, `bails_core_forms()`, `menu_icon()`) | `abstract-integration.php`, `trait-funnelkit-integration.php`, `inc/class-fluent-theme.php` |
| `assets/css/`, `assets/js/` | Logical-property (RTL-ready) token stylesheets (`vendor/element-plus.css` shared bridge) + build-free ES5 modules | `assets/js/settings/`, `assets/css/wp-core/` |
| `tokens/` & `inc/generated/` | Source palettes (`php tokens/build.php`), OPcache Lucide path map (`lucide-paths.php`) & integration manifest (`integrations-manifest.php`) | `tokens/build.php`, `dev/build-integrations-manifest.php`, `inc/class-icons.php` |
| `dev/` | Parallel quality gate (`php dev/test-all.php`), `dev/checks/` regression scripts, static analysis, JSDOM & Playwright suites (excluded via `.distignore`) | `dev/test-all.php`, `dev/check-compliance.php` |

## Common Workflows

- **Add/split an `inc/` PHP file** → register it in `AdminKit_Plugin::MODULES` (`inc/class-plugin.php`; `null` boot for helpers; add admin-only entries to `ADMIN_ONLY_MODULES`).
- **Add/split an integration adapter** → create `inc/integrations/{plugins|themes}/{slug}/class-{slug}.php` and regenerate `php dev/build-integrations-manifest.php`.
- **Add/split a JS module** → wire `deps` in `AdminKit_Settings_Page` or `AdminKit_Assets::enqueue_script()`, and update `dev/smoke/*.test.js` + `dev/checks/check-navigation-assets.php`.
- **Skin an Element Plus or FunnelKit plugin** → depend on `AdminKit_Assets::ELEMENT_PLUS_HANDLE` (`assets/css/vendor/element-plus.css`) or `use AdminKit_FunnelKit_Integration`.
- **Add/edit UI strings** → localize in PHP (`'adminkit'` domain; use `_n()` for plurals and `_x()` for gendered/ambiguous terms), translate in `languages/adminkit-fr_FR.po` (typographic `’` and NBSP `\u{00A0}` before `: ; ? !` and inside `« … »`), and run `php dev/i18n.php --write && php dev/check-i18n.php`.
- **Edit design tokens** → edit `tokens/*` and run `php tokens/build.php`; never hand-edit generated files under `assets/css/waaskit-*.css` or `inc/generated/*`.

## Non-Negotiable Guardrails

1. **Always run `php dev/test-all.php`** (PHP/JS syntax, token & manifest/icon drift, 33 regression/compliance checks, CSS/adapter contracts, 15 JSDOM suites, WPCS, PHPStan level 5, ESLint, Stylelint) before committing.
2. **Zero raw hex colors** in component/adapter CSS (use `--ak-*`, `--ak-on-accent`, `--ak-danger-solid`, `--ak-overlay`) and **zero physical directional spacing** (`margin-left`/`right`, `padding-left`/`right`) in `assets/css/**/*.css` (use `margin-inline-*`, `padding-inline-*`).
3. **GDPR & WP.org compliance**: Google Fonts stay disabled unless `remote_fonts_enabled` is opted in (and never load on `wp-login.php`); third-party plugin links in `plugins.php` are never hidden.
4. **Frozen invariants**: Keep `ADMINKIT_VERSION` at `1.0.0-beta.1` unless instructed, and never edit frozen `Upgrade 6` in `inc/class-upgrades.php`.
5. **Local live environment**: `http://localhost:10013/` (`/Users/vucko/Local Sites/adminkit/app/public`, symlinked to this repo, MySQL socket `/Users/vucko/Library/Application Support/Local/run/SIG0ugJnD/mysql/mysqld.sock`).
