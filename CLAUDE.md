# AdminKit — guide for AI assistants

AdminKit restyles wp-admin, wp-login, and the frontend admin bar through a
CSS-custom-property (`--ak-*`) token system. It is **standalone** (ships a
complete look with no dependencies) and gains brand colours from an optional
**provider** (Bricks today). Read this first, then the one doc that matches the task.

## Map — where things live

```
adminkit.php            Loader: defines constants, requires inc/, calls AdminKit_Plugin::init().
inc/
  class-plugin.php       Boot orchestrator. Boots core modules, then auto-discovers
                         integrations via glob( inc/integrations/*/*/class-*.php ).
  class-assets.php       Asset registry + dispatcher + the token cascade (enqueue_tokens);
                         enqueue_script() for the JS bricks.
  class-screen.php       WP_Screen helpers.
  class-settings.php     Settings registry (register/get/schema) + color_map() (display taxonomy).
  class-settings-page.php Settings SPA (admin menu) + REST save + providers() list.
  class-theme-toggle.php  Dark/light toggle + login logo. Owns the pre-paint inline script.
  class-dashboard.php     Dashboard widget registry (dormant until an integration registers one).
  wp-core/                AdminKit's restyle of WP-core surfaces (chrome, login, profile…).
  integrations/
    abstract-integration.php   AdminKit_Integration_Base.
    plugins/{slug}/            Plugin adapters (acf, woocommerce, …) — class + css/ + baseline.json.
    themes/{slug}/             Theme adapters (bricks).
assets/css/
  tokens.css            The --ak-* layer. ALWAYS loaded. Owns the dark-mode flip.
  waaskit-tokens.css    GENERATED WaasKit baseline (do not hand-edit).
  wp-core/ wp-components/ wp-screens/   AdminKit's own CSS, registered by wp-core/class-chrome.php.
assets/js/
  settings.js           Settings SPA.
  wp-core/*.js          Footer behaviour bricks (profile-account, post-previews, list-table-chrome).
tokens/                 Build-time source for the baseline (palettes/*.json + build.php).
dev/                    Dev tooling (TRACKED, excluded from the dist zip): css-scan.php (shared
                        parser), adapter-scan.php, adapter-audit.php, adapter-drift.php, baselines/.
docs/                   Deep-dive guides (see "More docs" below).
```

## Common tasks

| Task | Do this | Doc |
| --- | --- | --- |
| Add an integration (skin a plugin/theme) | `php dev/adapter-scan.php ../{host} --slug={slug} --emit`, fill TODOs, fine-tune css, then audit + drift | [docs/INTEGRATIONS.md](docs/INTEGRATIONS.md) |
| Add per-screen CSS | Add `assets/css/wp-screens/{name}.css`, register via `self::register_screen()` in `inc/wp-core/class-chrome.php` | [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) |
| Add a JS behaviour | New `assets/js/wp-core/{name}.js`, enqueue via `AdminKit_Assets::enqueue_script()` | [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) |
| Change a baseline token | Edit `tokens/palettes/*.json`, run `php tokens/build.php`, commit JSON + regenerated CSS | [docs/TOKENS.md](docs/TOKENS.md) |
| Detect host / WP-core CSS changes | `php dev/adapter-drift.php` (per adapter) or `--wp-core` | [docs/TOKENS.md](docs/TOKENS.md#drift-detection-keeping-adapters-alive) |
| Add a setting / hook into AdminKit | `AdminKit_Settings::register()`; the filters/actions | [docs/EXTENDING.md](docs/EXTENDING.md) |
| Understand the whole system | — | [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) |

## Guardrails — do NOT break these

- **`assets/css/waaskit-tokens.css` is GENERATED.** Never hand-edit it. Change
  `tokens/palettes/*.json` and run `php tokens/build.php`. `--check` is a drift gate.
- **Every AdminKit stylesheet reads `--ak-*` tokens, never raw colours.** That
  indirection powers dark mode and provider theming. Keep it.
- **Integration discovery is `glob( inc/integrations/*/*/class-*.php )`** — two
  levels deep (`{plugins,themes}/{slug}/`). The class name derives from the file
  basename (`AdminKit_Integration_{Studly_Slug}`). Don't rename a class without
  renaming its file to match, or it silently never loads.
- **Assets are cache-busted by `filemtime`** — editing a CSS/JS file is enough.
- **JS behaviour lives in `assets/js/*.js`, enqueued via `enqueue_script()`** — don't
  print inline scripts from PHP. The ONE exception is the theme pre-paint bootstrap
  in `class-theme-toggle.php`: it must stay inline in `<head>` to avoid FOUC.
- **Dev tooling lives in `dev/` (tracked) and is excluded from the dist zip via
  `.distignore`.** `tokens/build.php` stays in `tokens/` (next to its palettes).
  Don't point docs at `.claude/` — that's local-only and gitignored.
- **The Tokens settings tab is a read-only reference.** There is no per-token colour
  editor; the palette is driven by the provider/baseline cascade. Don't re-add the
  removed editing machinery.
- **The token layers are each optional** (provider → baseline → neutral). Don't
  hard-require any one of them. See ARCHITECTURE.
- **Class names are stable public-ish API** (`AdminKit_*`). Folder reorg keeps them.

## Verify a change

- Lint PHP: `php -l <file>`.
- Token drift gate: `php tokens/build.php --check`.
- Adapter CSS-debt: `php dev/adapter-audit.php` (Tier A = 0 `!important`).
- Host/WP CSS drift: `php dev/adapter-drift.php`.
- UI: reload any wp-admin page (CSS/JS auto-bust via mtime) and check light + dark.

## More docs

[README.md](README.md) (overview + extension API) · [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)
(bootstrap, asset registry, settings) · [docs/INTEGRATIONS.md](docs/INTEGRATIONS.md)
(contract + walkthrough + patterns) · [docs/TOKENS.md](docs/TOKENS.md) (token map,
build, drift, alignment) · [docs/EXTENDING.md](docs/EXTENDING.md) (every hook) ·
[docs/WAASKIT-DESIGN-SYSTEM.md](docs/WAASKIT-DESIGN-SYSTEM.md) (the locked WaasKit spec).
