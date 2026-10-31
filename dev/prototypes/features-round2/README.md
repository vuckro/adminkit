# Feature discovery — second round

Preview: http://localhost:10013/wp-content/plugins/adminkit/dev/prototypes/features-round2/

The user selected a direction between A (categories) and C (list/detail), favoring C while the catalogue is small. Finding and understanding carry equal priority. This round compares three structures within the established monochrome Operate surface:

- **C1 / grouped:** all ten tools remain visible in three named families; selection opens a neighboring detail pane. Recommended for the current catalogue.
- **C2 / focused:** category filters precede the list/detail workspace. Useful as the catalogue grows; extra filtering can slow small-catalogue browsing.
- **C3 / inline:** one expanded effect inside a grouped list; activation occurs inside its explanation. No separate reading pane, but expanding moves following rows.

Shared search, status filtering, Lucide icons, single simulated Save, bulk controls and reset. The mobile list/detail variants scroll and focus the selected detail and provide a back-to-list control. Keyboard selection/toggle focus is preserved when the selected control survives; otherwise it returns to the status filter.

Original proposals remain unchanged at `../features/`. This round reuses their verbatim ten-item catalogue snapshot, icon subset (Lucide license in assets/icons/LUCIDE-LICENSE), and base stylesheet. Local overrides are in round2.css. Short summaries remain proposed UX copy. All states are in memory, initially enabled for demonstration; no authentication, WordPress API, database copy or real settings persistence. It is a direction comparison, not an approved plugin replacement. Source is in dev/ and excluded from release packages.

After selection, integrate the chosen presentation with the existing feature factory, translations, dependency locks and draft/save transport; verify real WordPress behavior separately. Existing global design documents remain unchanged. Evidence: workspace output/playwright/features-directions-round2-20261031/.
