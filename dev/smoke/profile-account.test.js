// Exercise profile-account.js: .pw-weak grouping, tab order, <details> invalid auto-open and 3rd-party sections.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const domHelperSource = fs.readFileSync(path.resolve(__dirname, '../../assets/js/wp-core/profile-account-dom.js'), 'utf8');
const profileSource = fs.readFileSync(path.resolve(__dirname, '../../assets/js/wp-core/profile-account.js'), 'utf8');

const html = `<!doctype html><html class="ak-account-pending"><body class="wp-admin profile-php">
<div class="wrap"><h1>Profile</h1>
<form id="your-profile">
  <h2>Personal Options</h2>
  <table class="form-table"><tbody>
    <tr class="user-language-wrap"><th>Language</th><td><select name="locale"><option value="">Default</option></select></td></tr>
    <tr class="show-admin-bar"><th>Toolbar</th><td><input type="checkbox" name="admin_bar_front"></td></tr>
  </tbody></table>
  <h2>Name</h2>
  <table class="form-table"><tbody>
    <tr class="user-user-login-wrap"><th>Username</th><td><input id="user_login" value="ada" disabled></td></tr>
    <tr class="user-first-name-wrap"><th>First Name</th><td><input name="first_name" value="Ada"></td></tr>
    <tr class="user-last-name-wrap"><th>Last Name</th><td><input name="last_name" value="Lovelace"></td></tr>
    <tr class="user-nickname-wrap"><th>Nickname</th><td><input name="nickname" value="ada"></td></tr>
    <tr class="user-display-name-wrap"><th>Display</th><td><select name="display_name"><option>Ada</option></select></td></tr>
  </tbody></table>
  <h2>Contact Info</h2>
  <table class="form-table"><tbody>
    <tr class="user-email-wrap"><th>Email</th><td><input type="email" name="email" value="ada@example.test"></td></tr>
    <tr class="user-url-wrap"><th>Website</th><td><input type="url" name="url"></td></tr>
  </tbody></table>
  <h2>About Yourself</h2>
  <table class="form-table"><tbody>
    <tr class="user-description-wrap"><th>Bio</th><td><textarea name="description"></textarea></td></tr>
  </tbody></table>
  <h2>Account Management</h2>
  <table class="form-table"><tbody>
    <tr id="password" class="user-pass1-wrap"><th>New Password</th><td><input type="password" name="pass1"></td></tr>
    <tr class="pw-weak"><th>Confirm Password</th><td><input type="checkbox" name="pw_weak"></td></tr>
    <tr class="user-sessions-wrap"><th>Sessions</th><td><button type="button">Log Out Everywhere Else</button></td></tr>
  </tbody></table>
  <div id="application-passwords-section"><h2>Application Passwords</h2><p>App access</p></div>
  <h2>Customer billing address</h2>
  <table class="form-table"><tbody>
    <tr><th>Billing First</th><td><input id="billing_first_name" name="billing_first_name"></td></tr>
  </tbody></table>
  <h2>Customer shipping address</h2>
  <table class="form-table"><tbody>
    <tr><th>Shipping City</th><td><input id="shipping_city" name="shipping_city" required></td></tr>
  </tbody></table>
  <h2>Custom CRM</h2>
  <table class="form-table"><tbody>
    <tr><th>CRM Tag</th><td><input name="crm_tag" value="vip"></td></tr>
  </tbody></table>
  <p class="submit"><input type="submit" id="submit" value="Update Profile"></p>
</form></div></body></html>`;

const dom = new JSDOM(html, { url: 'http://localhost/wp-admin/profile.php', runScripts: 'outside-only' });
const w = dom.window;
w.matchMedia = () => ({ matches: false, addEventListener() {} });
w.AdminKitProfileAccount = {
  pendingClass: 'ak-account-pending',
  readyClass: 'ak-account-ready',
  nav: 'Account sections',
  username_locked: 'Usernames cannot be changed.',
  cards: {
    info: { label: 'Information', desc: 'Identity and password.' },
    settings: { label: 'Settings', desc: 'Language and preferences.' },
  },
  sections: {
    name: 'Name',
    contact: 'Contact Info',
    personal: 'Personal Options',
    account: 'Account Management',
    app_passwords: 'Application Passwords',
    about: ['About Yourself', 'About the user'],
    capabilities: 'Additional Capabilities',
  },
  public_profile: 'Public profile',
  public_profile_desc: 'Language and bio.',
  admin_preferences: 'Administration preferences',
  admin_preferences_desc: 'Toolbar options.',
  profile_sessions: 'Sessions',
  profile_sessions_desc: 'Active sessions.',
  profile_apps: 'Application passwords',
  profile_apps_desc: 'Connected apps.',
  addresses: 'Addresses',
  identity_contact: 'Identity and contact',
  security: 'Security',
  more: 'Other settings',
};

w.eval(domHelperSource);
w.eval(profileSource);

assert.equal(w.document.documentElement.classList.contains('ak-account-ready'), true);
const infoSecurity = w.document.getElementById('ak-profile-security');
assert.ok(infoSecurity && infoSecurity.querySelector('.pw-weak'), '.pw-weak must stay paired with password in #ak-profile-security');

const tabTargets = Array.from(w.document.querySelectorAll('.ak-tabs button[role="tab"]')).map((b) => b.dataset.target);
assert.deepEqual(tabTargets, ['information', 'addresses', 'settings', 'security', 'custom-crm'], 'Profile tabs must sort deterministically');

const shippingInput = w.document.getElementById('shipping_city');
const shippingDetails = shippingInput.closest('details');
assert.equal(shippingDetails.open, false, 'Second address <details> starts collapsed');

shippingInput.dispatchEvent(new w.Event('invalid', { bubbles: false, cancelable: true }));
assert.equal(shippingDetails.open, true, 'Invalid input inside collapsed <details> must auto-open it');
assert.equal(w.document.getElementById('ak-account-panel-addresses').hidden, false, 'Invalid input must activate its owning tab');

w.close();
console.log('OK: profile-account groups .pw-weak with password, orders tabs, preserves 3rd-party sections and opens <details> on invalid.');
