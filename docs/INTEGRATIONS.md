# Integrations

An **integration** is a small adapter that makes AdminKit cooperate with another
plugin or theme. It's optional code: AdminKit ships standalone and looks good
without any integration; removing an integration folder removes its support
cleanly. This page is the contract, the build walkthrough, and the patterns.
Token vocabulary: [TOKENS.md](TOKENS.md). Asset registry: [ARCHITECTURE.md](ARCHITECTURE.md).
Every hook: [EXTENDING.md](EXTENDING.md).

## Conventions

```
inc/integrations/{plugins|themes}/{slug}/class-{slug}.php
    └─ class AdminKit_Integration_{Studly_Slug} extends AdminKit_Integration_Base
       css/   (optional) integration CSS, registered by the class
       baseline.json   (optional) host CSS snapshot for drift detection
```

The boot orchestrator globs `inc/integrations/*/*/class-*.php`, derives the class
name from the file basename (`fluent-crm` → `AdminKit_Integration_Fluent_Crm`),
and queues `maybe_init()` on `after_setup_theme`. **No loader edit is needed** —
drop the folder under `plugins/` or `themes/` and it's live. The group is purely
organizational; the class name must match the file basename or the folder
silently never loads.

## The minimum integration

```php
<?php
defined( 'ABSPATH' ) || exit;

class AdminKit_Integration_Foo extends AdminKit_Integration_Base {
    public static function slug()      { return 'foo'; }
    public static function is_active() { return defined( 'FOO_VERSION' ); }
}
```

If `is_active()` returns false, `maybe_init()` short-circuits — the integration
does nothing. Override only what you need; the base no-ops the rest:

| Method | Purpose |
| --- | --- |
| `slug()` | REQUIRED — identifier used in handles. |
| `is_active()` | REQUIRED — host detection (`defined()` / `class_exists()`). |
| `register_assets()` | Declare CSS via `AdminKit_Assets::register()`. |
| `boot()` | Wire non-asset filters/actions. |
| `owns_screen( $screen )` | True on screens the integration cares about; used in `condition` closures. |
| `host_version()` + `max_tested_host_version()` | Tier B only — version gate (see below). |

`maybe_init()` (concrete) runs `is_active → register_assets → boot`. Don't override it.

---

## Walkthrough — skin a new host

The scanner does the mechanical ~80% (find the colors, map them to `--ak-*`,
write the folder); you do the ~20% that needs judgment (surface split, scope,
fine-tuning). The dev tooling lives in `dev/` (tracked; excluded from the
distributed zip).

```bash
# 1. discover the host (by hand — not reliably in the CSS):
grep -rn "define.*_VERSION" ../<host> --include=*.php   # version constant → is_active()
#    open the host's admin page, inspect <body class> → owns_screen() scope
grep -rln "attachShadow" ../<host>                      # shadow DOM? → JS-bridge delivery

# 2. scan admin AND frontend CSS (a host's :root vars often live in its frontend bundle)
php dev/adapter-scan.php ../<host>/assets ../<host>/public --slug=<slug>

# 3. generate the live-but-inert folder (is_active/owns_screen return false until you fill TODOs)
php dev/adapter-scan.php ../<host>/assets --slug=<slug> --emit

# 4. fill the TODOs in class-<slug>.php, fine-tune css/admin.css (the 20% below)
# 5. verify in Chrome (http://localhost:10018/wp-admin/), light AND dark
php dev/adapter-audit.php                                # Tier A = 0 !important; gate set for Tier B
php dev/adapter-drift.php --slug=<slug> --host=../<host> --update   # freeze the host baseline
```

The scan reports two tiers and emits a paste-ready scaffold:

- **Tier A — the host's CSS variables.** Each `--x: <color>` with a suggested
  `--ak-*` remap. Non-empty → remap these first and most of the UI (dark mode
  included) follows for free, **zero `!important`**. It also reveals the variable
  *graph* (e.g. Element Plus `--el-*` alias down to a few `--fc-*` roots — remap
  the roots).
- **Tier B — hardcoded literals** (`#hex` / `rgb()` / `hsl()`), ranked by use,
  grouped by property (bg / border / text), each classified to a token by
  lightness + chroma + hue.

> **Scanner blind spots.** It reads CSS by regex, so it can't parse `light-dark()`,
> native nesting, `oklch()`, or shadow DOM. When the scaffold comes back wrong,
> hand-map straight from the host's `:root` variable block — the mapping is
> mechanical once you have the list.

### The 20% — fine-tune `css/admin.css`

