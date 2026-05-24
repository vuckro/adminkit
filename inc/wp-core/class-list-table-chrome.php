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
 * that wp-core/chrome.css styles into inline pills with round notification badges.
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

	// Quick / Bulk Edit: keep the inline-edit form within the visible area on a
	// wide (horizontally scrollable) table. The edit cell spans every column, so
	// (a) publish each wrapper's visible width as --ak-qe-w for the form grid to
	// size against, and (b) wrap the form columns in .ak-qe-grid so CSS can lay
	// them out as a responsive grid instead of letting them ride the wide cell.
	var scrolls = document.querySelectorAll('.ak-table-scroll');
	function setQEWidth(el) { el.style.setProperty('--ak-qe-w', el.clientWidth + 'px'); }
	if (window.ResizeObserver) {
		var ro = new ResizeObserver(function (entries) {
			entries.forEach(function (e) { setQEWidth(e.target); });
		});
		Array.prototype.forEach.call(scrolls, function (el) { ro.observe(el); });
	} else {
		Array.prototype.forEach.call(scrolls, setQEWidth);
		window.addEventListener('resize', function () {
			Array.prototype.forEach.call(scrolls, setQEWidth);
		});
	}
	// Wrap the hidden Quick/Bulk Edit templates once. WP clones them into rows on
	// demand, so the clones inherit both the .ak-qe-grid wrapper and --ak-qe-w
	// (inherited from the .ak-table-scroll the clone lands in).
	['inline-edit', 'bulk-edit'].forEach(function (id) {
		var tmpl = document.getElementById(id);
		var cell = tmpl && tmpl.querySelector('td.colspanchange');
		if (!cell) return;
		var first = cell.firstElementChild;
		if (first && first.classList.contains('ak-qe-grid')) return;
		var grid = document.createElement('div');
		grid.className = 'ak-qe-grid';
		while (cell.firstChild) { grid.appendChild(cell.firstChild); }
		cell.appendChild(grid);
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
