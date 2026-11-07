// Exercise the real Quick Edit client: excluded roles, multi-role accounts, self-email lock and Enter-to-save.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const source = fs.readFileSync(path.resolve(__dirname, '../../assets/js/wp-core/user-quick-edit.js'), 'utf8');

(async () => {
  for (const [currentRole, multiRole, isSelf] of [
    ['administrator', '0', '0'],
    ['subscriber', '0', '0'],
    ['subscriber', '1', '0'],
    ['subscriber', '0', '1'],
  ]) {
    const dom = new JSDOM(`<form method="get"><table><tbody><tr><td><button class="adminkit-qe-open" data-user-id="8" data-role="${currentRole}" data-multi-role="${multiRole}" data-self="${isSelf}" data-email="test@example.invalid">Edit</button></td></tr></tbody></table></form>
      <template id="adminkit-quick-edit-template"><tr><td>
      <input class="adminkit-qe-first-name"><input class="adminkit-qe-last-name"><input class="adminkit-qe-email">
      <select class="adminkit-qe-role"><option value="subscriber">Subscriber</option></select>
      <button class="adminkit-qe-save">Save</button><span class="adminkit-qe-error"></span>
      </td></tr></template>`, { url: 'http://localhost/', runScripts: 'outside-only' });
    const w = dom.window;
    let posted = null;
    w.AdminKitUserQuickEdit = {
      ajaxUrl: '/wp-admin/admin-ajax.php',
      multiRoleHint: 'Multiple roles',
      selfEmailHint: 'Self email',
    };
    w.fetch = (url, request) => { posted = request.body; return new Promise(() => {}); };
    w.eval(source);
    await new Promise(resolve => setTimeout(resolve, 10));
    w.document.querySelector('.adminkit-qe-open').click();

    const roleField = w.document.querySelector('select.adminkit-qe-role');
    const emailField = w.document.querySelector('input.adminkit-qe-email');
    const shouldDisableRole = currentRole === 'administrator' || multiRole === '1';
    assert.equal(roleField.disabled, shouldDisableRole);
    assert.equal(emailField.readOnly, isSelf === '1');

    // Pressing Enter inside an input must prevent outer GET form submit and trigger AJAX save.
    const firstInput = w.document.querySelector('input.adminkit-qe-first-name');
    firstInput.value = 'Ada';
    const enterEvent = new w.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
    firstInput.dispatchEvent(enterEvent);
    assert.equal(enterEvent.defaultPrevented, true, 'Enter inside Quick Edit must prevent outer GET form submission');
    assert.notEqual(posted, null, 'Enter inside Quick Edit must save via AJAX');
    assert.equal(posted.has('role'), !shouldDisableRole, 'excluded or multi-role must be omitted, never replaced');
    if (posted.has('role')) assert.equal(posted.get('role'), 'subscriber');
    assert.equal(posted.get('user_id'), '8');
    assert.equal(posted.get('first_name'), 'Ada');
    assert.equal(posted.has('user_email'), true, 'other fields stay editable');
    w.close();
  }

  // A completed request must not close a newer editor or submit twice on Enter.
  const race = new JSDOM(`<table><tbody>${[8,9].map(id => `<tr><td><button class="adminkit-qe-open" data-user-id="${id}" data-role="subscriber">Edit</button></td></tr>`).join('')}</tbody></table>
    <template id="adminkit-quick-edit-template"><tr><td><input class="adminkit-qe-first-name"><input class="adminkit-qe-last-name"><input class="adminkit-qe-email"><select class="adminkit-qe-role"><option value="subscriber">Subscriber</option></select><button class="adminkit-qe-save">Save</button><button class="adminkit-qe-cancel">Cancel</button><span class="adminkit-qe-error"></span></td></tr></template>`, {url:'http://localhost/',runScripts:'outside-only'});
  const w=race.window,d=w.document;
  w.AdminKitUserQuickEdit={ajaxUrl:'/wp-admin/admin-ajax.php',genericErr:'Failed'};
  const requests=[];
  w.fetch=()=>new Promise((resolve,reject)=>requests.push({resolve,reject}));
  w.eval(source);await new Promise(resolve=>setTimeout(resolve,10));
  const buttons=[...d.querySelectorAll('.adminkit-qe-open')];
  buttons[0].click();
  const first=d.querySelector('.adminkit-qe-first-name');
  const enter=()=>first.dispatchEvent(new w.KeyboardEvent('keydown',{key:'Enter',bubbles:true,cancelable:true}));
  enter();enter();assert.equal(requests.length,1,'Repeated Enter created a duplicate write');
  first.dispatchEvent(new w.KeyboardEvent('keydown',{key:'Escape',bubbles:true,cancelable:true}));
  buttons[1].click();const newer=d.querySelector('.adminkit-qe-first-name');newer.value='Unsaved second editor';
  requests[0].resolve({json:async()=>({success:true,data:{first_name:'Saved first editor'}})});
  await new Promise(resolve=>setTimeout(resolve,0));
  assert.equal(newer.isConnected,true,'Old response closed the newer editor');
  assert.equal(newer.value,'Unsaved second editor');
  assert.equal(buttons[1].getAttribute('aria-expanded'),'true');
  const save=d.querySelector('.adminkit-qe-save');save.click();
  requests[1].reject(new Error('Offline'));await new Promise(resolve=>setTimeout(resolve,0));
  assert.equal(save.disabled,false,'Failed request prevented retry');
  save.click();assert.equal(requests.length,3,'Retry after network failure did not submit');
  w.close();
  console.log('OK: Quick Edit protects excluded/multi roles, locks self-email, prevents GET submit on Enter and submits assignable roles.');
})().catch(error => { console.error(error); process.exitCode = 1; });
