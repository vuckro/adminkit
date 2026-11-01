# Architecture

AdminKit is a standalone WordPress 6.0+/PHP 7.4+ plugin with no runtime Composer
or JavaScript dependencies. PHP owns settings and native hooks; build-free DOM
modules enhance existing controls; CSS consumes the shared `--ak-*` token layer.
Development tooling is separate from the distributed plugin.

## Bootstrap and ownership

`adminkit.php` defines paths/version and hooks `AdminKit_Plugin::init()` onto
`plugins_loaded` (priority 5, with an idempotent immediate fallback if already fired)
so third-party plugins can register `adminkit/*` filters before modules boot.
`MODULES` is the ordered registry: first every file is required, then non-null boot
callables execute in registry order. Helpers have null boot values. Files under
`inc/` outside integrations must belong to this registry, except the orchestrator
and `inc/generated/lucide-paths.php`. The module/package check verifies that
relationship and every boot callable.

- `inc/settings/`: schema, catalogs, `AdminKit_Settings_I18n`, memoized permission/loading gates and workspace host.
- `inc/wp-core/`: existing WordPress surfaces and optional native workflow tools.
- `inc/features/`: Menu (`Manager` + `Config`/`Snapshot`/`Renderer`/`Store`), Toolbar (`Manager` + `Config`/`Snapshot`/`Renderer`) and the notification drawer.
- `inc/integrations/{plugins,themes}/{slug}/`: auto-discovered host adapters (memoized manifest, `AdminKit_FunnelKit_Integration` trait, shared `assets/css/vendor/element-plus.css`).
- `inc/class-assets.php`, `class-icons.php`, `class-design-system.php`,
  `class-storage.php`: shared services (`inc/generated/lucide-paths.php` provides OPcache icon paths).

Settings registers integration keys immediately from adapter filenames, without
loading translated labels or consulting host activation. Adapter files load after
the modules; `maybe_init()` runs on `after_setup_theme`, when host constants are
available. Catalog labels/host state resolve later for the UI. `adminkit/loaded`
fires once module hooks are wired and integration initialization is queued.
Class names and public `adminkit/*` hooks remain stable.

## Assets and CSS contracts

Modules declare styles through `AdminKit_Assets::register()`. Dispatchers at
priority 9999 handle `admin`, `login`, `frontend`, `editor` and `customize` using
the corresponding WordPress enqueue hooks. Frontend dispatch requires the admin
bar. Loading first checks `adminkit/should_load` and `adminkit/enqueue_{context}`;
each style then checks its section filter, handle filter and optional condition.

Scripts use `AdminKit_Assets::enqueue_script($handle, $src, $deps, $data)`, with
filemtime cache busting and an optional inline `before` bootstrap. The helper
checks admin/frontend context gates. WP-Core callbacks also gate their markup and
behavior: vetoed user tools do not add controls or perform their writes; vetoed
list enhancements leave native lists alone. Prepaint/profile footer placement
retains its existing special handling to prevent hidden or flashing forms.
Settings host scripts are owned by the settings controller.

Navigation and settings editors use build-free modules under `assets/js/settings/`:

- `navigation-controls.js`: shared title/icon controls, lazy `/adminkit/v1/icons` picker, popup lifecycle, drag
  gestures and reorder animation. Each workspace gets one controls instance.
- `navigation-menu-model.js` + `navigation-menu.js`: menu normalization/composite keys/reconcile/serialization in `navigation-menu-model.js`, and DOM rendering, submenu movement, custom links, reset and import/export in `navigation-menu.js`. Exposes `build()` and `request()`.
- `navigation-toolbar.js`: node loading, independent admin/frontend drafts,
  rendering, context reset and serialization. Exposes `build()` and `request()`.
- `navigation.js`: pane/hash routing and the existing main/standalone factory
  contract; delegates builds and request payloads to the owning editor.
- `design-colors.js` + `design.js`: sRGB/contrast/palette swatch editors in `design-colors.js`, and typography/shape/surface controls in `design.js`.
- `wp-core/profile-account-dom.js` + `wp-core/profile-account.js`: shared icons/DOM table helpers and tabbed profile layout.
- `AdminKit_Assets::print_prepaint()` emits the shared anti-FOUC inline script for Options General, Options Discussion and Profile screens.

The controls instance is passed explicitly to each editor. Editors receive the
existing host context and keep the same per-scope revisions; neither editor owns
cross-endpoint saving. WordPress enqueue dependencies load controls before editors
and all factories before the navigation host, for main and legacy menu/toolbar
pages. Every file retains filemtime cache busting. Asset-graph and working-package
checks cover these runtime dependencies.

