# Robustness and asset-boundary follow-up

## Confirmed findings and corrections

- The 475,377-byte generated Lucide map was listed as an eager bootstrap module even though `AdminKit_Icons::paths()` already had an on-demand loader. Move the data file into an explicit lazy-module registry, keep it in the package, and validate both eager/lazy registries. The packaged WordPress bootstrap test now checks that anonymous initialization does not load unused icon data.
- Media and ThickBox skin files (16,343 raw CSS bytes together) were enqueued on unrelated screens. Media styling now follows library screens or host-enqueued `media-views`; ThickBox styling follows its native stylesheet/script. The late dispatcher retains all ordinary native enqueue hooks. The asset regression exercises dashboard/list/settings, library and arbitrary host screens, anonymous/frontend boundaries and the global veto. The real browser test opens a native media modal and checks plugin-install ThickBox assets.
- Repeated Enter presses in a pending Quick Edit could issue duplicate writes. A response from a closed editor could then close a different editor opened meanwhile, destroying its draft. Each editor now has its own pending guard; responses update the originating row and only close the submitting editor. Tests exercise delayed responses, repeated Enter and retry after network failure.
- Quick Edit coerced target IDs before validation and passed nested/non-string fields into scalar sanitizers. Validate positive integer IDs and optional string fields before any storage call. Malformed arrays, nulls, booleans and partial-number IDs now return 400 without updating users. Existing target capabilities, row nonce, role/email constraints and multi-role preservation remain in place. This is input-boundary hardening, not evidence of a demonstrated privilege escalation.
- Remove an unused settings variable and an overwritten palette assignment/private parameter. Enforce `no-unused-vars` for runtime JavaScript so unused locals fail CI. Keep hook parameters and documented public compatibility facades intentionally.

## Verification

The complete offline gate has 222 checks: syntax, controller regressions, package/registry integrity, translations, token/CSS contracts, JSDOM behaviors, WPCS security/i18n, PHPStan, ESLint and Stylelint. The CI additionally boots the shipped package in WordPress/SQLite and runs real browser flows, including anonymous REST denial and saved design-value preservation.

No claim of universally absent dead code or undiscovered vulnerabilities is made. Dynamic WordPress hooks, public integration APIs, opt-in features, imports of saved foundations and historical upgrade paths remain necessary code. The inspected write boundaries remain permission- and nonce-protected. Real customer transactions and mail delivery are outside this AdminKit code update.
