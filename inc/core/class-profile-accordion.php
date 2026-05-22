<?php
/**
 * Profile-screen accordions.
 *
 * WordPress renders profile.php / user-edit.php as one long form: a
 * run of `<h2>` section headings (Personal Options, Name, Contact Info,
 * About the user, Account Management, Application Passwords, plus
 * anything plugins inject via show_user_profile / edit_user_profile),
 * each followed by a `.form-table`. Core exposes no per-section wrapper
 * hook, so the folding is done client-side: a tiny script walks the
 * form's top-level `<h2>`s and folds each heading + its following
 * siblings into a native `<details>` / `<summary>` pair.
 *
 * On top of that the script surfaces a single open "My Account" panel at
 * the very top — the 80/20 set of fields (name, role, email, new
 * password) an admin reaches for most. It is built by MOVING (never
 * copying) the matching `<tr>`s out of their native sections; because
 * each `<input>` keeps its `name`, the form posts exactly as WP expects,
 * so there is no server-side change, no duplicate field, and no CSS
 * override — the panel reuses the same `.ak-accordion` + `.form-table`
 * styling as everything else. Curate the set via the `ESSENTIALS` list.
 *
 * Native disclosure was chosen over a scripted toggle on purpose — it
 * is keyboard-accessible for free, animates nothing (matches AdminKit's
 * flat, motion-free design) and needs zero click handlers. The script
 * is printed inline — like the theme toggle — rather than enqueued as a
 * file, so the asset registry stays CSS-only.
 *
 * Visuals live in assets/css/screens/profile.css, already enqueued on
 * exactly these three screens.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Profile_Accordion {

	/**
	 * Screen ids that carry the profile form.
	 *
	 * @var string[]
	 */
	const SCREENS = array( 'profile', 'user-edit', 'user-new' );

	/**
	 * Wire the hooks. Called once from the plugin orchestrator.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_footer', array( __CLASS__, 'print_script' ) );
	}

	/**
	 * Print the inline accordion-builder, but only on the user
	 * profile / edit / new screens.
	 *
	 * @return void
	 */
	public static function print_script() {
		if ( ! AdminKit_Screen::is_one_of( self::SCREENS ) ) {
			return;
		}

		$account_label = __( 'My Account', 'adminkit' );
		?>
<script id="adminkit-profile-accordion">
(function () {
	var form = document.querySelector('#your-profile, #createuser');
	if (!form || form.dataset.akAccordion) return;
	form.dataset.akAccordion = '1';

	// --- 1. "My Account" — one open panel of the most-used fields ----------
	// The 80/20 set, in display order. We MOVE these <tr>s (appendChild moves,
	// it doesn't clone) out of their native sections into one open table. Each
	// <input> keeps its name=, so the form still posts exactly as WP expects:
	// no duplicate fields, no server change, no CSS override. Edit this list to
	// curate the panel — order is preserved, missing rows are skipped.
	var ESSENTIALS = [
		'.user-first-name-wrap',
		'.user-last-name-wrap',
		'.user-role-wrap',
		'.user-email-wrap',
		'#password',         // New Password (tr#password.user-pass1-wrap)
		'.user-pass2-wrap',  // Repeat New Password
		'.pw-weak'           // Confirm use of weak password
	];

	var rows = ESSENTIALS
		.map(function (sel) { return form.querySelector(sel); })
		.filter(Boolean);

	if (rows.length) {
		var table = document.createElement('table');
		table.className = 'form-table';
		table.setAttribute('role', 'presentation');
		var tbody = document.createElement('tbody');
		rows.forEach(function (row) { tbody.appendChild(row); });
		table.appendChild(tbody);

		var account = document.createElement('details');
		account.className = 'ak-accordion';
		account.open = true;
		var accountSummary = document.createElement('summary');
		accountSummary.textContent = <?php echo wp_json_encode( $account_label ); ?>;
		account.appendChild(accountSummary);
		account.appendChild(table);

		// Place it above the first native section (Personal Options).
		var firstHeading = form.querySelector('h2');
		form.insertBefore(account, firstHeading || form.firstChild);
	}

	// --- 2. Normalize the one wrapped section ------------------------------
	// "Application Passwords" is the only heading WP nests in a <div>; lift it
	// out (the wrapper keeps its id + classes for WP's own app-password JS) so
	// the fold loop below can treat it like any other section.
	var apw = form.querySelector('#application-passwords-section');
	if (apw) {
		var apwHeading = apw.querySelector('h2');
		if (apwHeading) form.insertBefore(apwHeading, apw);
	}

	// --- 3. Fold every remaining section into a collapsed <details> --------
	// Snapshot the headings first: we move nodes below, so walking a frozen
	// list (not the live child collection) keeps the iteration stable.
	var headings = [];
	for (var i = 0; i < form.children.length; i++) {
		if (form.children[i].tagName === 'H2') headings.push(form.children[i]);
	}

	headings.forEach(function (heading) {
		var details = document.createElement('details');
		details.className = 'ak-accordion';

		var summary = document.createElement('summary');
		summary.textContent = heading.textContent;
		details.appendChild(summary);

		form.insertBefore(details, heading);

		// Fold everything between this heading and the next section boundary
		// (the next <h2>, or the submit button) into the panel.
		var node = heading.nextSibling;
		form.removeChild(heading);
		while (node) {
			var next = node.nextSibling;
			if (node.nodeType === 1 &&
				(node.tagName === 'H2' ||
				(node.classList && node.classList.contains('submit')))) {
				break;
			}
			details.appendChild(node);
			node = next;
		}
	});
})();
</script>
		<?php
	}
}
