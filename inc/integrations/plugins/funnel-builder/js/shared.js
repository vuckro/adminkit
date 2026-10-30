/* Keyboard access for the host's clickable div tabs and span view switches.
 * Native click handlers still own routing, state, saving and permissions. */
(function () {
 'use strict';
 if (!document.body.classList.contains('adminkit-funnelkit')) return;
 var scheduled = false;
 var labelId = 0;
 function sync() {
  scheduled = false;
  var groups = new Set(document.querySelectorAll('.bwf--settings-tab-list, .bwf--settings-subtab-list, .bwf-advance-toggle'));
  document.querySelectorAll('.bwf-tab-item').forEach(function (item) { groups.add(item.parentElement); });
  document.querySelectorAll('.bwf-input-label, .bwf-checkbox-control__label').forEach(function (label) {
   var container = label.classList.contains('bwf-checkbox-control__label') ? label.closest('.bwf-checkbox-control') : label.parentElement;
   if (!container) return;
   var input = container.querySelector('input:not([type=hidden]), textarea, select');
   if (!input || input.getAttribute('aria-label') || input.getAttribute('aria-labelledby') || (input.labels && input.labels.length)) return;
   if (!label.id) label.id = 'ak-funnelkit-label-' + (++labelId);
   input.setAttribute('aria-labelledby', label.id);
  });
  document.querySelectorAll('.bwf-clear-text, .bwf-info-empty-value, .bwf-c-s-tag-add, .bwf-modal-close, .bwf-filter__tag').forEach(function (action) {
   if (action.closest('button, a[href]') || action.hasAttribute('role')) return;
   action.setAttribute('role', 'button'); action.tabIndex = 0;
   action.setAttribute('data-ak-funnelkit-action', '');
   if (action.classList.contains('bwf-modal-close')) {
    var modal = action.closest('.components-modal__frame');
    var nativeClose = modal && modal.querySelector('.components-modal__header button[aria-label]');
    if (nativeClose) action.setAttribute('aria-label', nativeClose.getAttribute('aria-label'));
   }
   var row = action.closest('.bwf-c-s-list');
   var label = row && row.querySelector('.bwf-c-s-label');
   if (label && !action.hasAttribute('aria-label') && !action.hasAttribute('aria-labelledby')) {
    if (!label.id) label.id = 'ak-funnelkit-label-' + (++labelId);
    if (!action.id) action.id = 'ak-funnelkit-action-' + (++labelId);
    action.setAttribute('aria-labelledby', action.id + ' ' + label.id);
   }
  });
  document.querySelectorAll('.bwf-hover-submenu:not(.bwf-hover-submenu-php)').forEach(function (host) {
   var trigger = host.querySelector(':scope > span:not(.bwf-hover-suheader-menu)');
   if (!trigger) return;
   trigger.setAttribute('role', 'button'); trigger.tabIndex = 0;
   trigger.setAttribute('data-ak-funnelkit-disclosure', '');
   var menu = host.querySelector('.bwf-hover-suheader-menu');
   trigger.setAttribute('aria-expanded', String(!!menu));
   if (menu) {
    if (!menu.id) menu.id = 'ak-funnelkit-label-' + (++labelId);
    trigger.setAttribute('aria-controls', menu.id);
    if (host.hasAttribute('data-ak-focus-menu')) {
     host.removeAttribute('data-ak-focus-menu');
     var firstLink = menu.querySelector('a'); if (firstLink) firstLink.focus();
    }
   } else trigger.removeAttribute('aria-controls');
  });
  groups.forEach(function (group) {
   var tabs = !group.classList.contains('bwf-advance-toggle');
   group.setAttribute('role', tabs ? 'tablist' : 'group');
   var items = Array.from(group.children).filter(function (item) {
    return tabs ? item.matches('.tab--title, .subtab--title, .bwf-tab-item') : item.tagName === 'SPAN';
   });
   items.forEach(function (item, index) {
    var selected = item.classList.contains('active--tab') || item.classList.contains('bwf-active') || item.classList.contains('active-tab');
    item.setAttribute('role', tabs ? 'tab' : 'button');
    item.setAttribute(tabs ? 'aria-selected' : 'aria-pressed', String(selected));
    item.tabIndex = selected || (!items.some(function (el) { return el.classList.contains('active--tab') || el.classList.contains('bwf-active') || el.classList.contains('active-tab'); }) && index === 0) ? 0 : -1;
   });
  });
 }
 document.addEventListener('keydown', function (event) {
  if (event.defaultPrevented) return;
  var disclosure = event.target.closest('[data-ak-funnelkit-disclosure]');
  var menuHost = event.target.closest('.bwf-hover-submenu:not(.bwf-hover-submenu-php)');
  if (menuHost && event.key === 'Escape') {
   event.preventDefault();
   menuHost.dispatchEvent(new MouseEvent('mouseout', { bubbles: true, relatedTarget: document.body }));
   var trigger = menuHost.querySelector('[data-ak-funnelkit-disclosure]');
   if (trigger) trigger.focus();
   return;
  }
  if (disclosure && (event.key === 'Enter' || event.key === ' ' || event.key === 'ArrowDown')) {
   event.preventDefault();
   if (event.key !== 'ArrowDown' && disclosure.getAttribute('aria-expanded') === 'true') {
    menuHost.dispatchEvent(new MouseEvent('mouseout', { bubbles: true, relatedTarget: document.body })); return;
   }
   if (event.key === 'ArrowDown') menuHost.setAttribute('data-ak-focus-menu', '');
   menuHost.dispatchEvent(new MouseEvent('mouseover', { bubbles: true }));
   if (menuHost.querySelector('.bwf-hover-suheader-menu')) sync();
   return;
  }
  var action = event.target.closest('[data-ak-funnelkit-action]');
  if (action && event.target === action && (event.key === 'Enter' || event.key === ' ')) {
   event.preventDefault(); action.click(); return;
  }
  var item = event.target.closest('.tab--title, .subtab--title, .bwf-tab-item, .bwf-advance-toggle > span');
  if (!item || event.altKey || event.ctrlKey || event.metaKey) return;
  var items = Array.from(item.parentElement.children).filter(function (el) { return el.matches('[role=tab], [role=button]'); });
  var index = items.indexOf(item), next = index;
  if (event.key === 'ArrowRight' || event.key === 'ArrowDown') next = (index + 1) % items.length;
  else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') next = (index - 1 + items.length) % items.length;
  else if (event.key === 'Home') next = 0;
  else if (event.key === 'End') next = items.length - 1;
  else if (event.key !== 'Enter' && event.key !== ' ') return;
  if (index < 0 || !items.length) return;
  event.preventDefault();
  items[next].focus();
  items[next].click();
 });
 document.addEventListener('focusout', function (event) {
  var host = event.target.closest('.bwf-hover-submenu:not(.bwf-hover-submenu-php)');
  if (host && event.relatedTarget && !host.contains(event.relatedTarget)) {
   host.dispatchEvent(new MouseEvent('mouseout', { bubbles: true, relatedTarget: event.relatedTarget }));
  }
 });
 new MutationObserver(function () {
  if (!scheduled) { scheduled = true; requestAnimationFrame(sync); }
 }).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] });
 sync();
}());
