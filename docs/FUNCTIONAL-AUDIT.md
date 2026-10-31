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
