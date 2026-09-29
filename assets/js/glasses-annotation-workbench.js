(function(){'use strict';
const boot=window.GELATO_VISION_LAB||{},root=document.getElementById('vlAnnotationWorkbench');if(!root)return;
let catalog=boot.catalog||{};
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
async function api(payload){
  payload.csrf_token=boot.csrfToken;
  const r=await fetch(boot.apiUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
  const j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Annotation workbench request failed.');
  await refresh();return j;
}
async function refresh(){
  const r=await fetch(boot.apiUrl,{credentials:'same-origin',cache:'no-store'}),j=await r.json();
  if(!r.ok||!j.ok)throw new Error(j.message||'Annotation workbench refresh failed.');
  catalog=j.catalog||{};render();
}
function evidence(){return catalog.productionEvidence?.evidence||[];}
function corrections(){return catalog.annotationWorkbench?.corrections||[];}
function chooseEvidence(){
 const list=evidence();if(!list.length)throw new Error('No V11 production evidence is available.');
 const menu=list.map((x,i)=>(i+1)+'. '+x.publicId+' · '+(x.operatorName||'operator')+' · '+(x.recipeStepKey||'no step')).join('\n');
 const n=Number(prompt('Choose evidence:\n'+menu,'1')||0);if(n<1||n>list.length)throw new Error('Evidence selection cancelled.');return list[n-1];
}
async function submitCorrection(){
 const e=chooseEvidence();
 const type=prompt('Correction type: label, bbox, false_positive, false_negative, step, ingredient, combined','label');if(!type)return;
 const label=(type==='step')?'':(prompt('Correct label (leave blank if unchanged)','')||'');
 const step=(type==='step'||type==='combined')?(prompt('Correct recipe step key','')||''):'';
 const reason=prompt('Why is this correction needed?');if(!reason)return;
 let anns=[];
 if(['bbox','false_negative','ingredient','combined'].includes(type)){
   const box=prompt('Normalized box x,y,width,height (example 0.2,0.2,0.3,0.3)','0.2,0.2,0.3,0.3');
   if(box){const p=box.split(',').map(Number);if(p.length!==4||p.some(Number.isNaN))throw new Error('Bounding box is invalid.');anns=[{label:label||'target',bbox:{x:p[0],y:p[1],width:p[2],height:p[3]}}];}
 }
 await api({action:'annotation.submit',evidencePublicId:e.publicId,correctionType:type,proposedLabel:label,proposedRecipeStepKey:step,proposedAnnotations:anns,reason});
}
async function decide(publicId,action){
 const notes=prompt((action==='adjudicate'?'Adjudication':'Review')+' notes','')||'';
 const decision=confirm('Approve this correction?\nOK = approve, Cancel = reject')?'approve':'reject';
 await api({action:action==='adjudicate'?'annotation.adjudicate':'annotation.review',correctionPublicId:publicId,decision,notes});
}
function render(){
 const rows=corrections();
 root.innerHTML='<div class="vl-head"><div><strong>Correction queue</strong><small>Two independent reviews required before automatic acceptance.</small></div><button id="vlAnnotationSubmit" class="admin-button dark" type="button">New correction</button></div>'+
 (rows.length?rows.map(c=>'<article class="vl-pilot-card"><div class="vl-head"><div><strong>'+esc(c.correctionType)+' · '+esc(c.samplePublicId)+'</strong><small>'+esc(c.submitterName)+' · '+esc(c.previousLabel||'unlabeled')+' → '+esc(c.proposedLabel||'unchanged')+'</small></div><span class="vl-state '+esc(c.status)+'">'+esc(c.status)+'</span></div><small>'+esc(c.reason)+'</small><div class="inline-actions">'+
 (['submitted'].includes(c.status)?'<button data-review="'+esc(c.publicId)+'">Review</button>':'')+
 (c.status==='needs_adjudication'?'<button data-adjudicate="'+esc(c.publicId)+'">Adjudicate</button>':'')+
 '</div></article>').join(''):'<div class="vl-empty">No V11 annotation corrections yet.</div>');
 document.getElementById('vlAnnotationSubmit')?.addEventListener('click',()=>submitCorrection().catch(e=>alert(e.message)));
}
root.addEventListener('click',e=>{const b=e.target.closest('button');if(!b)return;if(b.dataset.review)decide(b.dataset.review,'review').catch(err=>alert(err.message));if(b.dataset.adjudicate)decide(b.dataset.adjudicate,'adjudicate').catch(err=>alert(err.message));});
render();
})();