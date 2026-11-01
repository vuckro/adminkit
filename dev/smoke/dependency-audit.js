// Destructive fixture checks: run only against a disposable WordPress instance.
module.exports = async function auditTools(page, options) {
  page.setDefaultTimeout(10000);
  page.setDefaultNavigationTimeout(30000);
  const base=options.baseURL.replace(/\/$/, ''), results=[], errors=[];
  const check=(ok,message)=>{if(!ok)throw new Error(message);};
  const go=async path=>{await page.goto(base+path,{waitUntil:'domcontentloaded'});};
  await go('/wp-admin/');
  if(await page.locator('#user_login').count()){
    await page.fill('#user_login',options.username);await page.fill('#user_pass',options.password);
    await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.click('#wp-submit')]);
  }
  await go('/wp-admin/admin.php?page=adminkit-configuration');
  const api=(path,data)=>page.evaluate(async ({path,data})=>window.wp.apiFetch({path,method:data?'POST':'GET',data}),{path,data});
  let target;
  const users=await api('/wp/v2/users?context=edit');
  target=users.find(u=>u.slug==='audit_subject'||u.email==='audit@example.invalid');
  if(!target)target=await api('/wp/v2/users',{username:'audit_subject',email:'audit@example.invalid',password:'DisposableAudit!4268',roles:['subscriber']});
  const keys=['menu_manager_enabled','toolbar_manager_enabled','notification_center_enabled','quick_edit_users_enabled','username_changer_enabled','post_previews_enabled','active_items_first_enabled','clean_chrome_enabled','module_login_enabled','theme_toggle_enabled'];
  const set=async (mode,key,extra={})=>{
    await go('/wp-admin/admin.php?page=adminkit-configuration');
    const values=Object.fromEntries(keys.map(k=>[k,false]));
    Object.assign(values,{admin_theme_enabled:true,interface_default:mode},key?{[key]:true}:{},extra);
    await api('/adminkit/v1/settings',{values});
  };
  const run=async(name,fn)=>{try{await fn();results.push(name);}catch(e){errors.push({name,error:e.message.slice(0,700)});}};
  for(const mode of ['wordpress','adminkit']){
    await run(mode+': notifications alone',async()=>{
      await set(mode,'notification_center_enabled');await go('/wp-admin/');
      const box=await page.locator('#wp-admin-bar-ak-notifications svg').boundingBox();check(box&&box.width>0&&box.height>0,'Bell invisible');
      await page.evaluate(()=>{const n=document.createElement('div');n.id='audit-warning';n.className='notice notice-warning';n.textContent='Isolated audit';document.querySelector('#wpbody-content .wrap').appendChild(n);});
      await page.locator('.ak-nc-list #audit-warning').waitFor({state:'attached'});
      await page.click('#wp-admin-bar-ak-notifications > a');await page.locator('.ak-nc-drawer.is-open').waitFor();await page.keyboard.press('Escape');
    });
    await run(mode+': quick edit alone saves and rejects invalid data',async()=>{
      await set(mode,'quick_edit_users_enabled');await go('/wp-admin/users.php');
      const row=page.locator('#user-'+target.id);await row.hover();await row.locator('.adminkit-qe-open').click();
      await page.fill('.adminkit-qe-first-name','Audited '+mode);
      await page.click('.adminkit-qe-save');
      await page.waitForFunction(({id,mode})=>document.querySelector('#user-'+id+' .column-name').textContent.includes('Audited '+mode),{id:target.id,mode});
      await row.hover();
      const rejection=await row.locator('.adminkit-qe-open').evaluate(async(btn)=>{
        const f=new FormData();f.set('action','adminkit_user_quick_edit');f.set('user_id',btn.dataset.userId);f.set('_ajax_nonce',btn.dataset.nonce);f.set('user_email','invalid');
        const response=await fetch('admin-ajax.php',{method:'POST',body:f});return {status:response.status,body:await response.json()};
      });
      check(rejection.status===400&&!rejection.body.success,'Invalid email accepted');
    });
    await run(mode+': username changer alone saves a target account',async()=>{
      await set(mode,'username_changer_enabled');await go('/wp-admin/user-edit.php?user_id='+target.id);
      if(mode==='adminkit')await page.getByRole('tab',{name:/Information|Identity/i}).first().click();
      await page.click('.adminkit-uc-trigger');await page.click('.adminkit-uc-confirm-accept');
      await page.fill('#user_login','audit_'+mode);
      await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.click('#submit')]);
      check(await page.locator('#user_login').inputValue()==='audit_'+mode,'Username was not persisted');
    });
    await run(mode+': previews alone render with usable dimensions',async()=>{
      await set(mode,'post_previews_enabled');await go('/wp-admin/edit.php');
      check(await page.locator('th.column-ak_preview').count()>0,'Preview column absent');
      const preview=page.locator('.ak-preview').first();const box=await preview.boundingBox();check(box&&box.width>0&&box.height>0,'Preview invisible');
      await page.setViewportSize({width:390,height:844});check(!await preview.isVisible(),'Preview breaks native mobile table');await page.setViewportSize({width:1280,height:900});
    });
    await run(mode+': active items first alone respects chosen filters',async()=>{
      await set(mode,'active_items_first_enabled',{active_items_first_content_default:true,active_items_first_sort:true});await go('/wp-admin/edit.php');
      check(new URL(page.url()).searchParams.get('post_status')==='publish','Default Published redirect missing');
      await go('/wp-admin/edit.php?post_status=draft');check(new URL(page.url()).searchParams.get('post_status')==='draft','Explicit Draft filter overwritten');
    });
    await run(mode+': clean interface alone hides only selected elements',async()=>{
      await set(mode,'clean_chrome_enabled',{clean_chrome_hide_footer:true,clean_chrome_hide_help:false});await go('/wp-admin/');
      check(!await page.locator('#wpfooter').isVisible(),'Footer hiding lost');check(await page.locator('#contextual-help-link-wrap').isVisible(),'Help hidden despite saved choice');
    });
    await run(mode+': menu manager alone persists labels and icons',async()=>{
      await set(mode,'menu_manager_enabled');await go('/wp-admin/admin.php?page=adminkit-navigation');
      await api('/adminkit/v1/menu',{items:[{slug:'edit.php',parent:'',position:0,hidden:true},{slug:'tools.php',parent:'',position:1,title:'Audited tools',icon:'bell',hidden:false}]});
      await go('/wp-admin/tools.php');check((await page.locator('#menu-tools').innerText()).includes('Audited tools'),'Menu label lost');
      check(await page.locator('#adminkit-menu-manager').count()===1,'Custom icon CSS missing');
      check(!await page.locator('#menu-posts').isVisible(),'Hidden menu entry remains visible');
      const positions=await page.locator('#adminmenu > li').evaluateAll(rows=>rows.filter(n=>getComputedStyle(n).display!=='none').map(n=>n.id));
      check(positions.indexOf('menu-tools')<positions.indexOf('menu-dashboard'),'Saved menu order not applied');
      check(await page.locator('#menu-tools .wp-menu-image').evaluate(n=>getComputedStyle(n,'::before').maskImage!=='none'),'Menu icon mask missing');
      await go('/wp-admin/admin.php?page=adminkit-navigation');await api('/adminkit/v1/menu',{items:[]});
    });
    await run(mode+': toolbar manager alone persists customizations',async()=>{
      await set(mode,'toolbar_manager_enabled');await go('/wp-admin/admin.php?page=adminkit-navigation');
      await api('/adminkit/v1/toolbar',{order:[],hidden:['comments'],titles:{'new-content':'Audited create'},icons:{'new-content':'bell'}});
      await go('/wp-admin/');check(await page.locator('#wp-admin-bar-comments').count()===0,'Hidden toolbar entry persisted');
      check((await page.locator('#wp-admin-bar-new-content').innerText()).includes('Audited create'),'Toolbar title not saved');
      await go('/wp-admin/admin.php?page=adminkit-navigation');await api('/adminkit/v1/toolbar',{order:[],hidden:[],titles:{},icons:{}});
    });
  }
  return {results,errors,targetId:target.id};
};
