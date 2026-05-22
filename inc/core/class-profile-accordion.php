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
		?>
<script id="adminkit-profile-accordion">
(function () {
	var form = document.querySelector('#your-profile, #createuser');
	if (!form || form.dataset.akAccordion) return;
	form.dataset.akAccordion = '1';

	// "Application Passwords" is the one section WP wraps in a <div>, so its
	// <h2> isn't a direct child of the form like every other heading. Lift it
	// out (the wrapper keeps its id + classes for WP's own app-password JS) so
	// the loop below can treat it like any other section.
	var apw = form.querySelector('#application-passwords-section');
	if (apw) {
		var apwHeading = apw.querySelector('h2');
		if (apwHeading) form.insertBefore(apwHeading, apw);
	}

	// Snapshot the top-level section headings up front: we move nodes around
	// below, so walking a frozen list (not the live child collection) keeps
	// the iteration stable.
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
