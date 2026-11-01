# Optional administration appearance

Status: proposal only; no runtime changes or live-site settings changes.

Reviewed against `65eace3` on 2026-11-01. Implementation must be reconciled with
subsequent changes before it reaches the local site.

## Intended behavior

Each account can choose AdminKit appearance or native WordPress appearance without
losing access to enabled AdminKit tools. An administrator sets the site's default.
Existing installations continue using AdminKit until a choice is saved.

Proposed controls:

- AdminKit → Configuration: **Default administration interface**, AdminKit or WordPress.
- Native Profile → Personal Options: **Administration interface**, Site default,
  AdminKit or WordPress. Show the current site default alongside the inherited choice.

Use one enum setting for the site default and one site-scoped user preference.
Absence of a user preference means inheritance. On Multisite, changing an account's
preference on one site must not change that account's choice on another site.
The site default requires `manage_options`; saving one's own preference must not.
Use the native profile submission, capability checks and nonce. Do not put the
personal preference into the site-wide settings REST payload or export.

Changing appearance takes effect only after a successful save and page reload.
An unsaved or rejected change must not replace the current interface. Changing
the site default affects inheriting accounts, not accounts with an explicit choice.

## Compatibility contract

| Surface or tool | Native WordPress appearance |
| --- | --- |
| Administration frame, fonts, palettes and automatic icon replacement | Native WordPress styles, including the account's native color scheme |
| Profile, General, Reading, Discussion and taxonomy layouts | Native screen structure; no automatic AdminKit card/tab rearrangement |
| Block editor, Site Editor, widgets editor and Customizer | Native chrome; no AdminKit dark-mode or variable bridge |
| Frontend toolbar | Native styling for that account; enabled toolbar actions remain available |
| Menu and toolbar managers | Keep saved ordering, labels, visibility and explicitly assigned icons |
| User quick edit, username changes, previews and active-items-first | Keep independent feature flags, permissions and behavior |
| Notification center | Keep collecting notices when enabled, with self-contained accessible UI |
| Clean interface | Keep explicitly selected footer/help hiding when enabled |
| AdminKit configuration pages and editors | Remain accessible with styles limited to their own content |
| Login screen | Continue using its existing independent site setting |
| Anonymous public pages | Continue emitting no AdminKit assets or markup |

Native appearance is a visual choice. It does not restore hidden navigation items,
change role capabilities, disable tools, reset configuration or change content.
Automatic icon replacement stops; explicit icons saved in a menu manager remain
part of that manager's configuration.

AdminKit light/dark preferences remain saved but do not recolor native WordPress
screens. Login appearance remains independent because there is no authenticated
account whose personal preference can be resolved there.

## Remove overlap in plugin appearance settings

Keep one site-wide inventory under Design system → Extensions. Describe these as
appearance exceptions, not plugin activation controls. Do not add a second set
of plugin switches under Features.

- An AdminKit account uses the existing per-plugin choices.
- A WordPress account receives native plugin interfaces regardless of those choices.
- Keep all existing integration flags and `generic_theming_off` values unchanged.
- Explain an inherited pause separately from a saved off choice. Do not show an
  appearance preference as globally disabled just because the viewing account is native.
- Remove the locked AdminKit “System” row from the exceptions list. Its own settings
  pages remain functional in both modes.
- Preserve integration host-version safety gates and third-party filter vetoes.

Likewise, the design editor remains accessible in native mode: administrators can
configure the site's AdminKit appearance even when they personally use WordPress.
Show a short contextual note; do not disable those site-wide controls for everyone.

## Implementation boundaries

Introduce one appearance resolver, loaded through `AdminKit_Plugin::MODULES`.
Resolve user identity at the relevant WordPress hooks, not at initial plugin load.
Do not cache a decision across users or sites. The resolver combines the site
default, account preference, surface context and applicable appearance exceptions.

Keep `adminkit/should_load` as the existing broad compatibility veto. Do not use a
false value there to implement native appearance: it currently gates feature PHP,
feature JavaScript, notifications and generic script enqueueing as well as styles.
Introduce a separate appearance decision and apply it to presentation code.
Move the existing plugin appearance exceptions to that decision, with regression
coverage for their current behavior and the preserved broad third-party veto.

Audit these owning sources rather than relying on CSS hiding:

- `inc/class-assets.php`: distinguish appearance dispatch from tool assets and
  script loading; apply the appearance decision to the admin body marker.
- `inc/class-theme-toggle.php`: gate prepaint scripts, toolbar controls, TinyMCE
  content styling and editor bridges consistently in every context.
- `inc/wp-core/`: classify layout rearrangement separately from functional tools.
  Native administration restores WordPress toolbar identity; custom login branding
  remains controlled by the independent login setting. Keep the configured logo
  stored for use when AdminKit appearance is selected again.
