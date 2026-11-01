// Exercise options-general.js and options-discussion.js: 3rd-party sections preserved in cards and logo upload nonce wired.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const generalSource = fs.readFileSync(path.resolve(__dirname, '../../assets/js/wp-screens/options-general.js'), 'utf8');
const discussionSource = fs.readFileSync(path.resolve(__dirname, '../../assets/js/wp-screens/options-discussion.js'), 'utf8');

// 1. options-general.php
{
  const html = `<!doctype html><html class="ak-options-pending"><head><title>General Settings ‹ Site</title></head><body>
  <div class="wrap"><form action="options.php" method="post">
    <input type="hidden" name="option_page" value="general">
    <input type="hidden" name="_wp_http_referer" value="/wp-admin/options-general.php">
    <table class="form-table"><tbody>
      <tr><th>Site Title</th><td><input name="blogname" value="WaasKit"></td></tr>
      <tr><th>Admin Email</th><td><input name="admin_email" value="admin@example.test"></td></tr>
      <tr><th>Site Language</th><td><select name="WPLANG"><option value="fr_FR">Français</option></select></td></tr>
      <tr><th>Logo</th><td>
        <img id="ak-general-logo-preview" hidden>
        <input type="hidden" id="ak-general-logo-url" name="adminkit_general_logo_url" value="">
        <button type="button" id="ak-general-logo-upload" data-upload-nonce="nonce-123">Choose</button>
        <button type="button" id="ak-general-logo-remove" disabled>Remove</button>
      </td></tr>
    </tbody></table>
    <h2>Custom SEO Section</h2>
    <p>Configure indexation</p>
    <table class="form-table"><tbody>
      <tr><th>Meta</th><td><input name="custom_seo_meta" value="index"></td></tr>
    </tbody></table>
    <p class="submit"><input type="submit" value="Save Changes"></p>
  </form></div></body></html>`;

  const dom = new JSDOM(html, { url: 'http://localhost/wp-admin/options-general.php', runScripts: 'outside-only' });
  const w = dom.window;
  let capturedFrameOpts = null;
  w.wp = {
    media(opts) {
      capturedFrameOpts = opts;
      return { on() {}, open() {} };
    },
  };
  w.AdminKitOptionsGeneral = {
    identity: 'Identity',
    siteIcon: 'Site Icon',
    logo: 'Brand Logo',
    logoDesc: 'Admin mark',
    account: 'Account',
    languageZone: 'Language',
    formats: 'Formats',
    tabGeneral: 'General',
    tabLocale: 'Language',
    tabAccount: 'Account',
    tabOther: 'Other',
  };

  w.eval(generalSource);

  const otherPanel = w.document.getElementById('ak-options-panel-other');
  assert.ok(otherPanel, '3rd-party settings section must create the Other tab panel');
  assert.ok(otherPanel.querySelector('input[name="custom_seo_meta"]'), '3rd-party field must stay inside #ak-options-panel-other');
  assert.equal(otherPanel.hidden, true, 'Inactive Other panel must be hidden on initial General tab');

  w.document.getElementById('ak-general-logo-upload').click();
  assert.ok(capturedFrameOpts && capturedFrameOpts.uploader, 'wp.media must receive uploader params');
  assert.equal(capturedFrameOpts.uploader.params._ak_logo_nonce, 'nonce-123');
  assert.equal(capturedFrameOpts.uploader.params.adminkit_logo_upload, '1');
  w.close();
}

// 2. options-discussion.php
{
  const html = `<!doctype html><html class="ak-options-pending"><head><title>Discussion Settings ‹ Site</title></head><body>
  <div class="wrap"><form action="options.php" method="post">
    <input type="hidden" name="option_page" value="discussion">
    <h2>Default post settings</h2>
    <table class="form-table"><tbody>
      <tr><th>Comments</th><td><input type="checkbox" name="default_comment_status"></td></tr>
    </tbody></table>
    <h2>Anti-spam Plugin</h2>
    <table class="form-table"><tbody>
      <tr><th>API Key</th><td><input name="antispam_key" value="abc"></td></tr>
    </tbody></table>
    <h2>Avatars</h2>
    <table class="form-table"><tbody>
      <tr><th>Show Avatars</th><td><input type="checkbox" name="show_avatars" checked></td></tr>
    </tbody></table>
    <p class="submit"><input type="submit" value="Save Changes"></p>
  </form></div></body></html>`;

  const dom = new JSDOM(html, { url: 'http://localhost/wp-admin/options-discussion.php', runScripts: 'outside-only' });
  const w = dom.window;
  w.AdminKitOptionsDiscussion = {
    avatars: 'Avatars',
    comments: 'Comments',
    participation: 'Participation',
    display: 'Display',
    notifications: 'Notifications',
    moderation: 'Moderation',
    otherSettings: 'Other settings',
  };

  w.eval(discussionSource);

  const commentsPanel = w.document.getElementById('ak-options-panel-comments');
  const pluginCard = commentsPanel.querySelector('.ak-discussion-card input[name="antispam_key"]');
  assert.ok(pluginCard, '3rd-party discussion table must be wrapped inside a .ak-discussion-card');
  w.close();
}

console.log('OK: options-general and options-discussion wrap 3rd-party sections into tab cards and wire logo upload nonces.');
