# Fluent applications: Hub and standalone administration

AdminKit uses one theme bridge for supported Fluent administration screens. The
optional FluentHub replacement shell is a presentation layer, not a prerequisite
for the bridge. Each owner must be active, within its supported version range,
and enabled in AdminKit; global appearance and loading opt-outs still apply.
The shared bridge emits no public frontend assets.

## Native ownership

WordPress owns the theme attribute and preference; Fluent's documented native
`fluent_theme_mode` preference and origin-scoped BroadcastChannel carry changes
in both directions. System intent remains automatic instead of becoming a fixed
light/dark preference. Native root/body classes mirror resolved mode. Storage and
channel failures fall back gracefully; repeated initialization is idempotent.
Existing Booking and Cart prepaint synchronizers no longer overwrite the shared
system preference. Booking's same-origin rich-text synchronization is retained.

CRM and Booking isolate third-party scripts. Their native asset allowlist filters
now include the exact `/adminkit/` directory on owned, enabled admin screens.
The original lists remain intact; no asset filter is removed and other plugin
folders are not admitted. Public community portals and login/security behavior
remain owned by their host plugins.

## Version boundaries and surfaces

- FluentSMTP 2.4: native `--fsm-*` roles and shared Element Plus primitives,
  including teleported controls. The legacy 2.2 selector skin remains separate;
  unreviewed 2.3 screens do not inherit either skin. The dashboard split follows
  actual application width, accommodating Hub's rail and WordPress's menu.
- Fluent Forms 6.2: bounded editor rail, expanding canvas, stacked narrow layout,
  keyboard focus and native scrolling. Saved form fields, values, configured
  submit styles and frontend behavior are not rewritten.
- FluentSnippets: native semantic palette plus the shared bridge, inside or
  outside Hub. No snippet is created, activated or executed by this integration.
- FluentCRM 3.3: additional subscriber-status, compose and sidebar semantic roles.
- FluentBooking 2.6: additional notice, switch, placeholder and status roles.
- FluentCommunity 2.12: admin/onboarding and admin insight roles only; the public
  community and its frontend editor are excluded.
- FluentAuth 3.1: a dedicated admin-only native token/Element Plus adapter. No
  authentication setting, login redirect, social login, 2FA or public form changes.
- Existing Cart and Affiliate adapters participate through their own version and
  activation gates. This change does not certify uninstalled host versions.

Unknown subsequent minor versions still pause selector-based adapters rather
than silently applying a possibly incompatible skin. Additional Fluent products
without an adapter retain generic WordPress styling; this is not a promise of
native coverage for every product in the vendor catalogue.

## Translation maintenance

Host application translations belong to their own text domains and protected
Loco catalogues, not AdminKit's runtime. No DOM text replacement or vendor-file
patch is used. On Flippizz, selected French dashboard/editor labels were merged
into the current Loco PO files and recompiled with Loco's native compiler. User
form content and labels not exposed by the host's localization mechanism remain
host/content-owned. AdminKit's own messages remain in its French catalogue.

## Verification

Run `php dev/test-all.php`. The Fluent behavioral suite exercises native and
AdminKit choices, standalone mode, system changes, class mirroring, storage
removal/denial, absent/rejected BroadcastChannel, opt-outs and idempotence.
`dev/check-fluent-theme.php` covers screen ownership, inactive/paused hosts,
integration opt-out and the exact native asset allowlist boundary.

Host rendering and business-operation verification are separate from the offline
gate. The site deployment record must identify the exact commit, archive,
verified routes and limits. Inactive hosts should be tested in a disposable
WordPress instance with empty data instead of activating them on a client site.
No delivery test, campaign, booking, community publication or security change is
required to review this visual integration.
