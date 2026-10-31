/* Isolated design study. No WordPress API calls or persistent storage. */
(async function () {
 'use strict';
 const [features, icons] = await Promise.all(['../features/catalog.json','../features/icons.json'].map(async path => { const r=await fetch(path); if(!r.ok) throw new Error('Chargement impossible'); return r.json(); }));
 const summaries = ['Un sélecteur clair / sombre dans la barre d’outils.','Une connexion assortie à votre administration.','Un menu latéral adapté à votre façon de travailler.','Une barre d’outils réorganisée et allégée.','Une administration sans aide contextuelle ni pied de page.','Les notifications réunies dans un tiroir.','Les contenus publiés et extensions actives en premier.','Des miniatures dans les listes d’articles et de pages.','Les informations du compte modifiables dans la liste.','Un identifiant de connexion modifiable par un administrateur.'];
 const iconNames=['moon','log-in','panel-left','panel-top','scan','bell','list-filter','image','user-pen','user-round-cog'];
 const groupNames=[...new Set(features.map(f=>f.group))];
 const groupIcons=['palette','file-text','users'];
 const groupSummaries=['Personnalisez l’environnement de travail.','Repérez les informations utiles au quotidien.','Simplifiez la gestion des comptes.'];
 features.forEach((f,i)=>{f.summary=summaries[i];f.icon=iconNames[i];f.enabled=true;f.saved=true;});
 let view=['grouped','focused','inline'].includes(location.hash.slice(1)) ? location.hash.slice(1) : 'grouped';
 let category='all', selected=features[0].key;
 const $=s=>document.querySelector(s);
 function node(tag,attrs={},children=[]){const n=document.createElement(tag);Object.entries(attrs).forEach(([k,v])=>{if(k==='text')n.textContent=v;else if(k.startsWith('on'))n.addEventListener(k.slice(2),v);else n.setAttribute(k,v);});children.filter(Boolean).forEach(c=>n.append(c));return n;}
 function icon(name){const n=document.createElementNS('http://www.w3.org/2000/svg','svg');n.setAttribute('viewBox','0 0 24 24');n.setAttribute('aria-hidden','true');n.innerHTML=icons[name]||icons.plug;return n;}
 function glyph(f){return node('span',{class:'feature-icon'},[icon(f.icon)]);}
 function toggle(f){return node('button',{class:'toggle',type:'button',role:'switch','aria-checked':String(f.enabled),'aria-label':f.label,'data-focus':'toggle-'+f.key,onclick:()=>{f.enabled=!f.enabled;render();}});}
 function matches(f){const q=$('#search').value.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();const hay=(f.label+' '+f.desc+' '+f.group).normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();return hay.includes(q)&&($('#status').value==='all'||($('#status').value==='active')===f.enabled);}
 function changed(){return features.some(f=>f.enabled!==f.saved);}
 function saveState(){const dirty=changed();$('#save').disabled=!dirty;$('#save-status').textContent=dirty?'Modifications simulées non enregistrées':'Aucune modification';}
 function detail(f){const pane=node('section',{class:'detail-pane','aria-label':'Détail de '+f.label},[glyph(f),node('h2',{text:f.label,tabindex:'-1'}),node('p',{class:'category-label',text:f.group}),node('h3',{text:'Ce que fait cette fonctionnalité'}),node('p',{text:f.desc})]);if(f.key==='username_changer_enabled')pane.append(node('p',{text:'Attention : le changement d’identifiant déconnecte le compte concerné. Cette fonction est réservée aux sites simples.'}));pane.append(node('div',{class:'detail-state'},[node('div',{},[node('strong',{text:f.enabled?'Fonctionnalité active':'Fonctionnalité inactive'}),node('small',{text:'État simulé dans cette prévisualisation.'})]),toggle(f)]));return pane;}
 function revealDetail(){if(matchMedia('(max-width:720px)').matches){const pane=$('.detail-pane');if(pane){pane.scrollIntoView({block:'start'});pane.querySelector('h2').focus({preventScroll:true});}}}
 function row(f,compact=false){const main=node('div',{class:'row-main'},[node('h3',{text:f.label}),node('p',{text:f.summary})]);const r=node('article',{class:'row'},[glyph(f),main]);if(compact){r.append(node('button',{class:'more',text:'Détails','aria-label':'Détails de '+f.label,onclick:()=>{selected=f.key;setView('detail');revealDetail();}}));}else{main.append(node('details',{'data-effect':f.key},[node('summary',{text:'Comprendre l’effet'}),node('p',{text:f.desc})]));}r.append(toggle(f));return r;}
 function selectFeature(f){selected=f.key;render();if(view!=='inline')revealDetail();}
 function pickList(fs){const list=node('div',{class:'grouped-list'});groupNames.forEach(g=>{const items=fs.filter(f=>f.group===g);if(!items.length)return;const ul=node('ul',{class:'feature-list','aria-label':g});items.forEach(f=>ul.append(node('li',{},[node('button',{'aria-pressed':String(f.key===selected),'data-focus':'select-'+f.key,onclick:()=>selectFeature(f)},[icon(f.icon),node('span',{class:'list-title'},[node('strong',{text:f.label}),node('small',{text:f.enabled?'Active':'Inactive'})]),icon('chevron-right')])])));list.append(node('section',{class:'pick-group'},[node('h2',{text:g}),ul]));});return list;}
 function render(){
 const focusKey=document.activeElement&&document.activeElement.getAttribute('data-focus');
 document.querySelectorAll('[data-view]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.view===view)));
 $('#direction').textContent={grouped:'C1 · Toutes les fonctionnalités restent visibles, classées par famille. Sélectionnez un outil pour lire son effet dans le panneau voisin.',focused:'C2 · Choisissez d’abord une catégorie, puis une fonctionnalité. Une vue ciblée pour faire grandir le catalogue sans allonger la page.',inline:'C3 · Ouvrez une fonctionnalité pour lire et agir au même endroit. Une seule explication ouverte, sans panneau séparé.'}[view];
 const host=$('#content');host.replaceChildren();const filtered=features.filter(matches);$('#empty').hidden=filtered.length>0;
 if(view==='focused'){
 const nav=node('nav',{class:'category-strip','aria-label':'Catégories de fonctionnalités'});
 ['all',...groupNames].forEach((g,i)=>nav.append(node('button',{'aria-pressed':String(category===g),'data-focus':'category-'+g,onclick:()=>{category=g;render();}},[icon(i===0?'layout-list':groupIcons[i-1]),node('span',{text:g==='all'?'Toutes':g}),node('span',{class:'count',text:String(filtered.filter(f=>g==='all'||f.group===g).length)})])));host.append(nav);
 }
 const visible=filtered.filter(f=>view!=='focused'||category==='all'||f.group===category);
 if(!visible.length&&filtered.length)host.append(node('p',{class:'notice',role:'status',text:'Aucun résultat dans cette catégorie. Choisissez Toutes ou une autre catégorie.'}));
 if(visible.length){if((selected||view!=='inline')&&!visible.some(f=>f.key===selected))selected=visible[0].key;
 if(view==='inline'){
 const stack=node('div',{class:'inline-list'});
 groupNames.forEach(g=>{const fs=visible.filter(f=>f.group===g);if(!fs.length)return;const section=node('section',{class:'inline-group'},[node('h2',{text:g})]);fs.forEach(f=>{
 const open=f.key===selected;const trigger=node('button',{class:'inline-trigger','aria-expanded':String(open),'aria-controls':'effect-'+f.key,'data-focus':'select-'+f.key,onclick:()=>{selected=open?'':f.key;render();}},[icon(f.icon),node('span',{class:'list-title'},[node('strong',{text:f.label}),node('small',{text:f.summary})]),node('span',{class:'inline-state',text:f.enabled?'Active':'Inactive'}),icon('chevron-right')]);
 const article=node('article',{class:'inline-item'+(open?' is-selected':'')},[trigger]);if(open){const pane=detail(f);pane.id='effect-'+f.key;article.append(pane);}section.append(article);
 });stack.append(section);});host.append(stack);
 }else{
 const pane=detail(features.find(f=>f.key===selected));const back=node('button',{class:'back-to-list',text:'Retour aux fonctionnalités',onclick:()=>{const list=$('.grouped-list');list.scrollIntoView({block:'start'});const target=[...list.querySelectorAll('[data-focus]')].find(n=>n.dataset.focus==='select-'+selected);if(target)target.focus({preventScroll:true});}});pane.prepend(back);
 host.append(node('div',{class:'detail-layout '+(view==='focused'?'focused-layout':'grouped-layout')},[pickList(visible),pane]));
 }}
 if(focusKey){const target=[...document.querySelectorAll('[data-focus]')].find(n=>n.dataset.focus===focusKey);if(target)target.focus({preventScroll:true});else $('#status').focus({preventScroll:true});}saveState();
 }
 function setView(v){view=v;history.replaceState(null,'','#'+v);render();}
 document.querySelectorAll('[data-view]').forEach(b=>b.addEventListener('click',()=>setView(b.dataset.view)));
 $('#search').addEventListener('input',render);$('#status').addEventListener('change',render);
 document.querySelectorAll('[data-bulk]').forEach(b=>b.addEventListener('click',()=>{features.forEach(f=>f.enabled=b.dataset.bulk==='on');$('.bulk').open=false;render();}));
 $('#save').addEventListener('click',()=>{features.forEach(f=>f.saved=f.enabled);saveState();$('#save-status').textContent='Enregistré dans la démo uniquement';});
 $('#reset').addEventListener('click',()=>{features.forEach(f=>{f.saved=true;f.enabled=true;});$('#search').value='';$('#status').value='all';category='all';render();});
 function theme(){const dark=document.documentElement.dataset.adminkitTheme==='dark';$('#theme').replaceChildren(icon(dark?'sun':'moon'));$('#theme').setAttribute('aria-label',dark?'Passer au thème clair':'Passer au thème sombre');}
 $('#theme').addEventListener('click',()=>{document.documentElement.dataset.adminkitTheme=document.documentElement.dataset.adminkitTheme==='dark'?'light':'dark';theme();});theme();$('#search-icon').append(icon('search'));document.querySelectorAll('[data-icon]').forEach(n=>n.prepend(icon(n.dataset.icon)));render();
})().catch(()=>{document.querySelector('#content').textContent='La prévisualisation n’a pas pu charger. Rechargez cette page depuis le site local.';});
