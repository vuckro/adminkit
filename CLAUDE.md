# Working on AdminKit

AdminKit is a standalone WordPress plugin. It styles native administration screens,
login and the frontend toolbar, and adds optional navigation and user tools.
It has no runtime dependencies and no npm or Composer build.

## Structure

- `adminkit.php` defines constants and delegates to `inc/class-plugin.php`.
- `AdminKit_Plugin::MODULES` is the ordered module registry. Add a module there.
- `inc/wp-core/` styles existing WordPress surfaces; `inc/features/` adds tools.
- `inc/integrations/{plugins,themes}/{slug}/class-{slug}.php` adapters are discovered
  automatically and extend `AdminKit_Integration_Base`.
- `inc/class-assets.php` owns asset registration, context gates and the token cascade.
- `inc/settings/` owns the schema, catalogs, permissions, settings route and UI host.
- `assets/js/settings.js` is the shared UI host. `settings/main.js`,
  `settings/navigation.js` load only on their matching pages.
  Styles use the same split under `assets/css/settings/`. Main tabs mount on demand.
- `tokens/palettes/waaskit.json` is the complete upstream WaasKit snapshot.
  `tokens/adminkit.json` maps roles; `tokens/appearance.css` owns custom-state CSS; `tokens/wordpress.css` maps native WordPress
  variables. `php tokens/build.php` generates all three token stylesheets.
- `dev/` contains PHP-only checks, adapter tooling and packaging; it never ships.

## Guardrails

- Follow the user's chosen branch and target. This rebuild currently uses
  `reprise/base-main-locale` and `http://localhost:10013/`; remote sites are separate.
  Never force-push or rewrite main. Stage explicit files and commit coherent units.
- Keep plugin classes and public `adminkit/*` hooks stable. Reference:
  [docs/EXTENDING.md](docs/EXTENDING.md).
- Keep version 1.0.0 until the maintainer explicitly approves a release bump.
- Monochrome WaasKit is the default preset; blue and custom are explicit.
- Every component consumes `--ak-*` roles. Accent ink uses `--ak-primary-text`,
  while filled controls use `--ak-primary` plus `--ak-on-accent`. The WaasKit bridge consumes `--wk-*`.
  Generated `tokens.css`, `waaskit-tokens.css` and `waaskit-toolbar-tokens.css` must
  be changed through their build-time sources, then regenerated.
- Dark mode is driven by AdminKit's existing document attribute. Do not add an
  automatic palette engine or import WaasKit's public component CSS into wp-admin.
- Preserve native WordPress behavior, capabilities and REST nonces. Sanitize input,
  escape output and use prepared SQL for values. Hidden menus do not revoke access.
- User-facing strings remain translatable with the `adminkit` domain. Refresh the
  bundled translation catalogs if strings change.
- Keep screen modules isolated: shared state and helpers arrive through the host's
  explicit context. Each screen returns tabs and, if editable, one save request.
- Keep comments about current constraints; history and abandoned designs belong in Git.
- Do not ship documentation, agent instructions, build sources or dependencies.
  The packager reads `.distignore` and defaults to `HEAD`, including on a branch.

## Checks

```sh
php -l changed-file.php
node --check changed-file.js
php tokens/build.php --check
php dev/check.php
php dev/css-contract.php
php dev/adapter-audit.php
php dev/adapter-drift.php
```

The drift report skips hosts that are not installed; a skip is not compatibility
validation. Browser-check changed screens in light/dark, at mobile width, and test
writes plus permission failures when changing UI persistence. Back up before replacing
an installed copy. Never reset the site's database to test a plugin change.

```sh
php dev/package.php --target=/path/to/wp-content/plugins
php dev/package.php --ref=HEAD --zip=/path/to/adminkit.zip
```

## References

[Architecture](docs/ARCHITECTURE.md) · [Installation](docs/INSTALL.md) ·
[Tokens](docs/TOKENS.md) · [WaasKit](docs/WAASKIT-DESIGN-SYSTEM.md) ·
[Integrations](docs/INTEGRATIONS.md) · [Extension API](docs/EXTENDING.md).
