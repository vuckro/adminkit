# Functional audit — 2026-10-31

Target: local AdminKit checkout on `reprise/base-main-locale`, WordPress at
`localhost:10013`. No remote deployment or plugin version change.

## Confirmed defects and corrections

| Area | Trigger / former behavior | Correction |
| --- | --- | --- |
| Unified save | Edit settings and navigation, then save: only active endpoint ran, followed by reload and lost drafts | Revision-tracked scopes; snapshot every pending payload; save layouts before settings; reload only when all drafts save |
| Initial hash | Open directly on Navigation: initial tab selection was overwritten by first-tab initialization | Initialize active tab before applying the hash |
| Pending edits | Edit during a request or the delayed reload: newer changes disappeared | Retain scopes whose revision changed; cancel delayed reload on edit |
| Partial failure | A later endpoint failed after an earlier success | Keep unsaved scopes; retry only those scopes; show error without reload |
| Import | Import while editing, or edit while FileReader runs: direct import/reload could discard drafts | Import button and file callback refuse dirty/busy state; edits during its request prevent reload |
| Option persistence | SQL failures were reported as successful settings/toolbar saves | Shared `AdminKit_Storage` result contract; unchanged values succeed, failed writes return HTTP 500 |
| Menu persistence | Delete succeeded, later insert failed: old layout was lost | InnoDB transaction with delete/insert/commit checks and rollback; nontransactional engines fail before deletion |
| Schema/cache | Failed schema creation advanced version; failed read cached an empty menu for a day | Confirm schema creation before version update; do not cache query failures |
| REST input | Missing/invalid menu or toolbar bodies became implicit resets; malformed setting values reached scalar sanitizers | Reject invalid containers and setting types before writes; explicit empty layouts still reset |
| Feature state | Stored string `false` evaluated as true; malformed option objects could break reads | Normalize toggle values on read; malformed option container uses defaults |
| Disabled menu | Main settings read the menu table even with the feature disabled and no table provisioned | Disabled editor receives an empty config without querying storage |
| Toolbar load | Disabled toolbar still fetched its absent route; reset/edit could race an initial fetch | No controls/fetch when disabled; pane inert until successful initial load |
| Imported scales | Merely mounting Design rounded valid non-preset scales to a nearby preset, later persisted by unrelated saves | Preserve valid imported/server scales as extra select options |
| Username change | Any `profile_update` with matching POST data could rename a target without this module's validation; length/cache/session handling was incomplete | Queue native-nonce-validated candidates by target ID; consume once; validate 60-byte limit; clear old-login cache; retain current session token |
| User role edit | Server checked all registered roles rather than the filtered assignable set | Respect WordPress `get_editable_roles()` |
| Documentation | Defaults, font catalog, navigation structure, border range and roadmap contradicted current code | Update README, settings and architecture references |

The username syntax and duplicate checks, native target capabilities, settings/menu
`manage_options`, filterable toolbar capability, WordPress REST authentication,
AJAX nonce, uninstall ownership and per-site menu scoping remain in place.

## Verification

Offline regression commands:

```sh
php dev/check-write-endpoints.php
php dev/check-user-quick-edit.php
php dev/check-username-changer.php
node dev/smoke/boot.test.js
node dev/smoke/saving.test.js
```

The endpoint suite also runs `check-feature-storage.php`, injecting failures at
transaction start, delete, second insert, commit and option update. It checks
nontransactional-engine refusal, unchanged saves, site/context isolation,
permission callbacks, malformed requests and typed legacy reads. Username tests
exercise nonce/candidate/target binding, duplicates, length, cache keys and session
retention without changing an account. Quick edit checks editable roles and
unauthorized targets. UI tests cover hash routing, multiple drafts, partial
failure/retry, edits during saves, delayed reload and non-preset scale preservation.

Existing checks passed: PHP/JavaScript syntax, custom-accent contrast, design
system, automatic branding, SVG branding, icons, content ordering, editor gating,
storage upgrades, uninstall, token drift, CSS contracts and adapter budgets.
Adapter host drift was skipped because the external hosts were not supplied to
the detector; this does not validate their runtime compatibility.

