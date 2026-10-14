/* Gelato 285D4: governed operator-approved ingredient checklist handoff.
 * Camera hypotheses NEVER apply themselves. Server audits operator-reported
 * decisions, not camera-verified placements, and KDS status is untouched.
 */
((root,factory)=>{'use strict';const api=factory();
 if(typeof module==='object'&&module.exports)module.exports=api;
 if(root)root.GelatoIngredientProgression=api;
})(typeof window!=='undefined'?window:null,()=>{'use strict';
 const normalize=s=>String(s||'').normalize('NFKC').toLowerCase().replace(/[^\p{L}\p{N}]+/gu,' ').trim().replace(/\s+/g,' ');
 const TOKEN=/^[a-f0-9]{32}$/i;
 const nowMs=()=>Date.now();
 function create({now=nowMs,persist,applyChecklist,tokenFactory}={}){
  if(typeof persist!=='function'||typeof applyChecklist!=='function'||typeof tokenFactory!=='function')
   throw Error('Governed progression requires audit persistence and explicit action handlers.');
  let pending=null,busy=false,audit=[],used=new Set(),lastApplied=null;
  function snapshot(){return {pending:pending?{...pending}:null,lastApplied:lastApplied?{...lastApplied}:null,busy,
    audit:audit.map(x=>({...x})),automaticEnabled:false,cameraVerified:false,
    kdsStatusChanged:false};}
  function reset(){pending=null;busy=false;}
  function scope(c){return [c?.devicePublicId,c?.stationPublicId,c?.locationId,c?.item?.kdsItemPublicId,c?.modelSha256].join('|');}
  function eligible(receipt,c){
   if(!receipt||receipt.decision!=='confirm'||receipt.operatorConfirmed!==true||
      receipt.cameraPlacementVerified!==false||receipt.ingredientAdded!==false||
      receipt.kdsChanged!==false||!Number.isFinite(receipt.reviewedAt)||
      receipt.reviewedAt>now()||now()-receipt.reviewedAt>15000)return {error:'stale_or_unconfirmed_review'};
   if(!c||c.mode!=='live'||!c.runtimeBound||!c.cameraLive||!c.modelReady||
      c.hidden||!c.permissions?.kdsUpdate||!c.permissions?.glassesView||
      !c.devicePublicId||!c.stationPublicId||!c.locationId||
      !/^[a-f0-9]{64}$/i.test(String(c.modelSha256||''))||
      !c.item?.kdsItemPublicId||!c.item?.ticket?.checkPublicId||c.item.status!=='in_progress')
    return {error:'permission_or_scope_not_ready'};
   if(receipt.itemPublicId!==c.item.kdsItemPublicId)return {error:'different_ticket'};
   const ingredientName=normalize(receipt.label);
   const choices=(c.item.menu?.ingredients||[]).map((v,i)=>({
      id:String(i)+':'+String(v?.canonicalName||v?.name||''),
      name:String(v?.name||''),canonical:normalize(v?.canonicalName||v?.name)})).filter(v=>v.canonical===ingredientName);
   if(choices.length!==1)return {error:'ingredient_not_unique_in_ticket'};
   const ingredient=choices[0];
   if(['added','skipped'].includes(c.actions?.[ingredient.id]))return {error:'ingredient_already_accounted'};
   return {ingredient};
  }
  function queue(receipt,context){
   if(busy||pending)return {ok:false,reason:'pending_or_busy'};
   const e=eligible(receipt,context);
   if(e.error)return {ok:false,reason:e.error};
   const token=tokenFactory();
   if(!TOKEN.test(String(token||''))||used.has(token))return {ok:false,reason:'invalid_unique_token'};
   const expiresAt=Math.min(now()+12000,receipt.reviewedAt+15000);
   if(expiresAt<=now())return {ok:false,reason:'expired_review'};
   pending={token,reviewId:String(receipt.id||''),reviewedAt:receipt.reviewedAt,
    ingredientId:e.ingredient.id,label:e.ingredient.name,itemPublicId:context.item.kdsItemPublicId,
    scope:scope(context),status:'operator_approval_required',expiresAt};
   return {ok:true,pending:{...pending}};
  }
  function reject(){if(busy)return {ok:false,reason:'busy'};
   const declined=pending;pending=null;
   if(declined)audit.unshift({token:declined.token,decision:'reject',itemPublicId:declined.itemPublicId,
      ingredientId:declined.ingredientId,at:now(),persisted:false});
   audit=audit.slice(0,30);return {ok:true};
  }
  async function approve(context){
   if(busy)return {ok:false,reason:'busy'};
   const q=pending;
   if(!q||now()>q.expiresAt||q.scope!==scope(context)){
    pending=null;return {ok:false,reason:'expired_or_context_changed'};
   }
   const check=eligible({decision:'confirm',operatorConfirmed:true,cameraPlacementVerified:false,
    ingredientAdded:false,kdsChanged:false,reviewedAt:q.reviewedAt,itemPublicId:q.itemPublicId,label:q.label},context);
   if(check.error||check.ingredient.id!==q.ingredientId){pending=null;
    return {ok:false,reason:check.error||'ingredient_changed'};}
   if(used.has(q.token))return {ok:false,reason:'already_applied'};
   busy=true;
   try{
    // Persist an authenticated, station-scoped, idempotent KDS audit event first.
    const recorded=await persist({action:'kds.item.ingredient_review',
     kdsItemPublicId:q.itemPublicId,expectedStatus:context.item.status,
     reviewToken:q.token,ingredientId:q.ingredientId,decision:'operator_confirmed'});
    if(!recorded?.kdsResult?.noted||recorded.kdsResult.kdsItemPublicId!==q.itemPublicId)
     throw Error('KDS audit did not acknowledge this item');
    used.add(q.token);
    const applied=applyChecklist({ingredientId:q.ingredientId,itemPublicId:q.itemPublicId,
      source:'operator_placement_review',operatorConfirmed:true,reviewToken:q.token});
    const ok=applied?.ok===true;
    if(ok)lastApplied={itemPublicId:q.itemPublicId,ingredientId:q.ingredientId,reviewToken:q.token,scope:q.scope};
    audit.unshift({token:q.token,decision:'operator_confirmed',itemPublicId:q.itemPublicId,
     ingredientId:q.ingredientId,at:now(),persisted:true,duplicate:!!recorded.kdsResult.duplicate,
     checklistApplied:ok});
    audit=audit.slice(0,30);pending=null;
    return {ok,reason:ok?'operator_reported_checklist_progress':'audited_but_local_checklist_stale',
      audit:snapshot().audit[0]};
   }catch(e){return {ok:false,reason:'audit_failed',message:String(e?.message||e)};
   }finally{busy=false;}
  }
  async function undo(context){
   const q=lastApplied;
   if(busy)return {ok:false,reason:'busy'};
   if(!q||scope(context)!==q.scope||context?.item?.status!=='in_progress'||
      context?.actions?.[q.ingredientId]!=='added'||
      !context?.permissions?.kdsUpdate||!context?.permissions?.glassesView||
      !context?.runtimeBound||!context?.cameraLive||context?.hidden){
      return {ok:false,reason:'undo_no_longer_eligible'};
   }
   const token=tokenFactory();
   if(!TOKEN.test(String(token||''))||used.has(token))return {ok:false,reason:'invalid_unique_token'};
   busy=true;
   try{
     const result=await persist({action:'kds.item.ingredient_review',
       kdsItemPublicId:q.itemPublicId,expectedStatus:context.item.status,
       ingredientId:q.ingredientId,reviewToken:token,referenceToken:q.reviewToken,
       decision:'operator_reversed'});
     if(!result?.kdsResult?.noted||result.kdsResult.kdsItemPublicId!==q.itemPublicId)
       throw Error('KDS reversal audit did not acknowledge this item');
     used.add(token);
     const applied=applyChecklist({undo:true,ingredientId:q.ingredientId,itemPublicId:q.itemPublicId,
       operatorConfirmed:true,reviewToken:token});
     const ok=applied?.ok===true;
     audit.unshift({token,referenceToken:q.reviewToken,decision:'operator_reversed',
       itemPublicId:q.itemPublicId,ingredientId:q.ingredientId,
       at:now(),persisted:true,checklistApplied:ok});
     audit=audit.slice(0,30);lastApplied=null;
     return {ok,reason:ok?'undo_recorded':'undo_audited_but_checklist_stale',audit:snapshot().audit[0]};
   }catch(e){return {ok:false,reason:'undo_audit_failed',message:String(e?.message||e)};}
   finally{busy=false;}
  }
  return Object.freeze({queue,approve,undo,reject,reset,snapshot});
 }
 return Object.freeze({create,normalize});
});
