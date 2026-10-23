const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {chromium}=require('playwright');

(async()=>{
  const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
  let checks=0;
  const ok=(condition,message)=>{assert.ok(condition,message);checks++;};
  const source=name=>fs.readFileSync(path.resolve(__dirname,'..',name),'utf8');
  try{
    const page=await browser.newPage({viewport:{width:960,height:750},reducedMotion:'reduce'});
    const errors=[];
    page.on('pageerror',error=>errors.push(error.message));
    await page.route('https://gelato.test/**',async route=>{
      const url=new URL(route.request().url());
      if(url.pathname==='/')return route.fulfill({status:200,contentType:'text/html',
        body:'<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"></head><body></body></html>'});
      if(url.pathname==='/assets/food-monster/chef-mascot.svg')
        return route.fulfill({status:200,contentType:'image/svg+xml',body:source('assets/food-monster/chef-mascot.svg')});
      if(url.pathname.startsWith('/js/'))
        return route.fulfill({status:200,contentType:'text/javascript',body:source(url.pathname.slice(1).split('?')[0])});
      const body=route.request().postDataJSON?.()||{};
      let data={ok:true};
      if(url.pathname==='/api/agent-workspace.php'){
        if(url.searchParams.get('action')==='bootstrap')
          data={ok:true,csrf:'manual-chat-csrf',activeThread:'agent-0123456789abcdefabcd',
            permissions:['menu.view'],user:{id:7,name:'Owner',firstName:'Owner'},messages:[]};
        else if(body.action==='append')data={ok:true,message:{databaseId:93,public_id:'msg-93'}};
        else if(body.action==='route')data={ok:true,route:'api/food-monster-knowledge.php',domain:'food_monster_grounded_knowledge'};
        else if(url.searchParams.get('action')==='thread')data={ok:true,thread:{title:'Conversation'},messages:[]};
        else if(url.searchParams.get('action')==='threads')data={ok:true,threads:[]};
      }else if(url.pathname==='/api/food-monster-knowledge.php')
        data={ok:true,skill:'food_monster.menu_knowledge',
          answer:'The current menu includes a margherita pizza.',sources:['Gelato active menu']};
      return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify(data)});
    });
    await page.goto('https://gelato.test/');
    await page.addStyleTag({content:source('css/food-monster.css')});
    await page.addScriptTag({content:source('js/global-agent.js')});
    await page.addScriptTag({content:source('js/food-monster.js')});
    await page.addScriptTag({content:source('js/dynamic-agent-canvas.js')});
    await page.waitForFunction(()=>window.GelatoGlobalAgent?.user?.id===7);
    await page.locator('#gaInput').fill('What is on our menu?');
    await page.locator('#gaSend').click();
    await page.waitForFunction(()=>document.querySelector('#food-monster-root .fm-speech')?.textContent?.includes('margherita'));
    ok(!(await page.locator('#gelato-agent-response-drawer').evaluate(el=>el.classList.contains('open'))),
      'Sending must not auto-open the conversation');
    ok(await page.locator('#food-monster-root.fm-has-speech').count()===1,
      'Food Monster displays the response in its speech bubble');
    ok(await page.locator('#gaResponseToggle').getAttribute('aria-expanded')==='false',
      'Chat button remains closed after an answer');
    await page.locator('#gaResponseToggle').click();
    ok(await page.locator('#gelato-agent-response-drawer.open').count()===1,
      'Explicit chat button opens the conversation');
    ok(await page.locator('#gaResponseToggle').getAttribute('aria-expanded')==='true',
      'Chat button announces expanded state');
    await page.waitForTimeout(130);
    const desktop=await page.evaluate(()=>{
      const actor=document.querySelector('#food-monster-root .fm-actor');
      const drawer=document.querySelector('#gelato-agent-response-drawer');
      const ar=actor.getBoundingClientRect(),dr=drawer.getBoundingClientRect();
      return {actorBottom:ar.bottom,drawerTop:dr.top,
        mascotZ:Number(getComputedStyle(document.querySelector('#food-monster-root')).zIndex),
        drawerZ:Number(getComputedStyle(drawer).zIndex)};
    });
    ok(desktop.mascotZ>desktop.drawerZ,'Mascot always stays in front of the chat drawer');
    ok(desktop.actorBottom<=desktop.drawerTop+2,
      'Mascot moves clear of the open desktop drawer: '+JSON.stringify(desktop));
    await page.locator('.gar-close').click();
    await page.locator('#gaInput').fill('Tell me about the menu again');
    await page.locator('#gaInput').press('Enter');
    await page.waitForTimeout(160);
    ok(await page.locator('#gelato-agent-response-drawer.open').count()===0,
      'Enter submission keeps the closed drawer closed');
    await page.evaluate(()=>window.dispatchEvent(new CustomEvent('gelato-agent-error',{detail:{message:'Temporary error'}})));
    ok(await page.locator('#gelato-agent-response-drawer.open').count()===0,
      'Errors cannot force open a closed chat drawer');
    await page.setViewportSize({width:390,height:800});
    await page.locator('#gaResponseToggle').click();
    await page.waitForTimeout(130);
    const mobile=await page.evaluate(()=>{
      const ar=document.querySelector('#food-monster-root .fm-actor').getBoundingClientRect();
      const dr=document.querySelector('#gelato-agent-response-drawer').getBoundingClientRect();
      return {actorBottom:ar.bottom,drawerTop:dr.top};
    });
    ok(mobile.actorBottom<=mobile.drawerTop+2,
      'Mascot avoids the open mobile drawer: '+JSON.stringify(mobile));
    ok(errors.length===0,'No browser exceptions: '+errors.join('; '));
    console.log('Food Monster manual chat UI PASS ('+checks+' assertions)');
  }finally{await browser.close();}
})().catch(error=>{console.error(error.stack||error);process.exit(1);});
