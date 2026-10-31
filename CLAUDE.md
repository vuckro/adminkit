# Working on AdminKit

AdminKit is a WordPress plugin without runtime Composer/npm dependencies.
Keep changes simple, explicit and compatible with PHP 7.4+/WordPress 6.0+.

## Structure

- `adminkit.php` delegates to `AdminKit_Plugin::MODULES`. Files load first; boot
  callables run second. Add helpers/modules there; keep public class names stable.
- `inc/wp-core/` enhances existing screens; `inc/features/` adds tools. Native
  fields, forms, capabilities, nonces and handlers remain owned by WordPress.
- Adapter files auto-discover under `inc/integrations/{plugins,themes}/{slug}/`.
  Their setting keys register early in Settings::init; host labels resolve later.
- `inc/class-assets.php` owns style registration and the script helper. Respect
  `adminkit/should_load` for markup, behavior and asset loading, including vetoed
  native user-tool writes. Do not enqueue native-module styles directly.
- `AdminKit_Icons` owns shared menu/toolbar icon validation and masks. Keep the
  compatibility constant/hooks; do not copy the allowlist or SVG parser.
- `assets/js/settings.js` hosts per-scope revisioned drafts. Save layouts before
  settings; clear only unchanged revisions and never reload away newer edits.
  Factories receive explicit context; runtime JS stays build-free. Navigation
  has separate controls/menu/toolbar factories; its host only routes panes and
  delegates payloads. Update enqueue dependencies and smoke loaders when adding
  a settings part.
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
