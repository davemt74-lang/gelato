'use strict';
const fs=require('node:fs');
const assert=require('node:assert/strict');
const path=require('node:path');
const {chromium}=require('playwright');
const root=path.resolve(__dirname,'..');
const php=fs.readFileSync(path.join(root,'agent-canvas.php'),'utf8');
const html=php.slice(php.indexOf('<!doctype html>'));
const script=fs.readFileSync(path.join(root,'js/agent-canvas.js'),'utf8');

const safe={kind:'verified_internal_page',id:'menu_manager',
  label:'Menu Manager',section:'Operations',href:'menu-manager.php',executed:false};
const bad={kind:'verified_internal_page',id:'menu_manager',
  label:'<img src=x onerror="window.injected=1">',href:'https://evil.example/out'};

(async()=>{
  const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
  try{
    const page=await browser.newPage({viewport:{width:1300,height:820}});
    let nav=safe;
    await page.addInitScript(()=>{
      window.GelatoGlobalAgent={user:{id:7},threadId:'thread-1',
        selectThread(){},send:async()=>{},talkOnce:()=>{}};
    });
    await page.route('https://gelato.example/**',async route=>{
      const url=new URL(route.request().url());
      if(url.pathname==='/agent-canvas.php')
        return route.fulfill({contentType:'text/html',body:html});
      if(url.pathname==='/js/agent-canvas.js')
        return route.fulfill({contentType:'application/javascript',body:script});
      if(url.pathname.startsWith('/js/'))
        return route.fulfill({contentType:'application/javascript',body:''});
      if(url.pathname==='/api/agent-workspace.php'){
        const action=url.searchParams.get('action');
        const payload=action==='bootstrap'?{ok:true,csrf:'test',activeThread:'thread-1',
          threads:[{public_id:'thread-1',title:'Agent Navigation'}]}:
          action==='threads'?{ok:true,threads:[{public_id:'thread-1',title:'Agent Navigation'}]}:
          {ok:true,thread:{title:'Agent Navigation'},messages:[{
            role:'agent',channel:'text',content:'I found the screen.',
            created_at:'2026-10-27 12:00:00',skill:'agent.model_selected_tools',
            structured:{navigation:nav},sources:['Gelato staff navigation'],
          }]};
        return route.fulfill({contentType:'application/json',body:JSON.stringify(payload)});
      }
      return route.fulfill({status:404,body:'Not found'});
    });
    await page.goto('https://gelato.example/agent-canvas.php');
    const link=page.locator('#canvasMessages a[href="menu-manager.php"]');
    await link.waitFor();
    assert.match(await link.innerText(),/Open Menu Manager/);
    assert.match(await page.locator('#resultBody').innerText(),/Verified Gelato page/);
    nav=bad;
    await page.reload();
    await page.locator('#canvasMessages .bubble').waitFor();
    assert.equal(await page.locator('#canvasMessages a[href^="https:"]').count(),0,
      'external server receipt cannot become clickable');
    assert.equal(await page.locator('#resultBody a[href^="https:"]').count(),0,
      'Agent Brain rejects arbitrary external URLs');
    assert.equal(await page.evaluate(()=>window.injected===1),false,
      'malicious navigation labels remain escaped');
    nav=safe;
    await page.setViewportSize({width:390,height:790});
    await page.reload();
    await page.locator('#canvasMessages a[href="menu-manager.php"]').waitFor();
    assert.equal(await page.locator('#canvasMessages a[href="menu-manager.php"]').isVisible(),true,
      'mobile chat retains its verified page action');
    console.log('Food Monster B8D17B browser: desktop, mobile, permission receipt, unsafe URL and XSS PASS');
  }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
