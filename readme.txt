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

== External services ==

AdminKit makes **no external network connections by default**. All baseline fonts (Geist) and icons (Lucide, Heroicons) are bundled locally inside the plugin. Two optional, administrator-controlled features can connect to third-party services when explicitly enabled:

= Google Fonts (optional, disabled by default) =

* **What it is used for:** Loading optional interface font families (Inter, Plus Jakarta Sans, DM Sans, Manrope, Outfit, Poppins, Space Grotesk, IBM Plex Sans) inside WordPress administration when an administrator explicitly enables **Load Google Fonts from Google servers** under **AdminKit → Design system → Typography**.
* **What data is sent and when:** When enabled and a Google Font family is selected, the logged-in user's browser requests the CSS stylesheet from `https://fonts.googleapis.com/css2` and font files from `https://fonts.gstatic.com` on authenticated `wp-admin` / toolbar screens (never on `wp-login.php` or for logged-out public visitors). Standard HTTP request headers (including IP address and User-Agent) are transmitted to Google.
* **Provider terms & privacy:** [Google Privacy Policy](https://policies.google.com/privacy) and [Google APIs Terms of Service](https://developers.google.com/terms).

= WordPress.com mShots (developer filter opt-in, disabled by default) =

* **What it is used for:** Generating fallback webpage thumbnail previews in post list tables when **Post previews** is enabled and a developer explicitly opts into the `mshots` provider via the `adminkit/post_previews/provider` PHP filter (the default provider uses local WordPress featured images only).
* **What data is sent and when:** When opted in, the logged-in editor's browser requests a screenshot image from `https://s0.wordpress.com/mshots/v1/` containing the URL of published, non-password-protected public posts listed on the current admin table screen. Draft, private, and password-protected URLs are never sent.
* **Provider terms & privacy:** [Automattic Privacy Policy](https://automattic.com/privacy/) and [WordPress.com Terms of Service](https://wordpress.com/tos/).

== Credits ==

* **Geist** typeface by Vercel — licensed under the [SIL Open Font License 1.1](https://openfontlicense.org/) ([source](https://github.com/vercel/geist-font)).
* **Lucide** icon set by Lucide Contributors — licensed under the [ISC / MIT License](https://lucide.dev/license) ([source](https://github.com/lucide-icons/lucide)).
* **Heroicons** by Tailwind Labs — licensed under the [MIT License](https://github.com/tailwindlabs/heroicons/blob/master/LICENSE) ([source](https://github.com/tailwindlabs/heroicons)).

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
