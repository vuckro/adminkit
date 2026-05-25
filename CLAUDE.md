# AdminKit — guide for AI assistants

AdminKit restyles wp-admin, wp-login, and the frontend admin bar through a
CSS-custom-property (`--ak-*`) token system. It is **standalone** (ships a
complete look with no dependencies) and gains brand colours from an optional
**provider** (Bricks today). Read this first, then the one doc that matches the task.

## Map — where things live

```
adminkit.php            Loader: defines constants, requires inc/, calls AdminKit_Plugin::init().
inc/
  class-plugin.php       Boot orchestrator. Boots core modules, then auto-discovers
                         integrations via glob( inc/integrations/*/*/class-*.php ).
  class-assets.php       Asset registry + dispatcher + the token cascade (enqueue_tokens);
                         enqueue_script() for the JS bricks.
  class-screen.php       WP_Screen helpers.
  class-settings.php     Settings registry (register/get/schema) + color_map() (display taxonomy).
  class-settings-page.php Settings SPA (admin menu) + REST save + providers() list.
  class-theme-toggle.php  Dark/light toggle + login logo. Owns the pre-paint inline script.
  class-dashboard.php     Dashboard widget registry (dormant until an integration registers one).
  wp-core/                AdminKit's restyle of WP-core surfaces (chrome, login, profile…).
  integrations/
    abstract-integration.php   AdminKit_Integration_Base.
    plugins/{slug}/            Plugin adapters (acf, woocommerce, …) — class + css/ + baseline.json.
    themes/{slug}/             Theme adapters (bricks).
assets/css/
  tokens.css            The --ak-* layer. ALWAYS loaded. Owns the dark-mode flip.
  waaskit-tokens.css    GENERATED WaasKit baseline (do not hand-edit).
  wp-core/ wp-components/ wp-screens/   AdminKit's own CSS, registered by wp-core/class-chrome.php.
assets/js/
  settings.js           Settings SPA.
  wp-core/*.js          Footer behaviour bricks (profile-account, post-previews, list-table-chrome).
tokens/                 Build-time source for the baseline (palettes/*.json + build.php).
dev/                    Dev tooling (TRACKED, excluded from the dist zip): css-scan.php (shared
                        parser), adapter-scan.php, adapter-audit.php, adapter-drift.php, baselines/.
docs/                   Deep-dive guides (see "More docs" below).
```

## Common tasks

