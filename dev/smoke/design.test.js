// Real main/design modules: source parity, immutable scales and import/export contracts.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const root = path.resolve(__dirname, '../..');
const base = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures/boot-main.json')));
function boot(source, native = false, overrides = {}) {
 const dom = new JSDOM('<div id="adminkit-app"></div>', { url:'http://localhost/#brand',runScripts:'outside-only',pretendToBeVisual:true });
 const w=dom.window;
 const data=JSON.parse(JSON.stringify(base));
 if(native){data.interfaceMode='wordpress';data.interfaceDefault='adminkit';data.profileUrl='/wp-admin/profile.php';}
 data.accentSource=source; data.brandAccent='#123456';
 data.values={ logo_url:'https://example.test/logo.svg',integration_hidden_enabled:false,extension_key:'keep' };
 data.designSystem={ type_scale:1.13,radius_scale:1.37,colors:{light:{bg:'#abcdef',accent:'#ffffff'},dark:{bg:'#112233'}} };
 if(source==='bricks'){w.document.documentElement.style.setProperty('--accent','#224466');w.document.documentElement.style.setProperty('--accent-hover','#335577');}
 Object.assign(data,overrides);
 w.AdminKitData=data; w.matchMedia=()=>({matches:false,addEventListener(){}});
 w.HTMLCanvasElement.prototype.getContext=()=>({ fillRect(){},getImageData(){return {data:[0,0,0,255]};} });
 const calls=[]; let reader, exported;
 w.wp={apiFetch:request=>{calls.push(request);return new Promise(()=>{});}};
 w.FileReader=class{constructor(){reader=this;}readAsText(){}};
 w.Blob=class{constructor(parts){exported=JSON.parse(parts.join(''));}};
 w.URL.createObjectURL=()=> 'blob:fixture';w.URL.revokeObjectURL=()=>{};
 w.HTMLAnchorElement.prototype.click=()=>{};
 for(const name of ['design-colors','design','features','plugins','main']) w.eval(fs.readFileSync(path.join(root,'assets/js/settings/'+name+'.js'),'utf8'));
 const original=w.AdminKitScreens.main;
 w.AdminKitScreens.main=context=>{w.auditState=context.state;w.auditSurface=original(context);return w.auditSurface;};
 w.eval(fs.readFileSync(path.join(root,'assets/js/settings.js'),'utf8'));
 return {dom,w,calls,read(text){reader.result=text;reader.onload();},get exported(){return exported;}};
}
(async()=>{
for(const source of ['monochrome','adminkit','bricks','custom']) {
 const t=boot(source); const w=t.w, state=w.auditState;
 await new Promise(resolve=>w.requestAnimationFrame(resolve));
 assert.equal(state.designSystem.type_scale,1.13);assert.equal(state.designSystem.radius_scale,1.37);
 const frames=[...w.document.querySelectorAll('.ak-palette-theme.ak-bp__frame')];
 assert.ok(frames.length>=2);
 for(const frame of frames) {
  assert.equal(!!frame.style.getPropertyValue('--wk-bg'),source==='custom','inactive custom colors leaked for '+source);
 }
 if(source==='bricks'){assert.equal(frames[0].style.getPropertyValue('--wk-accent'),'#224466');assert.equal(frames[0].style.getPropertyValue('--wk-accent-hover'),'#335577');}
 if(source==='custom') {
  assert.equal(frames[0].style.getPropertyValue('--wk-on-accent'),'#000000','per-mode accent determines automatic foreground');
  assert.equal(frames[0].style.getPropertyValue('--wk-accent-hover'),'#ffffff','derived hover follows the active palette foreground');
  assert.equal(frames[1].style.getPropertyValue('--wk-accent-hover'),'#0f2b47','dark custom hover matches server compiler');
  const hex=w.document.querySelector('.ak-accent-inline__hex');
  hex.value='#154';hex.dispatchEvent(new w.Event('input'));
  assert.equal(state.brandAccent,'#123456','typing 3-digit prefix must not prematurely overwrite accent');
  assert.equal(state.designSystem.colors.light.accent,'#ffffff','typing 3-digit prefix must not erase custom palette overrides');
  hex.value='#abc';hex.dispatchEvent(new w.Event('change'));
  assert.equal(state.brandAccent,'#aabbcc','3-digit hex expands to 6-digit on change');
  hex.value='#123456';hex.dispatchEvent(new w.Event('input'));
  hex.value='#oops';hex.dispatchEvent(new w.Event('input'));
  assert.equal(state.brandAccent,'#123456','invalid text changed last valid accent');
  w.document.querySelector('.ak-accent-inline [role=radio]').click();
  assert.equal(state.brandAccent,'#123456','source change erased saved accent');
  assert.equal(hex.getAttribute('aria-invalid'),null);
 }
 w.auditSurface.exportSettings();
 assert.equal(t.exported._adminkit,'settings');
 assert.equal(t.exported.values.logo_url,'https://example.test/logo.svg');
 assert.equal(t.exported.values.integration_hidden_enabled,false);
 assert.equal(t.exported.values.extension_key,'keep');
 assert.equal(t.exported.values.design_system.type_scale,1.13);
 assert.equal(w.auditSurface.requests().settings.data.values.logo_url,undefined,'normal save must not overwrite General Settings logo');
 t.dom.window.close();
}
const statusCase=boot('monochrome',true,{
 interfacePreference:'wordpress',adminThemeEnabled:true,customizationAvailable:true,notificationLauncherHidden:true,
 features:[
  {key:'admin_theme_enabled',label:'Administration theme',group:'Appearance',value:true,default:true,bulk:false},
  {key:'module_login_enabled',label:'Login',group:'Appearance',value:false,default:false,bulk:false},
  {key:'toolbar_manager_enabled',label:'Toolbar',group:'Navigation',value:true,default:true},
  {key:'notification_center_enabled',label:'Notifications',group:'Interface',value:true,default:true}
 ],i18n:Object.assign({},base.i18n,{featureNotApplied:'Not applied to your account',featurePaused:'Paused',featureClassicAccount:'Your account uses WordPress classic',featureLauncherHidden:'Bell hidden',featuresFilterPaused:'Paused',featuresFilterNotApplied:'Not applied',featuresFilterUnavailable:'Unavailable'})
});
await new Promise(resolve=>statusCase.w.requestAnimationFrame(resolve));
statusCase.w.document.getElementById('ak-tab-features').click();
await new Promise(resolve=>statusCase.w.requestAnimationFrame(resolve));
const sd=statusCase.w.document;
const rows=[...sd.querySelectorAll('.ak-features-item')];
const themeRow=rows.find(n=>n.textContent.includes('Administration theme'));
assert.equal(themeRow.dataset.featureState,'not-applied','Saved-on theme must explain classic personal choice');
assert.equal(themeRow.parentElement.querySelector('input').checked,true,'Not-applied status must preserve saved-on switch');
assert.equal(rows.find(n=>n.textContent.includes('Notifications')).dataset.featureState,'paused','Hidden notification launcher must be reported as paused');
statusCase.w.auditSurface.onSaved('settings',{values:{admin_theme_enabled:false}},{values:{admin_theme_enabled:false}});
assert.equal(themeRow.querySelector('.ak-features-item__status').textContent,base.i18n.featureActivationPending,'Enabling a non-applied theme must announce activation pending');
const filter=sd.querySelector('.ak-features-status');filter.value='paused';filter.dispatchEvent(new statusCase.w.Event('change'));
assert.equal(rows.filter(n=>!n.closest('[hidden]')).length,1,'Paused filter disagrees with badge resolver');
filter.value='all';filter.dispatchEvent(new statusCase.w.Event('change'));
sd.querySelectorAll('.ak-features-bulk button')[1].click();
assert.equal(statusCase.w.auditState.features.admin_theme_enabled,true,'Bulk tools action disabled the theme');
assert.equal(statusCase.w.auditState.features.notification_center_enabled,false,'Bulk tools action missed a tool');
statusCase.dom.window.close();
const native=boot('monochrome',true);
await new Promise(resolve=>native.w.requestAnimationFrame(resolve));
assert.equal(native.w.document.documentElement.style.cssText,'','native design editor must not modify global appearance');
assert.equal(native.w.auditSurface.requests().settings.data.values.interface_default,'adminkit','site default missing from save payload');
native.w.document.getElementById('ak-tab-configuration').click();
await new Promise(resolve=>native.w.requestAnimationFrame(resolve));
const selector=native.w.document.getElementById('ak-interface-default');
selector.value='wordpress';selector.dispatchEvent(new native.w.Event('change'));
assert.equal(native.w.auditSurface.requests().settings.data.values.interface_default,'wordpress','site default edit not included in save');
assert.equal(native.w.document.documentElement.style.cssText,'','site default draft must not switch the current interface');
native.dom.window.close();
const t=boot('monochrome');
for(const payload of [{values:{}},{_adminkit:'menu',values:{}},{_adminkit:'settings',values:[]},[]]) {
 t.w.auditSurface.importSettings({});t.read(JSON.stringify(payload));assert.equal(t.calls.length,0,'invalid export marker/container submitted');
}
t.w.auditSurface.importSettings({});t.read(JSON.stringify({_adminkit:'settings',values:{obsolete_key:true,unknown_key:'ok'}}));
assert.equal(t.calls.length,1,'unknown legacy keys should be sent for schema filtering');
t.dom.window.close();
console.log('OK: preset/custom parity, preserved scales/accent, complete export, marker validation and unknown import keys.');

})().catch(error=>{console.error(error);process.exitCode=1;});
