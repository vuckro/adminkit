// Boot real navigation controls and save host: every edit must reach its own endpoint.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const root = path.resolve(__dirname, '../..');
const tick = () => new Promise(resolve => setTimeout(resolve, 15));
(async () => {
  for (const [screen, scope, toolbarContext] of [['main', 'menu', null], ['main', 'toolbar', 'admin'], ['main', 'toolbar', 'frontend'], ['menu', 'menu', null], ['toolbar', 'toolbar', 'admin'], ['toolbar', 'toolbar', 'frontend']]) {
    for (const action of scope === 'menu' ? ['title', 'icon', 'url'] : ['title', 'icon']) {
      const dom = new JSDOM('<div id="adminkit-app"></div>', { url: 'http://localhost/#' + scope, runScripts: 'outside-only' });
      const w = dom.window, calls = [], errors = [];
      w.addEventListener('error', event => errors.push(event.error || event.message));
      const data = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures/boot-main.json'), 'utf8'));
      data.screen = screen;
      data.toolbar.enabled = true;
      data.menuManager.menu = [{ slug: 'tools.php', title: 'Tools', children: [] }];
      w.AdminKitData = data;
      w.AdminKitParts = { design: context => () => context.el('section'), features: context => () => context.el('section') };
      w.wp = { apiFetch: request => {
        if (!request.method) return Promise.resolve({ nodesByContext: { admin: [{ id: 'site-name', label: 'Site', group: 'root' }], frontend: [{ id: 'site-name', label: 'Frontend site', group: 'root' }] }, configByContext: {} });
        calls.push(request); return new Promise(() => {});
      } };
      for (const file of ['assets/js/settings/navigation-controls.js', 'assets/js/settings/navigation-menu.js', 'assets/js/settings/navigation-toolbar.js', 'assets/js/settings/navigation.js', 'assets/js/settings/main.js', 'assets/js/settings.js']) w.eval(fs.readFileSync(path.join(root, file), 'utf8'));
      await tick();
      const pane = screen === 'main' ? w.document.getElementById('ak-navigation-' + scope) : w.document.querySelector('.ak-panel--menu');
      if (toolbarContext === 'frontend') pane.querySelectorAll('.ak-menu-toolbar__seg button')[1].click();
      if (scope === 'toolbar') {
        assert.equal(pane.querySelector('h3').textContent, data.i18n[toolbarContext === 'frontend' ? 'toolbarFrontendTitle' : 'toolbarAdminTitle']);
        assert.equal(pane.querySelector('.ak-toolbar-scope').textContent, data.i18n[toolbarContext === 'frontend' ? 'toolbarFrontendScope' : 'toolbarAdminScope']);
        assert.equal(w.document.querySelector('.ak-savebar button').disabled, true, 'context selection does not dirty a draft');
      }
      if (action === 'title') {
        const input = pane.querySelector('.ak-menu-title'); input.value = 'Audit title'; input.dispatchEvent(new w.Event('input'));
      } else if (action === 'url') {
        pane.querySelector('.ak-menu-url').click();
        w.document.querySelector('.ak-url-pop input').value = 'tools.php?probe=1';
        w.document.querySelector('.ak-url-pop button').click();
        assert.equal(w.document.querySelector('.ak-url-pop'), null, 'URL picker closes after apply');
      } else {
        pane.querySelector('.ak-menu-icon').click();
        assert.equal(w.document.querySelector('.ak-savebar button').disabled, true, 'opening the picker must not dirty a draft');
        w.document.querySelector('.ak-icon-pop__sw[title="home"]').click();
      }
      w.document.querySelector('.ak-savebar button').click(); await tick();
      assert.equal(calls.length, 1);
      assert.equal(calls[0].path, '/adminkit/v1/' + scope, scope + ' ' + action + ' reached wrong endpoint');
      if (scope === 'toolbar') assert.equal(calls[0].data[toolbarContext][action === 'title' ? 'titles' : 'icons']['site-name'], action === 'title' ? 'Audit title' : 'home');
      else assert.equal(calls[0].data.items[0][action], action === 'title' ? 'Audit title' : action === 'url' ? 'tools.php?probe=1' : 'home');
      assert.deepEqual(errors, [], 'navigation controls must not throw');
      w.close();
    }
  }
  console.log('OK: actual menu/toolbar titles and icons save to their own endpoints.');
})().catch(error => { console.error(error); process.exitCode = 1; });
