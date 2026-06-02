=== AdminKit ===
Contributors: waaskit
Tags: admin, dashboard, dark-mode, admin-theme, avatars
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Give your WordPress admin a clean, modern look — one-click dark mode, a refreshed dashboard, a menu editor and private stats. No setup needed.

== Description ==

AdminKit gives the whole WordPress back office — the dashboard, every settings screen, the login page and the toolbar — a clean, calm, modern look, with a proper **light and dark mode** you toggle from the admin bar. Activate it and your admin is restyled; there is nothing to configure.

It also adds a handful of quality-of-life tools, each an individual on/off toggle:

* a **redesigned dashboard** (greeting, quick actions, at-a-glance counts, site health, storage, recent activity — drag the cards to taste),
* an **admin-menu editor** (reorder, rename, re-icon, hide entries),
* **cookieless visitor stats** (unique visitors + page views, with a live view — no cookies, no IP stored, no consent banner),
* a **notifications drawer** that tidies admin notices off the top of every screen,
* **generated avatars** for users with no photo, and inline **Quick Edit** on the users list.

It is **standalone** — no theme or page builder required, and **nothing changes on the public side of your site**; AdminKit lives entirely inside wp-admin. Under the hood it's built on CSS variables, so it can optionally pick up your brand colours from a provider like Bricks.

= Highlights =

* A flat, modern restyle of wp-admin, wp-login.php and the frontend admin bar.
* A light + dark mode with a sun/moon toggle in the admin bar (and `prefers-color-scheme` on first visit).
* CSS custom properties (`--ak-*`) any other admin-side stylesheet can consume.
* Conditional, per-screen CSS loading — pages only load the styles they need.
* Custom avatars: adds "AdminKit Portraits (Generated)" to WordPress's native Settings → Discussion → Default Avatar (next to Wavatar, Identicon, etc.). Pick it there to give every user a unique generated portrait on a pastel-gradient backdrop.
* Users-list Quick Edit: a "Quick Edit" link on each row of Users → All Users opens an inline editor for first / last name, email and role — same pattern WordPress ships for posts. Saves via AJAX, no full page reload.
* Username changer (opt-in): turns the natively-disabled Username field into an editable one on Users → Edit. Validates the new login, dedupes against existing users, and destroys the affected user's sessions so the old name can't keep an old device signed in. Single-site only.
* A custom dashboard that replaces the stock one: a greeting, quick actions, at-a-glance counts, site-health and storage, recent activity — drag the cards to rearrange them per user.
* A built-in Menu editor: reorder the admin menu and submenus, swap icons and hide entries, on its own AdminKit screen.
* Cookieless Statistics: a tiny no-cookie beacon counts unique visitors and page views, shown on a dashboard card and a dedicated Statistics screen with a live view. Uniqueness uses a salted, daily-rotating hash of IP + browser computed in memory — the raw IP is never stored and the hash can't be linked across days, so there's no cookie and no consent banner. Off by default; turn it on in Settings → Features.
* A Notification Center: a toolbar bell that gathers admin notices into a side drawer so the top of every screen stays calm.
* Online users: see who's signed in right now (read from existing WordPress sessions, no extra tracking), with an "Online" filter and a "Last login" column on the Users screen.
* Tabbed Settings, plus dedicated Menu and Statistics screens under the AdminKit menu.
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
* Gutenberg canvas theming (on by default, switch-off-able) and AdminKit's own icon set (always on — pick a per-item icon, or reset to the WordPress one, in the Menu manager).
* A custom dashboard (greeting, quick actions, at-a-glance counts, site health, storage, recent activity) with per-user drag-to-rearrange cards.
* A built-in admin Menu editor, a cookieless Statistics screen (unique visitors + page views, no cookies / no IP stored, with a live view), a Notification Center drawer, and an Online-users view — each an individual toggle.
* Tabbed Settings screens (including Discussion, Reading and Writing), plus dedicated Menu and Statistics screens under the AdminKit menu.
* Login-screen branding with a centred logo and a light/dark toggle; plus a brand mark at the site title — your logo, the site favicon, or none — with the top-left WordPress logo hidden.
* Registry-based assets with per-screen conditional loading, integration scaffolding and host-drift detection.
* Optional adapters that skin popular plugins/themes (Bricks, WooCommerce, ACF, the Fluent suite, and more).

== Upgrade Notice ==

= 1.0.0 =
Initial release. Generated avatars use the hosted DiceBear service (opt-out; no personal data sent) — see the "External services" section.
