---
name: adminkit-adapter-scan
description: Scaffold a new AdminKit host integration (adapter) by scanning a plugin/theme's CSS and drafting the token mapping. Use when onboarding a new plugin/theme to AdminKit, creating an adapter, or mapping a host's colors/CSS variables to --ak-* design tokens.
---

# AdminKit adapter scan

Scaffolds a new AdminKit integration by scanning a host plugin/theme's CSS and
drafting the `--ak-*` token mapping, so a new adapter starts from a generated
base instead of a blank file. The color→token suggestions are **heuristic** —
they get the base ~right, then you fine-tune. See `docs/INTEGRATIONS.md` and
`docs/ONBOARDING-A-PLUGIN.md` for the full process.

## When to use

- Onboarding a new plugin or theme to AdminKit.
- Discovering which of a host's CSS variables / hardcoded colors map to which
  AdminKit tokens.

## How it works

The script splits the host's colors into two tiers:

- **Tier A** — the host's own CSS variables (`--x: <color>`). Each is a remap
  target; remapping these makes the host follow AdminKit for free, dark mode
  included. A host that exposes variables yields a *Tier A* adapter (target: 0
  `!important`).
- **Tier B** — hardcoded color literals (hex / rgb / hsl), ranked by frequency
  and grouped by property (background / border / text), each classified to a
  suggested `--ak-*` token. A host that hardcodes its colors yields a *Tier B*
  adapter that overrides selectors.

## Usage

Run from anywhere inside the AdminKit repo (the script locates the plugin root
automatically):

```bash
# Preview the scan + scaffold (prints to stdout)
php .claude/skills/adminkit-adapter-scan/adapter-scan.php <path|glob> [more…] [options]

# Write the integration to disk (live-but-inert stub + scaffold CSS)
php .claude/skills/adminkit-adapter-scan/adapter-scan.php <path>/assets --slug=<slug> --emit
```

`<path>` is a plugin dir (scanned recursively for `*.css`), a single `.css`
file, or a shell glob. Point it at the host's **frontend** CSS too — that's
often where the `:root` variable layer lives.

### Options

| Option        | Meaning |
| ------------- | ------- |
| `--slug=NAME` | name for the scaffold's comment + scope (default: guessed). **Required** with `--emit` (names the folder + class, must be kebab-case). |
| `--scope=SEL` | extra selector to scope rules under (e.g. `.acf-admin-page`). |
| `--top=N`     | cap the Tier B table at N colors (default 40). |
| `--rtl`       | include `*-rtl.css` files (skipped by default). |
| `--emit`      | write `inc/integrations/{slug}/class-{slug}.php` (a live-but-inert stub) + `css/admin.css`. The loader auto-discovers it. |
| `--force`     | with `--emit`, overwrite an existing folder's two generated files. |

### Examples

```bash
php .claude/skills/adminkit-adapter-scan/adapter-scan.php ../fluentform
php .claude/skills/adminkit-adapter-scan/adapter-scan.php ../woocommerce/assets/client/admin --slug=woocommerce --top=60
php .claude/skills/adminkit-adapter-scan/adapter-scan.php "../fluent-crm/assets/**/*.css" --slug=fluent-crm
php .claude/skills/adminkit-adapter-scan/adapter-scan.php ../slim-seo/css --slug=slim-seo --emit
```

## After scanning

When you `--emit`, the generated class is **live but inert** (`is_active()` /
`owns_screen()` return `false`) until you fill the TODOs, so a fresh emit can
never mis-skin a screen. Finish it:

1. `is_active()` — grep the host for its `*_VERSION` constant.
2. `owns_screen()` — inspect `<body class>` on the host's admin screen.
3. For Tier B only: set `host_version()` + `max_tested_host_version()`.

Then fine-tune `css/admin.css` and run the **adminkit-adapter-audit** skill to
check the adapter's override debt (Tier A target = 0 `!important`).
