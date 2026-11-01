# AGENTS.md — Quick Orientation

Short orientation for AI coding assistants. Deep engineering rules live in [CLAUDE.md](CLAUDE.md); full architecture lives in [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Mental Model

AdminKit skins `wp-admin` through `--ak-*` CSS tokens and adds opt-in workflow tools with **zero runtime Composer/npm dependencies** (PHP 7.4+ / WordPress 6.0+ / SQLite & InnoDB compatible) and **zero public frontend footprint** for anonymous visitors.

| Path | Responsibility | Example |
| --- | --- | --- |
| `inc/settings/` | Schema, catalog, i18n dictionary (`class-settings-i18n.php`), gates, 5 native WP submenu pages | `class-settings-page.php` |
| `inc/wp-core/` | Restyle or enhance existing WordPress screens | `class-chrome.php`, `class-profile-account.php` |
| `inc/features/{menu,toolbar,notifications}/` | Facade (`Manager`) + registered helpers (`Config`, `Snapshot`, `Renderer`, `Store`) | `class-menu-manager.php` |
| `inc/integrations/{plugins,themes}/<slug>/` | Auto-discovered host skins (`AdminKit_Integration_<Slug>`, version-gated in `maybe_init()`) | `plugins/fluent-booking/` |
| `assets/css/`, `assets/js/` | Token-driven stylesheets (`vendor/element-plus.css` for Vue/Element Plus) + build-free JS modules | `assets/js/settings/` |
| `tokens/` & `inc/generated/` | Source palettes (`php tokens/build.php`) & OPcache icon map (`lucide-paths.php`) | — |
| `dev/` | Offline test gate (`php dev/test-all.php`), drift/audit scripts, smoke suite (excluded via `.distignore`) | `dev/test-all.php` |

## Common Tasks

- **Add/split an `inc/` PHP file** → register it in `AdminKit_Plugin::MODULES` (`inc/class-plugin.php`; `null` boot for helpers). Boot runs on `plugins_loaded` (priority 5).
- **Add/split a JS module** → wire `deps` in `AdminKit_Settings_Page` / `AdminKit_Assets::enqueue_script()` and keep `dev/smoke/*.test.js` + `dev/check-navigation-assets.php` aligned.
- **Skin an Element Plus / FunnelKit plugin** → depend on `AdminKit_Assets::ELEMENT_PLUS_HANDLE` (`assets/css/vendor/element-plus.css`) or `use AdminKit_FunnelKit_Integration`.
- **Add UI strings** → localize in PHP (`adminkit` domain), add `fr_FR` translations in `languages/adminkit-fr_FR.po`, and run `php dev/i18n.php --write`.
- **Edit tokens** → edit `tokens/*` and run `php tokens/build.php`; never hand-edit generated CSS or `inc/generated/lucide-paths.php`.

## Non-Negotiable Guardrails

- Run `php dev/test-all.php` before every commit; keep docs aligned in the same commit.
- Never use raw hex colors in component/adapter CSS (consume `--ak-*`, `--ak-on-accent`, `--ak-danger-solid`, `--ak-overlay`).
- Keep `ADMINKIT_VERSION` at `1.0.0` unless explicitly instructed, and never modify frozen `Upgrade 6` in `inc/class-upgrades.php`.
- Read [CLAUDE.md](CLAUDE.md) for all persistence, security, migration, and packaging invariants.
