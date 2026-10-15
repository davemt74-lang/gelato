const assert=require('node:assert/strict');
const fs=require('node:fs'),path=require('node:path');
const {chromium}=require('playwright');
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 let checks=0; const ok=(value,name)=>{assert.ok(value,name);checks++};
 try{
  for(const reducedMotion of ['no-preference','reduce']){
   const page=await browser.newPage({viewport:{width:390,height:844},reducedMotion});
   const errors=[];page.on('pageerror',e=>errors.push(e.message));
   await page.setContent('<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"></head><body class="food-monster-stage-page"><main id="food-monster-stage"></main><div id="gelato-agent-bar"><textarea id="gaInput"></textarea></div></body></html>');
   const base=path.resolve(__dirname,'..');
   await page.addStyleTag({content:fs.readFileSync(path.join(base,'css/food-monster.css'),'utf8')});
   const svg=fs.readFileSync(path.join(base,'assets/food-monster/chef-mascot.svg'),'utf8');
   await page.evaluate(source=>{window.fetch=async()=>({ok:true,text:async()=>source});},svg);
   await page.addScriptTag({content:fs.readFileSync(path.join(base,'js/food-monster.js'),'utf8')});
   await page.locator('[data-fm-pupil]').waitFor();
   const root=page.locator('#food-monster-root');
   async function dispatch(name,detail){await page.evaluate(({name,detail})=>window.dispatchEvent(new CustomEvent(name,{detail})),{name,detail});}
   await dispatch('gelato-agent-response',{answer:'Here is the information you requested.'});
   ok(await root.getAttribute('data-mood')==='happy','typed response gets happy reaction');
   ok(await root.evaluate(el=>!el.classList.contains('fm-speaking')),'typed text must never mimic spoken audio');
   await dispatch('gelato-agent-listening-change',{listening:true,microphoneActive:true});
   ok(await root.getAttribute('data-mood')==='listening','listening when mic is actually active');
   await dispatch('gelato-agent-status',{message:'Gelato is checking your restaurant context…'});
   ok(await root.getAttribute('data-mood')==='thinking','thinking overrides listening');
   await dispatch('gelato-voice-turn',{kind:'speaking',owner:'kds-agent'});
   ok(await root.getAttribute('data-mood')==='thinking','other modules cannot hijack mascot speech');
   await dispatch('gelato-voice-turn',{kind:'speaking',owner:'global-agent'});
   ok(await root.getAttribute('data-mood')==='speaking','TTS lifecycle triggers mouth animation');
   await page.waitForTimeout(180);
   ok(!!(await root.getAttribute('style')).match(/--fm-mouth-open/),'speech rhythm updated while TTS active');
   await dispatch('gelato-voice-turn',{kind:'ended',owner:''});
   ok(await root.getAttribute('data-mood')==='thinking','speech end returns to prior thinking state');
   ok(!(await root.getAttribute('style')).includes('--fm-mouth-open'),'speech end stops rhythm');
   await dispatch('gelato-agent-status',{message:'Ready.'});
   ok(await root.getAttribute('data-mood')==='listening','listening resumes after thought complete');
   await dispatch('gelato-agent-listening-change',{listening:false,microphoneActive:false});
   await dispatch('gelato-voice-turn',{kind:'speaking',owner:'global-agent'});
   await dispatch('gelato-voice-turn',{kind:'interrupted',owner:'global-agent'});
   ok(await root.getAttribute('data-mood')!=='speaking','barge-in clears speaking');
   await dispatch('gelato-agent-response',{answer:'Short update',result:{data:{nextMoves:[{severity:'critical'}]}}});
   ok(await root.getAttribute('data-mood')==='alert','critical permitted next-move triggers visual alert');
   ok(errors.length===0,'no JS errors '+errors.join(';'));
   await page.close();
  }
  console.log('FM-G2 speech/expression browser acceptance PASS ('+checks+' assertions)');
 }finally{await browser.close();}
})().catch(err=>{console.error(err.stack||err);process.exit(1);});
