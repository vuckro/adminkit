# Feature dependency audit

Status: audit plan, with the notification-center regressions corrected. The
proposed site-wide administration-theme parent is not implemented by this change.
Current personal interface selection is documented in [INTERFACE.md](INTERFACE.md).

## Required distinction

Separate saved configuration, prerequisites and effective behavior. A feature can
be enabled in storage while paused in a context, or can have its own component UI
without using the administration theme. Labels, filters and counts must eventually
use the same resolution and explain why an enabled preference is not applied.

Actual dependencies include assets, icon geometry, event handlers, native fields,
permissions, available screens and entry points. They are not limited to parent
checkboxes. Component assets must own essential layout and states. The full admin
skin must not be an implicit dependency of otherwise independent tools.

## Confirmed notification defects

With native WordPress appearance, notifications enabled, and both navigation
managers disabled, the toolbar node, script and drawer were present but the bell
SVG measured 0 × 0 pixels. Its dimensions came from the administration theme's
toolbar stylesheet. The notification component now supplies its own desktop icon
dimensions, retains its mobile size and uses native toolbar hover colors when the
admin skin is absent.

A separate missing-launcher case was identified in the client: it collected
warnings even if another toolbar configuration/plugin removed the bell. It now
leaves notices inline when no launcher is present.

Regression checks cover a native toolbar with only the notification component's
assets: actual icon dimensions, native hover behavior, notice grouping, inline
success feedback, drawer access, Escape/focus restoration and mobile dimensions.
The jsdom suite also verifies that a missing launcher leaves notices visible.
These checks do not establish independence of every other module.

## Audit matrix

| Tool | Explicit prerequisites | Remaining interaction checks |
| --- | --- | --- |
| Notification center | Own module, native admin toolbar launcher, supported notice screen, component CSS/JS | Toolbar launcher removal; custom toolbar icons; absent/late notices; script failure |
| Menu manager | Own module, capability, native menu nodes, storage | Standalone ordering/renaming/hiding/icons; current-page highlighting; new plugin entries |
| Toolbar manager | Own module, capability, native toolbar nodes | Standalone saved layouts; notification entry point; frontend/admin contexts; responsive visibility |
| Users quick edit | Own module, users list, target-user permissions, nonce, own form assets | Standalone real save/error/cancel; secondary roles; narrow native table layout |
| Username changer | Own module, supported site, target-user permissions, profile fields, nonce | Standalone validation/save/session preservation; native and enhanced profile layouts |
| Post previews | Own module, supported list screen/post type, component assets | Standalone empty/broken/real thumbnails; hover/focus; mobile; quick-edit row regeneration |
| Active items first | Own module, supported query/list, applicable sub-option | Standalone redirects/sorting; explicit filters/search/sort; post-action landing; network screens |
| Clean interface | Own module, explicit footer/help sub-options, own targeted CSS | Standalone hiding/restoration; both appearances; no unrelated form/navigation changes |
| Login appearance | Own independent site module and login context | Admin theme unavailable; logos/fonts/colors; dark mode policy; anonymous login |
| Administration appearance | Site availability (proposed), personal/default resolution, context/exceptions | Per-user/site isolation; editors/Customizer/frontend bar; disabled child settings retained |

## Verification procedure

1. Capture the exact feature settings and account appearance for the test case.
2. Enable only the tool under test. Disable the other optional tools, rather than
   validating only with everything enabled.
3. Exercise it in native WordPress and AdminKit, on desktop and mobile.
4. Verify actual rendered dimensions/visibility and keyboard access, not only
   element existence or a clickable DOM node.
5. Exercise successful persistence, rejected persistence and restoration where
   the tool writes data. Use disposable test data for account/content mutations.
6. Add meaningful pairwise cases for modules sharing menus, toolbar nodes, notices,
   profile fields, tables or assets. For derived dependencies, remove the prerequisite
   and verify an explicit pause or a safe native fallback.
7. Verify that no notices, fields or controls become inaccessible when an asset or
   launcher is absent. Preserve capability checks and broad third-party vetoes.
8. Record what was verified and what remains open. Restore only test-owned state;
   do not overwrite user edits made concurrently during the audit.

## Implementation order

Correct proven functional dependencies before reorganizing categories. Then add
the proposed parent-module logic and effective-state messaging, using the verified
matrix to decide which controls depend on the theme. Do not declare a real
dependency on the theme merely to conceal a missing component asset.

Keep each correction and its regression test in a focused commit. Run the unified
gate and relevant browser checks before pushing. Preserve all saved choices when
pausing a dependent surface or reverting a change.