Generated `waaskit-tokens.css`, `waaskit-toolbar-tokens.css` and `tokens.css` come
from `tokens/palettes/waaskit.json`, `tokens/adminkit.json`, `tokens/appearance.css`
and `tokens/wordpress.css`. Never edit generated output. Components consume
`--ak-*`, including separate accent ink, on-accent ink, surface elevation, table
frames, outline width and focus roles. Surface choices do not remove internal
separators or focus rings. Frontend foundation output stays scoped to the toolbar.
See [TOKENS.md](TOKENS.md) and the executable CSS contract for details.

Dark/light is selected by the existing document attribute and explicit user
preference; there is no automatic theme-selection engine. Monochrome is default;
WordPress blue (`adminkit`), Bricks and custom are explicit accent sources.
Custom palettes remain stored but are previewed/compiled only in custom mode.
Bricks preview values use the same provider properties as saved accent CSS.
Invalid hex field text cannot replace the last validated brand accent, and source
changes discard invalid field text. Valid non-preset type/radius scales appear as
extra select choices; mounting Design does not mutate them.

## Settings workspace and writes

The main workspace has Design system, Features and Navigation tabs. Navigation
contains Menu and Toolbar; disabled features do not fetch or provision their
storage. Legacy standalone editor host factories remain supported.

`assets/js/settings.js` supplies explicit data/state/helpers to screen factories.
Main loads Design/Features/Plugins/Navigation parts; main tabs build on first
selection. Styles are shared plus context-specific sheets. Factories return tabs
and `requests()` keyed by `settings`, `menu`, `toolbar` (legacy hosts can return
one `request()`). Controls call `markDirty(scope)`; main settings are the default
scope. Shared title/icon controls receive their owning scope explicitly from the
toolbar so title-only or icon-only edits cannot mark the menu draft instead. The
toolbar pane is inert until its initial data fetch completes.

Save snapshots all dirty scopes and sends layouts before settings, since settings
can disable their routes. Successful scopes clear only if their revision has not
changed. A failure keeps remaining drafts for retry; newer edits prevent reload
and cancel a scheduled reload. These are independent endpoint writes, not a
cross-endpoint transaction. Separate browser tabs retain last-write semantics.

After each successful request, the host invokes the optional
`surface.onSaved(scope, data, response)` hook with that request's posted snapshot
and response, before checking whether its revision can clear. Failed requests do
not invoke it. Main forwards successful settings values to
`buildFeatures.acknowledge()`, preferring response `values` and falling back to the
posted `data.values`. Acknowledgment updates only the feature and nested-option
metadata baseline, never the live draft. Feature pending badges compare against
that baseline, so changes made during an in-flight save remain pending and a
later scope failure does not make already-saved feature choices look unsaved.
Lazy feature-panel construction reads the same updated metadata.

The schema is authoritative. Boolean/select reads normalize legacy types before
applying `adminkit/setting/{key}`. Malformed settings containers use defaults.
REST validates container/setting types before writes. Settings replace posted
registered keys only; omitted and unknown stored keys remain. Unknown imported
keys are ignored. Clearing an override removes it, while false remains false.
`AdminKit_Storage::save_option()` returns `true|WP_Error`, distinguishing unchanged
values from failed updates; controllers propagate failures rather than success.

Settings exports merge every registered effective value with editable drafts,
including logo and inactive adapter switches. Normal saves omit non-editable keys
such as the General Settings logo. Imports require `_adminkit: settings` plus an
object `values`. Import is blocked by pending drafts/busy state. During its request, editing
regions are inert while the save status stays accessible. Success keeps the
workspace locked until reload mounts server values; failure restores editing. Menu import/export has its own marked layout format.

## Navigation storage and application

Menu rows use `{base_prefix}adminkit_menu_items`, keyed by `blog_id` (and cleaned up
on `wp_uninitialize_site`). The schema is created lazily when enabled, never by
activation. Writes use transactions on both InnoDB and SQLite (`WP_SQLite_DB` /
WordPress Playground), rolling back failed deletes/inserts while preserving rows
whose slugs are invisible to non-Super-Admin editors on Multisite. Existing MyISAM
or MEMORY tables are refused before deletion rather than silently converted.

Menu PHP mirrors the five-owner split under `inc/features/menu/`:

- `AdminKit_Menu_Manager`: stable public facade, setting gate, WordPress hook
  callbacks/priorities, capabilities and REST response envelopes.
- `AdminKit_Menu_Config`: item normalization, strict custom-link URL validation
  (rejecting quotes, angle brackets and control characters), composite `parent::slug`
  keys and `seen_order` computation.
- `AdminKit_Menu_Snapshot`: pristine native `$menu`/`$submenu` capture across all
  five AdminKit submenu screens for the Navigation editor.
- `AdminKit_Menu_Renderer`: composite-key layout application, counter/update badge
  preservation, cross-parent `$_parent_pages`/`$_registered_pages` hook remapping
  (falling back to the original parent when a target parent is inaccessible for the
  current role), and icon/folded-tooltip output.
