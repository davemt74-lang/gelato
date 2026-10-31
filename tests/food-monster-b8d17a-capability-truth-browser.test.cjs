'use strict';
const fs = require('node:fs');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const php = fs.readFileSync(path.join(root, 'agent-canvas.php'), 'utf8');
const html = php.slice(php.indexOf('<!doctype html>'));
const script = fs.readFileSync(path.join(root, 'js/agent-canvas.js'), 'utf8');
const dangerous = '<img src=x onerror="window.capabilityXss=1">';

const owner = {
  schemaVersion:'1.1.0', counts:{nativeRegistered:2,delegationRegistered:1,
    promptTemplates:1,plannedNotExecutable:2,sourceCandidatesWithRelatedNativeTool:1,
    sourceCandidatesWithoutRelatedNativeTool:1},
  nativeTools:[{id:'get_current_user',impact:'read',status:'registered_for_current_account'},
    {id:'propose_crm_customer_note',impact:'read',actionMode:'proposal_requires_confirmation'}],
  delegatedAgents:[{id:'operations',label:dangerous,mode:'read_confirmed_write'}],
  chatPrompts:[{id:'brain_brief',label:'Agent Brain priorities'}],
  planned:[{id:'crm.note_add',label:'Add note',feature:'crm',
    relatedNativeTools:['propose_crm_customer_note']},
    {id:'crm.consent',label:dangerous,feature:'crm',relatedNativeTools:[]}],
  inventoryVisibility:'owner_only', inventoryStatus:'source_inventory_only',
  readiness:{provider:'not_tested_by_capability_snapshot',voiceBargeIn:'requires_device_acceptance'},
};
const staff = {...owner, planned:[], inventoryVisibility:'withheld_for_this_account',
  counts:{...owner.counts,plannedNotExecutable:null,
    sourceCandidatesWithRelatedNativeTool:null,sourceCandidatesWithoutRelatedNativeTool:null}};

(async () => {
  const browser = await chromium.launch({headless:true,args:['--no-sandbox']});
  try {
    const page = await browser.newPage({viewport:{width:1300,height:900}});
    let role = 'owner';
    const requests=[];
    await page.route('https://gelato.example/**', async route => {
      const url=new URL(route.request().url());
      requests.push([route.request().method(),url.pathname,url.searchParams.get('action')]);
      if(url.pathname==='/agent-canvas.php')
        return route.fulfill({status:200,contentType:'text/html',body:html});
      if(url.pathname==='/js/agent-canvas.js')
        return route.fulfill({status:200,contentType:'application/javascript',body:script});
      if(url.pathname.startsWith('/js/'))
        return route.fulfill({status:200,contentType:'application/javascript',body:''});
      if(url.pathname==='/api/agent-workspace.php'
         &&url.searchParams.get('action')==='food_monster_capability_truth')
        return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({
          ok:true, capabilities:role==='owner'?owner:staff,
        })});
      return route.fulfill({status:404,body:'Not available in test'});
    });
    await page.goto('https://gelato.example/agent-canvas.php');
    await page.getByRole('button',{name:'Tools & status'}).click();
    await page.getByText('Native registered tools',{exact:false}).waitFor();
    assert.match(await page.locator('#capabilityTruthContent').innerText(),/Source contracts — NOT independently executable/);
    assert.match(await page.locator('#capabilityTruthContent').innerText(),/1 of 2 old discovery contracts/);
    await page.getByText('Native registered tools',{exact:false}).click();
    assert.match(await page.locator('#capabilityTruthContent').innerText(),/separate human confirmation/);
    await page.getByText('Source contracts — NOT independently executable',{exact:false}).click();
    assert.match(await page.locator('#capabilityTruthContent').innerText(),/Related registered tool: propose_crm_customer_note/);
    await page.getByText('Diagnostics and acceptance still needed').click();
    assert.match(await page.locator('#capabilityTruthContent').innerText(),/not_tested_by_capability_snapshot/);
    assert.equal(await page.locator('#capabilityTruthContent img').count(),0,'server labels must be escaped');
    assert.equal(await page.evaluate(()=>window.capabilityXss===1),false,'malicious label never executes');
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('#capabilityTruthOverlay').isHidden(),true,'escape closes dialog');
    role='staff';
    await page.setViewportSize({width:390,height:790});
    await page.getByRole('button',{name:'Tools & status'}).click();
    await page.getByText('Native registered tools',{exact:false}).waitFor();
    assert.doesNotMatch(await page.locator('#capabilityTruthContent').innerText(),/Source contracts — NOT independently executable/,
      'staff does not see owner action inventory');
    assert.doesNotMatch(await page.locator('#capabilityTruthContent').innerText(),/old discovery contracts/,
      'staff cannot see owner coverage counts');
    assert.equal(await page.locator('#capabilityTruthOverlay').isVisible(),true,'mobile dialog visible');
    await page.getByRole('button',{name:'Close'}).click();
    assert.equal(await page.locator('#capabilityTruthOverlay').isHidden(),true,'explicit close works');
    assert.equal(requests.filter(x=>x[1]==='/api/agent-workspace.php').length,2,'only two on-demand reads');
    assert.equal(requests.filter(x=>x[0]!=='GET').length,0,'diagnostics never POST writes');
    console.log('Food Monster B8D17A browser: dialog, scope, escaping, mobile and read-only PASS');
  } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