| Task | Do this | Doc |
| --- | --- | --- |
| Add an integration (skin a plugin/theme) | `php dev/adapter-scan.php ../{host} --slug={slug} --emit`, fill TODOs, fine-tune css, then audit + drift | [docs/INTEGRATIONS.md](docs/INTEGRATIONS.md) |
| Add per-screen CSS | Add `assets/css/wp-screens/{name}.css`, register via `self::register_screen()` in `inc/wp-core/class-chrome.php` | [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) |
| Add a JS behaviour | New `assets/js/wp-core/{name}.js`, enqueue via `AdminKit_Assets::enqueue_script()` | [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) |
| Change a baseline token | Edit `tokens/palettes/*.json`, run `php tokens/build.php`, commit JSON + regenerated CSS | [docs/TOKENS.md](docs/TOKENS.md) |
| Detect host / WP-core CSS changes | `php dev/adapter-drift.php` (per adapter) or `--wp-core` | [docs/TOKENS.md](docs/TOKENS.md#drift-detection-keeping-adapters-alive) |
| Add a setting / feature toggle | `AdminKit_Settings::register()` (+ a `feature_descriptors()` row for the UI), then update the Settings inventory | [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md#settings) |
| Add a hook (filter / action) | namespace it `adminkit/…`, then document it | [docs/EXTENDING.md](docs/EXTENDING.md) |
| Add a branding / logo option | `inc/wp-core/class-branding.php` + the `brand_logo()` resolver | [docs/EXTENDING.md](docs/EXTENDING.md) |
| Understand the whole system | — | [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) |

## Keep the docs alive (every iteration)

AdminKit grows through many small iterations. These docs are how we *don't*
re-derive context each time — and how we move fast without drifting. **Treat docs
as part of the code: a change isn't done until the docs that describe it are true
again, in the SAME branch — not "later".**

**Definition of done** for any behaviour change:

1. Code it, then **verify** (lint + gates — see [Verify a change](#verify-a-change)).
2. **Update whatever the change made stale:**
   - new / renamed **setting or feature toggle** → the Settings inventory in
     [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md#settings) + its UI label.
   - new / changed **hook** → [docs/EXTENDING.md](docs/EXTENDING.md) (+ the README filter table).
   - new **integration** or integration behaviour → [docs/INTEGRATIONS.md](docs/INTEGRATIONS.md) + the README integrations list.
   - **file / folder move** → the README file tree + the Map at the top of this file.
   - **renamed UI label / tab** (e.g. a settings tab) → `grep -rn "<old name>" CLAUDE.md README.md docs/` and update every hit. Easy to forget; the doc keeps the old name otherwise.
   - **roadmap change** → the single source is the in-app roadmap in
     `AdminKit_Settings_Page::dashboard()` (`roadmap` ⇒ Planned / Next / In progress).
     Update it as work moves between buckets, then mirror it in README.md's Roadmap.
   - any **user-facing string** → refresh i18n (see [Verify a change](#verify-a-change)).
3. **Record important decisions as guardrails.** When you lock a non-obvious
   choice (a default, an order, a "don't do X — it broke Y"), add a one-line
   bullet to [Guardrails](#guardrails--do-not-break-these) *with the why*. The why
   is what stops a later iteration from silently undoing it.
4. **Re-read the touched docs** for consistency — the same names, defaults and
   paths everywhere. A quick `grep` for a renamed key across `README.md`,
   `CLAUDE.md` and `docs/` catches drift in seconds.

The loop that keeps us fast: **build → verify → make the docs true → lock the
decision.** Skipping step 2 or 3 is exactly how past iterations got lost.

## Guardrails — do NOT break these

- **NEVER change the version number without the maintainer's explicit go-ahead.**
  The `Version:` header + `ADMINKIT_VERSION` in `adminkit.php` (and the `readme.txt`
  Stable tag) are a **release decision the maintainer owns** — do not bump them on
  your own, ever, even after a big batch of work. Ask first; only then prepare the
  release (bump the version, write the `readme.txt` changelog entry, set the stable
  tag together). Leave the version alone otherwise.
- **`assets/css/waaskit-tokens.css` is GENERATED.** Never hand-edit it. Change
  `tokens/palettes/*.json` and run `php tokens/build.php`. `--check` is a drift gate.
- **Every AdminKit stylesheet reads `--ak-*` tokens, never raw colours.** That
  indirection powers dark mode and provider theming. Keep it. (One deliberate
  exception: `integrations/themes/bricks/css/builder.css` — the Bricks builder is
  a live-WaasKit surface where `--ak-*` isn't loaded but the WaasKit provider vars
  are, so it maps to those directly. See its header.)
- **Integration discovery is `glob( inc/integrations/*/*/class-*.php )`** — two
  levels deep (`{plugins,themes}/{slug}/`). The class name derives from the file
  basename (`AdminKit_Integration_{Studly_Slug}`). Don't rename a class without
  renaming its file to match, or it silently never loads.
- **Assets are cache-busted by `filemtime`** — editing a CSS/JS file is enough.
- **JS behaviour lives in `assets/js/*.js`, enqueued via `enqueue_script()`** — don't
  print inline scripts from PHP. The ONE exception is the theme pre-paint bootstrap
  in `class-theme-toggle.php`: it must stay inline in `<head>` to avoid FOUC.
- **Dev tooling lives in `dev/` (tracked) and is excluded from the dist zip via
  `.distignore`.** `tokens/build.php` stays in `tokens/` (next to its palettes).
  Don't point docs at `.claude/` — that's local-only and gitignored.
- **The Design settings tab (the token reference, i18n key `design`) is read-only.**
  There is no per-token colour editor; the palette is driven by the provider/baseline
  cascade. Don't re-add the removed editing machinery.
- **The token layers are each optional** (provider → baseline → neutral). Don't
  hard-require any one of them. See ARCHITECTURE.
- **Default feature toggles ship ON** — Gutenberg canvas theming
  (`editor_content_theme`), AdminKit icons (`replace_icons_enabled`) and local avatars
  (`local_avatars_enabled`) all default ON (decided this cycle), so the plugin presents fully-featured on
  activation. Each stays individually switch-off-able; only `bricks_builder_enabled`
  is opt-in (it restyles a third-party builder's own UI). Keep the on-by-default
  posture — don't quietly flip these back to opt-in. **Generated avatars are NOT a
  separate toggle** — they ride along with `local_avatars_enabled` (don't re-add the
  removed `generated_avatars_enabled`; that merge was deliberate).
- **Generated avatars call an external service (DiceBear, `api.dicebear.com`)** —
  served as the Gravatar `d=` fallback for users with no upload and no real
  Gravatar, automatically whenever `local_avatars_enabled` is on (no separate
  toggle — opt out by turning local avatars off). It's disclosed in `readme.txt`
  (the .org "External services" section) and the seed is NON-PII (md5 of the login,
  or a stored random seed) — **never send the raw email.** If you change the service
  or what's sent, update that disclosure in the same change.
- **`content:url()` on a pseudo-element renders at the image's intrinsic size** —
  browsers ignore `width`/`height` on a pseudo-element's `content` image. For a
  *sized* icon/logo use a `background-image` on a sized box, or a real `<img>` (a
  replaced element: `width:auto` + `border-radius` work). This bit the admin-bar
  brand logo and the site-name favicon — both now render as `<img>` /
  `background-image`, not `content:url()`. See `inc/wp-core/class-branding.php`.
- **Bricks BUILDER logo + scrollbars (hard-won — don't regress).** The preloader
  logo is a **real `<img>` injected by JS** (`themes/bricks/js/preloader-logo.js`
  appends `<img class="ak-preloader-logo">` into `#bricks-preloader`;
  `builder-essentials.css` sizes it `height:Xrem; width:auto` so it hugs the logo's
  natural ratio at any size — a CSS background on a fixed box letterboxes or crops a
  logo of unknown aspect). Don't revert it to a background. The logo branding
  (toolbar favicon + preloader) loads **ALWAYS via `builder-essentials.css`** (a REAL
  stylesheet — a `src=false` inline-only handle can be dropped in the builder),
  independent of `bricks_builder_enabled`, which only gates the heavier chrome
  restyle (`builder.css`). Builder chrome **scrollbar theming is abandoned** —
  Bricks's panels use a non-native scrollbar `::-webkit-scrollbar` can't reach
  (unlike the Gutenberg native iframe scrollbar). Don't re-add it.
- **`options.js` tabs the six built-in Settings screens** — it splits each screen
  at its `<h2>` sections into tabs, and "explodes" the Discussion
  `.form-table.indent-children` table (the one core screen that packs its groups
  into `<th>` rows of a single table) into one tab per `<th>` row. Every field
  stays in the one submitting form. Gotchas it must handle (all real): a screen
  with NO `<h2>` (Reading) or only one section → render a single plain card, **no
  tab strip** (a lone tab reads as a stray raw button); the leading
  `settings_fields()` hidden inputs sit *before* the first table, so the explode
  scans the section body (not "is the table the only node?") and a lead run of only
  hidden inputs is folded into the first real section (no empty "General" tab).
- **Class names are stable public-ish API** (`AdminKit_*`). Folder reorg keeps them.
- **All user-facing strings stay translatable** — wrap them in `__()` / `esc_html__()`
  with the `adminkit` text domain; pass JS copy from PHP via `wp_localize_script` /
  `wp_add_inline_script` (never hardcode UI text in `.js`). The domain loads on `init`
  (`AdminKit_Plugin::load_textdomain`); after adding strings, regenerate the template:
  `wp i18n make-pot . languages/adminkit.pot`.
- **Docs are part of the change, not a follow-up.** A new/renamed setting, hook or
  integration, or a moved file, isn't done until `README.md`, this map, the matching
  `docs/*` and i18n are true again — same branch. Lock non-obvious decisions as a
  new guardrail here (with the *why*). See [Keep the docs alive](#keep-the-docs-alive-every-iteration).

## Verify a change

- Lint PHP: `php -l <file>`.
- Token drift gate: `php tokens/build.php --check`.
- Adapter CSS-debt: `php dev/adapter-audit.php` (Tier A = 0 `!important`).
- Host/WP CSS drift: `php dev/adapter-drift.php`.
- i18n fresh: new strings wrapped in `__()` (domain `adminkit`) AND present in
  `languages/adminkit.pot` + `adminkit-fr_FR.po` (then recompile the `.mo`).
  Regenerate with `wp i18n make-pot …`; if wp-cli is absent, the gettext toolchain
  works — `xgettext` (WP keywords) to diff, `msgfmt -c` to compile + validate.
- UI: reload any wp-admin page (CSS/JS auto-bust via mtime) and check light + dark.

## Git & GitHub workflow

`main` is the *eventual* source of truth: always clean, always deployable. **But today
the live, active integration branch is `docs/overhaul`** — read the box below before you
branch. The pain we hit before all came from drifting off the right branch — read the
**anti-patterns**.

### ⚠️ Current working branch — `docs/overhaul` (read before you start)

`main` is the trunk — what people clone / download — and we keep it current by
**promoting `docs/overhaul` → `main` at each verified checkpoint** (it's a fast-forward,
since `main` stays an ancestor of `docs/overhaul`). Between promotions, active work lands
on **`docs/overhaul`** first. So:

- **Work on `docs/overhaul`** (the orchestrator commits + pushes here). Get the live code
  with `git fetch origin && git checkout docs/overhaul && git pull --ff-only`. Never start
  from a downloaded release / ZIP — it's a snapshot, not a git checkout.
- **Pull before you start, re-sync often.** After anything merges into `docs/overhaul`,
  `git fetch && git rebase origin/docs/overhaul` so nothing drifts (also how two changes
  to the same file stay mergeable — rebase the second).
- **Promote to `main` at clean checkpoints** so `main` never lags far behind the live
  site — that gap is exactly what makes a fresh download look "wrong". Fast-forward only;
  never force-push or rewrite `main`.
- The clean `main`-based loop below is the long-term target (once `docs/overhaul` retires);
  until then, read each "`main`" in it as "`docs/overhaul`".

### The loop (one topic at a time)

1. Branch **off a freshly-pulled `docs/overhaul`** (the current integration branch — it's
   `main` only once we've promoted): `feat/…`, `fix/…`, `refactor/…`, `docs/…`,
   `chore/…` — one topic, short-lived. Direct pushes to `main` are blocked.
2. **Conventional commits, one concern each** (`feat:`, `fix:`, `refactor:`,
   `docs:`, `style:`, `chore:`, scoped: `fix(buttons): …`). **Stage explicit
   paths — never `git add -A`** (the tree may hold a second agent's work). Push often.
3. Open a PR **into `docs/overhaul`**, run the pre-merge checks, **squash-merge** (one
   clean commit on the integration branch), then **delete the branch immediately**.

### Anti-patterns (these bit us — don't repeat)

- **Never reuse a branch after its PR is merged.** A squash-merge collapses the
  branch into one *new* commit on `main`, so the old branch **diverges** and the
  next PR returns `CONFLICTING`. Fix: delete + re-branch per topic. If a
  long-lived integration branch is unavoidable, **re-sync it right after every
  merge** (`git merge origin/main` into it) so `main` stays an ancestor — never
  let it drift, never force-push it.
- **Never let scratch reach the repo.** Throw-away mockups, experiments and
  one-off blueprints belong in a **gitignored** spot (`.claude/` is already
  ignored; or add `scratch/` to `.gitignore`) — not in tracked folders, or they
  get swept into `main` on the next squash. `.distignore` only strips files from
  the **zip**; it does **not** keep them out of `main`.
- **Don't pile up branches.** Delete merged branches locally too
  (`git branch -d`) and `git fetch --prune` to clear gone remotes.

### Agents — centralised, one working tree

Development is **centralised in the main Claude conversation** (the orchestrator), working
in the single tree on the live site. When a task needs parallel hands, the orchestrator
spawns **non-isolated sub-agents** (the Agent tool) that **edit + verify in this same tree
and leave everything uncommitted** — the orchestrator does ALL git (stage explicit paths,
commit, push, PR, promote). Why non-isolated: the Agent tool's `isolation:"worktree"`
branches off the DEFAULT branch (`main`), not `docs/overhaul`, so an isolated worktree is
missing the branch's work and is unusable here.

(We earlier ran agents on separate clones/sites; that's retired in favour of this
centralised model. The two notes below still apply if you ever do run a second checkout.)

- **Separate `git worktree`s:** one branch per agent, one directory each
  (`git worktree add ../adminkit-b feat/…`). No shared-file races either.
- **Sharing one tree:** stage explicit paths only, **never touch the other agent's
  uncommitted files**, push often, and on a rejected push `git fetch` + `git rebase`/merge
  — never force-push a shared branch, never drop their work.

### Before you merge

`php -l <changed>` · `php tokens/build.php --check` · `php dev/adapter-audit.php`
· `php dev/adapter-drift.php` · re-read the diff · confirm no scratch/secret is
staged. One-time per machine: set `git config user.name` + `user.email` so
commits aren't attributed to `user@host`.

## More docs

[README.md](README.md) (overview + extension API) · [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)
(bootstrap, asset registry, settings) · [docs/INTEGRATIONS.md](docs/INTEGRATIONS.md)
(contract + walkthrough + patterns) · [docs/TOKENS.md](docs/TOKENS.md) (token map,
build, drift, alignment) · [docs/EXTENDING.md](docs/EXTENDING.md) (every hook) ·
[docs/WAASKIT-DESIGN-SYSTEM.md](docs/WAASKIT-DESIGN-SYSTEM.md) (the locked WaasKit spec).