- `AdminKit_Menu_Store`: InnoDB and SQLite (`WP_SQLite_DB`) transactional persistence
  and Multisite protection for invisible role-restricted rows.

Toolbar PHP has four owners under `inc/features/toolbar/`:

- `AdminKit_Toolbar_Manager`: stable public facade, setting gate, WordPress hook
  callbacks/priorities, capabilities and REST response envelopes.
- `AdminKit_Toolbar_Config`: storage key, protected-node/capability filters,
  normalization, merged-parameter validation and persistence through
  `AdminKit_Storage`. `save(array $params)` returns normalized contexts or WP_Error.
- `AdminKit_Toolbar_Snapshot`: pristine node capture/cache, REST fallback rebuild,
  context-specific metadata and frontend synthesis. The facade guards privileged
  frontend capture; fallback collection suspends the facade's application hooks.
- `AdminKit_Toolbar_Renderer`: applies an explicit validated layout/protected-node
  set/branding policy and owns per-request icon CSS queues and native markup edits.

The three helpers have null boot entries in `MODULES`; only the manager registers
hooks. Existing public manager methods, constants, route names, response shapes,
cache keys and filters remain the integration contract. Helpers are internal.

Toolbar data is the `adminkit_toolbar` option with separate `admin`/`frontend`
contexts; legacy flat layouts read as admin. Omitted contexts remain unchanged.
Custom titles use literal regex callbacks so dollar/backslash sequences cannot
be interpreted as replacement references. Protection accepts native IDs and
historical `wp-admin-bar-` prefixed IDs, keeping
the account and responsive toggle available. Reordering retains child parents and
metadata. Hiding menus/toolbar nodes never grants or revokes WordPress permissions.

`AdminKit_Icons` owns the dashicon allowlist, sanitization, mask compilation and
strict custom-SVG validation. The old menu constant remains an alias. Raw,
percent-encoded and base64 vector inputs canonicalize to an encoded mask. Scripts,
events, styles, links, XML entities and embedded content are rejected. Stored
values are revalidated before CSS output; native Lucide names/aliases stay stable.

## User tools

Quick Edit uses the native AJAX nonce and target capabilities, and limits role
assignment to `get_editable_roles()` with self-demotion protection. The form uses
the same allowlist; rejected roles return HTTP 403 before any profile write. A
filtered-out current role disables the select and is omitted from the request.
Username changes are single-site only: the native profile nonce, target capability, strict
syntax, uniqueness and 60-byte limit validate a queued candidate. `profile_update`
consumes only that target's candidate once, clears old/new cache keys, and retains
the current session token on self-edit. Another user's sessions are invalidated.
Native fields, other profile updates and unrelated user metadata remain WordPress's.

## Upgrades and uninstall

`AdminKit_Upgrades` runs on `admin_init` for `manage_options`; version state is a
non-autoloaded per-site option. Versions 1–5 retire portraits, builder flags,
old active-first flags, dashboard/stats storage and online-user tracking.
Upgrade 6 (logo/appearance cleanup) is immutable and pinned by a regression hash.
Upgrade 7 prepares legacy footer/help intent before version 6 could discard it.
An explicit `clean_chrome_enabled` wins; otherwise any old opt-out produces false,
while all known old flags true produces true. Retired keys are removed only after
a successful write, so failure is retryable. Independent historical footer/help
filters remain effective over the unified switch. Upgrade 8 repairs historical
settings left behind by failed writes, including installations marked version 7.
It preserves explicit replacement values and verifies persistence before advancing
the storage version. Upgrade 9 promotes `adminkit_settings` and `adminkit_toolbar`
to `autoload = true` so cold admin requests avoid extra per-option SQL queries.
Upgrade 6 remains unchanged.

Deactivation preserves storage. Uninstall removes current and historical AdminKit
options, per-site stats tables, the shared menu table, retired user metadata and
transients (including expired/network sitemeta entries across networks). Native
session tokens, dashboard preferences and other plugins' namespaces are preserved.

## Translation and verification

All UI copy is localized in PHP and sent to JavaScript through explicit bootstraps;
JS does not maintain separate English fallback sentences. Proper product/font
names and technical color examples are explicit checker exceptions.
`php dev/i18n.php --write` filters PHP tokens so only `'adminkit'`-domain gettext
calls are extracted, preserves `/* translators: */` comments, supports `_n()`
plurals and `_x()` contexts, removes dead entries and generates POT, PO, MO
(`msgfmt`) and PHP catalogs. `--check` verifies all four without editing the
worktree, and `dev/check-i18n.php` enforces French typographic apostrophes (`’`)
and non-breaking spaces (`\u{00A0}`) before `: ; ? !` and inside `« … »`.