- **3-surface split** (the #1 manual fix). The scanner can't tell the page from
  the lightest card. Confirm by role: `--ak-bg` (page) vs `--ak-surface` (cards /
  inputs / rows) vs `--ak-elevated` (headers / dropdowns / selected).
- **Brand vs info blue.** The busiest hued color is auto-promoted to `--ak-primary`.
  If the host's blue is informational, route it to `--ak-info`.
- **Prune & merge the Tier B draft**, scope each rule under
  `body.adminkit.<screen-id-or-wrapper>`, and keep any hardcoded host literal in
  **one** place so a host change is a one-line fix.
- **Don't tokenize fixed logos** (a two-tone masthead loses its light parts).
  **Flatten shadows** (AdminKit is shadow-free — set host shadow vars to `none`).
- **Dark-mode guard.** If the host toggles its own dark class, redeclare the
  remapped vars on both `:root` and `body.adminkit.<scope>` so your values win.
- **Bail bare-element primitives.** If the host ships its own inputs/buttons,
  `add_filter( 'adminkit/enqueue_forms', '__return_false' )` scoped to its screens
  in `boot()` (FluentForm / FlyingPress precedent).

### PR checklist

```md
- [ ] Host discovered: version constant `____`, screen scope `____`
- [ ] Render target checked: shadow DOM? → JS bridge; else asset registry
- [ ] Scanned admin + frontend CSS; tier decided
- [ ] Generated with `php dev/adapter-scan.php --slug=<slug> --emit`
- [ ] is_active() + owns_screen() filled (+ version gate if Tier B)
- [ ] 3-surface split confirmed; brand vs info routed
- [ ] Tier B selectors pruned, merged, scoped; logos left; shadows flattened; dark guarded
- [ ] Verified every host screen in Chrome — light AND dark
- [ ] `php dev/adapter-audit.php` clean; `php dev/adapter-drift.php --slug=<slug> --update` baseline frozen
```

---

## Patterns

### Inject tokens (provider)

Pipe a host CSS file as a dependency of `adminkit-tokens` so AdminKit inherits
its variables. [Bricks](../inc/integrations/themes/bricks/class-bricks.php) is the
reference (pipes Bricks' generated `style-manager.min.css`).

```php
protected static function boot() {
    add_filter( 'adminkit/extra_tokens_handle', array( __CLASS__, 'provide_tokens' ), 10, 2 );
}
public static function provide_tokens( $handle, $context ) {
    wp_enqueue_style( 'my-host-tokens', /* url */, array(), /* version */ );
    return 'my-host-tokens';
}
```

### Bypass restyling in a host's full-screen UI

```php
add_filter( 'adminkit/should_load', function ( $load, $context ) {
    return ( 'admin' === $context && self::is_inside_host_ui() ) ? false : $load;
}, 10, 2 );
```

### Target the host's colors in the right order

The cheapest adapter never touches the host's selectors. Reach for these in order
— earlier is more stable:

1. **The host's own CSS variables** — remap to `--ak-*`, dark mode included, zero
   `!important` (*Tier A*; see [wpcode](../inc/integrations/plugins/wpcode/css/admin.css)).
2. **Shallow utility/state classes** (`.bg-indigo-600`) — only when there's no
   variable layer (a Tailwind app).
3. **Deep component chains** (`.el-tabs__item.is-active`) — last resort, brittle.

Always scope to the screen (`body.adminkit.{screen-id}`).

### Version-gate selector overrides (Tier B)

A Tier B adapter breaks **silently** when the host renames a selector. Declare the
tested range so that case degrades to the host's clean native UI:

```php
protected static function host_version()            { return defined( 'FOO_VERSION' ) ? FOO_VERSION : null; }
protected static function max_tested_host_version() { return '2'; } // host's current major
public static function register_assets() {
    if ( ! static::host_within_tested_range() ) { return; } // past tested major → native UI
    AdminKit_Assets::register( array( /* … */ ) );
}
```

The gate compares **majors**, so the skin survives minor/patch updates.
[fluent-booking](../inc/integrations/plugins/fluent-booking/class-fluent-booking.php)
and [flying-press](../inc/integrations/plugins/flying-press/class-flying-press.php)
are the reference. **Pair every Tier B adapter with a `baseline.json`** (drift
detection) so a host class/color change is caught before it ships — see
[TOKENS.md → Drift detection](TOKENS.md#drift-detection-keeping-adapters-alive).

### Deliver CSS into a shadow root

Some hosts (Query Monitor 4.0+) render inside an **open shadow root**: a normal
enqueued stylesheet never reaches it (selectors don't cross the boundary), but CSS
custom properties **do inherit in**. So the token remap is unchanged — only
delivery differs: scope the CSS to the in-shadow id (no `body.adminkit` prefix),
and inject it as a `<link>` into the host's `shadowRoot` from a small JS bridge
(poll for it — `attachShadow` fires no observer). Mirror AdminKit's mode onto the
host's own theme attribute (one-way) for `color-scheme`. The
[query-monitor adapter](../inc/integrations/plugins/query-monitor/) is the reference.

### Register a dashboard widget

`AdminKit_Dashboard::register_widget( $id, $title, $cb )` in `boot()`. The registry
only mounts `wp_dashboard_setup` if a widget is registered, so a vanilla site pays
nothing.

---

## Anti-patterns

- **No third-party JS feedback loops.** A MutationObserver bridging two theme
  attributes once caused a runtime loop. Sync one-way at load, let the user re-click.
- **No hard dependencies on the host.** `class_exists()` / `defined()` live in
  `is_active()`. Removing the host must not break AdminKit.
- **No global state mutation outside `boot()`.** Hooks wire only when active.
- **Don't load CSS unconditionally** — always scope via a `condition` closure.
- **Don't target deep component chains when a CSS variable exists** — and
  version-gate selector overrides.

## Reference implementations

- [bricks](../inc/integrations/themes/bricks/class-bricks.php) — token injection + builder bypass.
- [wpcode](../inc/integrations/plugins/wpcode/class-wpcode.php) — minimal Tier A (remap `--wpcode-*`, zero `!important`).
- [acf](../inc/integrations/plugins/acf/class-acf.php) — clean Tier B (host hardcodes hex), screen-scoped + version-gated.
- [woocommerce](../inc/integrations/plugins/woocommerce) — the most comprehensive: wc-admin React app + classic screens by token remap.
- [flying-press](../inc/integrations/plugins/flying-press/class-flying-press.php) — Tier B against a Tailwind app compiled `important: true`.
- [query-monitor](../inc/integrations/plugins/query-monitor/) — Tier A delivered into an open shadow root via a JS bridge.
