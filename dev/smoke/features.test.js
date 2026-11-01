// Real feature factory: discovery must never mutate drafts, dependencies or bulk policy.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const root = path.resolve(__dirname, '../..');
const dom = new JSDOM('<!doctype html><body></body>', { runScripts: 'outside-only', pretendToBeVisual: true });
const w = dom.window, d = w.document;
let mobile = false, dirty = 0, scrolls = 0;
w.matchMedia = () => ({ matches: mobile });
w.HTMLElement.prototype.scrollIntoView = () => scrolls++;
const I = { features:'Features',featuresIntro:'Tools',searchFeatures:'Search',filterFeatures:'Status',featuresFilterAll:'All',featuresFilterActive:'Active',featuresFilterInactive:'Inactive',featureBulk:'Bulk',featureBulkScope:'All available tools',enableAll:'Enable all',disableAll:'Disable all',resetDefaults:'Defaults',featureCategories:'Categories',featureChoose:'Choose',featureBack:'Back',featureEffect:'Effect',featureAllCategories:'All categories',featureActive:'Active',featureInactive:'Inactive',featureUnavailable:'Unavailable',featureParentOff:'Enable parent',featureSaveHint:'Save to apply',noResults:'No results',featureCategoryEmpty:'Empty category',featureOptions:'Options',featureOptionsHint:'Choices retained while off' };
const extraI = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures/boot-main.json'), 'utf8')).i18n; Object.keys(extraI).filter(k=>I[k]===undefined).forEach(k=>I[k]=extraI[k]);
const features = [
 {key:'parent',label:'Appearance',desc:'Parent effect',group:'Interface',value:true,default:true,options:[{key:'footer_option',label:'Hide footer',desc:'Footer description',value:true,default:true},{key:'help_option',label:'Hide help',desc:'Help description',value:false,default:true}]},
 {key:'child',label:'Child',desc:'Child effect',group:'Interface',parent:'parent',value:true,default:true},
 {key:'unavailable',label:'Unavailable host',desc:'Needs host',group:'Users',available:false,unavailableHint:'Install host',value:true,default:true},
 {key:'guarded',label:'Identifiant',desc:'Renommer le compte',group:'Users',bulk:false,value:false,default:false}
];
const state = { features:Object.fromEntries(features.flatMap(f=>[[f.key,f.value],...(f.options||[]).map(o=>[o.key,o.value])])) };
function el(tag,attrs={},kids=[]) { const n=d.createElement(tag);Object.entries(attrs).forEach(([k,v])=>{if(v==null)return;if(k==='text')n.textContent=v;else if(k.startsWith('on'))n.addEventListener(k.slice(2),v);else n.setAttribute(k,v);});kids.filter(Boolean).forEach(k=>n.append(k));return n; }
w.eval(fs.readFileSync(path.join(root,'assets/js/settings/features.js'),'utf8'));
const build = w.AdminKitParts.features({D:{features},I,state,el,intro:text=>el('p',{text}),markDirty:()=>dirty++});
d.body.append(build());
const visible = selector => [...d.querySelectorAll(selector)].filter(n=>!n.closest('[hidden]'));
const select = label => [...d.querySelectorAll('.ak-features-item')].find(n=>n.textContent.includes(label));
const category = label => [...d.querySelectorAll('.ak-features-categories button')].find(n=>n.textContent.includes(label));
const input = index => d.querySelectorAll('.ak-features-state .ak-switch__input')[index];
const change = n => n.dispatchEvent(new w.Event('change',{bubbles:true}));
assert.equal(dirty,0,'mount never changes draft');
const sub = d.querySelectorAll('.ak-feature-options input');
assert.equal(sub[0].checked,true);assert.equal(sub[1].checked,false);
sub[0].checked=false;change(sub[0]);assert.equal(state.features.footer_option,false,'nested edit reaches shared draft');
assert.equal(d.querySelector('.ak-features-count').textContent,'4','nested controls do not inflate feature counts');
category('Users').click(); select('Unavailable host').click();
assert.equal(dirty,1,'navigation never changes draft after the nested edit');
assert.equal(input(2).disabled,true); assert.equal(input(2).checked,false); assert.equal(state.features.unavailable,true,'unavailable value retained');
assert.match(visible('.ak-features-hint')[0].textContent,/Install host/);
category('All categories').click(); select('Appearance').click();input(0).checked=false;change(input(0));
assert.equal(sub[0].matches(':disabled'),true);assert.equal(state.features.footer_option,false);
assert.equal(input(1).disabled,true);assert.equal(state.features.child,true,'parent lock preserves child choice');
select('Child').click();assert.match(visible('.ak-features-hint')[0].textContent,/Enable parent/);
const search=d.querySelector('input[type=search]');search.value='identifiant';search.dispatchEvent(new w.Event('input'));
assert.equal(visible('.ak-features-item').length,1);select('Identifiant').click();assert.equal(visible('.ak-features-effect')[0].textContent,'Renommer le compte');
const bulk = [...d.querySelectorAll('.ak-features-bulk button')];bulk[0].click();
assert.equal(state.features.parent,true,'bulk applies outside visible search');assert.equal(state.features.guarded,false,'bulk:false preserved');assert.equal(state.features.unavailable,true,'unavailable preserved');assert.equal(input(1).disabled,false);
bulk[1].click();assert.equal(state.features.footer_option,false,'bulk off preserves nested choices');bulk[0].click();assert.equal(state.features.footer_option,false,'bulk on preserves nested choices');bulk[2].click();assert.equal(state.features.footer_option,true,'defaults restore nested choices');assert.equal(state.features.help_option,true);assert.equal(state.features.parent,true);assert.equal(state.features.child,true);assert.equal(state.features.guarded,false);
search.value='Footer description';search.dispatchEvent(new w.Event('input'));assert.equal(visible('.ak-features-item').length,1,'search finds parent via nested option');search.value='';search.dispatchEvent(new w.Event('input'));const status=d.querySelector('select');status.value='active';change(status);select('Appearance').click();input(0).focus();input(0).checked=false;change(input(0));
assert.equal(d.activeElement,status,'filtered-out last active control returns focus to status');
search.value='zzzz';search.dispatchEvent(new w.Event('input'));assert.equal(d.querySelector('.ak-empty').hidden,false);assert.equal(d.querySelector('.ak-features-workspace').hidden,true);
search.value='';search.dispatchEvent(new w.Event('input'));status.value='all';change(status);category('Users').click();mobile=true;select('Identifiant').click();assert.equal(d.activeElement.textContent,'Identifiant');assert.equal(d.querySelector('.ak-feature-dialog').hidden,false);visible('.ak-feature-done')[0].click();assert.equal(d.activeElement,select('Identifiant'));
category('All categories').click();select('Appearance').click();
const parentPanel=d.getElementById(select('Appearance').getAttribute('aria-controls'));
assert.equal(parentPanel.querySelector('.ak-feature-pending').hidden,false,'pending master change is explicit');
build.acknowledge({parent:false,footer_option:true,help_option:true});
assert.equal(parentPanel.querySelector('.ak-feature-pending').hidden,true,'successful save establishes a new baseline');
assert.equal(parentPanel.querySelector('.ak-feature-options-note strong').textContent,I.featureOptionsPaused);
assert.match(parentPanel.querySelector('.ak-feature-option__state').textContent,/Choice:/,'paused status describes a preference, not a live effect');
input(0).checked=true;change(input(0));
assert.equal(select('Appearance').querySelector('.ak-features-item__status').textContent,I.featureActivationPending);
sub[0].checked=false;change(sub[0]);
build.acknowledge({parent:true,footer_option:true,help_option:true});
assert.equal(state.features.footer_option,false,'acknowledging an older request never overwrites a newer draft');
assert.equal(parentPanel.querySelector('.ak-feature-pending').hidden,false,'newer edit remains visibly pending');
assert.equal(parentPanel.querySelector('.ak-feature-option__pending').hidden,false);
assert.match(sub[0].getAttribute('aria-describedby'),/-pending/,'pending status is described only while pending');
build.acknowledge({footer_option:false});
assert.equal(parentPanel.querySelector('.ak-feature-pending').hidden,true,'saving the newer edit clears pending');
assert.ok(!sub[0].getAttribute('aria-describedby').includes('-pending'),'saved controls never announce hidden pending text');
// An inactive editor must offer activation instead of linking to its unavailable workspace.
const navigationState = { features: { menu_manager_enabled:false, toolbar_manager_enabled:true } };
const navigationBuild = w.AdminKitParts.features({D:{pages:{navigation:'/wp-admin/admin.php?page=adminkit-navigation'},features:[
 {key:'menu_manager_enabled',label:'Menu manager',value:false,group:'Navigation'},
 {key:'toolbar_manager_enabled',label:'Toolbar manager',value:true,group:'Navigation'}
]},I,state:navigationState,el,intro:text=>el('p',{text}),markDirty:()=>dirty++});
const navigationPanel = navigationBuild();d.body.append(navigationPanel);
navigationPanel.querySelector('.ak-features-item').click();
assert.equal(navigationPanel.querySelector('dialog').hidden,false,'inactive editor opens its activation details even when the other editor is enabled');
assert.equal(navigationPanel.querySelector('.ak-features-state input').checked,false,'opening details preserves the disabled setting');
assert.ok(dirty>0); console.log('OK: real feature discovery preserves drafts, bulk/dependency policy, filters and mobile keyboard focus.');
dom.window.close();
