/** Keep HPOS order actions in their visual and keyboard priority order. */
(function () {
 var save = document.querySelector('#woocommerce-order-actions .save_order');
 if (save && save.parentNode) save.parentNode.insertBefore(save, save.parentNode.firstChild);
 document.querySelectorAll('table.wc-shipping-zones, table.wc-shipping-zone-methods, table.wc_shipping, table.wc_tax_rates, table.wc-shipping-classes').forEach(function (table) {
  if (table.parentNode.classList.contains('ak-table-scroll')) return;
  var scroll = document.createElement('div');
  scroll.className = 'ak-table-scroll';
  table.parentNode.insertBefore(scroll, table);
  scroll.appendChild(table);
 });
 // WooCommerce adds inline minimum widths to native selects. Once SelectWoo
 // hides them, those widths must not enlarge the document behind the widget.
 function normalizeHiddenSelects() {
  document.querySelectorAll('select.select2-hidden-accessible').forEach(function (select) {
   select.style.minWidth = '0';
  });
 }
 if (window.jQuery) window.jQuery(function ($) {
  normalizeHiddenSelects();
  $(document.body).on('wc-enhanced-select-init.adminkit', normalizeHiddenSelects);
 });
})();
