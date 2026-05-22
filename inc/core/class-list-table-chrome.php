<?php
/**
 * List-table chrome polish.
 *
 * The status-filter row (`.subsubsub`: All | Active | Inactive …) ships two
 * bits of markup that fight a modern presentation:
 *   1. literal " |" separators as text nodes between the links, and
 *   2. counts wrapped in parentheses, e.g. "(12)".
 *
 * CSS can hide the pipes (font-size tricks) but can't strip the parentheses,
 * so a tiny footer script removes both — leaving clean links + numeric counts
 * that core/chrome.css styles into inline pills with round notification badges.
 *
 * Printed inline on every admin page (like the theme toggle / profile
 * accordion) so the asset registry stays CSS-only. It is a no-op wherever no
 * `.subsubsub` is present.
 *
 * @package AdminKit
 */

defined( 'ABSPATH' ) || exit;

class AdminKit_Core_List_Table_Chrome {

	/**
	 * Wire the hook. Called once from the plugin orchestrator.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_footer', array( __CLASS__, 'print_script' ) );
	}

	/**
	 * Strip the parentheses from `.subsubsub .count` and remove the literal
	 * " |" separator text nodes between the filter links.
	 *
	 * @return void
	 */
	public static function print_script() {
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) {
			return;
		}
		?>
<script id="adminkit-subsubsub">
(function () {
	var lists = document.querySelectorAll('.subsubsub');
	if (!lists.length) return;
	Array.prototype.forEach.call(lists, function (ul) {
		// "(12)" -> "12"
		Array.prototype.forEach.call(ul.querySelectorAll('.count'), function (count) {
			var n = count.textContent.replace(/[()]/g, '').trim();
			if (n !== '') { count.textContent = n; }
		});
		// Drop the literal " |" separators (direct text-node children of each <li>).
		Array.prototype.forEach.call(ul.querySelectorAll('li'), function (li) {
			Array.prototype.slice.call(li.childNodes).forEach(function (node) {
				if (node.nodeType === 3) { li.removeChild(node); }
			});
		});
	});
})();
</script>
		<?php
	}
}
