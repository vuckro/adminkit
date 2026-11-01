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
w.confirm = () => {
  throw new Error('window.confirm() must not be called; inline confirmation UI is required');
};
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

  // Focusing or clicking #user_login must never trigger a blocking confirm or unlock it.
  input.focus();
  input.click();
  assert.equal(input.hasAttribute('readonly'), true, 'Focusing #user_login keeps it readonly without focus trap');

  const trigger = w.document.querySelector('.adminkit-uc-trigger');
  const confirmBox = w.document.querySelector('.adminkit-uc-confirm');
  assert.ok(trigger, 'Explicit Change username trigger button is rendered');
  assert.equal(confirmBox.hidden, true, 'Inline confirmation starts hidden');

  // Escape closes inline confirmation and restores focus to trigger.
  trigger.click();
  assert.equal(confirmBox.hidden, false, 'Clicking trigger opens inline confirmation');
  assert.equal(w.document.activeElement, w.document.querySelector('.adminkit-uc-confirm-accept'));
  confirmBox.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
  assert.equal(confirmBox.hidden, true, 'Escape closes inline confirmation');
  assert.equal(w.document.activeElement, trigger, 'Escape returns focus to trigger');

  // Cancel button keeps input readonly.
  trigger.click();
  w.document.querySelector('.adminkit-uc-confirm-cancel').click();
  assert.equal(input.hasAttribute('readonly'), true, 'Cancelling confirmation keeps #user_login readonly');

  // Confirm unlocks #user_login and focuses it.
  trigger.click();
  w.document.querySelector('.adminkit-uc-confirm-accept').click();
  assert.equal(input.hasAttribute('readonly'), false, 'Confirmed unlock makes #user_login editable');
  assert.equal(w.document.activeElement, input, 'Confirmed unlock moves focus to #user_login');
  assert.ok(w.document.querySelector('.adminkit-uc-active'), 'Unlocked hint is rendered');

  w.close();
  console.log('OK: username-changer starts readonly, uses inline accessible confirmation, and unlocks cleanly.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