Additional local integration checks:

- A uniquely named scratch table cloned from the real menu table verified actual
  MySQL/InnoDB replacement, rollback after a failed second insert, and per-site
  isolation. The scratch table was dropped in a `finally` block. Live menu rows
  and users were not changed by this database test.
- Chromium loaded the actual WordPress workspace with no JavaScript errors.
  Real REST calls verified an unchanged settings save, HTTP 400 for malformed
  settings, and denial without a REST nonce.
- Actual Design, Menu and Toolbar controls produced three ordered save requests
  with configuration-changing POSTs intercepted. Desktop/mobile navigation at
  1440px and 390px had no document overflow, with light/dark captures. Screenshots and the browser runner
  are retained in the workspace's `output/functional-audit-20261031/` directory.
- The temporary browser authentication session was revoked after verification.

## Practical limits

Cross-endpoint saves are sequential, not a database transaction across settings,
menu and toolbar. Earlier successful scopes remain saved if a later scope fails;
the UI retains failed drafts for retry. Concurrent editing from separate browser
tabs still follows last-successful-write semantics.

New menu tables use InnoDB. A legacy MyISAM table is not converted automatically:
its save returns `adminkit_menu_requires_innodb` without deleting rows. Convert
such a table during explicit maintenance before enabling edits.

No live account was renamed and no email was sent. The browser configuration
writes were intercepted except for the unchanged REST settings save. Inactive
third-party adapters and multisite runtime were not exhaustively exercised;
per-site storage and uninstall behavior have offline coverage.

## Follow-up: stabilization and quality lock

The follow-up adds upgrade 7 without editing upgrade 6, restores independent
legacy chrome filters, registers integration settings early and resolves generic
screen ownership by longest prefix. Settings exports cover every active schema
key and require their format marker on import. Shared icon validation, restricted
menu-history writes, taxonomy highlighting, WP-Core vetoes, protected toolbar IDs
and historical/network uninstall cleanup now have regression coverage.

`php dev/test-all.php` is the unified local/CI gate: PHP/JS syntax, tokens, all
check scripts, editor gating, CSS contracts, adapter budgets, jsdom, i18n and
MODULES/package integrity. It passed 135 checks on local PHP 8.2.30 and 8.4.10.
The committed workflow also targets PHP 7.4; that runtime was not available
locally, and no remote CI execution is claimed here.

Current POT/PO/MO/PHP catalogs agree on 259 active messages; 327 JS bootstrap
references are checked. All missing French translations were supplied and dead
entries removed. The catalog generation/check process is deterministic.

Additional Chromium verification compared actual preview colors with CSS produced
by the real PHP compiler: four sources × two themes × seven roles (56 comparisons),
including dormant custom palettes and simulated provider variables. No differences
remained after fixing custom hover derivation. The actual workspace exported all
40 active setting keys and rejected a wrong import marker without posting. Live
REST accepted an unchanged save and rejected malformed settings, invalid accent
input and missing nonces. Configuration-changing UI POSTs were intercepted.
Navigation was checked at 1440/390px in light/dark with no JS errors or document
overflow. The temporary audit session was revoked afterward.

Evidence lives in `output/stabilization-20261031/` at workspace level: PHP-version
logs, browser log, color comparisons, reproducible runners and viewport captures.
This does not claim live account-renaming, remote deployment, exhaustive multisite
execution or compatibility approval for inactive host adapters.


## Follow-up: four reliability regressions

- Settings import now makes editing regions inert until success reloads the page.
  A failed import unlocks them and keeps the previous values. Status remains
  accessible. This prevents stale client values from overwriting imported values.
- Storage upgrade 8 repairs retired setting keys from failed historical writes,
  including sites already marked version 7. Failed persistence does not advance
  the version; retry preserves explicit logo, priority and chrome choices. Frozen
  upgrade 6 remains byte-for-byte unchanged.
- Quick Edit displays only `get_editable_roles()` and rejects forbidden/empty
  posted roles with HTTP 403 before updating any fields. If the current role is
  excluded by that filter, the client disables and omits the role field so other
  profile fields remain editable. Self-demotion protection remains in place.
