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
      for (const file of ['assets/js/settings/navigation-controls.js', 'assets/js/settings/navigation-menu-model.js', 'assets/js/settings/navigation-menu.js', 'assets/js/settings/navigation-toolbar.js', 'assets/js/settings/navigation.js', 'assets/js/settings/main.js', 'assets/js/settings.js']) w.eval(fs.readFileSync(path.join(root, file), 'utf8'));
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
  // Failed toolbar GET must clear inert, block premature reset, and allow Retry.
  {
    const dom = new JSDOM('<div id="adminkit-app"></div>', { url: 'http://localhost/#toolbar', runScripts: 'outside-only' });
    const w = dom.window;
    const data = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures/boot-main.json'), 'utf8'));
    data.screen = 'toolbar';
    data.toolbar.enabled = true;
    w.AdminKitData = data;
    let attempts = 0;
    w.wp = { apiFetch: () => {
      attempts++;
      if (attempts === 1) return Promise.reject({ message: 'REST temporary error' });
      return Promise.resolve({ nodesByContext: { admin: [{ id: 'site-name', label: 'Site', group: 'root' }], frontend: [] }, configByContext: {} });
    } };
    for (const file of ['assets/js/settings/navigation-controls.js', 'assets/js/settings/navigation-menu-model.js', 'assets/js/settings/navigation-menu.js', 'assets/js/settings/navigation-toolbar.js', 'assets/js/settings/navigation.js', 'assets/js/settings.js']) w.eval(fs.readFileSync(path.join(root, file), 'utf8'));
    await tick();
    const pane = w.document.querySelector('.ak-panel--menu');
    assert.equal(pane.inert, false, 'failed GET must lift inert on toolbar pane');
    const resetBtn = pane.querySelector('.ak-menu-toolbar .ak-btn--ghost');
    assert.equal(resetBtn.disabled, true, 'reset stays disabled before toolbar nodes load');
    resetBtn.click();
    assert.equal(w.document.querySelector('.ak-savebar button').disabled, true, 'reset before load must not dirty empty toolbar draft');
    const retryBtn = pane.querySelector('.ak-toolbar-error button');
    assert.ok(retryBtn, 'retry button rendered on load error');
    retryBtn.click();
    await tick();
    assert.equal(attempts, 2, 'retry button re-fetches toolbar nodes');
    assert.equal(resetBtn.disabled, false, 'reset enabled once nodes load');
    assert.ok(pane.querySelector('.ak-menu-title'), 'toolbar items rendered after retry');
    w.close();
  }
  // Lazy Icon Picker catalog fetch populates icons on demand when iconsUrl is provided.
  {
    const dom = new JSDOM('<div id="adminkit-app"></div>', { url: 'http://localhost/#menu', runScripts: 'outside-only' });
    const w = dom.window;
    const data = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures/boot-main.json'), 'utf8'));
    data.screen = 'menu';
    data.menuManager.menu = [{ slug: 'tools.php', title: 'Tools', children: [] }];
    data.menuManager.icons = { app: '<svg viewBox="0 0 24 24"></svg>' };
    data.menuManager.iconsUrl = 'http://localhost/lucide.json';
    data.menuManager.iconAliases = { rocketship: 'rocket' };
    let fetchedUrl = '';
    w.fetch = url => {
      fetchedUrl = url;
      return Promise.resolve({ ok: true, json: () => Promise.resolve({ rocket: '<path d="M1 1l2 2"/>' }) });
    };
    w.AdminKitData = data;
    w.wp = { apiFetch: () => Promise.resolve({}) };
    for (const file of ['assets/js/settings/navigation-controls.js', 'assets/js/settings/navigation-menu-model.js', 'assets/js/settings/navigation-menu.js', 'assets/js/settings/navigation-toolbar.js', 'assets/js/settings/navigation.js', 'assets/js/settings.js']) w.eval(fs.readFileSync(path.join(root, file), 'utf8'));
    await tick();
    assert.equal(fetchedUrl, '', 'catalog is not fetched before opening the icon picker');
    w.document.querySelector('.ak-menu-icon').click();
    await tick();
    assert.equal(fetchedUrl, 'http://localhost/lucide.json', 'opening picker fetches lucide catalog');
    assert.ok(w.document.querySelector('.ak-icon-pop__sw[title="rocket"]'), 'lazy-loaded icon swatch rendered');
    assert.ok(w.document.querySelector('.ak-icon-pop__sw[title="rocketship"]'), 'alias icon swatch rendered from raw paths');
    w.close();
  }
  // Keyboard reorder preserves focus, and submenu parent <select> moves submenus across parents without drag-and-drop.
  {
    const dom = new JSDOM('<div id="adminkit-app"></div>', { url: 'http://localhost/#menu', runScripts: 'outside-only' });
    const w = dom.window, calls = [];
    const data = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures/boot-main.json'), 'utf8'));
    data.screen = 'menu';
    data.menuManager.menu = [
      { slug: 'tools.php', title: 'Tools', children: [{ slug: 'import.php', title: 'Import' }] },
      { slug: 'options-general.php', title: 'Settings', children: [] }
    ];
    w.AdminKitData = data;
    w.wp = { apiFetch: request => { calls.push(request); return Promise.resolve({ ok: true }); } };
    for (const file of ['assets/js/settings/navigation-controls.js', 'assets/js/settings/navigation-menu-model.js', 'assets/js/settings/navigation-menu.js', 'assets/js/settings/navigation-toolbar.js', 'assets/js/settings/navigation.js', 'assets/js/settings.js']) w.eval(fs.readFileSync(path.join(root, file), 'utf8'));
    await tick();

    // 1. Focus Move Down on Tools, click it, and verify focus stays on Tools' Move Down button in its new row.
    const firstDown = w.document.querySelector('[data-focus-key="top:tools.php:down"]');
    assert.ok(firstDown, 'Move down button carries data-focus-key');
    firstDown.focus();
    firstDown.click();
    assert.equal(w.document.activeElement.getAttribute('data-focus-key'), 'top:tools.php:down', 'Focus stays on moved item Move Down button across re-render');

    // 2. Expand Tools submenu, change parent <select> to Settings (index 0 after swap), and verify reparenting + focus.
    const expandBtn = w.document.querySelector('[data-focus-key="top:tools.php:expand"]');
    assert.equal(expandBtn.textContent.trim(), '1 sous-menu', 'Singular submenu count uses _n() template');
    expandBtn.click();
    const parentSel = w.document.querySelector('[data-focus-key="sub:tools.php:import.php:parent"]');
    assert.ok(parentSel, 'Keyboard parent selector rendered on child row');
    parentSel.focus();
    parentSel.value = '0';
    parentSel.dispatchEvent(new w.Event('change', { bubbles: true }));
    assert.equal(w.document.activeElement.getAttribute('data-focus-key'), 'sub:options-general.php:import.php:parent', 'Focus follows reparented child <select>');

    w.document.querySelector('.ak-savebar button').click();
    await tick();
    assert.equal(calls.length, 1);
    const reparented = calls[0].data.items.find(it => it.slug === 'import.php');
    assert.equal(reparented.parent, 'options-general.php', 'Submenu reparented to Settings via keyboard <select>');
    w.close();
  }
  console.log('OK: actual menu/toolbar titles, icons, lazy picker fetch, keyboard focus/reparenting and error recovery save to their own endpoints.');
})().catch(error => { console.error(error); process.exitCode = 1; });