- `inc/features/`: retain routes, snapshots, storage and behavior; provide component
  styles for their own UI without loading the full administration skin.
- `inc/integrations/`: gate registered styles, inline variables, editor mappings
  and runtime appearance hooks, not just the primary adapter stylesheet.
- `inc/settings/` and `assets/js/settings/`: keep global configuration and personal
  preferences separate, and distinguish stored choices from effective appearance.

Tool styles must work without `body.adminkit`, without global `:root` appearance
tokens and without AdminKit's toolbar stylesheet. Restrict any required variables
to the tool's own root. Keep essential styles such as `[hidden]`, focus visibility,
dialog layout and inert-background behavior in the component layer. Keep preview
palettes scoped to preview containers rather than recoloring native WordPress.

## Staged commits and verification

1. **Resolver and storage:** add enum validation, site-scoped personal preference,
   inheritance and permission tests. Keep existing runtime appearance unchanged.
2. **Separate tools from appearance:** make tool assets self-contained; preserve
   the broad compatibility veto. Verify every enabled tool without the global skin.
3. **Apply the appearance choice:** gate all presentation paths and introduce the
   native profile and site-default controls. Reuse plugin exceptions and update
   their effective-state messaging. Keep configuration accessible in both modes.
4. **Verification and documentation:** cover real save/reload flows, account/site
   isolation, appearance restoration and all supported surface contexts.

Each commit must keep the default behavior working and pass `php dev/test-all.php`.
Add tests for actual state, security and behavior rather than source-text mirrors.
Do not bump `ADMINKIT_VERSION` or edit frozen Upgrade 6. Prefer lazy, backward
compatible defaults over a bulk migration of accounts or saved design settings.

At this baseline, `dev/package.php` requires `.git` to be a directory and rejects
linked worktrees. Until that tooling supports worktrees, run the full gate in a
standalone local clone of the exact candidate tree. Do not weaken the package
check or run against the concurrently edited live checkout to hide this failure.

Required verification matrix:

- Two accounts on the same site: one native, one AdminKit, with no preference leakage.
- Site-default changes with explicit and inherited personal choices.
- One shared account on two Multisite sites with independent choices.
- Both appearances with menu/toolbar managers independently on and off; retain
  the navigation-page visibility fix introduced in `65eace3`.
- Notifications, clean interface, quick edit, username changes, previews and sorting
  exercised in native mode, including permissions, keyboard access and failure cases.
- Supported and generic plugin appearance exceptions; paused adapter versions.
- Native Profile, settings screens, block/Site editors, Customizer, frontend toolbar
  and login; no missing styles/scripts, hidden screens or leftover prepaint classes.
- Saved AdminKit dark mode followed by native appearance and a switch back.
- Failed saves and unsaved drafts; no interface switch before successful persistence.
- Desktop/mobile, RTL, reduced motion and forced colors where affected.
- Packaged activation and anonymous frontend footprint remain unchanged.

## Reversal

**Account:** choose AdminKit in the native Profile field and save. Choose Site
default to resume inheritance. Neither action resets any site or tool setting.

**Site default:** set the default to AdminKit and save. Explicit personal choices
remain respected; label this clearly so an administrator does not expect a forced
reset of every account.

**Code:** revert the appearance implementation's identified commits in reverse
order, retaining later unrelated work. Record the actual commit IDs when they
exist. Never reset `main` to `65eace3`, because that would discard later work.
The previous code ignores the additional option/user-preference keys. Keep those
keys dormant during a revert; do not restore an old database or delete design,
menu, toolbar, account or content data. Reapplying the implementation recovers
the saved choices. Verify both directions before merging.

**Current proposal:** this document lives on `codex/classic-interface-proposal`
in an isolated worktree. The live plugin symlink continues to target the original
`adminkit/` checkout on `main`. This proposal needs no site rollback because it
changes no runtime code, WordPress options or user preferences.

## Parallel work

The Antigravity conversation identified for coordination is
`efdc7b87-c2ae-4de3-ba07-ef74638a2717`. It currently touches appearance CSS,
`assets/js/settings/main.js`, `inc/class-design-system.php` and `tokens/adminkit.json`,
and is examining Gutenberg styling. This is a point-in-time observation, not a lock.

Before implementation or integration, reread its recent file operations and the
actual Git status/diff/history. Keep experimental code in a separate worktree.
Integrate the latest completed parallel commits before testing the combined result;
do not stage, stash, overwrite or reset another worker's uncommitted changes.
Do not change the live symlink or switch the live branch to preview this proposal.

Observation occurs when this chat is active; this document does not establish a
background monitor, mutual file locking or a guarantee against all concurrent edits.
