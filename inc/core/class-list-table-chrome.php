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
	// Wrap each list table in a horizontal-scroll container (.ak-table-scroll):
	// the table stays width:100% so it fills the area + Quick Edit spans full
	// width, while a too-wide table slides inside the wrapper instead of
	// squashing or stacking. The tablenav stays outside (only the table is wrapped).
	Array.prototype.forEach.call(document.querySelectorAll('.wp-list-table'), function (table) {
		var parent = table.parentNode;
		if (!parent || (parent.classList && parent.classList.contains('ak-table-scroll'))) return;
		var wrap = document.createElement('div');
		wrap.className = 'ak-table-scroll';
		parent.insertBefore(wrap, table);
		wrap.appendChild(table);
	});

	var lists = document.querySelectorAll('.subsubsub');
	if (!lists.length) return;
	function clean(ul) {
		// "(12)" -> "12"
		Array.prototype.forEach.call(ul.querySelectorAll('.count'), function (count) {
			var n = count.textContent.replace(/[()]/g, '').trim();
			if (n !== '' && n !== count.textContent) { count.textContent = n; }
		});
		// Drop the literal " |" separators (direct text-node children of each <li>).
		Array.prototype.forEach.call(ul.querySelectorAll('li'), function (li) {
			Array.prototype.slice.call(li.childNodes).forEach(function (node) {
				if (node.nodeType === 3) { li.removeChild(node); }
			});
		});
	}
	Array.prototype.forEach.call(lists, function (ul) {
		clean(ul);
		// WP's updates.js re-renders the counts (with parens) after its async
		// update check, overwriting our pass. Re-clean whenever the subtree changes.
		var obs = new MutationObserver(function () {
			obs.disconnect();
			clean(ul);
			obs.observe(ul, { childList: true, subtree: true, characterData: true });
		});
		obs.observe(ul, { childList: true, subtree: true, characterData: true });
	});
})();
</script>
		<?php
	}
}
