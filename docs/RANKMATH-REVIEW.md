> Consolidation du 7 novembre 2026 : cette recette historique est reprise par l’unique adaptateur `inc/integrations/plugins/rank-math/`. Le doublon `seo-by-rank-math/` n’est pas livré. Les styles de colonnes sont repris dans `columns.css` et les écrans éditeur sont conditionnés aux assets natifs réellement chargés.

# Rank Math — editor compatibility

6 November 2026. Inspected installed Rank Math 1.0.281 native metabox,
Gutenberg and schema styles. Adapter is limited to post and taxonomy editing
screens; selectors target Rank Math roots, including its modal portals.
The integration can be disabled independently in AdminKit Extensions.

Tabs, preview framing, labels, input surfaces, schema cards, notices and
semantic scores use AdminKit tokens. Native React handlers, SEO metadata,
structured data output and saving remain owned by Rank Math. No content was
saved and no production site was deployed.

Validation: full 216-check quality gate. Isolated representative components
with native host CSS checked in light/dark at desktop and 390 px; document
width 390 px in both mobile checks. Active tabs and error/success/warning
colors were inspected. This is component validation, not a complete connected
Rank Math workflow or a certification of all Pro features. The adapter range
is pinned to 1.0.x; later minor versions fall back to native until reviewed.
