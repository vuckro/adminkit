# Feature dependencies

Implemented and audited on 2026-11-01. Personal interface selection is documented
in [INTERFACE.md](INTERFACE.md).

## Resolution rules

Saved choices, prerequisites and effective behavior are separate. Turning off
`admin_theme_enabled` makes every account use native WordPress appearance without
deleting its personal preference, design values or tool configuration. Restoring
the theme restores the saved personal/default resolution.

The catalogue groups presentation under Appearance, and independent enhancements
under Interface tools, Navigation, Lists and tables, and Accounts. Categorization
does not create a runtime dependency. Sorting and thumbnails remain available
with native WordPress appearance.

The administration theme and login appearance are excluded from bulk tool actions.
The dark-mode switch lives in Features → Appearance → Administration theme → Configure, rather than being a second
top-level appearance module. Extension skins remain in Design system → Extensions.

Design system is available while either the administration theme or custom login
is enabled. With login alone, shared customization remains available and extension
skins are omitted. With neither surface enabled, the submenu and application
assets are omitted; an existing direct URL explains the pause and links to Features.
No saved values are reset.

Catalogue badges, filters and overview counts share one state resolver:

- Active: selected and applicable in the current context.
- Inactive: switched off.
- Paused: selected but a known prerequisite is absent or a broad veto applies.
- Not applied to this account: the site theme is enabled but this account chooses
  WordPress classic.
- Unavailable: unsupported platform or capability prerequisite.

Switches continue to show the selected value, including a retained selection on
an unavailable feature. Draft edits have pending badges and apply after saving.
Counts follow the current selections and applicability, including draft edits.

## Executed browser matrix

Each of the eight tools was enabled alone, with the other optional tools off,
under both WordPress classic and AdminKit: 16 passing cases on disposable WordPress
using SQLite. These checks exercise behavior, persistence and rendered geometry.

| Tool | Verified behavior in both appearances |
| --- | --- |
| Notification center | Nonzero bell geometry, warning grouping, drawer opening, Escape dismissal |
| Menu manager | Saved label/icon/order/hiding, rendered icon, reset |
| Toolbar manager | Saved title/icon/hiding, reset |
| Users quick edit | Successful first-name save and rejected invalid email |
| Username changer | Actual target-account rename through the native edit form |
| Post previews | Visible column and empty-image geometry; hidden at mobile width |
| Active items first | Published opening view; explicit Draft filter preserved |
| Clean interface | Selected footer hiding while Help stays available |

Six additional interaction groups passed: personal classic state; site-wide theme
suspension with preference retention and reactivation; login-only customization
and anonymous login assets; a toolbar-hidden bell pausing the notification state
and recovering when the toolbar manager is disabled; separate preferences for two
accounts; desktop/mobile layout. The login-only check also saved and reloaded the
shared dark-mode choice.

## Defects corrected

The notification component owns its bell dimensions and native hover behavior.
It leaves notices inline if no launcher is present, rather than moving them into
an unreachable drawer. Component browser/jsdom regressions cover these cases.

The menu audit exposed a SQLite transaction failure: the WordPress SQLite
translator expects MySQL `START TRANSACTION`, while raw SQLite accepts
`BEGIN TRANSACTION`. Storage now selects the appropriate syntax. Regression
checks cover successful persistence and rollback preserving the previous layout.

## Reproduce safely

Run the normal gate with `php dev/test-all.php`. The destructive browser matrix is
opt-in and must target a dedicated disposable loopback WordPress installation with
AdminKit active, stock admin pages and an administrator account:

```sh
AK_DISPOSABLE_AUDIT=1 AK_AUDIT_URL=http://127.0.0.1:19413 \
  AK_USER=admin AK_PASS=password npm run test:dependencies --prefix dev/smoke
```

The command refuses port 10013 and non-loopback hosts. It creates/updates fixture
content and accounts, changes feature options and navigation layouts, and does not
restore the disposable instance. Never point it at a working site. The fixture
credentials above belong only to the disposable Playground setup.

The settings/profile interaction checks were additionally exercised through
Playwright against that disposable instance. They are not part of the 16-case
command. The offline suite covers interface resolution, retained preferences,
nonce/capability checks, account/site isolation, conditional navigation, state
labels/filtering, bulk exclusions and dark-mode persistence.

## Limits

This is targeted coverage, not every combination. Real Multisite networks,
third-party plugin notice patterns, all plugin skins, delayed toolbar removal,
script failure, every image format and every user role still need dedicated
integration testing. Single-site SQLite browser results and offline site-isolation
checks do not establish those behaviors. Respect third-party vetoes, preserve
native forms/nonces/capabilities and leave notices inline when grouping is unsafe.
