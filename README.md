# AdminKit

A standalone WordPress plugin for a consistent administration interface, using the
current [WaasKit design system](https://design.waaskit.site/), with monochrome branding by default. WordPress 6.0+,
PHP 7.4+. No runtime dependencies, no npm or Composer build.

## Features

- Native administration screens, login and toolbar styled in light and dark.
- Geist fonts served locally; semantic OKLCH colors and visible keyboard focus.
- Brand logos, custom accent colors and import/export of settings.
- Optional notification center and signed-in user tools.
- Menu and toolbar editors with ordering, icons, renaming and visibility controls.
- Dedicated plugin/theme adapters, loaded only when the corresponding host is active.
  [Integration reference](docs/INTEGRATIONS.md).

Lucide icons are part of the default administration skin. Navigation editors, notifications and detected adapters are enabled by default.
User quick edit, username changing, post previews and active-items-first are opt-in.
Adjust these tools in the AdminKit settings. The menu table is created only when its feature is enabled. Hiding a menu does not change
WordPress permissions. The frontend toolbar is scoped so the site's public design
remains owned by its theme.

## Install

Copy a packaged `adminkit/` into `wp-content/plugins/` and activate **AdminKit**.
For development, clone the repository and package the checked-out revision:

```sh
git clone https://github.com/vuckro/adminkit.git
cd adminkit
php dev/package.php --target=/path/to/wp-content/plugins
```

The installer replaces only the `adminkit` directory. Back up an existing copy
first. [Full installation and packaging guide](docs/INSTALL.md).

## Development

The bootstrap registry is `inc/class-plugin.php`. Native screen work belongs in
`inc/wp-core/`; new tools in `inc/features/`; adapters in `inc/integrations/`.
Components consume `--ak-*` roles rather than hardcoded colors.

The settings UI has a small host and separate main/navigation modules.
Only the current screen's script and styles load; main tabs mount when opened.
WaasKit's complete source snapshot stays in Git while generated CSS includes only
variables required by AdminKit.

```sh
npm ci --prefix dev/smoke
php dev/test-all.php
```

The unified gate includes syntax, all regression scripts, CSS/token contracts,
i18n drift, module/package integrity and jsdom tests. Browser-check
affected screens and persistence before deploying. Host drift checks require the
corresponding third-party plugin/theme to be installed.

## Documentation

- [Architecture](docs/ARCHITECTURE.md)
- [Tokens and generation](docs/TOKENS.md)
- [WaasKit design reference](docs/WAASKIT-DESIGN-SYSTEM.md)
- [Integrations](docs/INTEGRATIONS.md)
- [Extension API and hooks](docs/EXTENDING.md)
- [Contributor guide](CLAUDE.md)

## Roadmap

This section is the roadmap reference.

- **Recently shipped** — menu manager (reorder · per-item icons · hide entries) and a matching toolbar manager (reorder + hide the admin bar's top-level items, left / right sides); notification center (a toolbar bell that collects promotional notice nags into a side drawer); hide admin footer; a mobile-responsive pass over the chrome + list tables.
- **In progress** — broader plugin coverage: more hand-tuned dark-mode adapters so the long tail of plugins looks right out of the box.
- **Next** — more native screens styled; in-app palette editor; colour sync; more provider adapters; accessibility / contrast checks; per-role visibility; admin-bar polish.
- **Planned** — command palette (⌘K); theme variants; per-user theme preference; white-label; custom admin CSS; density / compact mode; typography controls; Bricks dynamic logo tag.

## License

GPL-2.0-or-later. Geist font files retain their bundled SIL OFL licenses.

Bricks builder restyling is not included.
