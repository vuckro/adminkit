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
const I = { features:'Features',featuresIntro:'Tools',searchFeatures:'Search',filterFeatures:'Status',featuresFilterAll:'All',featuresFilterActive:'Active',featuresFilterInactive:'Inactive',featureBulk:'Bulk',featureBulkScope:'All available tools',enableAll:'Enable all',disableAll:'Disable all',resetDefaults:'Defaults',featureCategories:'Categories',featureChoose:'Choose',featureBack:'Back',featureEffect:'Effect',featureAllCategories:'All categories',featureActive:'Active',featureInactive:'Inactive',featureUnavailable:'Unavailable',featureParentOff:'Enable parent',featureSaveHint:'Save to apply',noResults:'No results',featureCategoryEmpty:'Empty category' };
const features = [
 {key:'parent',label:'Appearance',desc:'Parent effect',group:'Interface',value:true,default:true},
 {key:'child',label:'Child',desc:'Child effect',group:'Interface',parent:'parent',value:true,default:true},
 {key:'unavailable',label:'Unavailable host',desc:'Needs host',group:'Users',available:false,unavailableHint:'Install host',value:true,default:true},
 {key:'guarded',label:'Identifiant',desc:'Renommer le compte',group:'Users',bulk:false,value:false,default:false}
];
const state = { features:Object.fromEntries(features.map(f=>[f.key,f.value])) };
function el(tag,attrs={},kids=[]) { const n=d.createElement(tag);Object.entries(attrs).forEach(([k,v])=>{if(v==null)return;if(k==='text')n.textContent=v;else if(k.startsWith('on'))n.addEventListener(k.slice(2),v);else n.setAttribute(k,v);});kids.filter(Boolean).forEach(k=>n.append(k));return n; }
w.eval(fs.readFileSync(path.join(root,'assets/js/settings/features.js'),'utf8'));
d.body.append(w.AdminKitParts.features({D:{features},I,state,el,intro:text=>el('p',{text}),markDirty:()=>dirty++})());
const visible = selector => [...d.querySelectorAll(selector)].filter(n=>!n.closest('[hidden]'));
const select = label => [...d.querySelectorAll('.ak-features-item')].find(n=>n.textContent.includes(label));
const category = label => [...d.querySelectorAll('.ak-features-categories button')].find(n=>n.textContent.includes(label));
const input = index => d.querySelectorAll('.ak-switch__input')[index];
const change = n => n.dispatchEvent(new w.Event('change',{bubbles:true}));
assert.equal(dirty,0,'mount never changes draft');
category('Users').click(); select('Unavailable host').click();
assert.equal(dirty,0,'navigation never changes draft');
assert.equal(input(2).disabled,true); assert.equal(input(2).checked,false); assert.equal(state.features.unavailable,true,'unavailable value retained');
assert.match(visible('.ak-features-hint')[0].textContent,/Install host/);
category('All categories').click(); select('Appearance').click();input(0).checked=false;change(input(0));
assert.equal(input(1).disabled,true);assert.equal(state.features.child,true,'parent lock preserves child choice');
select('Child').click();assert.match(visible('.ak-features-hint')[0].textContent,/Enable parent/);
const search=d.querySelector('input[type=search]');search.value='identifiant';search.dispatchEvent(new w.Event('input'));
assert.equal(visible('.ak-features-item').length,1);assert.equal(visible('.ak-features-effect')[0].textContent,'Renommer le compte');
const bulk = [...d.querySelectorAll('.ak-features-bulk button')];bulk[0].click();
assert.equal(state.features.parent,true,'bulk applies outside visible search');assert.equal(state.features.guarded,false,'bulk:false preserved');assert.equal(state.features.unavailable,true,'unavailable preserved');assert.equal(input(1).disabled,false);
bulk[1].click();bulk[2].click();assert.equal(state.features.parent,true);assert.equal(state.features.child,true);assert.equal(state.features.guarded,false);
search.value='';search.dispatchEvent(new w.Event('input'));const status=d.querySelector('select');status.value='active';change(status);select('Appearance').click();input(0).focus();input(0).checked=false;change(input(0));
assert.equal(d.activeElement,status,'filtered-out last active control returns focus to status');
search.value='zzzz';search.dispatchEvent(new w.Event('input'));assert.equal(d.querySelector('.ak-empty').hidden,false);assert.equal(d.querySelector('.ak-features-workspace').hidden,true);
search.value='';search.dispatchEvent(new w.Event('input'));status.value='all';change(status);category('Users').click();mobile=true;select('Identifiant').click();assert.equal(d.activeElement.textContent,'Identifiant');assert.ok(scrolls>0);visible('.ak-features-back')[0].click();assert.equal(d.activeElement,select('Identifiant'));
assert.ok(dirty>0); console.log('OK: real feature discovery preserves drafts, bulk/dependency policy, filters and mobile keyboard focus.');
dom.window.close();
