'use strict';
const assert=require('node:assert/strict');
const {chromium}=require('playwright');
const {start}=require('./hardening-accounts-server.cjs');
(async()=>{
  const env=await start(),{data,fixture}=env;
  let browser;
  try{browser=await chromium.launch({headless:true,args:['--no-sandbox']});}
  catch(error){await env.close();throw error;}
  const ownerContext=await browser.newContext({viewport:{width:1440,height:1000}});
  const candidateContext=await browser.newContext({viewport:{width:390,height:844}});
  const secondContext=await browser.newContext();
  const owner=await ownerContext.newPage(),candidate=await candidateContext.newPage(),second=await secondContext.newPage();
  async function login(page,email,password){
    await page.goto(env.base+'/login.php?return=workspace.php');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(password);
    await Promise.all([page.waitForURL(/workspace\.php/),page.locator('form:has(#email) button[type="submit"]').click()]);
    await page.waitForFunction(()=>!!window.GelatoAccounts);
  }
  async function fillInvite(email){
    await owner.locator('#openCreateUser').click();
    await owner.locator('#account-firstName').fill('Browser');
    await owner.locator('#account-lastName').fill('Employee');
    await owner.locator('#account-displayName').fill('Browser Employee');
    await owner.locator('#account-email').fill(email);
    await owner.locator('#account-position').fill('Pizza cook');
    await owner.locator('#accountRole').selectOption('db-role-'+data.roles.employee);
  }
  try{
    await login(owner,data.users.owner.email,data.password);
    await owner.goto(env.base+'/workspace.php#users');
    await owner.waitForFunction(()=>!!window.GelatoAccounts);
    await owner.evaluate(()=>window.GelatoAccounts.refresh());
    await fillInvite(data.inviteEmail);
    assert.equal(await owner.locator('#userEditor input[name="password"]').count(),0,'Managers must not enter employee passwords');
    await owner.locator('#userEditor button[type="submit"]').click();
    await owner.waitForFunction(()=>!document.getElementById('adminModal').classList.contains('open'));
    let record=fixture('inspect',data.inviteEmail);
    assert.ok(record.user,'UI invitation must persist a real account');
    assert.equal(record.memberships.length,0,'UI invitation cannot pre-grant a restaurant membership');
    assert.equal(record.invitations.at(-1).delivery_status,'sent');
    const id='db-user-'+record.user.id,token=env.token(),password='Browser-Recipient!43';
    await candidate.goto(env.base+'/account-invitation.php?token='+token);
    await candidate.locator('#password').fill(password);
    await candidate.locator('#password_confirm').fill(password);
    await Promise.all([candidate.waitForURL(/workspace\.php/),candidate.locator('button[type="submit"]').click()]);
    assert.ok(await candidate.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+2),'Mobile acceptance must not create horizontal scrolling');
    await login(second,data.inviteEmail,password);
    assert.equal(await second.evaluate(()=>window.RestaurantAuth.current().id),id,'Independent browser must authenticate the invited database user');
    await owner.evaluate(()=>window.GelatoAccounts.refresh());
    await owner.locator('[data-user-edit="'+id+'"]').click();
    await owner.locator('#account-firstName').fill('Edited');
    await owner.locator('#account-displayName').fill('Edited Employee');
    await owner.locator('#account-position').fill('Lead cook');
    await owner.locator('#userEditor button[type="submit"]').click();
    await owner.waitForFunction(()=>!document.getElementById('adminModal').classList.contains('open'));
    await owner.reload();await owner.waitForFunction(()=>!!window.GelatoAccounts);
    await owner.evaluate(()=>window.GelatoAccounts.refresh());
    assert.match(await owner.locator('#userTableBody').innerText(),/Edited Employee/,'Reload must retain server edits');
    record=fixture('inspect',data.inviteEmail);
    assert.equal(record.user.first_name,'Edited');assert.equal(record.memberships[0].jobTitle,'Lead cook');
    await login(second,data.inviteEmail,password);
    owner.on('dialog',dialog=>dialog.accept());
    await owner.locator('[data-user-toggle="'+id+'"]').click();
    await owner.waitForFunction(id=>window.RestaurantAuth.users().find(u=>u.id===id)?.status==='suspended',id);
    await second.goto(env.base+'/workspace.php');
    assert.match(second.url(),/login\.php/,'Suspending through the UI must deny the existing independent session');

    // A server rejection keeps the editor open and never reports or persists success.
    const failedEmail='rejected-'+data.inviteEmail;
    await owner.route('**/api/users.php',async route=>{
      const request=route.request();
      if(request.method()==='POST'&&request.postDataJSON()?.email===failedEmail)
        return route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({ok:false,message:'Test server unavailable; account was not saved.'})});
      await route.continue();
    });
    await fillInvite(failedEmail);
    await owner.locator('#userEditor button[type="submit"]').click();
    await owner.waitForFunction(()=>!document.getElementById('userEditorError').hidden);
    assert.match(await owner.locator('#userEditorError').innerText(),/not saved/);
    assert.ok(await owner.locator('#adminModal').evaluate(el=>el.classList.contains('open')));
    assert.equal(fixture('inspect',failedEmail).user,null);
    for(const page of [owner,candidate]){
      const storage=await page.evaluate(()=>JSON.stringify({local:Object.entries(localStorage),session:Object.entries(sessionStorage)}));
      assert.ok(!storage.includes(password)&&!storage.includes(data.password),'No entered password may be saved in browser storage');
    }
    assert.ok(!/PHP (?:Fatal|Parse) error/.test(env.logs()),env.logs());
    console.log('Account browser journey: PASS (desktop invite/edit/suspend, mobile acceptance, independent sign-in, reload persistence, server rejection and no browser-stored passwords)');
  }finally{await browser.close();await env.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
