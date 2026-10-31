# Settings workspace

The main workspace has Design system, Features and Navigation tabs. Navigation
contains Menu and Toolbar editors when enabled. Toolbar headings and scope hints
identify the Administration or Public site view. The latter configures the
WordPress bar shown on the frontend, normally for logged-in users; it does not
edit public pages or the site navigation menu. Both layouts remain independent,
and Save applies pending edits from both views. The legacy standalone editor
hosts remain supported. Settings are site-wide; light/dark preference remains
personal. Branding's single `logo_url` is edited in WordPress General Settings.

## Defaults and data

`AdminKit_Settings::schema()` is authoritative. Branding, icons, dark-mode toggle,
clean chrome, menu/toolbar editors and notifications default on.
Login styling, post previews, active-items-first, user quick edit and username changing are
opt-in. Detected adapters and generic plugin styling default on; explicit opt-outs
are preserved. Hidden menu/toolbar nodes do not revoke WordPress capabilities.

Settings live in `adminkit_settings`; toolbar configurations in `adminkit_toolbar`
with independent `admin` and `frontend` contexts. Menu snapshots live in the
network-shared menu table, keyed by site ID. Deactivation preserves data;
uninstallation removes AdminKit storage.

## Design system

Typography defaults to Geist. Supported fonts are Geist, System, Inter,
Plus Jakarta Sans, DM Sans, Manrope, Outfit, Poppins, Space Grotesk and IBM Plex Sans.
Legacy Humanist maps to Geist and Classic to Inter. The Design system tab contains
Colors, Typography, Shapes & elevation, and Plugins sections. Logos remain in
General Settings. Plugins lists installed integrations, without activating hosts.

`AdminKit_Settings::sanitize_design_system()` allowlists the saved profile:

| Member | Values | Default |
| --- | --- | --- |
| `type_scale` | finite 0.75–1.5, rounded to two decimals | 1 |
| `radius_scale` | finite 0–2, rounded to two decimals | 1 |
| `border_width` | integer 0, 1, 2 (numeric strings accepted) | 1 |
| `elevation` | default, flat, subtle, raised | default |
| `components.buttons.highlight` | off, hover, always | always |
| `colors.light`, `colors.dark` | eleven allowlisted hex roles | empty |

Unknown profile keys are discarded. No spacing scale is currently persisted.
Palette roles: `bg`, `surface`, `text`, `text-muted`, `border`, `accent`,
`on-accent`, `success-text`, `warning-text`, `danger-text`, `info-text`.
Valid scales between the preset choices remain selectable and are preserved when
the editor mounts. Missing colors inherit tokens. Invalid hex fields block browser saves and retain
the previous valid draft. Monochrome is the default accent; WordPress blue
(`adminkit`), custom and the Bricks provider are explicit choices.

`AdminKit_Design_System::css()` compiles saved foundations for administration,
login and editor contexts. Frontend output is scoped to `#wpadminbar`.
Decorative outlines and card elevation do not remove keyboard focus indicators
or internal row separators. Component choices compile to shared semantic roles.

## Feature discovery (C2)

Features are grouped into Appearance (including notifications), Navigation (menu
and toolbar managers), Tables and Accounts. In French these are Apparence,
Navigation, Tableaux and Comptes. Each selection-list item has a green filled active or
hollow inactive dot alongside its textual status; unavailable items remain hollow.

The Features tab uses search, an All/Active/Inactive selector and category buttons
with neutral circular count badges above a grouped selection list and one detail pane. The pane shows
the feature icon, category, effect, current state, availability hint and switch.
Search matches labels, descriptions and categories without case or accent
sensitivity. Search and status combine with category selection; empty groups
hide, and an empty result displays a status message. Counts for status reflect
search matches; category counts additionally reflect the status filter. Both
filters and counts use the effective draft state, including unsaved changes.
The green filled/hollow dots keep this same meaning; they do not confirm a save.

Search, status, category and selected feature are transient UI state. They do
not dirty settings or enter saved payloads. If the selected feature leaves the
results, the first visible feature is selected. Detail panels and their actual
checkbox inputs are created once and retained in the DOM while hidden, so
filtering and selection preserve draft controls. Switches update the existing
`state.features` draft and shared host dirty/save flow; there is no separate
feature transport or storage format.

Unavailable features and children whose parent is off expose disabled switches
with an explanation. Effective Active status requires availability, an enabled
draft value and an enabled parent when present. Turning a parent off preserves
the child's draft value. Bulk Enable all, Disable all and Reset to defaults live
in a disclosure and apply across all filters. They skip unavailable features and
catalog entries with `bulk: false`; otherwise they also update dependent child
values, even while the parent locks their individual switches. Defaults come
from the registered schema.

Catalog metadata supplies shared icon names. PHP resolves them through
`AdminKit_Icons::display_svg()` into trusted `iconSvg` bootstrap markup; feature
JavaScript does not maintain a second icon path library.

