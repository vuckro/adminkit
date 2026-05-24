---
name: adminkit-adapter-audit
description: Audit AdminKit integration adapters for CSS-override debt (!important + raw hex). Use when checking adapter quality, verifying a Tier A adapter stays at zero !important, before committing an adapter change, or to confirm no adapter grew past its accepted override budget.
---

# AdminKit adapter audit

Walks every `inc/integrations/{slug}/css/*.css` file and reports the
selector-override debt each adapter carries: the count of `!important`
declarations (the proxy for "fighting the host's CSS" instead of remapping its
variables) plus raw hex literals.

## When to use

- After editing or scaffolding an adapter, to confirm its override debt didn't
  grow.
- To verify a **Tier A** adapter (pure variable remap) still reports **0
  `!important`**.
- As a pre-commit / CI gate on adapter changes.

## How it works

- **Tier A** adapters (pure variable remap) should stay at 0 `!important`.
- **Tier B** adapters override host selectors out of necessity — the host
  hardcodes its colors or compiles Tailwind with `important: true` — so their
  accepted ceiling is recorded in the `$BUDGET` map inside the script.

The script is a **ratchet, not a tribunal**: it exits non-zero only when an
adapter climbs *above* its ceiling (debt grows). Today's host-forced debt is
left alone. Raise a ceiling only when a new override is genuinely unavoidable —
and prefer remapping the host's CSS variables instead (`docs/INTEGRATIONS.md`).

## Usage

Run from anywhere inside the AdminKit repo (the script locates the plugin root
automatically):

```bash
php .claude/skills/adminkit-adapter-audit/adapter-audit.php
```

Exit code `0` = every adapter within budget; `1` = override debt grew (the
offending adapters are listed). When debt is genuinely host-forced, raise that
adapter's ceiling in the `$BUDGET` array near the top of `adapter-audit.php`.
