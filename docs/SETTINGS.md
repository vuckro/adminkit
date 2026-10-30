# Settings workspace and appearance presets

The first AdminKit submenu entry is Dashboard, followed by Menu and Toolbar when available. The parent menu opens Dashboard. The main AdminKit settings screen is a configuration workspace with overview, section navigation, a foundations editor and grouped feature rows. Settings are site-wide unless explicitly presented as personal preferences. The existing personal light/dark preference stays personal.

## Appearance storage and validation

`AdminKit_Settings` registers three allowlisted settings. Unknown density/radius values normalize to `default`; unknown font values normalize to `humanist`. User-authored CSS is never emitted.

| Setting | Allowed values | Default |
| --- | --- | --- |
| `ui_density` | `default`, `compact`, `comfortable` | `default` |
| `ui_radius` | `default`, `square`, `soft` | `default` |
| `ui_font` | `default`, `humanist`, `classic`, `system` | `humanist` |

`AdminKit_Assets::appearance_css()` emits mapped preset declarations. Density/radius `default` and explicit font `system` add no override, allowing the existing token and card-style system to supply its values. Humanist, the registered font default, emits its bounded font stack. The baseline control-height token is 32px; the baseline touch target is 44px. Defaults do not force native table cell padding.

| Preset | Emitted tokens / behavior |
| --- | --- |
| Density: compact | `--ak-control-height:28px`; `--ak-ui-row-space:8px` |
| Density: comfortable | `--ak-control-height:40px`; `--ak-ui-row-space:16px` |
| Radius: square | `--ak-radius-s:2px`; `--ak-radius-m:4px`; `--ak-radius-l:4px`; `--ak-card-radius:4px` |
| Radius: soft | `--ak-radius-s:10px`; `--ak-radius-m:14px`; `--ak-radius-l:16px`; `--ak-card-radius:16px` |
| Font: humanist | `--ak-font-body:"Trebuchet MS",Verdana,sans-serif` |
| Font: classic | `--ak-font-body:Arial,Helvetica,sans-serif` |

Non-default density also sets vertical padding on `body.wp-admin .widefat td` and `th` through `--ak-ui-row-space`. Control heights, radii and fonts affect components that consume their respective `--ak-*` tokens; this does not guarantee that every third-party layout follows the presets. Adapters retain their own layouts.

Explicit System uses `ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif`. Humanist defaults to `"Trebuchet MS",Verdana,sans-serif`; Classic uses `Arial,Helvetica,sans-serif`. Legacy `default` maps to Humanist in the editor until Save; explicit `system` stays System. Typography choices are bounded system stacks. They do not introduce font upload, arbitrary font names or remote custom-font loading.

## Scope and preview

Saved foundations are compiled by `AdminKit_Design_System::css()` and appended after the baseline/accent CSS in admin, login, editor and Customizer contexts. The Gutenberg iframe receives the same compiler output through its existing bridge. On the public frontend, foundations target only `#wpadminbar`; page typography, surfaces and content remain owned by the theme.

Light/dark palette overrides now drive shared semantic roles and their hover, focus, foreground and status-tint derivatives. Saved continuous scales drive native WordPress text roles, owned component/adaptor text and spacing declarations, radii and control heights. Pill/icon geometry, breakpoints and structural offsets remain separate. Legacy density/radius values remain stored; the current foundation radius scale has precedence. No blanket `zoom`, transform or universal font override is used.

## Features and overview

Feature rows show a short summary and an information button beside the title. The button reveals an inline contextual panel with the functional description and technical storage facts: the current site’s options table, the adminkit_settings option, the exact setting key and registered default. It supports keyboard activation and Escape to close and restore focus; opening help does not dirty or save settings. Off settings remain readable; the switch communicates their state. Missing prerequisites and parent dependencies preserve their existing locking behavior.

The overview groups identity and feature/adapter states in a single surfaced panel. All rows share the same surface; action links use neutral secondary button styling, independently of the selected brand accent. The overview numerator counts enabled, available feature rows whose parent, if present, is enabled. The denominator counts all available rows. Unavailable rows are excluded. These are row counts, not a claim that all configured modules are currently executing. Identity and active-host adapter summaries use the available settings/catalog data.

