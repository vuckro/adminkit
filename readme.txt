=== AdminKit ===
Contributors: waaskit
Tags: admin, dashboard, dark-mode, admin-theme, avatars
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A clean, modern restyle of the WordPress admin built on CSS tokens. Standalone, with a light/dark mode and optional brand-colour providers.

== Description ==

AdminKit restyles wp-admin, wp-login.php and the frontend admin bar into a flat, modern interface built entirely on CSS custom properties (`--ak-*` tokens). It is **standalone** — it ships a complete look with no dependencies — and can optionally inherit brand colours from a provider (Bricks today, more later).

It ships fully-featured: Gutenberg canvas theming, AdminKit's icon set, and local avatars — with an auto-generated face for users who have none — are all on by default, each switchable from the settings page.

= Highlights =

* A flat, modern restyle of wp-admin, wp-login.php and the frontend admin bar.
* A light + dark mode with a sun/moon toggle in the admin bar (and `prefers-color-scheme` on first visit).
* CSS custom properties (`--ak-*`) any other admin-side stylesheet can consume.
* Conditional, per-screen CSS loading — pages only load the styles they need.
* Custom avatars: adds "AdminKit Portraits (Generated)" to WordPress's native Settings → Discussion → Default Avatar (next to Wavatar, Identicon, etc.). Pick it there to give every user a unique generated portrait on a pastel-gradient backdrop.
* Tabbed Settings screens and an interactive dashboard roadmap.
* Optional adapters that skin popular plugins/themes (Bricks, WooCommerce, ACF, the Fluent suite, and more).

== External services ==

This plugin connects to **api.dicebear.com** to generate avatars when the **Custom avatars** feature is enabled AND you select **AdminKit Portraits (Generated)** in WordPress's *Settings → Discussion → Default Avatar* dropdown.

* What it is: DiceBear is a free, key-less HTTP avatar service. AdminKit uses its hosted API to render a unique portrait per user.
* When it is used: only when **Custom avatars** is on AND **AdminKit Portraits (Generated)** is the selected Default Avatar. Picking any other option in that dropdown (Mystery Person, Wavatar, Identicon, Retro, MonsterID, Blank, Gravatar Logo) makes no AdminKit request — Gravatar's native pipeline runs untouched. Note that picking AdminKit Portraits gives every user a generated portrait, including users who have a real Gravatar — it's an explicit opt-in.
* What data is sent: no personal data. The avatar is requested with a non-reversible seed — the md5 hash of the user's login name. The raw email address is never sent.
* Service provider: DiceBear. Terms of use: https://www.dicebear.com/licenses/ — Privacy policy: https://www.dicebear.com/legal/privacy-policy/

Turn **Custom avatars** off (or pick any other option in Settings → Discussion) and AdminKit makes no external calls for avatars.

== Installation ==

1. Upload the `adminkit` folder to `/wp-content/plugins/`, or install the plugin through the Plugins screen in WordPress.
2. Activate AdminKit through the Plugins screen.
3. That's it — AdminKit works with zero configuration. Visit the top-level **AdminKit** menu to review the settings.

== Frequently Asked Questions ==

= Does AdminKit require a page builder? =

No. AdminKit is standalone and ships a complete look. If you use Bricks, AdminKit picks up its brand colours automatically.

= Does it change anything on the front end of my site? =

No — only the admin bar shown to logged-in users is restyled. Your site's public design is untouched.

= Where do generated avatars come from, and is any personal data sent? =

They are rendered by the hosted DiceBear service (api.dicebear.com), and only when Custom avatars is on AND Settings → Discussion → Default Avatar is set to "AdminKit Portraits (Generated)". Picking any other option (Mystery Person, Wavatar, etc.) makes no AdminKit request. No personal data is sent — the request is seeded with a non-reversible hash, never the raw email. See the "External services" section above.

= Can I turn features off? =

Yes. Every feature is an individual toggle on the AdminKit settings page, even though they ship enabled.

== Changelog ==

= 1.0.0 =
* Initial release.
* A flat, modern restyle of wp-admin, wp-login.php and the frontend admin bar, built entirely on CSS custom properties (`--ak-*` tokens).
* Light + dark mode with a sun/moon toggle in the admin bar (and `prefers-color-scheme` on first visit).
* Custom avatars: registers "AdminKit Portraits (Generated)" in Settings → Discussion → Default Avatar (next to Wavatar / Identicon / Retro / MonsterID). Selecting it gives every user a unique generated portrait on a pastel-gradient backdrop. Via DiceBear, explicit opt-in, non-PII seed.
* Gutenberg canvas theming and AdminKit's own icon set, both on by default and individually switch-off-able.
* Tabbed Settings screens (including Discussion, Reading and Writing) and an interactive dashboard roadmap with status badges and detail modals.
* Login-screen branding with a centred logo and a light/dark toggle; plus a brand mark at the site title — your logo, the site favicon, or none — with the top-left WordPress logo hidden.
* Registry-based assets with per-screen conditional loading, integration scaffolding and host-drift detection.
* Optional adapters that skin popular plugins/themes (Bricks, WooCommerce, ACF, the Fluent suite, and more).

== Upgrade Notice ==

= 1.0.0 =
Initial release. Generated avatars use the hosted DiceBear service (opt-out; no personal data sent) — see the "External services" section.
