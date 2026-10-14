/* 285D4: operator must make a second, explicit, permission-scoped approval.
 * Only the authenticated server records durable KDS audit; no automatic advancement.
 */
(()=>{'use strict';
 const core=window.GelatoIngredientProgression;
 if(!core)return;
 const $=id=>document.getElementById(id),nav=()=>window.GelatoGlassesNavigation;
 function freshToken(){
  const crypto=window.crypto;
  if(!crypto?.getRandomValues)throw Error('Secure review-token generation unavailable.');
  const bytes=new Uint8Array(16);crypto.getRandomValues(bytes);
  return [...bytes].map(b=>b.toString(16).padStart(2,'0')).join('');
 }
 const engine=core.create({
  now:()=>Date.now(),tokenFactory:freshToken,currentContext:()=>context(),
  persist:payload=>{
   const fn=nav()?.persistIngredientReview;
   if(typeof fn!=='function')throw Error('Authorized KDS audit is unavailable.');
   return fn(payload);
  },
  applyChecklist:payload=>{
   const fn=nav()?.applyReviewedIngredient;
   return typeof fn==='function'?fn(payload):{ok:false};
  }
 });
 let status='Approve a fresh placement hypothesis to begin. No automatic KDS changes.';
 function context(){return nav()?.ingredientProgressionContext?.()||null;}
 function render(){
  const s=engine.snapshot(),p=s.pending;
  const el=$('glassesProgressionStatus');if(el)el.textContent=status;
  const a=$('glassesProgressionApply'),d=$('glassesProgressionDecline'),u=$('glassesProgressionUndo');
  if(a)a.disabled=!p||s.busy;
  if(d)d.disabled=!p||s.busy;
  if(u)u.disabled=!s.lastApplied||s.busy;
  const log=$('glassesProgressionAudit');if(log){
   log.replaceChildren();
   for(const event of s.audit.slice(0,8)){
    const row=document.createElement('li');
    row.textContent=event.decision+' · '+event.ingredientId+
      (event.persisted?' · KDS audit saved':' · local decline')+
      (event.checklistApplied===false?' · checklist not updated':'');
    log.appendChild(row);
   }
  }
 }
 function approveReview(event){
  const receipt=event.detail?.receipt;
  const result=engine.queue(receipt,context());
  status=result.ok?'REVIEW READY · '+result.pending.label+
    '. Apply requires another operator click; persists an audit-only event.':
    'Review cannot advance this ingredient: '+result.reason+'.';
  render();
 }
 async function apply(){
  const p=engine.snapshot().pending;
  if(!p)return;
  status='Saving authenticated KDS operator audit before local checklist update…';render();
  const result=await engine.approve(context());
  status=result.ok?p.label+' recorded by operator; ingredient checklist updated. KDS status unchanged.':
    'Ingredient was NOT advanced: '+(result.message||result.reason)+'.';
  render();
 }
 async function undo(){
  const s=engine.snapshot();if(!s.lastApplied)return;
  status='Recording explicit operator reversal…';render();
  const result=await engine.undo(context());
  status=result.ok?'Undo recorded in KDS audit; local ingredient returned to pending. KDS status unchanged.':
   'Undo did not change the checklist: '+(result.message||result.reason)+'.';
  render();
 }
 function decline(){engine.reject();status='Review declined. No ingredient or KDS status updated.';render();}
 function reset(){engine.reset();status='Camera, model, or ticket context changed. Awaiting a new approved hypothesis.';render();}
 window.addEventListener('gelato-glasses-placement-reviewed',approveReview);
 window.addEventListener('gelato-glasses-hand-reset',reset);
 window.addEventListener('gelato-glasses-runtime-revoked',reset);
 window.addEventListener('gelato-glasses-location-change',reset);
 window.addEventListener('gelato-glasses-camera-source-change',reset);
 window.addEventListener('gelato-camera-configuration-changed',reset);
 window.addEventListener('pagehide',reset);
 document.addEventListener('visibilitychange',()=>{if(document.hidden)reset();});
 function mount(){
  $('glassesProgressionApply')?.addEventListener('click',()=>void apply());
  $('glassesProgressionDecline')?.addEventListener('click',decline);
  $('glassesProgressionUndo')?.addEventListener('click',()=>void undo());
  render();
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount,{once:true});
 else mount();
 window.GelatoIngredientProgressionUI={summary:()=>engine.snapshot(),reset};
})();