## Saving and security

Main settings, menu and toolbar screens share one bottom-right sticky save bar, with status alongside the action. At narrow widths the bar wraps and retains touch-sized buttons. The existing save transport, REST nonce and capability checks are unchanged. Appearance settings participate in the existing gather/save/import/export flow.

## Validation and limits

Browser save/reload checks confirmed changed token values, including a 28px control height, a 16px card radius and the Trebuchet stack; defaults were then restored. This validates the tested persistence/token path, not every visual combination.

`output/settings-redesign/finish-review.md` retains `recapture`: long full-page screenshots include sticky-bar occlusion and supplemental top/bottom viewport captures could not all be completed after browser disconnection. Recorded viewport measurements explain the 15px scrollbar gutter in capture widths. This documentation does not claim release readiness or whole-surface visual approval.

Implementation sources: `inc/settings/class-settings.php`, `inc/class-assets.php`, `assets/js/settings.js`, `assets/js/settings/main.js`, `assets/css/settings.css`, `assets/css/settings/main.css`, `tokens/adminkit.json`, and `tokens/palettes/waaskit.json`.

## Extension defaults and debugging

Detected native adapters and generic plugin styling default to enabled. This controls AdminKit styling, not WordPress plugin activation. Saved native adapter choices remain effective, including explicit false values. Generic opt-outs are stored as plugin file paths in `generic_theming_off`; newly detected plugins are therefore enabled automatically. The legacy `generic_theming_on` allowlist is no longer used. Resetting Extensions enables every available toggle; changes remain pending until saved. Inactive WordPress plugins remain non-actionable. Disabled save buttons use a muted surface and readable muted text without a shadow; locked row labels retain full opacity, with only the locked switch subdued.

## Foundations editor

The Design system tab has a category tablist: Identity, Colors, Typography, Shapes & elevation, and Spacing. Identity opens first; one dedicated pane is visible. Arrow keys, Home and End select and focus categories; roving tabindex keeps only the active category in the Tab sequence. At 782px the vertical navigation becomes horizontal and updates its ARIA orientation. Changing category never dirties the profile.

Colors provides independently scoped light/dark WaasKit palettes with eleven editable roles: `bg`, `surface`, `text`, `text-muted`, `border`, `accent`, `on-accent`, `success-text`, `warning-text`, `danger-text`, and `info-text`. Overrides live in `design_system.colors.light` / `.dark` and accept only hex colors. Missing values inherit generated token CSS. The `ak-bp__frame` class remains solely as palette scope.

Native picker display converts inherited computed colors to sRGB without storing that conversion until edited. Clearing a hex field or resetting a role removes its override; resetting a palette removes all overrides for that mode. Invalid hex shows an error and retains the previous valid state. Choosing an accent preset clears only `accent` and `on-accent` overrides in both modes. Text/background contrast is displayed; values below 4.5 receive a warning treatment.

The explicit blue accent is labeled WordPress (stored source `adminkit`). Accent options share equal widths, with custom swatch/hex below. Arrow keys, Home and End select/focus accent radio options; only the selected option remains in the Tab sequence.

Typography defaults to Humanist. Its ten specimen rows, 6xl through xs, interpolate between viewport widths 320–1440px, multiply by type scale, and cap at 6rem (96px with a 16px root). Each row displays its computed pixel size. Sample text and weight controls are transient and do not dirty settings; selected font and type scale persist through the existing profile flow.

Shapes shows a 2/4/8/12/16/24px radius family multiplied by radius scale; pill geometry remains separate. Spacing shows 2/4/8/12/16/24/32/48px multiplied by space scale. These specimens share the same scales now consumed by administration components.

`AdminKit_Settings::sanitize_design_system()` accepts these members, discards unknown keys, and restores invalid values to defaults:

| Member | Accepted range or values | UI step | Default |
| --- | --- | --- | --- |
| `type_scale` | 0.75–1.5 | 0.01 | 1 |
| `radius_scale` | 0–2 | 0.05 | 1 |
| `space_scale` | 0.5–2 | 0.05 | 1 |
| `elevation` | default, flat, subtle, raised | select | default |
| `border_width` | 0, 1, 2, 3, 4 | select | 1 |
| `colors.light`, `colors.dark` | eleven allowlisted hex role overrides | picker / hex | empty objects |

Scale values are finite, within bounds and rounded server-side to two decimals; UI steps do not impose a discrete server allowlist. Values participate in save/import/export and are compiled into shared runtime tokens after saving. Identity and existing accent/provider paths remain intact; explicit per-role palette values win over preset/provider defaults, including custom accents in dark mode.

`output/foundations-editor-review/*.png` uses shipping CSS/JS with synthetic boot data and a mocked API at widths 1440 and 390px. It provides isolated rendering/interaction evidence, not authenticated WordPress persistence verification. The finish review is ship at this isolated-editor scope. Earlier save/reload checks above concern the previous bounded preset implementation and do not validate this new profile.

### Feature status filters

The Features screen combines text search with All / Active / Inactive filters and counts for the current search. Status reflects the current unsaved configuration and prerequisites; unavailable or parent-blocked features are inactive. Filtering does not modify settings. Groups with no matching rows disappear. Bulk actions retain their global scope, explained below the controls.

## Runtime integration verification (2026-10-30)

`php dev/check-design-system.php` checks the real compiler and sanitizer, light/dark roles, scales including zero radius, contrasting action foregrounds, legacy RGB consumers, derived states, CSS injection rejection, frontend containment and a guard against fixed text sizes returning in owned CSS. Existing token, contrast, CSS-contract, adapter-debt, content-list and upgrade checks also pass.

`output/runtime-design-system/` contains browser fixtures using shipping styles, generated runtime CSS and the actual Gutenberg theme bridge. Computed text changes from 13px to 19.5px at type scale 1.5; postbox padding from 16px to 32px at spacing 2; radius reaches 0px. Light/dark custom backgrounds synchronize into the iframe. A toolbar-only compilation changes toolbar tokens while leaving the parent page background at its baseline. Five settings panes were inspected at 1440px and 390px with maximum type/spacing values.

This is isolated rendering and compiler evidence. Authenticated WordPress save/reload and real screens of all third-party extensions are not claimed as verified. An automatic approval review refused creation of a temporary administrator session; no authentication credential was created for this verification.

## Automatic custom neutrals

With `accent_source=custom` and a valid brand hex, the runtime generates five neutral roles from that seed (background, surface, text, muted text and border). `AdminKit_Design_System::neutral_recipe()` defines low-percentage sRGB interpolation per mode. It is passed to the editor as boot data so the browser and PHP use one recipe. Generated values remain inherited and are never saved into role overrides; explicit per-role edits win, and reset returns to the generated value. Monochrome/WordPress/provider presets keep their existing baseline. Semantic status hues remain independent.

The regression suite samples 4,096 seeds in both modes and checks body/muted text against both backgrounds at 4.5:1. Arbitrary manual palette changes can still reduce contrast; the editor continues to display its contrast warning. Browser checks cover PHP/JS parity, manual override preservation and reset.

## Color reliability and simplified foundations

Unsaved accent changes no longer modify the document theme/accent attribute or global CSS. All color experiments remain in local light/dark palette scopes; saving reloads the page and applies the complete PHP-compiled palette together. WordPress/monochrome palette scopes select their own preset independently of the saved document preset. Invalid color inputs block saving and focus the affected field rather than silently saving a previous value.

Typography now contains only family and scale; Shapes only radius scale and elevation; Spacing only scale. The specimen galleries and transient text/weight tools were removed. Identity upload/placement controls and stored settings are preserved. Browser checks validate distinct preset colors, scoped unsaved custom neutrals and the simplified controls; authenticated save/reload remains unverified.

## Current simplified scope