Run `npm ci --prefix dev/smoke` once, then `php dev/test-all.php` (`--fast` skips
the static-analysis pass during quick iteration). The parallel offline gate (185
checks) covers PHP/JS syntax, generated tokens, all 23 `dev/check*.php` suites
(including `check-compliance.php` for GDPR/WP.org rules), editor gating, CSS
contracts, adapter budgets, 11 jsdom suites, i18n, registry/package integrity, and
static analysis (**WPCS Security/i18n**, **PHPStan level 5**, **ESLint**, and
**Stylelint** logical RTL properties). CI (`.github/workflows/ci.yml`) invokes this
gate across PHP 7.4–8.4 and WordPress 6.0 / latest.
Real browser saves, user/session behavior, multisite and active host visual checks
remain integration checks; a skipped host drift check is not validation.

### Settings pages inside native WordPress

`AdminKit_Settings_Page` registers Overview, Features, Design system, Navigation and Configuration under the native AdminKit menu. All five share the existing SPA host and REST contracts. The hook-to-pane map selects the initial content; `AdminKitData.pages` supplies native links. No standalone web-app frame is mounted. `assets/css/settings/pages.css` is the content-only override layered after the shared screen styles. The menu manager's pristine snapshot whitelist includes those exact page slugs so its editor works on the new Navigation page. Draft ownership, permissions, nonces, module defaults and saved layouts remain unchanged.


Content geometry is scoped to the main settings host: border-box sizing prevents
links from adding padding to their minimum heights, and a 1080px maximum bounds
long editor rows without changing native WordPress gutters. Foundation and feature
navigation use the same underline pattern; narrow screens scroll the category
strip. Palette fields respond to the width of their own container, while the
inactive theme remains hidden at every breakpoint.

The shared save bar is hidden in a clean workspace. Editing, saving, success and
error feedback reveal it; import errors remain visible even when no settings draft
is dirty. The existing revision and recovery contracts still own persistence. The
optional first-steps checklist uses three compact columns on desktop, stacked on
mobile, and remains dismissible and automatically hidden after completion.

On mobile wp-admin, `wp-core/admin-bar.js` progressively moves secondary native
admin-bar nodes into a “More actions” disclosure. It preserves each node and its
listeners/submenus, uses comment placeholders to restore the exact desktop order,
and reapplies labels after diagnostic plugins refresh their node contents.
Menu, site, notifications, View site and the theme switch remain directly reachable. The account is grouped into More actions so those controls retain 44px targets even at 320px. The behavior is
transient DOM presentation; it never changes the stored toolbar layout or its
server-side pristine snapshot. The public-site toolbar retains horizontal scrolling
and edge indicators; without JavaScript the original scrollable bar remains usable.

Foundation/navigation sections gain a native mobile select from the existing tab
buttons. Both controls invoke the same pane selection and stay synchronized; only
the appropriate control is displayed at the WordPress 782px breakpoint. Switching
section does not dirty the shared draft. Desktop keeps the existing tab keyboard
behavior. Touch fields use larger text/targets and the save action respects the
bottom safe area.

The wp-admin navigation enhancement keeps WordPress's menu-toggle handler and
`#wpwrap.wp-responsive-open` as its source of truth. Below 783px, the existing menu
becomes a fixed, independently scrollable drawer (up to 300px, leaving a 56px outer
touch area on narrow phones). It overlays rather than shifts `#wpbody`. The toolbar
remains fixed, and its 22px line icon changes to a close glyph from the same expanded
state. Escape/outside tap restore trigger focus; visible drawer links and the trigger
form a keyboard loop. Toolbar actions and navigation dismiss one another.

The protected page region is `#wpbody` plus `#wpfooter`, never `#wpcontent` because
WordPress mounts `#wpadminbar` inside that container. Existing inert states are
restored. Opening records the scroll position/body offset before WordPress handles
the click; a scoped fixed-body lock keeps the page visually in place and restores
its scroll position after close or desktop resize. No menu tree, stored layout or
permission is changed. Transitions have reduced-motion alternatives and the native
frame remains the fallback when the enhancement script/styles are disabled.
The drawer additionally requires the chrome stylesheet to be enqueued; a consumer
veto of that stylesheet preserves native navigation while the toolbar enhancement
can remain active. This compatibility path has a regression check.


Mobile content gutters now come from symmetric 20px `.wrap` margins after removing
WordPress's left-only `#wpcontent` padding. The More panel keeps at least 16px at
each phone edge. Native secondary nodes are still moved/restored without cloning;
account presentation normalizes inherited text indentation and grid rows.

Mobile sidebar disclosure chevrons use CSS only. They read the existing native
`aria-expanded` state, with current/open class fallbacks, and respect reduced motion.
Relative label positioning explicitly clears the native folded-menu left offset;
no extra submenu state or click handler is introduced.
