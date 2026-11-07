# AdminKit consolidation, code quality and security review

The `main` branch did not contain the Cooms settings simplification and follow-up fixes. This consolidation joins the deployed dashboard correction (`447cc46`) with the historical Cooms branch (`5c2d775`, including `5568483` and `eab61a9`) while retaining the newer Rank Math and FunnelKit audits already on main.

## Result

- Keep the simplified interface: Features → Administration theme exposes accent / text on accent in light and dark; Plugins remains available when appearance is disabled. Preserve hidden foundation/font settings and complete import/export.
- Retain Cooms menu labels after late host rewrites, configured icon priority, query-route IDs and stable toolbar group order. Retain current Rank Math secondary-page/editor/column coverage and ACF refinements.
- One Rank Math adapter instead of competing auto-discovered adapters. Its column styles already cover the historical Cooms list fix.
- One FunnelKit widget stylesheet instead of overlapping dashboard declarations. Keep the Cooms placeholder/reduced-motion handling. The app layers retain independent version gates and keyboard tests from both branches; Builder supports the inspected 3.17/3.18 series, Automations 3.8.
- Remove 72 settings CSS selectors with no remaining runtime producer, 51 unused translated dictionary keys, the unused design tab icon, the unused fonts bootstrap payload and an empty palette refresh hook. Regenerate all four translation artifacts. Retain runtime font compilation and legacy bookmarked routes intentionally.

## Security scope and evidence

Reviewed settings/menu/toolbar REST routes, user quick-edit AJAX, rename/profile hooks, branding SVG upload and canonicalization, icon SVG validation, custom URLs, storage/schema boundaries and the integration boot/asset registry. Existing capability checks, WordPress REST cookie/nonce authentication, profile/AJAX nonces, strict URL/SVG allowlists and prepared storage paths remain in place. No new unauthenticated endpoint or runtime dependency is introduced.

The offline suite exercises actual controllers with denied permissions, malformed payloads, partial saves, failed writes, menu XSS, SVGs, role restrictions and preservation of unrelated settings. The browser suite adds real WordPress anonymous denial checks for toolbar reads and settings/menu/toolbar writes; empty payloads prevent mutation even when testing a failing permission guard. No critical vulnerability was identified in the inspected AdminKit paths; this is not a penetration test of WordPress or every installed host plugin.

Local rendering uses the actual shipped settings scripts/styles and current translations with illustrative bootstrap data and simulated persistence. Light/dark at 1440, 900 and 390 pixels have no horizontal page/dialog overflow. Hidden typography/scales/colors, reset scope and partial-save behavior are covered by the real JavaScript modules in JSDOM. CI additionally runs disposable WordPress/SQLite browser flows and validates the packaged plugin.

## Deployment contract

Merge the reviewed consolidation into main after successful CI. Package that precise main SHA. For each authorized site, verify site URL, archive hash and concurrent plugin/configuration/menu state; create private plugin and database backups; install only AdminKit through WP-CLI; then verify every shipped file, absence of extras, active boot, unchanged configuration/menu hashes, public availability and anonymous API denial. Do not change content, payments, emails, users or other plugins to exercise this UI update.
