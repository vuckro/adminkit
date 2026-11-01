# Administration interface choice

AdminKit appearance is optional. Enabled tools and their saved configurations are
independent of the account's appearance choice.

## Try it locally

1. Open `http://localhost:10013/wp-admin/profile.php#settings`.
2. In **Administration interface**, choose **WordPress classic** and save the profile.
   In French: **Interface d’administration**, then **Mettre à jour le profil**.
   AdminKit places the field under **Réglages**; native WordPress places it in
   **Options personnelles**.
3. Visit the dashboard, posts, profile and AdminKit settings. WordPress supplies
   the frame, color scheme and editor appearance. AdminKit's automatic icon
   replacement, dark mode and screen rearrangements are suspended.
4. Return to the profile, choose **AdminKit** and save to restore modern appearance.
   Choose **Follow site default** to resume inheritance instead.

Changes apply after a successful save. They preserve palettes, logos, module
switches, navigation layouts and the saved light/dark preference.

## Defaults and tools

Under **AdminKit → Configuration**, **Default administration interface** controls
accounts following the site default. Save with the existing settings save button.
The adjacent **My administration interface** link opens the personal profile.

Existing installations default to AdminKit. An explicit personal choice overrides
the default while the site makes the theme available. Under **Features → Appearance**,
**Administration theme** controls availability for the site. Disabling it suspends
the personal and default selectors, retaining their values. Re-enabling it restores
the saved choices. Preferences use site-scoped user metadata, outside the settings
export. A shared Multisite account can choose differently on each site. Personal
saving uses the successful native profile-update hook, its own nonce and the
current account's `edit_user` capability; it cannot change another account.

The unauthenticated login screen retains its independent site setting.

Menu/toolbar managers, notifications, user quick edit, username changes, previews,
sorting and explicit clean-interface choices keep their own switches. Ordering,
renamed items, hidden entries and explicitly assigned icons remain applied when
their manager is enabled. Native appearance does not undo those tools.

Tool components use local scoped variables and their own styles, without global
AdminKit tokens, fonts or editor bridges. Configuration pages remain accessible
within the native frame. Native General settings retain a logo URL field.

**Design system → Extensions** is the single list of site-wide appearance
exceptions. Native appearance pauses skins for that account while preserving the
saved switches. Design settings remain editable for accounts using AdminKit; a
contextual note explains the distinction. The locked AdminKit system row is omitted.

**Design system → General → Theme options** contains the dark-mode switch.
Design system remains accessible to administrators using native appearance while
the site theme is available, because these settings also affect other accounts.
When only custom login is enabled, shared design controls remain available and
the Extensions section is omitted. When both theme and login are disabled, the
Design system submenu is hidden; its direct URL explains how to reactivate it.

Tools have their own prerequisites rather than inheriting the theme switch.
For example, a hidden notification launcher pauses grouping and keeps notices
inline. The catalogue distinguishes enabled-but-paused tools and a theme not
applied to the current account. See [FEATURE-DEPENDENCIES.md](FEATURE-DEPENDENCIES.md)
for the verified matrix and remaining coverage.

## Recovery

- Personal: save **AdminKit** in the native Profile field.
- Site default: save **AdminKit** in Configuration. Explicit personal choices remain.
- Site availability: enable **Administration theme** in Features → Appearance.
  Switching off/on retains the design, default interface and personal preferences.
- Code: revert the interface implementation commits in reverse order, identifying
  them in Git history. The preceding baseline is `a4df8f0`, including Gemini's
  completed work. Do not reset the branch to that baseline: later unrelated work
  may exist.
- Keep new default/preference keys dormant after a code revert. Older code ignores
  them. No database restore, user deletion, schema downgrade or design/navigation
  reset is required.

## Verification and extension hooks

Offline checks cover inheritance, malformed inputs, nonce/capability denial,
account/site isolation, broad third-party vetoes, tool assets without the skin,
and design-preview isolation from the document root. Settings saves use the
existing validated REST schema.

Local browser checks are separate. Single-site verification does not establish
live Multisite compatibility or visual coverage of every third-party plugin.

Verified on localhost:10013: native profile save/reload, explicit personal override
of a classic site default, real settings save/reload, all five AdminKit pages,
notification drawer open/close, quick-edit open/cancel, username confirmation
open/cancel, preview column, native General/Reading layouts, the block editor,
frontend toolbar and 390px configuration layout. No page errors or failed AdminKit
asset requests occurred in that run. Test settings and the personal preference
were restored to their original values afterward.

Presentation can be vetoed through `adminkit/appearance_enabled` with its context
(`admin`, `editor`, `customize`, `login`, `frontend`). `adminkit/should_load` retains
its broad veto over appearance and tools. Registry entries default to presentation
assets; functional entries opt out with `appearance => false`. Component variables
are generated by `tokens/build.php`.
