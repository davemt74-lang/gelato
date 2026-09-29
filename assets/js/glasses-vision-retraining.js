(()=>{'use strict';
const boot=window.GELATO_VISION_LAB||{},root=document.getElementById('vlRetrainingCandidates'),prepare=document.getElementById('vlRetrainingPrepare');
if(!root)return;
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
const nice=s=>String(s??'').replaceAll('_',' ');
let catalog=boot.catalog||{};
async function post(payload){const r=await fetch(boot.apiUrl,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({...payload,csrfToken:boot.csrfToken})});const j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Vision Lab request failed.');return j;}
async function refresh(){const r=await fetch(boot.apiUrl,{credentials:'same-origin'}),j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Refresh failed.');catalog=j.catalog||{};render();}
function render(){
 const batches=catalog.retrainingCandidates?.batches||[],b=batches[0]||null;
 if(!b){root.innerHTML='<div class="vl-empty">No retraining candidate batch prepared yet.</div>';return;}
 const c=b.counts||{},items=b.items||[];
 root.innerHTML='<div class="vl-retraining-summary"><div><span>Eligible</span><strong>'+Number(c.eligible||0)+'</strong></div><div><span>Ineligible</span><strong>'+Number(c.ineligible||0)+'</strong></div><div><span>Pending</span><strong>'+Number(c.pending||0)+'</strong></div><div><span>Include</span><strong>'+Number(c.include||0)+'</strong></div><div><span>Exclude</span><strong>'+Number(c.exclude||0)+'</strong></div></div>'+
 '<div class="vl-retraining-actions"><button id="vlRetrainingBuild" '+(b.status==='built'?'disabled':'')+'>Build draft dataset</button><small>'+esc(b.status)+' · '+esc(b.batchHash)+'</small></div>'+
 items.map(i=>'<div class="vl-retraining-item"><div><strong>'+esc(nice(i.eligibility))+' · '+esc(i.candidatePublicId)+'</strong><small>'+esc(i.samplePublicId||'No approved sample')+' · '+esc(i.mediaPublicId||'No governed media')+'</small><small>'+esc(i.eligibilityReason||i.decision||'pending')+'</small><code>'+esc(i.evidenceHash||'')+'</code></div><div class="vl-retraining-actions">'+
 (b.status!=='built'&&i.eligibility==='eligible'?'<button data-review="'+esc(i.publicId)+'" data-decision="include">Include</button><button data-review="'+esc(i.publicId)+'" data-decision="exclude">Exclude</button>':'')+
 '</div></div>').join('');
 root.querySelectorAll('[data-review]').forEach(btn=>btn.addEventListener('click',async()=>{const decision=btn.dataset.decision,reason=decision==='exclude'?(prompt('Why exclude this candidate?')||''):'';if(decision==='exclude'&&!reason)return;try{await post({action:'retraining_candidate.review',batchPublicId:b.publicId,itemPublicId:btn.dataset.review,decision,reason});await refresh();}catch(e){alert(e.message);}}));
 document.getElementById('vlRetrainingBuild')?.addEventListener('click',async()=>{const name=prompt('Candidate dataset name','Production Retraining Candidates');if(!name)return;const version=prompt('Version label','v'+new Date().toISOString().slice(0,10));if(!version)return;try{await post({action:'retraining_candidate.build_dataset',batchPublicId:b.publicId,name,versionLabel:version});await refresh();}catch(e){alert(e.message);}});
}
prepare?.addEventListener('click',async()=>{const runs=catalog.hardExampleMining?.runs||[];const run=runs[0];if(!run){alert('Run hard-example mining first.');return;}prepare.disabled=true;try{await post({action:'retraining_candidate.prepare',miningRunPublicId:run.publicId});await refresh();}catch(e){alert(e.message);}finally{prepare.disabled=false;}});
render();
})();