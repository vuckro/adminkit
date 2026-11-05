// Boot real navigation controls and save host: every edit must reach its own endpoint.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const root = path.resolve(__dirname, '../..');
const tick = () => new Promise(resolve => setTimeout(resolve, 15));
(async () => {
  // Demotion survives save/reload, flattens children and supports returning to first level.
  {
    const dom = new JSDOM('<div id="adminkit-app"></div>', { url: 'http://localhost/#menu', runScripts: 'outside-only' });
    const w = dom.window;
    const data = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures/boot-main.json'), 'utf8'));
    data.screen = 'menu';
    data.i18n.menuTopLevel = 'Top level';
    data.menuManager.menu = [
      { slug: 'plugins.php', title: 'Plugins', children: [{ slug: 'plugins.php', title: 'Installed plugins' }] },
      { slug: 'weglot', title: 'Weglot', children: [{ slug: 'weglot', title: 'Weglot settings' }, { slug: 'weglot-languages', title: 'Languages' }] },
      { slug: data.menuManager.self, title: 'AdminKit', children: [] }
    ];
    data.menuManager.config = { top: [], sub: {} };
    w.AdminKitData = data;
    const calls = [];
    w.wp = { apiFetch: request => { calls.push(request); return new Promise(() => {}); } };
    for (const file of ['assets/js/settings/navigation-controls.js', 'assets/js/settings/navigation-menu-model.js', 'assets/js/settings/navigation-menu.js', 'assets/js/settings/navigation-toolbar.js', 'assets/js/settings/navigation.js', 'assets/js/settings.js']) w.eval(fs.readFileSync(path.join(root, file), 'utf8'));
    await tick();
    const rows = [...w.document.querySelectorAll('.ak-menu-row--top')];
    const weglotRow = rows.find(row => row.querySelector('input').value === 'Weglot');
    const select = weglotRow.querySelector('select');
    assert.ok(select, 'Native top-level menus expose a parent selector');
    select.value = '0'; select.dispatchEvent(new w.Event('change'));
    await tick();
    assert.equal([...w.document.querySelectorAll('.ak-menu-row--top input')].some(input => input.value === 'Weglot'), false);
    const childRow = [...w.document.querySelectorAll('.ak-menu-row--child')].find(row => row.querySelector('input').value === 'Weglot');
    assert.ok(childRow, 'Demoted menu renders as a second-level row');
    assert.equal(childRow.querySelector('select').options[0].textContent, 'Top level');
    childRow.querySelector('.ak-menu-url').click();
    w.document.querySelector('.ak-url-pop input').value = 'admin.php?page=weglot&tab=advanced';
    w.document.querySelector('.ak-url-pop button').click();
    w.document.querySelector('.ak-savebar button').click(); await tick();
    const items = calls.find(request => request.method).data.items;
    assert.equal(items.filter(item => item.slug === 'weglot').length, 1, 'Native self-link is not duplicated');
    assert.equal(items.find(item => item.slug === 'weglot').parent, 'plugins.php');
    assert.equal(items.find(item => item.slug === 'weglot-languages').parent, 'plugins.php', 'Children flatten to the same second level');
    const M = w.AdminKitParts.navigationMenuModel;
    assert.equal(items.find(item => item.slug === 'weglot').url, 'admin.php?page=weglot&tab=advanced', 'Submenu URL edited through dialog reaches save request');
    const config = M.configFromItems(items);
    const reloaded = M.buildMenuModel(data.menuManager.menu, config, []);
    assert.equal(reloaded.some(top => top.slug === 'weglot'), false, 'Reload does not resurrect demoted menus');
    const target = reloaded.find(top => top.slug === 'plugins.php');
    assert.equal(target.children.find(child => child.slug === 'weglot').url, 'admin.php?page=weglot&tab=advanced', 'Submenu URL survives snapshot merge and import/export');
    const index = target.children.findIndex(child => child.slug === 'weglot');
    assert.ok(target.children[index].sourceTop, 'Reload preserves the option to return to first level');
    M.promoteChild(reloaded, reloaded.indexOf(target), index);
    const restored = M.buildMenuModel(data.menuManager.menu, M.configFromItems(M.gatherMenu(reloaded, item => item.title)), []);
    assert.ok(restored.some(top => top.slug === 'weglot'), 'Promotion survives save/reload');
    assert.equal(restored.find(top => top.slug === 'plugins.php').children.some(child => child.slug === 'weglot'), false);
    w.close();
  }

  // The same legacy Slim SEO conflict must resolve identically in editor/save/reload.
  {
    const dom = new JSDOM('', { runScripts:'outside-only' });
    const w = dom.window;
    w.eval(fs.readFileSync(path.join(root, 'assets/js/settings/navigation-menu-model.js'), 'utf8'));
    const M = w.AdminKitParts.navigationMenuModel;
    const snapshot = [
      {slug:'settings',title:'Settings',children:[{slug:'slim-seo',title:'Slim SEO'},{slug:'slim-seo',title:'Slim SEO Pro'}]},
      {slug:'plugins',title:'Plugins',children:[]}
    ];
    const legacy = {top:[],sub:{plugins:[{slug:'slim-seo',hidden:true,position:2},{slug:'slim-seo',title:'SEO',hidden:false,position:3}]}};
    const model = M.buildMenuModel(snapshot, legacy, []);
    const rows = model.find(top => top.slug === 'plugins').children;
    assert.equal(rows.length, 1);
    assert.equal(rows[0].title, 'SEO');
    assert.equal(rows[0].hidden, false);
    const items = M.gatherMenu(model, item => item.title);
    assert.equal(items.filter(row => row.slug === 'slim-seo').length, 1);
    const reloaded = M.buildMenuModel(snapshot, M.configFromItems(items), []);
    assert.deepEqual(JSON.parse(JSON.stringify(reloaded)), JSON.parse(JSON.stringify(model)), 'Legacy cleanup must reach a fixed point after save/reload');
    // Explicit cross-parent moves replace an existing same-route target, preserving the moved policy.
    const moves = [{slug:'a',children:[{slug:'same',title:'Moved',hidden:true,url:'tools.php?tab=1'}]},{slug:'b',children:[{slug:'same',title:'Old',hidden:false}]}];
    M.moveChildToTop(moves,0,0,1);
    assert.equal(moves[1].children.length,1);
    assert.equal(moves[1].children[0].title,'Moved');
    assert.equal(moves[1].children[0].hidden,true);
    assert.equal(moves[1].children[0].url,'tools.php?tab=1');
    // Ambiguous shared native routes remain local, independently of config parent order.
    const shared = ['a','b','c'].map(slug => ({slug:slug,title:slug,children:[{slug:'shared',title:slug}]}));
    const cfg = {top:[],sub:{a:[{slug:'shared'}],b:[{slug:'shared'}]}};
    assert.equal(M.buildMenuModel(shared,cfg,[]).find(top => top.slug === 'c').children.length,1);
    w.close();
  }

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
  // Save only changed toolbar contexts; retain unavailable actions and acknowledge posted snapshots.
  for (const screen of ['main','toolbar']) {
    const dom = new JSDOM('<div id="adminkit-app"></div>', {url:'http://localhost/#toolbar',runScripts:'outside-only'});
    const w = dom.window, posts = [];
    const nativeTimer=w.setTimeout.bind(w);w.setTimeout=(fn,delay)=>delay===600?0:nativeTimer(fn,delay);
    const data=JSON.parse(fs.readFileSync(path.join(__dirname,'fixtures/boot-main.json'),'utf8'));data.screen=screen;data.toolbar.enabled=true;w.AdminKitData=data;
    const configs={admin:{order:['offline','site-name'],hidden:['offline'],titles:{offline:'Keep offline'},icons:{offline:'home'}},frontend:{order:['site-name'],hidden:[],titles:{},icons:{}}};
    w.AdminKitParts={design:c=>()=>c.el('section'),features:c=>()=>c.el('section')};
    w.wp={apiFetch:request=>!request.method?Promise.resolve({nodesByContext:{admin:[{id:'site-name',label:'Site',group:'root'}],frontend:[{id:'site-name',label:'Front',group:'root'}]},configByContext:configs}):new Promise(resolve=>posts.push({request,resolve}))};
    for(const file of ['navigation-controls','navigation-menu-model','navigation-menu','navigation-toolbar','navigation','main'])w.eval(fs.readFileSync(path.join(root,'assets/js/settings/'+file+'.js'),'utf8'));
    w.eval(fs.readFileSync(path.join(root,'assets/js/settings.js'),'utf8'));await tick();
    const pane=screen==='main'?w.document.getElementById('ak-navigation-toolbar'):w.document.querySelector('.ak-panel--menu');
    const edit=value=>{const input=pane.querySelector('.ak-menu-title');input.value=value;input.dispatchEvent(new w.Event('input'));};
    const save=()=>w.document.querySelector('.ak-savebar button').click();
    edit('Admin edit');save();await tick();
    assert.deepEqual(Object.keys(posts[0].request.data),['admin'],'Untouched frontend must not be overwritten');
    assert.deepEqual(JSON.parse(JSON.stringify(posts[0].request.data.admin.order)),['offline','site-name']);
    assert.equal(posts[0].request.data.admin.titles.offline,'Keep offline');
    assert.equal(posts[0].request.data.admin.icons.offline,'home');
    pane.querySelectorAll('.ak-menu-toolbar__seg button')[1].click();edit('Front edit');
    posts[0].resolve({configByContext:{admin:posts[0].request.data.admin}});await tick();save();await tick();
    assert.deepEqual(Object.keys(posts[1].request.data),['frontend'],'Acknowledged admin draft must not be sent again after an in-flight frontend edit');
    posts[1].resolve({configByContext:{frontend:posts[1].request.data.frontend}});await tick();
    pane.querySelectorAll('.ak-menu-toolbar__seg button')[0].click();pane.querySelector('.ak-menu-toolbar__spacer').click();save();await tick();
    assert.equal(posts[2].request.data.admin.order.includes('offline'),false,'Explicit context reset must also clear unavailable actions');
    w.close();
  }

  // Invalid imports are rejected atomically and prototype-like keys stay ordinary data.
  {
    const dom=new JSDOM('',{runScripts:'outside-only'}),w=dom.window;
    w.eval(fs.readFileSync(path.join(root,'assets/js/settings/navigation-menu-model.js'),'utf8'));
    const M=w.AdminKitParts.navigationMenuModel;
    for(const row of [{slug:'ok',hidden:'false'},{slug:'ok',parent:[]},{slug:'ok',position:-1},{slug:'https:evil.test',type:'link'},{slug:'ok',url:'//evil.test'}])assert.equal(M.sanitizeImportedItems([{slug:'safe'},row]),null);
    assert.equal(M.isSafeMenuUrl('/'),true);
    const snapshot=[{slug:'live',title:'Live',children:[]},{slug:'separator1',type:'separator',children:[]}];
    const layout=M.buildMenuModel(snapshot,{top:[{slug:'live',type:'item'},{slug:'ak-sep-kept',type:'separator'}],sub:{}},[]);
    assert.deepEqual(JSON.parse(JSON.stringify(layout.map(row=>row.slug))),['live','ak-sep-kept'],'Deleted native separators must not resurrect in the editor');
    assert.equal(M.buildMenuModel(snapshot,{top:[],sub:{}},[]).some(row=>row.slug==='separator1'),true,'Reset retains native separator defaults');
    const cfg=M.configFromItems([{parent:'__proto__',slug:'route'}]);assert.equal(cfg.sub.__proto__[0].slug,'route');assert.equal({}.route,undefined);
    const unavailable=M.preserveUnavailable([{parent:'',slug:'live'}],[{slug:'live',children:[]}],{top:[{slug:'inactive',type:'item',title:'Kept'}],sub:{live:[{slug:'inactive-child',title:'Child'}]}});
    assert.equal(unavailable.length,3,'Saving visible rows must retain settings for deactivated plugin routes');w.close();
  }

  // File read failures and invalid/racing imports leave the actual editor draft intact.
  {
    const dom=new JSDOM('<div id="adminkit-app"></div>',{url:'http://localhost/#menu',runScripts:'outside-only'}),w=dom.window;
    let reader;w.FileReader=class{constructor(){reader=this;}readAsText(){}};
    const data=JSON.parse(fs.readFileSync(path.join(__dirname,'fixtures/boot-main.json'),'utf8'));data.screen='menu';data.menuManager.menu=[{slug:'live',title:'Live',children:[]}];data.menuManager.config={top:[],sub:{}};w.AdminKitData=data;
    w.wp={apiFetch:()=>new Promise(()=>{})};
    for(const file of ['navigation-controls','navigation-menu-model','navigation-menu','navigation-toolbar','navigation'])w.eval(fs.readFileSync(path.join(root,'assets/js/settings/'+file+'.js'),'utf8'));
    w.eval(fs.readFileSync(path.join(root,'assets/js/settings.js'),'utf8'));await tick();
    const input=w.document.querySelector('input[type="file"]');Object.defineProperty(input,'files',{value:[{size:100}],configurable:true});
    input.dispatchEvent(new w.Event('change'));reader.result=JSON.stringify({_adminkit:'menu',items:[{slug:'live',hidden:'false'}]});reader.onload();
    assert.equal(w.document.querySelector('.ak-menu-title').value,'Live','Invalid import partially replaced the draft');
    input.dispatchEvent(new w.Event('change'));const title=w.document.querySelector('.ak-menu-title');title.value='New live edit';title.dispatchEvent(new w.Event('input'));
    reader.result=JSON.stringify({_adminkit:'menu',items:[]});reader.onload();assert.equal(w.document.querySelector('.ak-menu-title').value,'New live edit','Async import overwrote a newer edit');
    const M=w.AdminKitParts.navigationMenuModel,tree=[{slug:'a',type:'item',children:[]}],before=JSON.stringify(tree);
    M.moveTop(tree,9,0);M.moveChild(tree,9,0,0,0);M.moveChildToTop(tree,0,9,0);M.promoteChild(tree,9,0);
    assert.equal(JSON.stringify(tree),before,'Stale drag indices corrupted the model');w.close();
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
