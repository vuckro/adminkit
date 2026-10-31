# Feature discovery — direction study

Local preview: http://localhost:10013/wp-content/plugins/adminkit/dev/prototypes/features/

Three reversible proposals within the existing AdminKit visual system:

- **A — Categories:** filter by a recognizable family, scan short summaries, expand the original explanation inline. Recommended starting point for finding and understanding equally.
- **B — Catalogue:** compact grouped overview. Details open proposal C. Best for frequent administration; density becomes a limit as the catalogue grows.
- **C — Detail:** select a tool, read its complete effect, then toggle it. Best for consequential settings; browsing requires more selections. Mobile stacks the scrollable chooser above details.

This study is independent from the settings host. It does not duplicate the database, use authentication, call WordPress REST, or persist settings. Switches start enabled for demonstration, not as a reading of site configuration. Save only updates in-memory demo state. Reset restores all switches to enabled and clears search, status and category filters; it keeps the selected proposal and preview theme. Reload clears switch changes and opens the proposal in the URL fragment. The permanent return link opens the existing Features screen. Design/Navigation labels provide visual context and are not navigation controls.

`catalog.json` snapshots the ten translated feature definitions returned by `AdminKit_Settings_Catalog::features()` on 2026-10-31. Labels and full effects remain verbatim. Short summaries are proposed UX copy. `icons.json` contains selected Lucide paths from the existing distribution; license: ../../../assets/icons/LUCIDE-LICENSE. Styling consumes the existing generated AdminKit tokens. No new dependency, schema, migration, release version or default. `dev/` is excluded from release packages.

This is an Operate-mode comparison within the incumbent monochrome system. Semantic colors come from the generated token stylesheets; prototype typography, spacing, radii and breakpoints are local CSS, not new global token definitions. `PRODUCT.md`, `DESIGN.md` and global design metadata remain authoritative and unchanged by this study. The demos do not exercise dependency locks, permissions or technical storage help, and are not a complete implementation of the shipping feature-row behavior.

Not an approved replacement. After selection, integrate the chosen structure into `assets/js/settings/features.js`, keep capability/nonce/draft/save semantics, translate proposed copy, validate real save/reload and dependencies, and commit the integration separately. Do not copy the demo's in-memory model into the settings host.

Validation evidence in the workspace output directory: `output/playwright/features-directions-20261031/`. `results.json` records twelve render cases at desktop 1440px/mobile 390px in light/dark, with no measured horizontal overflow or recorded errors, plus search, empty state, toggle, simulated save, reset and catalogue-to-detail navigation checks. Screenshots accompany those render cases. The status filter is implemented, but the result file does not separately record an inactive-filter assertion. This validates the prototypes only; authenticated WordPress persistence and production integration are unverified.
