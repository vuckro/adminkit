# Fluent surfaces — AdminKit compatibility

6 November 2026. This pass covers the installed FluentHub, FluentCRM,
FluentSMTP, Fluent Forms and FluentBooking surface families, plus the shared
Element Plus card bridge used by other enabled Fluent adapters.

Outer cards map to `--ak-card-bg/border/radius/shadow`; table containers map
to `--ak-table-*`. Card contact elevation remains independent of the deeper
floating-menu elevation. SMTP separates its card and popup shadow tokens.
Hub workspace/theme menus use overlay depth, its anchored footer stays flat,
and its decorative hero wash is removed. Existing row separators, semantic
borders, emphasis variants, charts, handlers and application layout remain
host-owned. Nested table/card sections do not receive a second drop shadow.

Validation: complete 216-check quality gate; no new important declarations
or raw component colors. Representative component fixtures use installed
native styles (Hub rules extracted from its app bundle). Computed border,
radius and shadow values were compared to the actual AdminKit role tokens
for all five families in light/dark. Nested shadows and CRM's semantic error
border were checked. Desktop and 390px fixtures showed no document overflow
in both themes. This is component-level validation, not a full connected
regression of every Fluent route, dropdown, editor or Pro feature.

Delivery is limited to GitHub and COOMS LocalWP. No production deployment,
settings changes, contact edits, email sends or MCP activation are required.
