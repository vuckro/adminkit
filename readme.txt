=== AdminKit ===
Contributors: waaskit
Tags: admin, dark-mode, admin-theme
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Give your WordPress admin a clean, modern look — optional dark mode, a menu editor and focused administration tools. No setup needed.

== Description ==

AdminKit gives the whole WordPress back office — the dashboard, every settings screen, the login page and the toolbar — a clean, calm, modern look, with a proper **light and dark mode** you toggle from the admin bar. Activate it and your admin is restyled; there is nothing to configure.

It also adds a handful of quality-of-life tools, each an individual on/off toggle:

* an **admin-menu editor** (reorder, rename, re-icon, hide entries),
* a **notifications drawer** that tidies admin notices off the top of every screen,
* inline **Quick Edit** on the users list.

It is **standalone** — no theme or page builder required, and **nothing changes on the public side of your site**; AdminKit lives entirely inside wp-admin. Under the hood it's built on CSS variables, so it can optionally pick up your brand colours from a provider like Bricks.

= Highlights =

* A flat, modern restyle of wp-admin, wp-login.php and the frontend admin bar.
* A light + dark mode with a sun/moon toggle in the admin bar (and `prefers-color-scheme` on first visit).
* CSS custom properties (`--ak-*`) any other admin-side stylesheet can consume.
* Conditional, per-screen CSS loading — pages only load the styles they need.
* Users-list Quick Edit: a "Quick Edit" link on each row of Users → All Users opens an inline editor for first / last name, email and role — same pattern WordPress ships for posts. Saves via AJAX, no full page reload.
* Username changer (opt-in): turns the natively-disabled Username field into an editable one on Users → Edit. Validates the new login, dedupes against existing users, and destroys the affected user's sessions so the old name can't keep an old device signed in. Single-site only.
* A built-in Menu editor: reorder the admin menu and submenus, swap icons and hide entries, on its own AdminKit screen.
* A Notification Center: a toolbar bell that gathers admin notices into a side drawer so the top of every screen stays calm.
* Online users: see who's signed in right now (read from existing WordPress sessions, no extra tracking), with an "Online" filter and a "Last login" column on the Users screen.
* Tabbed Settings, plus dedicated Menu and Toolbar screens under the AdminKit menu.
* Optional adapters that skin popular plugins/themes (Bricks, WooCommerce, ACF, the Fluent suite, and more).

== Installation ==

1. Upload the `adminkit` folder to `/wp-content/plugins/`, or install the plugin through the Plugins screen in WordPress.
2. Activate AdminKit through the Plugins screen.
3. That's it — AdminKit works with zero configuration. Visit the top-level **AdminKit** menu to review the settings.

== Frequently Asked Questions ==

= Does AdminKit require a page builder? =

No. AdminKit is standalone and ships a complete look. If you use Bricks, AdminKit picks up its brand colours automatically.

= Does it change anything on the front end of my site? =

No — only the admin bar shown to logged-in users is restyled. Your site's public design is untouched.

= Can I turn features off? =

Yes. Every feature is an individual toggle on the AdminKit settings page, and all optional features and integrations ship disabled.

== Changelog ==

= 1.0.0 =
* Initial release.
* A flat, modern restyle of wp-admin, wp-login.php and the frontend admin bar, built entirely on CSS custom properties (`--ak-*` tokens).
* Light + dark mode with a sun/moon toggle in the admin bar (and `prefers-color-scheme` on first visit).
* Opt-in Gutenberg canvas theming. Lucide icons are included in the default admin skin.
* Tabbed Settings screens (including Discussion, Reading and Writing), plus dedicated Menu and Toolbar screens under the AdminKit menu.
* Login-screen branding with a centred logo and a light/dark toggle; plus a brand mark at the site title — your logo, the site favicon, or none — with the top-left WordPress logo hidden.
* Registry-based assets with per-screen conditional loading, integration scaffolding and host-drift detection.
* Optional adapters that skin popular plugins/themes (Bricks, WooCommerce, ACF, the Fluent suite, and more).

== Upgrade Notice ==

= 1.0.0 =
Initial release.
