// Responsive relocation must preserve native nodes, handlers and desktop ordering.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require('jsdom');
(async()=>{
const dom = new JSDOM('<div id="wpadminbar"><div id="wp-toolbar"><ul id="root"><li id="wp-admin-bar-menu-toggle"><a class="ab-item">Menu</a></li><li id="wp-admin-bar-site-name"><a class="ab-item">Site</a></li><li id="wp-admin-bar-new-content"><a class="ab-item">Create</a><div class="ab-sub-wrapper"><a href="/new">Post</a></div></li><li id="wp-admin-bar-query-monitor"><a class="ab-item">QM</a></li></ul><ul><li id="wp-admin-bar-my-account"><a class="ab-item">Account</a></li></ul></div></div>', {url:'http://localhost', runScripts:'outside-only'});
const w=dom.window,d=w.document;
let resize, clicked=0;
const mq={matches:true,addEventListener(type,fn){resize=fn;}};
w.matchMedia=()=>mq;
w.AdminKitMobileBar={admin:true,more:'More actions',queryMonitor:'Query Monitor'};
const original=[...d.querySelector('#root').children];
const native=original[2];native.querySelector('a').addEventListener('click',()=>clicked++);
w.eval(fs.readFileSync(path.resolve(__dirname,'../../assets/js/wp-core/admin-bar.js'),'utf8'));
assert.equal(d.querySelector('.ak-mobile-bar-list').children.length,2);
assert.equal(d.querySelector('.ak-mobile-bar-list').firstElementChild,native,'move original rather than cloning');
native.querySelector('a').click();assert.equal(clicked,1,'native handler preserved');
const diagnostic=d.querySelector('#wp-admin-bar-query-monitor>.ab-item');
diagnostic.innerHTML='<span>Updated diagnostic timings</span>';
await new Promise(resolve=>setTimeout(resolve,0));
assert.equal(diagnostic.querySelector('.ak-mobile-action-label').textContent,'Query Monitor','diagnostic refresh cannot remove the accessible label');
const toggle=d.querySelector('.ak-mobile-bar-toggle'),panel=d.querySelector('#ak-mobile-bar-panel');
toggle.click();assert.equal(panel.hidden,false);assert.equal(toggle.getAttribute('aria-expanded'),'true');
d.dispatchEvent(new w.KeyboardEvent('keydown',{key:'Escape',bubbles:true}));assert.equal(panel.hidden,true);assert.equal(d.activeElement,toggle);
mq.matches=false;resize();assert.deepEqual([...d.querySelector('#root').children],original,'restore exact native ordering');
assert.equal(d.querySelector('.ak-mobile-bar-tools'),null);assert.equal(native.querySelector('.ak-mobile-action-label'),null);
mq.matches=true;resize();assert.equal(d.querySelectorAll('.ak-mobile-bar-tools').length,1,'repeat resize without duplication');
dom.window.close();console.log('OK: mobile disclosure, native handlers/submenus and exact desktop restoration.');

})().catch(error=>{console.error(error);process.exitCode=1;});