- Toolbar title replacements now use literal callbacks for native label, search,
  account and accessibility markup, preserving dollar and backslash sequences.

Permanent regression coverage exercises failed historical writes from storage
versions 0–7, retry/idempotency and explicit choices; real settings-host import
locking and failure recovery; role form/endpoint parity; and title replacements
across native markup shapes. These are isolated fixtures, not live account edits.


Local WordPress integration verification for these fixes:

- Actual settings imports and subsequent saves were exercised in Chromium at
  1440px/light and 390px/dark. A delayed request blocked editing; an injected HTTP
  500 restored editing; successful POST/reload and the next save retained the
  imported font/accent. No JavaScript errors or document overflow were observed.
- Native WordPress `editable_roles` filtering and AJAX handling rejected a
  forbidden role without partially updating a scratch user's profile, then
  persisted an allowed role. The scratch user was deleted afterward; email
  delivery was intercepted. A real `WP_Admin_Bar` preserved a title with dollar
  and backslash references.
- Original settings/toolbar options were restored and read back. The temporary
  browser session was revoked. Evidence is retained at workspace level in
  `output/four-fixes-20261031/`.

The unified gate passed all 137 checks after these corrections (85 PHP syntax,
27 JavaScript syntax, generated tokens, 18 regression scripts, two CSS/adapter
checks and four jsdom suites). Plugin version remains 1.0.0; storage version is 8.


## Follow-up: full navigation and feature-toggle flows

Two further defects were reproduced with the actual navigation factory and save
host, then corrected:

- Shared toolbar title/icon controls marked the menu scope dirty. A title-only or
  icon-only toolbar edit could therefore save the menu endpoint and lose the
  toolbar change on reload. These controls now receive their owning scope from
  the toolbar; menu controls keep their existing default.
- Switching toolbar contexts called an undefined `closePicker()`, raising a
  ReferenceError before selecting the frontend. It now closes the existing
  icon/URL popover through `closeIconPicker()`.

The permanent navigation jsdom suite boots real controls and the real save host,
checks title-only and icon-only edits for menu, admin toolbar and frontend
toolbar, and rejects JavaScript errors. Merely opening a picker stays clean.
Both the unified gate and `npm run test:boot` include the new suite.

Actual local WordPress/Chromium verification covered:

- Anonymous, missing-nonce and subscriber writes denied for settings/menu/toolbar;
  malformed bodies rejected with HTTP 400.
- Menu rename, icons, arrow movement controls, hiding, custom links/new tabs,
  separators, native mouse drag of a taxonomy submenu, save/reload and direct
  access to a hidden screen. Export/reset/import round-tripped the saved layout.
- Toolbar title-only and icon-only saves reached only the toolbar endpoint;
  order/hiding, literal `$1` labels, admin/frontend context selection, persistence
  and actual frontend application passed.
- An injected toolbar HTTP 500 after a successful menu save retained the toolbar
  draft. Retrying saved only the remaining scope.
- Pending menu/toolbar layouts saved before settings disabled their routes.
  Disabled routes returned 404, editor controls disappeared, and re-enabling
  restored the saved layouts.
- All available feature switches persisted off/on. Native checks covered the theme
  toggle, notifications, footer, Quick Edit template, username script, post-preview
  column and login assets/toggle.
- Persisted menu reset removed custom titles/icons/links. Toolbar resets affected
  only the selected context; protected account/toggle IDs remained available.
  Two temporary articles exercised Published-first off/on behavior, and the bulk
  defaults control restored each feature to its registered default.
- Desktop/mobile at 1440/390px in light/dark had no document overflow or JavaScript
  errors; screenshots were also inspected.

The unified gate passed 139 checks. Evidence and reproducible browser runners are
retained at workspace level under
`output/playwright/navigation-validation-20261031/`. Original options and raw menu
rows are restored and read back; temporary sessions/accounts/articles are removed. This
validates the local single-site target, not multisite or third-party adapters.