Automatic tinting of neutral roles was removed after visual review. Custom now
changes only the accent family; generated neutral recipes and editor boot data
were removed. Explicit saved per-role overrides remain intact. Typography and Spacing show an In development notice. Shapes/elevation exposes
a 0–2 corner scale, 0–4px outline width and Default/Flat/Subtle/Raised surface elevation, with scoped
light/dark previews and a reset for these three values. Changes apply after Save.
Default preserves the restored contact shadows; Flat explicitly removes card and
table shadows. Elevation does not change control shadows or focus indicators.
Outline width applies to owned native buttons, fields and outer card/table frames;
internal separators retain one pixel. Primary buttons keep a shared contact shadow
and top inset highlight in both themes, including alongside keyboard focus.
Third-party adapters retain their own geometry unless they consume these roles.
Existing saved elevation values are preserved.

## Single brand logo

Identity exposes one logo and an exclusive Upload / Image URL source selector.
`logo_url` wins over legacy mode-specific settings; legacy values remain readable
until the next save, which migrates to the single URL and clears obsolete slots.
Removing a logo clears both old slots to prevent an unexpected fallback.
SVG images are rendered as external images, never user-authored inline markup.
A monochrome SVG reverses colors in dark mode, preserving inverse lettering;
raster images retain original colors. Administrator SVG uploads accept only a
256KB vector subset with no scripts, event handlers, embedded/external content,
DTD/entities or styles. The supplied WaasKit SVG was imported as attachment 36
and selected locally. No public frontend branding was changed outside the toolbar.

Logo source selection is implicit: the import button and URL field are directly visible. A successful media pick sets upload source; a valid URL edit sets URL source. Both update one shared logo value. Replacing/removing clears stale URL validation errors. No source dropdown is rendered.

### Design system → Components → Buttons

`design_system.components.buttons.highlight` accepts `off`, `hover` or `always` (default). Older exports without `components` retain the existing permanent highlight; malformed values and unknown keys are discarded by `sanitize_design_system()`. This nested allowlist is the extension point for future component settings, without introducing a second option or save endpoint.

The shared settings Save and JSON import/export include the component draft. Light/dark previews inherit the current palette, corner scale and outline width. Changing a component only marks the draft dirty; it does not save or alter other foundation values.

The compiler emits separate resting/hover highlight and elevation tokens. Disabling the highlight also removes the inset highlight from the dark elevation, while keeping its outer shadow. Native primary buttons, primary page actions and AdminKit primary buttons consume the shared roles. Secondary/disabled controls and keyboard focus keep their established behavior. Vendor controls with their own shadows require adapter support; public page buttons are outside scope.

Border width is a component-outline setting, not an instruction to remove every line. Zero removes decorative outlines on token-consuming buttons, fields, cards, tables, status strips, pagination and notices. Row dividers, the administration frame and semantic indicators remain independent. Radio outlines keep a minimum 1px because they identify the control; focus rings remain independent. Existing profiles retain 1px. Values outside the integer allowlist 0–4 fall back to 1px.

The Shapes and Components previews render in a separate region below the settings panel. One Light/Dark button group selects the visible theme; the choice is shared across these sections for the lifetime of the page, initializes from the administration theme, and is never saved. Switching theme does not mark the draft dirty. Other sections hide this preview region. Both theme frames stay available to the palette/shape draft refresh so switching always shows current values.

Colors keeps the in-panel Light/Dark switch and both independent drafts, but no external sample/contrast preview or scope note. Shapes and Components retain the shared preview region. The reveal-field validation behavior remains in place.

### Brand identity feature

The overview shows feature and adapter counts; it no longer contains the identity summary. Features → Appearance contains `branding_enabled`, a boolean defaulting to true to preserve existing automatic toolbar branding. Its settings link opens the administration-logo field in native General settings. Off disables AdminKit’s toolbar mark injection and branding CSS for administration and frontend toolbars; stored logo URLs remain intact. Login styling and its independent logo setting are unchanged. The feature participates in the shared save, export/import, status counts and bulk controls.

Shapes and Components no longer expose local Light/Dark preview controls. Their single visible preview follows `data-adminkit-theme` and updates when the administration toolbar changes it, without marking the settings draft dirty. The Colors editor retains its independent palette-theme selector.
