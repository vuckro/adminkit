# AdminKit — guide for AI assistants

AdminKit restyles wp-admin, wp-login, and the frontend admin bar through a
CSS-custom-property (`--ak-*`) token system. It is **standalone** (ships a
complete look with no dependencies) and gains brand colours from an optional
**provider** (Bricks today). Read this first, then the doc that matches the task.

## Map — where things live

```
adminkit.php            Loader: defines constants, requires inc/, calls AdminKit_Plugin::init().
inc/
  class-plugin.php       Boot orchestrator. Boots core modules, then auto-discovers
                         integrations via glob( inc/integrations/*/*/class-*.php ).
  class-assets.php       Asset registry + dispatcher + the token cascade (enqueue_tokens).
  class-screen.php       WP_Screen helpers.
  class-settings.php     Settings registry + color_map() (the token catalogue).
  class-settings-page.php Settings SPA (admin menu) + REST save + providers() list.
  class-theme-toggle.php  Dark/light toggle + login logo.
  class-dashboard.php     Dashboard widget registry (dormant).
  wp-core/                AdminKit's restyle of WP-core surfaces (chrome, login, profile…).
  integrations/
    abstract-integration.php   AdminKit_Integration_Base.
    plugins/{slug}/            Plugin adapters (acf, woocommerce, …).
    themes/{slug}/             Theme adapters (bricks).
assets/css/
  tokens.css            The --ak-* layer. ALWAYS loaded. Owns the dark-mode flip.
  waaskit-tokens.css    GENERATED WaasKit baseline (do not hand-edit).
  wp-core/ wp-components/ wp-screens/   AdminKit's own CSS, loaded by wp-core/class-chrome.php.
tokens/                 Build-time source for the baseline (palettes/*.json + build.php).
docs/                   Deep-dive guides (see "More docs" below).
```

## Common tasks

| Task | Do this | Doc |
| --- | --- | --- |
| Add an integration (skin a plugin/theme) | Drop `inc/integrations/{plugins\|themes}/{slug}/class-{slug}.php` extending `AdminKit_Integration_Base` | [docs/INTEGRATIONS.md](docs/INTEGRATIONS.md), [docs/ADD-AN-INTEGRATION.md](docs/ADD-AN-INTEGRATION.md) |
| Add per-screen CSS | Add `assets/css/wp-screens/{name}.css`, register via `self::register_screen()` in `inc/wp-core/class-chrome.php` | [docs/ASSETS.md](docs/ASSETS.md) |
| Register any CSS (context/screen) | `AdminKit_Assets::register([...])` | [docs/ASSETS.md](docs/ASSETS.md) |
| Change a baseline token | Edit `tokens/palettes/*.json`, run `php tokens/build.php`, commit JSON + regenerated CSS | [tokens/README.md](tokens/README.md), [docs/TOKENS.md](docs/TOKENS.md) |
| Add a setting | `AdminKit_Settings::register()` | [docs/SETTINGS.md](docs/SETTINGS.md) |
| Understand the whole system | — | [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) |

## Guardrails — do NOT break these

- **`assets/css/waaskit-tokens.css` is GENERATED.** Never hand-edit it. Change
  `tokens/palettes/*.json` and run `php tokens/build.php`. `--check` is a drift gate.
- **Every AdminKit stylesheet reads `--ak-*` tokens, never raw colours.** That
  indirection is what powers dark mode and provider theming. Keep it.
- **Integration discovery is `glob( inc/integrations/*/*/class-*.php )`** — two
  levels deep (`{plugins,themes}/{slug}/`). The class name derives from the file
  basename (`AdminKit_Integration_{Studly_Slug}`), so the folder grouping is
  organizational only. Don't rename a class without renaming its file to match.
- **Assets are cache-busted by `filemtime`** — editing a CSS file is enough; no
  version bump needed.
- **Class names are stable public-ish API** (`AdminKit_*`). Folder reorg keeps
  them; don't churn them.
- **The token layers are each optional** (provider → baseline → neutral). Don't
  hard-require any one of them. See ARCHITECTURE.

## Verify a change

- Lint PHP: `php -l <file>`.
- Token drift: `php tokens/build.php --check`.
- Adapter CSS-debt: `php dev/adapter-audit.php`.
- UI: reload any wp-admin page (CSS auto-busts via mtime) and check light + dark.

## More docs

[README.md](README.md) (overview + extension API) · [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) · [docs/ASSETS.md](docs/ASSETS.md) · [docs/INTEGRATIONS.md](docs/INTEGRATIONS.md) · [docs/ADD-AN-INTEGRATION.md](docs/ADD-AN-INTEGRATION.md) · [docs/SETTINGS.md](docs/SETTINGS.md) · [docs/TOKENS.md](docs/TOKENS.md) · [tokens/README.md](tokens/README.md)
