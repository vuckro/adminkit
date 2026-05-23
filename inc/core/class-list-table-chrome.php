<?php
/**
 * List-table chrome polish.
 *
 * Two bits of list-table markup fight a modern presentation, and neither can
 * be fixed with CSS alone:
 *   1. the status-filter row (`.subsubsub`: All | Active | …) ships literal
 *      " |" separators as text nodes and counts wrapped in parentheses "(12)";
 *   2. post status labels render as `— <span class="post-state">Draft</span>`
 *      after the title — a leading mdash plus a trailing ", " separator inside
 *      multi-state spans.
 *
 * A tiny footer script strips all of it — leaving clean filter links + numeric
 * counts (styled into pills by core/chrome.css) and clean status spans (styled
 * into colored chips by components/tables.css, keyed off the row's status-*
 * class so no localized label matching is needed here).
 *
 * Printed inline on every admin page (like the theme toggle / profile
 * accordion) so the asset registry stays CSS-only. Each block is a no-op
 * wherever its markup is absent.
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
	 * Normalize list-table markup for the modern presentation:
	 *   - strip parentheses from `.subsubsub .count` + drop the " |" separators;
	 *   - strip the leading " — " and trailing ", " from `.post-state` chips.
	 *
	 * @return void
	 */
	public static function print_script() {
		if ( ! apply_filters( 'adminkit/should_load', true, 'admin' ) ) {
			return;
		}
		?>
<script id="adminkit-list-table-chrome">
(function () {
	// --- Status filters (.subsubsub: All | Published | Draft | …) ----------
	Array.prototype.forEach.call(document.querySelectorAll('.subsubsub'), function (ul) {
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

	// --- Status chips (.post-state) ----------------------------------------
	// Strip the trailing ", " separator WP packs inside multi-state spans so
	// each chip reads as a clean label; color is applied in CSS by status-* row
	// class, so we don't match the (localized) text here.
	var states = document.querySelectorAll('.wp-list-table .post-state');
	Array.prototype.forEach.call(states, function (span) {
		span.textContent = span.textContent.replace(/[,\s]+$/, '').trim();
	});
	// Remove the " — " mdash text node(s) WP prints before the chips, inside the
	// title's <strong>. Only whitespace / dash text nodes are touched.
	var cleaned = [];
	Array.prototype.forEach.call(states, function (span) {
		var parent = span.parentNode;
		if (!parent || cleaned.indexOf(parent) !== -1) { return; }
		cleaned.push(parent);
		Array.prototype.slice.call(parent.childNodes).forEach(function (node) {
			if (node.nodeType === 3 && /^[\s—–-]*$/.test(node.textContent)) {
				parent.removeChild(node);
			}
		});
	});
})();
</script>
		<?php
	}
}
