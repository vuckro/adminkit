// Exercise the real Quick Edit client with a role excluded by editable_roles.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const source = fs.readFileSync(path.resolve(__dirname, '../../assets/js/wp-core/user-quick-edit.js'), 'utf8');
(async () => {
  for (const currentRole of ['administrator', 'subscriber']) {
    const dom = new JSDOM(`<table><tbody><tr><td><button class="adminkit-qe-open" data-user-id="8" data-role="${currentRole}" data-email="test@example.invalid">Edit</button></td></tr></tbody></table>
      <template id="adminkit-quick-edit-template"><tr><td>
      <input class="adminkit-qe-first-name"><input class="adminkit-qe-last-name"><input class="adminkit-qe-email">
      <select class="adminkit-qe-role"><option value="subscriber">Subscriber</option></select>
      <button class="adminkit-qe-save">Save</button><span class="adminkit-qe-error"></span>
      </td></tr></template>`, { url: 'http://localhost/', runScripts: 'outside-only' });
    const w = dom.window;
    let posted;
    w.AdminKitUserQuickEdit = { ajaxUrl: '/wp-admin/admin-ajax.php' };
    w.fetch = (url, request) => { posted = request.body; return new Promise(() => {}); };
    w.eval(source);
    await new Promise(resolve => setTimeout(resolve, 10));
    w.document.querySelector('.adminkit-qe-open').click();
    const field = w.document.querySelector('select.adminkit-qe-role');
    assert.equal(field.disabled, currentRole === 'administrator');
    w.document.querySelector('.adminkit-qe-save').click();
    assert.equal(posted.has('role'), currentRole === 'subscriber', 'filtered-out role must be omitted, never replaced');
    if (posted.has('role')) assert.equal(posted.get('role'), 'subscriber');
    assert.equal(posted.get('user_id'), '8');
    assert.equal(posted.has('user_email'), true, 'other fields stay editable');
    w.close();
  }
  console.log('OK: Quick Edit omits excluded current roles and submits assignable roles.');
})().catch(error => { console.error(error); process.exitCode = 1; });
