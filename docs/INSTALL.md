# Installing and validating AdminKit

AdminKit requires WordPress 6.0+ and PHP 7.4+. It ships its generated assets and
fonts without runtime npm/Composer dependencies. PHP DOM is needed for custom SVG
validation. The plugin version remains 1.0.0 until an approved release bump.

## Install a packaged copy

Install an archive produced by `dev/package.php` through Plugins → Add New →
Upload Plugin, or copy its `adminkit/` directory to `wp-content/plugins/` and
activate it. Open AdminKit for Design system, Features and Navigation. Branding's
single logo is edited under WordPress General Settings.

Branding, icons, theme toggle, clean chrome, navigation editors,
notifications and detected adapters default on. Login styling, User Quick Edit, Username Changer,
post previews and active-items-first are opt-in. Hiding navigation is presentation,
not permission control. Settings are per-site; dark/light preference is personal.

Activation does not create statistics storage. The menu table is provisioned
lazily when its enabled module runs in administration or a menu save needs it.
Versioned upgrades run for an administrator and retain their retry state on
failure. Deactivation preserves data; deletion removes current/historical AdminKit
storage and caches. Back up before replacing or deleting an installed copy.

## Develop on the active checkout

The current local target is `main` and `http://localhost:10013` (`/Users/vucko/Local Sites/adminkit/app/public`).
Remote sites and release branches are separate targets. A symlink from
`wp-content/plugins/adminkit` to this checkout makes edits live immediately;
otherwise package the working tree explicitly. Cache busting uses filemtime.

Development prerequisites: PHP CLI with DOM/libxml, Node (24 for the CI workflow),
Git, gettext tools (`xgettext`, `msgfmt`) and rsync. Install JS test dependencies:

```sh
npm ci --prefix dev/smoke
php dev/test-all.php
```

The command runs PHP/JS syntax, token drift, every `dev/check*.php` (23 suites
including compliance/GDPR/security), the editor regression, CSS/adapter budgets,
all 11 jsdom suites, and static analysis (WPCS, PHPStan level 5, ESLint, Stylelint).
Registry checks verify boot callables and runtime files, plus both HEAD and
working-tree dry-runs. It fails if a test/dependency is unavailable; it does not
silently skip a gate. The GitHub workflow (`.github/workflows/ci.yml`) runs this
same command across PHP 7.4–8.4 and WordPress 6.0 / latest.

Host-dependent and browser checks remain additional:

```sh
php dev/adapter-drift.php --wp-core --host="/Users/vucko/Local Sites/adminkit/app/public"
php dev/adapter-drift.php --host="/Users/vucko/Local Sites/adminkit/app/public"
AK_URL=http://localhost:10013 npm run test:e2e --prefix dev/smoke
```

Authenticated smoke tests need `AK_USER` and `AK_PASS` in the environment; do not
commit or print credentials. A skipped host/authenticated test is not compatibility
or persistence validation. Test affected screens in light/dark and mobile widths,
actual save/reload, capability and REST-nonce failures as appropriate. Use isolated
accounts/storage for destructive failure tests; do not reset the site database.

## Translation changes

UI copy originates in PHP and is passed to JS via localized bootstraps. Product
names and technical examples are explicit exceptions. To synchronize:

```sh
php dev/i18n.php --missing
# Translate the missing msgids in languages/adminkit-fr_FR.po.
php dev/i18n.php --write
php dev/check-i18n.php
```

The write step extracts current strings, removes dead entries, regenerates POT/PO,
compiles MO with `msgfmt` and writes `adminkit-fr_FR.l10n.php`. Commit all four
artifacts together. `--check` verifies them without modifying the checkout.

## Package the intended revision

Packaging defaults to committed `HEAD`, including on feature branches:

```sh
php dev/package.php --dry-run
php dev/package.php --working-tree --dry-run
php dev/package.php --working-tree --zip=/tmp/adminkit-local.zip
php dev/package.php --ref=HEAD --zip=/tmp/adminkit-reviewed.zip
```

`.distignore` removes development dependencies/tools, token sources, baselines,
documentation and agent instructions. Runtime PHP, generated CSS/JS, icons/fonts,
licenses, translations and uninstall remain. The package integrity check confirms
that required modules and all catalog formats ship.

`--target=/path/to/wp-content/plugins` replaces only that target's `adminkit`
directory. Back up an existing copy first; do not target a live development symlink
with the replacement operation. For a working tree use `--working-tree`; otherwise
uncommitted changes are absent. Inspect the dry-run before installation.

## Releases and troubleshooting

Commit validated units on the active branch. A release/version bump, merge to
main, tag, push or remote installation needs its own intended workflow; none is a
side effect of testing or local packaging. Keep the plugin header, constant and
readme stable tag aligned if a release bump is approved.

- An unstyled page can be caused by an explicit context/integration veto, a disabled
  feature or cached assets. Verify loading gates before adding CSS overrides.
- `adminkit_menu_requires_innodb` means a legacy nontransactional menu table was
  refused before deletion. Convert it during explicit backed-up maintenance.
- A failed later save leaves earlier scopes saved and remaining drafts editable.
  Retry before navigating away; there is no transaction across the three routes.
- A packaging failure for a forbidden file means the exclusion/registry contract
  needs repair. Do not bypass the quality gate or install the whole source tree.