Selected category/list items and discovery controls use the existing
`--ak-border` role. The detail pane consumes shared `--ak-card-shadow`; the bulk
popup uses `--ak-shadow-elevated`. Its native `<details>` disclosure has a trailing
chevron that reflects the open state. These treatments reuse existing tokens.

Desktop and tablet use a grouped list beside a sticky detail pane. At 782px and
below, category controls form two columns, the list scrolls within a bounded
height and details follow the list. Selecting a feature scrolls to and focuses
its heading; the return control scrolls back and focuses its list button. If a
filter change hides or disables the focused control, focus moves to the next
selected feature button or the status selector for empty results.

The list distinguishes activation, deactivation and option changes awaiting Save.
The detail pane shows a To save badge when its master or any nested option differs
from the saved baseline, with text explaining when the change will apply. Returning
all choices to their saved values clears the feature pending indication.
Unavailable status keeps priority over draft activation text.

### Grouped feature options

Features may declare nested boolean `options` in the catalog. Registered keys
supply values/defaults to the bootstrap, share `state.features` and the existing
settings request, but do not create extra catalogue entries or inflate counts.
Search also matches option labels/descriptions. Nested controls stay visible but
are disabled while their parent is unavailable or off; choices are retained.
An explicit note explains that options are paused, that a pause awaits Save, or
why the feature is unavailable. Each option shows Enabled/Disabled by default;
semantic catalog labels can instead describe its effect. Clean interface uses
Hidden/Visible for its footer and Help choices. While the master is off, the
status reads Choice: followed by the retained choice, so it does not imply a
current effect. Each option also shows To save when its own value or the master
value differs from the saved baseline.

Enable all/Disable all only change the parent. Reset to defaults restores nested
options too, respecting the parent's availability and bulk exclusions.

Clean interface has `clean_chrome_hide_footer` and `clean_chrome_hide_help`, both
true by default to preserve the previous combined behavior without a migration.
The master `clean_chrome_enabled` gates their effects. Turning it off suspends
both effects without changing their stored values. Footer text/WordPress version
and the contextual Help tab can now be hidden independently. These settings affect
administration only. Existing per-element filters retain their override behavior.

Active items first has three enabled-by-default sub-options under its existing
opt-in master: `active_items_first_content_default` opens plain content arrivals
on Published, `active_items_first_plugins_default` opens plugins on Active and
follows the changed plugin after activation/deactivation, and
`active_items_first_sort` groups published/active rows in All. Landing and sorting
can be disabled independently. Searches, explicit ordering/status and native All
navigation remain authoritative. Missing sub-options retain the prior behavior;
turning the master off retains choices. No retired migration key is reused.

## Saving, import and export

The shared save bar saves all changed scopes, including drafts in hidden tabs.
Menu/toolbar requests run before settings, since settings can disable their routes.
Each payload is a snapshot; subsequent edits remain pending. Successful requests
are not retried after a later failure. Failed requests and newer edits prevent
reload. Successful settings writes update the feature-state baseline from the
response values, or from the posted snapshot when response values are absent.
They do not overwrite the current draft: edits made while a save is in flight
still compare against that acknowledged baseline and remain pending. A failed
write never acknowledges feature values. The endpoints are independent: a failure
can leave some scopes saved.

Settings POSTs replace only posted registered keys, preserving the General
Settings logo and unknown stored extension keys. Boolean false stays false;
legacy string booleans normalize on read. Empty color/text values remove overrides.
Malformed value types return HTTP 400 before mutation. Storage failures return
HTTP 500 rather than a success message; unchanged values count as success.

Import/export covers main settings, while the menu editor has its own export.
Import requires a clean draft and no active save, including when its file reader
finishes. Menu reset requires an explicit empty `items` array. Toolbar context
reset requires an explicit empty context; omitted contexts retain their values.

REST settings/menu writes require `manage_options`; toolbar uses its existing
filterable capability. Cookie-authenticated REST writes retain WordPress nonce
verification. Native user tools retain their form/AJAX nonce and target checks.

## Verification

Run the commands in [FUNCTIONAL-AUDIT.md](FUNCTIONAL-AUDIT.md). Offline tests cover
persistence failures and draft behavior. They do not prove third-party host
compatibility, live email delivery or live account-renaming behavior.

## Translation catalogs

UI text is translated in PHP and sent to JavaScript in each screen's bootstrap.
`php dev/i18n.php --write` synchronizes POT/PO and compiles both MO (with `msgfmt`)
and `adminkit-fr_FR.l10n.php`. The drift gate verifies source coverage, compiled
parity and JS bootstrap keys. Main exports carry the current plugin version and
all registered values; their `_adminkit: settings` marker is required on import.

Custom hover colors derive from the effective per-theme accent and foreground in
both palette/shape previews and the PHP compiler; an explicit foreground override
remains effective. Provider previews use provider hover variables when available.
