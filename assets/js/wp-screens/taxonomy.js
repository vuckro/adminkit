/** Align native taxonomy creation and management without replacing their forms. */
(function () {
 'use strict';
 if (!document.body.matches('.adminkit.edit-tags-php')) return;
 var columns = document.getElementById('col-container');
 var left = document.getElementById('col-left');
 var right = document.getElementById('col-right');
 if (columns && left && right && left.parentNode === columns && right.parentNode === columns) {
  columns.insertBefore(left, right);
 }
 var form = document.querySelector('#col-right #posts-filter');
 var card = form && form.querySelector('.ak-table-card');
 var S = window.AdminKitTaxonomyLayout;
 if (!card || !S || card.querySelector('.ak-taxonomy-head')) return;
 var head = document.createElement('div');
 head.className = 'ak-taxonomy-head';
 var h = document.createElement('h2');
 h.id = 'ak-taxonomy-list-title';
 h.textContent = S.title;
 var desc = document.createElement('p');
 desc.textContent = S.desc;
 head.appendChild(h);
 head.appendChild(desc);
 var searchForm = document.querySelector('.wrap > form.search-form');
 var search = searchForm && searchForm.querySelector('.search-box');
 if (search) {
  if (!searchForm.id) searchForm.id = 'ak-taxonomy-search';
  search.querySelectorAll('input, button, select').forEach(function (control) {
   control.setAttribute('form', searchForm.id);
  });
  head.appendChild(search);
 }
 if (columns) columns.classList.add('ak-taxonomy-ready');
 card.insertBefore(head, card.firstChild);
 card.setAttribute('role', 'region');
 card.setAttribute('aria-labelledby', h.id);
})();
