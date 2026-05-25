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
* Local avatars: let users upload a profile picture that replaces Gravatar.
* Generated avatars: a friendly auto-generated face for users with no photo, instead of a blank silhouette.
* Tabbed Settings screens and an interactive dashboard roadmap.
* Optional adapters that skin popular plugins/themes (Bricks, WooCommerce, ACF, the Fluent suite, and more).

== External services ==

This plugin connects to **api.dicebear.com** to generate avatars when the **Local avatars** feature is enabled (it is on by default and includes the auto-generated faces).

* What it is: DiceBear is a free, key-less HTTP avatar service. AdminKit uses its hosted API to render a friendly avatar for a user who has neither an uploaded profile picture nor a real Gravatar.
* When it is used: only when the **Local avatars** feature is enabled (it is opt-out — it can be turned off from the AdminKit settings page), and only for a user who has neither an uploaded picture nor a real Gravatar. A request is made to api.dicebear.com to fetch the avatar image; a real Gravatar always takes priority.
* What data is sent: no personal data. The avatar is requested with a non-reversible seed — the md5 hash of the user's login name, or a random value the user explicitly generates. The raw email address is never sent.
* Service provider: DiceBear. Terms of use: https://www.dicebear.com/licenses/ — Privacy policy: https://www.dicebear.com/legal/privacy-policy/

When Local avatars is off, AdminKit makes no external calls for avatars and Gravatar behaves exactly as it does without the plugin.

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

They are rendered by the hosted DiceBear service (api.dicebear.com), and only when the Local avatars feature is enabled (for a user with no uploaded picture and no Gravatar). No personal data is sent — the request is seeded with a non-reversible hash, never the raw email. See the "External services" section above. Turn Local avatars off and no external avatar requests are made.

= Can I turn features off? =

Yes. Every feature is an individual toggle on the AdminKit settings page, even though they ship enabled.

== Changelog ==

= 1.0.0 =
* Initial release.
* A flat, modern restyle of wp-admin, wp-login.php and the frontend admin bar, built entirely on CSS custom properties (`--ak-*` tokens).
* Light + dark mode with a sun/moon toggle in the admin bar (and `prefers-color-scheme` on first visit).
* Local avatars (upload a profile picture that replaces Gravatar) and generated avatars (a friendly auto-generated face, via DiceBear, for users with no photo — opt-out, non-PII seed).
* Gutenberg canvas theming and AdminKit's own icon set, both on by default and individually switch-off-able.
* Tabbed Settings screens (including Discussion, Reading and Writing) and an interactive dashboard roadmap with status badges and detail modals.
* Login-screen branding with a centred logo and a light/dark toggle; plus a brand mark at the site title — your logo, the site favicon, or none — with the top-left WordPress logo hidden.
* Registry-based assets with per-screen conditional loading, integration scaffolding and host-drift detection.
* Optional adapters that skin popular plugins/themes (Bricks, WooCommerce, ACF, the Fluent suite, and more).

== Upgrade Notice ==

= 1.0.0 =
Initial release. Generated avatars use the hosted DiceBear service (opt-out; no personal data sent) — see the "External services" section.
