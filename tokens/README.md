# tokens/

The **source** of AdminKit's built-in **WaasKit baseline** — the default design
tokens AdminKit ships so it looks complete with no theme/provider installed.

This folder is build-time only. Nothing here is loaded at runtime; the build
generates a CSS file that the plugin enqueues.

```
tokens/
├── build.php            generator (CLI)
└── palettes/            committed source of truth (WaasKit exports, normalized)
    ├── neutre.json        neutral ramps (--neutral / --black, l-*, d-*, t-*)
    ├── marque.json        brand palette (--primary / --secondary + ramps)
    ├── notifications.json  status hues (--success / --warning / --error / --info)
    └── semantique.json     semantic layer (--surface, --border, --text, --accent, …)
```

## Build

```
php tokens/build.php            # regenerate assets/css/waaskit-tokens.css
php tokens/build.php --check    # exit 1 if the committed CSS is stale (drift gate)
php tokens/build.php --print    # print to stdout, write nothing
```

Edit a palette JSON (or re-export from Bricks into the same shape), then run the
build and commit **both** the JSON and the regenerated CSS.

> **Guardrail:** `assets/css/waaskit-tokens.css` is GENERATED. Never hand-edit it —
> change the palette JSON and rebuild, or the drift gate will fail.

## How the layers stack (and how to remove each)

```
provider tokens   (e.g. Bricks live CSS, runtime)   ← optional: integration toggle / absent
      ↓ overrides
WaasKit baseline  (this folder → waaskit-tokens.css) ← optional: adminkit/enqueue_baseline filter
      ↓ feeds
--ak-* layer      (assets/css/tokens.css, owns dark) ← always on — this IS AdminKit
      ↓ final fallback
neutral greys     (hardcoded in the var() chains)    ← the "nothing installed" case
```

Each upper layer is independently removable; the `--ak-*` chains terminate in
neutral `hsl()` fallbacks, so AdminKit renders cleanly with **nothing** above it.

- **Disable a provider:** the integration's settings toggle
  (`integration_{slug}_enabled`) / the `adminkit/integration_enabled` filter.
  A disabled provider feeds no tokens, so the baseline (or neutral) shows through.
- **Disable the baseline:** `add_filter( 'adminkit/enqueue_baseline', '__return_false' )`.
  With no provider either, the admin falls back to neutral greys.

Providers are **not** stored here — each lives as a self-contained module under
`inc/integrations/` and supplies its tokens at runtime via
`adminkit/extra_tokens_handle`. Bricks is the reference: see
`inc/integrations/themes/bricks/class-bricks.php`.
