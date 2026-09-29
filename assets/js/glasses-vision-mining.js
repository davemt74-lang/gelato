(()=>{'use strict';
const boot=window.GELATO_VISION_LAB||{},root=document.getElementById('vlHardExampleMining'),button=document.getElementById('vlMiningRun');
if(!root)return;
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot',"'":'&#039;'}[c]));
const nice=s=>String(s??'').replaceAll('_',' ');
async function post(payload){const r=await fetch(boot.apiUrl,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({...payload,csrfToken:boot.csrfToken})});const j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Vision Lab request failed.');return j;}
function render(data){
 const runs=data?.runs||[],cands=data?.candidates||[],latest=runs[0]?.result||null,s=latest?.summary||{};
 root.innerHTML='<div class="vl-mining-summary"><div><span>Source errors</span><strong>'+Number(s.sourceEvents||0)+'</strong></div><div><span>Eligible</span><strong>'+Number(s.eligibleCandidates||cands.length)+'</strong></div><div><span>Open</span><strong>'+Number(s.open||cands.filter(x=>x.status==='open').length)+'</strong></div><div><span>Suppressed</span><strong>'+Number(s.suppressed||cands.filter(x=>x.status==='suppressed').length)+'</strong></div></div>'+
 (cands.length?'<div class="vl-mined-list">'+cands.slice(0,100).map(c=>'<div class="vl-mined-card" data-status="'+esc(c.status)+'"><div><strong>'+esc(nice(c.candidateType))+' · '+Number(c.score)+'/100</strong><small>'+esc(c.predictedComponentKey||'—')+' → '+esc(c.expectedComponentKey||'—')+' · '+esc(c.modelPackagePublicId||'unattributed')+'</small><small>'+esc(nice(c.status))+(c.reasons?.suppressionReason?' · '+esc(nice(c.reasons.suppressionReason)):'')+'</small><code>'+esc(c.productionErrorHash||'')+'</code></div>'+(c.status!=='dismissed'?'<button data-dismiss="'+esc(c.publicId)+'">Dismiss</button>':'')+'</div>').join('')+'</div>':'<div class="vl-empty">No mined candidates yet.</div>')+
 '<div class="vl-failure-note">Mining is advisory only. Candidates are not added to datasets, retrained, or rolled out automatically.</div>';
 root.querySelectorAll('[data-dismiss]').forEach(b=>b.addEventListener('click',async()=>{const reason=prompt('Why dismiss this candidate?');if(!reason)return;try{await post({action:'hard_example.dismiss',publicId:b.dataset.dismiss,reason});await refresh();}catch(e){alert(e.message);}}));
}
async function refresh(){const r=await fetch(boot.apiUrl,{credentials:'same-origin'}),j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Refresh failed.');render(j.catalog?.hardExampleMining||{});}
render(boot.catalog?.hardExampleMining||{});
button?.addEventListener('click',async()=>{button.disabled=true;try{await post({action:'hard_example.mine'});await refresh();}catch(e){root.insertAdjacentHTML('afterbegin','<div class="vl-alert">'+esc(e.message)+'</div>');}finally{button.disabled=false;}});
})();