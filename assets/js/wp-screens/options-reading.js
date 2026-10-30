/** Group native rows by field name; keep controls, nonces and one native submit. */
(function () {
 'use strict';
 var form = document.querySelector('body.adminkit.options-reading-php .wrap > form');
 var S = window.AdminKitReadingLayout;
 if (!form || !S || form.dataset.akReading) return;
 var source = form.querySelector(':scope > .form-table');
 if (!source) return;
 form.dataset.akReading = '1';
 [
  ['home', ['show_on_front', 'posts_per_page']],
  ['feeds', ['posts_per_rss', 'rss_use_excerpt']],
  ['visibility', ['blog_public']]
 ].forEach(function (group) {
  var rows = [];
  group[1].forEach(function (name) {
   var input = source.querySelector('[name="' + name + '"]');
   var row = input && input.closest('tr');
   if (row && rows.indexOf(row) < 0) rows.push(row);
  });
  if (!rows.length) return;
  var section = document.createElement('section');
  section.className = 'ak-reading-card';
  var h = document.createElement('h2');
  h.id = 'ak-reading-' + group[0];
  h.textContent = S[group[0]];
  section.setAttribute('aria-labelledby', h.id);
  var desc = document.createElement('p');
  desc.className = 'ak-reading-card__desc';
  desc.textContent = S[group[0] + 'Desc'];
  var table = document.createElement('table');
  table.className = 'form-table';
  table.setAttribute('role', 'presentation');
  var body = document.createElement('tbody');
  rows.forEach(function (row) { body.appendChild(row); });
  table.appendChild(body);
  section.appendChild(h);
  section.appendChild(desc);
  section.appendChild(table);
  form.insertBefore(section, source);
 });
 // Preserve extension-owned rows in their original table.
 if (!source.querySelector('tr')) source.remove();
})();
