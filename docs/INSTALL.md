# Installing AdminKit

AdminKit is a regular WordPress plugin: drop it into `wp-content/plugins/`,
activate it, you're done. No build step, no Composer or npm dependencies, no
database tables. WordPress 6.0+ and PHP 7.4+ are the only requirements.

This page covers three audiences:

1. [End users](#1-end-users--install-the-release-zip) — install the release zip
2. [Developers](#2-developers--clone-and-symlink) — clone the repo, hack on it
3. [Maintainers / AI assistants](#3-maintainers--cut-a-release) — cut a release

---

## 1. End users — install the release zip

Download the latest release zip from
[GitHub Releases](https://github.com/vuckro/adminkit/releases) (we'll publish
one as soon as 1.0.0 ships — until then, use the developer workflow below).

In WordPress:

1. **Plugins → Add New → Upload Plugin** → choose the `adminkit-X.Y.Z.zip` file → **Install Now**.
2. **Activate Plugin.**
3. Open **Settings → General** in wp-admin. The page now hosts six tabs in
   one strip: Site identity, Account & registration, Language/date/time
   (native WP) + Dashboard, Settings, Plugins (AdminKit). No separate
   AdminKit menu entry — everything lives on the same page.

That's it — there's no setup wizard, no DB migration, no required configuration.
Defaults are sensible: Gutenberg canvas theming, AdminKit icons, and local
avatars are on; everything else stays out of your way until you opt in.

The release zip contains **runtime files only** — no `dev/`, no build sources,
no Git metadata. If you accidentally downloaded the GitHub source tree
(via "Download ZIP" on the repo page) you'll have those extra folders; they're
harmless but not what you want. Prefer the **Releases** page.

---

## 2. Developers — clone and symlink

Use this when you want to read, modify, or contribute to AdminKit.

```bash
# 1. Clone the repo somewhere convenient (NOT inside wp-content/plugins/).
git clone https://github.com/vuckro/adminkit.git ~/code/adminkit
cd ~/code/adminkit
git checkout docs/overhaul         # active integration branch — see CLAUDE.md

# 2. Symlink (or copy) into your WP install.
ln -s ~/code/adminkit /path/to/wp-content/plugins/adminkit
# Or, if symlinks don't suit your setup, use the packager below.

# 3. Activate via wp-admin → Plugins.
```

Symlinking gives you live edits with no copy step — change a CSS or PHP file in
`~/code/adminkit/`, refresh wp-admin, see it. Cache-busting is automatic
(asset URLs include `?ver=<filemtime>`).

Pre-merge gates (run from inside the checkout — see CLAUDE.md for the full list):

```bash
php -l <changed-file>           # syntax
php tokens/build.php --check    # token-drift gate
php dev/adapter-audit.php       # CSS-debt gate
php dev/adapter-drift.php       # host/WP CSS drift gate
```

---

## 3. Maintainers — cut a release

AdminKit's packager (`dev/package.php`) produces a clean install in two forms:
**dropped straight into a WP plugins folder** (for testing on a real site) or
**zipped** (for the GitHub Releases page). It honours `.distignore`, so dev-only
files (`dev/`, `tokens/`, `.claude/`, `CLAUDE.md`, …) never leak into a release.

### Install into a WordPress site (no symlink, fresh copy)

```bash
# From inside the AdminKit checkout:
php dev/package.php \
  --target="/path/to/your/wp-content/plugins"

# To package a specific branch / tag instead of origin/docs/overhaul:
php dev/package.php --ref=main --target=...
php dev/package.php --ref=v1.0.0 --target=...

# To install the local working tree (incl. uncommitted changes):
php dev/package.php --working-tree --target=...
```

The packager writes to `<target>/adminkit/` (creating it, replacing it if it
already exists). After it finishes, deactivate + reactivate the plugin in
wp-admin to refresh.

### Produce the release zip

```bash
php dev/package.php --ref=v1.0.0 --zip=adminkit-1.0.0.zip
```

The zip has `adminkit/` as its single top-level entry, so users who upload it
via **Plugins → Add New → Upload Plugin** land in
`wp-content/plugins/adminkit/` — the layout WordPress expects.

### Sanity-check what would ship (dry run)

```bash
php dev/package.php --dry-run
```

Lists every file that would land in the install. Use this when you change
`.distignore` to confirm you didn't accidentally drop a runtime file.

### Cutting a versioned release (checklist)

1. Confirm the maintainer wants to bump the version (see Guardrails in
   [CLAUDE.md](../CLAUDE.md)). Until they say so, the version stays at 1.0.0.
2. Bump in **three** places — they must match:
   - `adminkit.php` → `Version:` header
   - `adminkit.php` → `define( 'ADMINKIT_VERSION', ... )`
   - `readme.txt` → `Stable tag:`
3. Add a `== Changelog ==` entry in `readme.txt`.
4. Run the four pre-merge gates above.
5. Promote `docs/overhaul` → `main` (fast-forward only, never force-push).
6. Tag: `git tag vX.Y.Z && git push --tags`.
7. Cut the zip: `php dev/package.php --ref=vX.Y.Z --zip=adminkit-X.Y.Z.zip`.
8. Upload the zip to GitHub Releases for tag `vX.Y.Z`.

---

## Troubleshooting

**Plugin activates but wp-admin doesn't look restyled.**
Hard-refresh (Cmd/Ctrl-Shift-R) — the browser may be serving a cached
stylesheet. AdminKit cache-busts via `filemtime`, so refreshing always picks up
the latest CSS.

**"Plugin file does not exist" on activation.**
You probably unzipped a folder named `adminkit-main/` or `adminkit-X.Y.Z/`
instead of `adminkit/`. WordPress wants the directory to match the main PHP
file's slug. Rename it to `adminkit/` and try again — or use the release zip
from `dev/package.php`, which gets the folder name right by construction.

**`git archive` fails: "unknown revision 'origin/docs/overhaul'".**
You haven't fetched the remote. `git fetch origin && php dev/package.php …`.

**`dev/package.php` complains about a forbidden path.**
Something in `.distignore` is too lax, or a new dev-only folder was added
without listing it. Update `.distignore`, re-run `--dry-run` to confirm.
