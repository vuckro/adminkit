// Exercise username-changer.js: locked initial state, confirmation gate and unlock flow.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const source = fs.readFileSync(path.resolve(__dirname, '../../assets/js/wp-core/username-changer.js'), 'utf8');

const html = `<!doctype html><html><body class="wp-admin user-edit-php">
<form id="your-profile"><table class="form-table"><tbody>
  <tr class="user-user-login-wrap">
    <th><label for="user_login">Username</label></th>
    <td><input type="text" name="user_login" id="user_login" value="ada" disabled="disabled"><span class="description">Usernames cannot be changed.</span></td>
  </tr>
</tbody></table></form></body></html>`;

const dom = new JSDOM(html, { url: 'http://localhost/wp-admin/user-edit.php?user_id=2', runScripts: 'outside-only' });
const w = dom.window;
let confirmed = false;
w.confirm = () => confirmed;
w.AdminKitUsernameChanger = {
  unlockLabel: 'Change username',
  unlockConfirm: 'Renaming invalidates active sessions. Continue?',
  confirmAction: 'Unlock username',
  cancelAction: 'Cancel',
  unlockedHint: 'Type the new username, then click Update User.',
};

(async () => {
  w.eval(source);
  await new Promise((resolve) => setTimeout(resolve, 10));

  const input = w.document.getElementById('user_login');
  assert.equal(input.hasAttribute('disabled'), false, 'Disabled attribute removed so form submits original or updated value');
  assert.equal(input.hasAttribute('readonly'), true, 'Username starts locked as readonly');

  // Cancelled confirmation keeps input readonly.
  const trigger = w.document.querySelector('.adminkit-uc-trigger') || input;
  trigger.click();
  const confirmBtn = w.document.querySelector('.adminkit-uc-confirm-accept');
  if (confirmBtn) {
    const cancelBtn = w.document.querySelector('.adminkit-uc-confirm-cancel');
    cancelBtn.click();
    assert.equal(input.hasAttribute('readonly'), true, 'Cancelling confirmation keeps #user_login readonly');
    trigger.click();
    w.document.querySelector('.adminkit-uc-confirm-accept').click();
  } else {
    assert.equal(input.hasAttribute('readonly'), true);
    confirmed = true;
    trigger.click();
  }

  assert.equal(input.hasAttribute('readonly'), false, 'Confirmed unlock makes #user_login editable');
  assert.ok(w.document.querySelector('.adminkit-uc-active'), 'Unlocked hint is rendered');

  w.close();
  console.log('OK: username-changer starts readonly, respects cancel, and unlocks cleanly upon confirmation.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
