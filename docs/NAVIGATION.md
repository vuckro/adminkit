# Sidebar layout contract

The menu manager changes presentation and placement, not authorization. Native
capabilities, callbacks and page slugs remain owned by WordPress and each plugin.
A hidden entry is not an access restriction.

## One identity and one policy

- A child is identified by **parent + slug**, never by label. Two different
  native parents may legitimately expose the same slug.
- Within one parent, duplicate rows resolve to the last ordered occurrence.
  The same rule applies to legacy saved rows, native snapshots, editor models,
  imports, save payloads and rendered children. SQL ties use increasing row ID.
- Legacy duplicates are normalized on read without a database rewrite. The next
  explicit save stores a unique payload. No schema migration is required.
- A local configured row wins. A cross-parent move is inferred only when that
  slug has one configured destination. Multiple destinations are ambiguous:
  unconfigured native occurrences stay local instead of moving by map order.
- Missing/inaccessible destination: preserve the native location and capability.
  A configured label/visibility policy still applies to the unique route.
- A demoted native top-level menu becomes one child; its self-link is not added
  twice. Its children are flattened to the supported second level. Cyclic or
  nested demotion targets are rejected by keeping the original top-level menu.
- An explicit editor move onto an existing same-route child replaces that child
  with the moved row, preserving its title, visibility and URL together.

## Pipeline

1. `Menu_Snapshot` captures pristine registrations before layout application.
2. `Menu_Config` normalizes identity and validates explicit save payloads.
3. `Menu_Layout::plan()` computes placement from immutable input arrays. It has
   no global, hook or storage side effects; capability checks are a predicate.
4. `Menu_Renderer` installs the planned tree, applies labels/visibility/URLs,
   forwards native plugin hooks for moves, and orders rows by saved position.
5. `navigation-menu-model.js` provides the editor's matching normalization and
   tree operations. Existing settings are preserved until an explicit save.

Do not reintroduce in-place moves while iterating `$submenu`: inserted rows can
otherwise be visited twice, and parent traversal order changes the result.
Keep route planning separate from callback registration and HTML presentation.

## Iteration and regression coverage

Run `php dev/test-all.php` before committing. Relevant checks:

- `dev/check-navigation-apply.php`: shared slugs, legacy Slim SEO conflict,
  visibility, missing parent, role fallback, demotion, pure planning and parent
  order independence.
- `dev/check-menu-routing.php`: native `.php` endpoints vs plugin page hooks,
  callbacks, capabilities, WooCommerce aliases and submenu URL overrides.
- `dev/smoke/navigation.test.js`: real editor factories, save/reload fixed point,
  duplicate collision moves, title/visibility/URL retention and promotion.
- `dev/smoke/smoke.spec.js`: native WordPress browser checks. Menu write scenarios
  require `AK_DISPOSABLE=1` and only run on the disposable CI instance. The
  `dev/smoke/fixtures/menu-host` plugin recreates duplicate free/Pro registrations;
  it is excluded from distribution and never installed on customer sites.

The storage facade and public REST payload shape remain unchanged. Extend this
contract and the corresponding PHP/JS/browser cases together when adding rules.
