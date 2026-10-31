# WooCommerce UI review — 2026-10-31

Target: localhost:10013, WooCommerce 11.2.1. CSS-only adapter refinement.

- HPOS custom-field tables use theme surfaces throughout.
- Product publishing controls wrap without float-induced misalignment; update notices reserve their icon space and TinyMCE focus keeps a themed surface.
- Product tour headings, descriptions, navigation and focus consume AdminKit roles on classic screens as well as wc-admin.
- Product Quick Edit gives its WooCommerce fieldset the full row, with responsive three/two/one-column data fields and aligned dimensions. Native field names, visibility toggles and save handlers remain intact.
- Product row actions omit the empty ID flex slot to align Edit with the title.
- Analytics dates and import status wrap, summary borders follow tokens at responsive breakpoints, and D3 tooltip/grid colors support dark mode without changing series colors.

Verification: token build check, dev/check.php, CSS contracts, PHP/JS syntax, adapter budget audit and installed WooCommerce drift check pass. Impeccable detector reported no findings. Browser inspection covered dark desktop product Quick Edit, product editor, tour steps 1–2, HPOS custom-field computed surfaces, dark mobile analytics/product tour, and light analytics at 1024px. No checkout, order creation, product save, or actual analytics import was submitted. Tooltip colors, publication notice spacing and draft-button wrapping were source-reviewed; a complete transaction and accessibility audit was outside this visual pass.

The initial CSS changes were included by a concurrent commit (298b01f); the follow-up preserves that history and completes the fieldset/grid/border corrections.

## Settings, payments and marketing follow-up

Offline sortable payment rows and the BACS account grid now use theme surfaces, text and border roles. Native help icons stay in their label flow, including keyboard-accessible tooltip triggers. Settings sub-tabs retain horizontal padding even for WooCommerce's special first link. Marketing and offline-payment buttons use the shared radius, with 44px mobile targets. Coupon notices explicitly restore vertical padding and paragraph alignment.

Verified dark desktop general settings and offline payments, BACS desktop/mobile with no document overflow, Marketing button radius and sub-tab padding computed styles, coupon notice text/icon centers, and source-reviewed shared light roles. Existing gateway activation and bank details were not changed; no extension was installed. All standard code/token/CSS and adapter checks passed. A direct BACS navigation once returned a WordPress access error; entering through the native Manage action loaded the form normally.

## Dashboard, order statuses and React shipping/onboarding follow-up

The dashboard status widget consumes text/border roles, including its totals. Order badges use compact semantic pills with Lucide icons from the existing registry; native status names and filtering are retained. Customer/report dropdowns use softer borders and larger radii. Shipping region trees, selected tags, COD method trees, pickup-location tables, provider loading rows, drag handles, recommendation badges and arrows now consume theme roles. WooPayments' WooCommerce-owned onboarding modal is themed alongside PayPal's root palette and hardcoded headings/buttons.

Validated dark desktop dashboard, completed/pending order badges (14px masks), shipping descriptions and region field, pickup table surfaces, mobile pickup layout (no document overflow), provider skeletons and WooPayments connection-step modal. The existing WooPayments flow lands on step 2; localhost cannot connect to Jetpack, so subsequent financial onboarding was not submitted. PayPal account activation, gateway settings, shipping methods, orders and pickup locations were not changed. Other badge states and WooPayments method-selection surfaces were source-reviewed. All registry icons resolve; standard token/code/CSS/adapter checks pass. Light values use existing semantic roles; this follow-up is not a complete checkout/financial onboarding verification.

Final readback: customer dropdown computed border matches --ak-border and radius is 12px. PayPal welcome view was also checked in light mode, then the original dark preference restored; its monochrome SVG wordmark and feature text follow semantic foregrounds.

## Marketplace, inbox and order-history polish

Marketplace inner card surfaces, prices, vendor labels and quality chips follow theme roles; sponsored cards override the host's inline white gradient. Shared wc-admin primary/secondary actions now use rounded controls and neutral secondary borders with visible keyboard focus. Inbox confirmation message/actions and its host-pinned header divider are themed, with wrapping actions and no horizontal overflow. Store-management quick-link hover and external icons inherit foreground roles. Order-note bubbles and the add-note divider use quiet borders. The current sidebar notch matches the canvas instead of leaving an accent outline in light mode. Setup-widget description specificity was strengthened against its host stylesheet.

Verified Marketplace in light/dark desktop, light sidebar-notch computed color, existing HPOS order-note borders/radii, and inbox confirmation in dark desktop/mobile at 390px (no horizontal overflow). Confirmation was cancelled; no messages or notes were deleted. Setup widget is no longer present on the current dashboard, so its description fix was source-reviewed against dashboard-setup.css. Original dark preference and default viewport restored. Token build, color checks, CSS contracts, adapter budget/drift checks, diff whitespace and Impeccable detector pass. The two added important overrides are documented host constraints (inline gradient and important divider).

## Home introduction and Blueprint settings follow-up

Home's introduction uses a WPDS Text span; it now receives the heading role explicitly. Activity-panel hover/focus/active surfaces, empty-state headings and activity-card borders follow theme roles. Blueprint's embedded settings island now themes its headings, descriptions, upload/success surfaces, export-group separators/counts and icons.

Browser checked dark home introduction, existing rounded neutral inbox buttons, opened Commandes panel/focused toggle and its empty-state heading, plus Blueprint in light/dark. No import/export or configuration save was submitted. Dark preference restored. Token/color/CSS checks, adapter budget/drift, whitespace and Impeccable detector pass.

## Product row-action leading gap

Corrected the hidden-ID selector to target the actual native product list (.post-type-product .wp-list-table); WooCommerce does not give this table a .products class. Removing the zero-width ID flex item also removes its leading flex gap. Browser keyboard reveal confirmed identical title/Edit x coordinates (360px), with ID display:none. CSS contracts, color checks and whitespace checks pass.
