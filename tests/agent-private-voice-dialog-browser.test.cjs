'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const {chromium}=require('playwright');

(async()=>{
  const src=fs.readFileSync('js/global-agent.js','utf8');
  const start=src.indexOf('  function requestPrivateVoiceConfirmation(question){');
  const end=src.indexOf('  async function approvedPrivateVoiceToken(',start);
  assert(start>=0&&end>start,'shipped custom dialog must exist');
  const browser=await chromium.launch({headless:true});
  try{
    const page=await browser.newPage();
    await page.goto('about:blank');
    await page.addScriptTag({content:'window.gavConfirm=(()=>{'+src.slice(start,end)+';return requestPrivateVoiceConfirmation;})();'});
    const dangerous='Show my schedule <img src=x onerror=alert(1)>';
    const approved=page.evaluate(question=>window.gavConfirm(question),dangerous);
    const modal=page.locator('dialog[open]');
    await modal.waitFor();
    assert.equal(await modal.locator('blockquote').innerText(),dangerous);
    assert.equal(await modal.locator('img').count(),0,'transcript must be rendered as text, never HTML');
    await modal.getByRole('button',{name:'Approve this request'}).click();
    assert.equal(await approved,true,'operator click approves exactly this request');
    assert.equal(await page.locator('dialog').count(),0,'confirmation dialog cleans up');

    const refused=page.evaluate(()=>window.gavConfirm('Private inventory'));
    await modal.waitFor();
    await modal.getByRole('button',{name:'Keep public'}).click();
    assert.equal(await refused,false,'operator can decline private access');

    const escaped=page.evaluate(()=>window.gavConfirm('Employee roster'));
    await modal.waitFor();
    await page.keyboard.press('Escape');
    assert.equal(await escaped,false,'closing modal must fail private access closed');
    process.stdout.write('Private voice Chromium consent: approve/decline/escape/XSS checks passed.\n');
  }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
