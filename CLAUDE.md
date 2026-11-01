# Working on AdminKit

AdminKit is a WordPress plugin without runtime Composer/npm dependencies.
Keep changes simple, explicit and compatible with PHP 7.4+/WordPress 6.0+.

## Structure

- `adminkit.php` hooks `AdminKit_Plugin::init()` onto `plugins_loaded` (priority 5)
  so standard plugins can attach `adminkit/*` filters before modules boot. `MODULES`
  requires every file first, then runs non-null boot callables in registry order.
- `inc/wp-core/` enhances existing screens; `inc/features/` adds tools. Native
  fields, forms, capabilities, nonces and handlers remain owned by WordPress.
- Adapter files auto-discover via a memoized manifest under
  `inc/integrations/{plugins,themes}/{slug}/`. `maybe_init()` enforces
  `host_within_tested_range()` centrally for assets, `boot()` and wordmarks. Element Plus
  hosts share `AdminKit_Assets::ELEMENT_PLUS_HANDLE` (`assets/css/vendor/element-plus.css`);
  FunnelKit hosts share `AdminKit_FunnelKit_Integration`.
- `inc/class-assets.php` owns style registration, `enqueue_script()`, and the shared
  `print_prepaint()` anti-FOUC helper. Respect `adminkit/should_load` across markup,
  behavior and asset loading, and emit zero markup/CSS on anonymous public views.
- `AdminKit_Icons` owns shared menu/toolbar icon validation, Dashicon glyph tables,
  and the OPcache-backed `inc/generated/lucide-paths.php` map (served lazily via
  `/adminkit/v1/icons` to the icon picker).
- `assets/js/settings.js` hosts per-scope revisioned drafts across the five native
  submenu pages (and legacy full-payload hosts), loading only the scripts/data each
  screen needs (`design-colors.js`, `navigation-menu-model.js`, `profile-account-dom.js`
  loaded via explicit `deps`). `AdminKit_Settings_I18n` owns localized UI strings.
- Menu and Toolbar PHP keep stable public facades on `AdminKit_Menu_Manager` and
  `AdminKit_Toolbar_Manager`; `*_Config`, `*_Snapshot` and `*_Renderer` helpers are
  registered with `null` boot entries in `MODULES`. Keep existing hook priorities,
  option/cache keys and REST envelopes on refactors.
- Tokens originate in `tokens/`; generated styles consume the `--ak-*` contract.
  Run the builder rather than editing generated CSS. Keep public toolbar CSS scoped.

## Guardrails

- Current branch/target: `reprise/base-main-locale`, `localhost:10013`. Preserve
  ongoing user work. Do not rewrite main, force-push or change remote sites.
- Keep version 1.0.0 unless a release bump is explicitly approved.
- Monochrome defaults; blue/custom/provider choices are explicit. No automatic
  theme engine, global reset or extra per-screen appearance persistence.
- Never mutate persisted scales just by mounting controls. Preview/source activation
  must match saved CSS; invalid text cannot overwrite a validated accent.
- Upgrade 6 is frozen. Add new versioned migrations; preserve explicit choices,
  retry data and unrelated options. Upgrade 7 owns legacy chrome consolidation.
- Hidden navigation is not access control. Keep capabilities and nonce checks.
  In particular restricted menu views must not rewrite administrator ordering.
- Settings normal saves preserve omitted keys; export includes all active schema
  keys. Import validates the format marker and tolerates unknown/obsolete keys.
- Deactivation preserves data. Uninstall removes only owned current/historical
  namespaces, including network transients; native sessions/preferences survive.
- Keep documentation and hook contracts aligned with the implementation. Stage
  explicit files and commit each validated unit without unrelated changes.

## Unified checks

Development needs PHP CLI with DOM/libxml, Node, Git, gettext and rsync:

```sh
npm ci --prefix dev/smoke
php dev/test-all.php
```

This is also the CI command. It runs PHP/JS lint, token drift, every check script,
editor gating, CSS contracts, adapter budgets, jsdom, i18n, MODULES integrity and
HEAD/working-tree package dry-runs. No required gate silently skips. Host drift and
real browser checks remain separate; a skipped host is not validation.

Browser-check changed workflows in light/dark and mobile, including save/reload
and permission/nonce failure paths when persistence changes. Use isolated scratch
storage/accounts for destructive tests; never reset a site's database.

## i18n and packaging

PHP owns translated copy passed to JS. Add all strings with the `adminkit` domain,
translate missing French PO entries, then generate both compiled catalog formats:

```sh
php dev/i18n.php --missing
php dev/i18n.php --write
php dev/check-i18n.php
```

Commit POT, PO, MO and l10n.php together; do not edit compiled catalogs manually.
The checker rejects missing/dead strings, stale compiled output and raw JS copy.

`php dev/package.php --dry-run` packages committed HEAD; add `--working-tree`
when testing uncommitted changes. `--target` replaces the target `adminkit` folder,
so back up first and never use it on the development symlink. Development sources,
docs, dependencies and agent instructions never ship.

[Architecture](docs/ARCHITECTURE.md) · [Hooks](docs/EXTENDING.md) ·
[Installation](docs/INSTALL.md) · [Settings](docs/SETTINGS.md) ·
[Tokens](docs/TOKENS.md) · [Integrations](docs/INTEGRATIONS.md).
