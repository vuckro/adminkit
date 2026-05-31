# AGENTS.md — working on AdminKit with an AI assistant

Short orientation for Claude Code (or any AI pair). The deep reference is
[CLAUDE.md](CLAUDE.md); the architecture is [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).
This file is the 60-second version: where things live, how to add the common
things, and the rules that keep changes safe.

## The mental model

AdminKit restyles wp-admin from CSS tokens (`--ak-*`) and adds a few optional
admin tools. Everything visual flows through one token layer, so dark mode and
brand theming come for free. PHP is organised by **intent**:

| Folder | Means | Example |
| --- | --- | --- |
| `inc/settings/` | The settings system (registry + SPA host) | `class-settings-page.php` |
| `inc/wp-core/` | Restyle a screen WordPress already renders | `class-chrome.php`, `class-login.php` |
| `inc/features/<name>/` | Add a NEW AdminKit surface (page, dashboard, store) | `features/stats/`, `features/menu/` |
| `inc/integrations/{plugins,themes}/<slug>/` | Skin a third-party plugin/theme | `integrations/themes/bricks/` |
| `assets/css`, `assets/js` | Styles + behaviour, organised by load context | `assets/css/wp-screens/` |
| `tokens/` | Source palettes → `php tokens/build.php` → `waaskit-tokens.css` | — |
| `dev/` | Tooling, never shipped (`.distignore`) | `dev/package.php` |

Rule of thumb: **restyling an existing WP screen → `wp-core/`. Adding something
new → `features/`.**

## How to add the common things

- **A feature module** → drop `inc/features/<name>/class-<name>.php` with a
  `public static function init()`, then add one `require_once` in `adminkit.php`
  and one `::init()` call in `inc/class-plugin.php` (boot order matters — see the
  ordered list there). Gate it on a setting: `AdminKit_Settings::register( '<name>_enabled', [ 'default' => true ] )`.
- **An integration (skin a plugin/theme)** → just create
  `inc/integrations/plugins/<slug>/class-<slug>.php` (class
  `AdminKit_Integration_<Slug>`). It's **auto-discovered** — no loader edit.
- **A setting / feature toggle** → `AdminKit_Settings::register()` + a row in
  `AdminKit_Settings_Catalog::features()` for the UI.
- **Per-screen CSS** → `assets/css/wp-screens/<name>.css` + a `register_screen()`
  line in `inc/wp-core/class-chrome.php`.
- **A token / colour** → edit `tokens/palettes/*.json`, run `php tokens/build.php`,
  commit both the JSON and the regenerated `waaskit-tokens.css`. Never hand-edit
  the generated CSS.

## Workflow

- **Main-only.** Work on `main`, commit in logical units, `git push`. Spin a
  short-lived branch only for risky work; fast-forward it back and delete it.
- **A pre-commit hook** rejects any commit with broken PHP (`php -l`),
  unparseable JS, or imbalanced CSS braces. Bypass (rarely) with `--no-verify`.
- **Docs are part of the change.** If you rename a file, add a setting, or change
  a hook, update the doc that describes it in the same commit.
- **i18n:** there is **no `msgfmt`/`xgettext`/`wp-cli` here** — only `php`. The
  `languages/` catalog is regenerated with a small PHP extract→`.po`→`.mo`
  pipeline (see [adminkit-i18n notes in CLAUDE.md]). `__()/_e()/esc_html__()`
  etc. with the `adminkit` text domain.

## Guardrails (don't break these)

- Every stylesheet reads `--ak-*` tokens, not raw colours (a few documented
  fixed-swatch exceptions aside). That indirection IS dark mode + theming.
- Dark mode is **design-time CSS only** — there is no runtime auto-theme engine.
- Don't bump the version (`adminkit.php` header + `ADMINKIT_VERSION` + the
  `readme.txt` stable tag) without the maintainer's go-ahead.
- Class name ↔ file name is 1:1 (`AdminKit_Custom_Dashboard` →
  `features/dashboard/class-custom-dashboard.php`). Keep it that way.

When in doubt, read [CLAUDE.md](CLAUDE.md) — it has the full task table and the
"why" behind the non-obvious choices.
