(()=>{'use strict';
const boot=window.GELATO_VISION_LAB||{},root=document.getElementById('vlAutonomyAudits');
if(!root)return;
let catalog=boot.catalog||{};
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
async function post(payload){const r=await fetch(boot.apiUrl,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({...payload,csrfToken:boot.csrfToken})});const j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Vision Lab request failed.');return j;}
async function refresh(){const r=await fetch(boot.apiUrl,{credentials:'same-origin'}),j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Refresh failed.');catalog=j.catalog||{};render();}
function render(){
 const data=catalog.autonomyAudits||{},audits=data.audits||[];
 const controls='<div class="vl-perception-actions"><button id="vlAuditRuntime">Capture latest runtime decision</button><button id="vlAuditFleet">Capture latest fleet decision</button></div>';
 root.innerHTML=controls+(audits.length?audits.map(a=>'<div class="vl-autonomy-row"><div><strong>'+esc(a.subjectKind)+' · '+esc(a.outcome)+' · '+esc(a.authorityState)+'</strong><small>'+esc(a.modelPackagePublicId)+' · '+esc(a.subjectPublicId)+'</small><small>'+esc(a.explanation?.summary||'')+'</small><code>'+esc(a.auditHash)+'</code></div><div class="vl-autonomy-actions"><button data-replay="'+esc(a.publicId)+'">Replay</button><button data-verify="'+esc(a.publicId)+'">Verify</button></div></div>').join(''):'<div class="vl-empty">No autonomy audits captured yet.</div>');
 document.getElementById('vlAuditRuntime')?.addEventListener('click',async()=>{const rows=catalog.confidencePolicies?.decisions||[];const d=rows[0];if(!d){alert('No confidence decision is available.');return;}try{await post({action:'autonomy_audit.capture',confidenceDecisionPublicId:d.publicId});await refresh();}catch(e){alert(e.message);}});
 document.getElementById('vlAuditFleet')?.addEventListener('click',async()=>{const rows=catalog.fleetHealth?.analyses||[];const d=rows[0];if(!d){alert('No fleet-health analysis is available.');return;}try{await post({action:'autonomy_audit.capture',fleetHealthAnalysisPublicId:d.publicId});await refresh();}catch(e){alert(e.message);}});
 root.querySelectorAll('[data-verify]').forEach(b=>b.addEventListener('click',async()=>{try{const j=await post({action:'autonomy_audit.verify',publicId:b.dataset.verify});alert(j.verification.passed?'Autonomy audit verified.':'Autonomy audit integrity failed.');}catch(e){alert(e.message);}}));
 root.querySelectorAll('[data-replay]').forEach(b=>b.addEventListener('click',async()=>{try{const j=await post({action:'autonomy_audit.replay',publicId:b.dataset.replay});alert((j.replay.explanation?.summary||'Audit replay')+'\n\n'+(j.replay.explanation?.why||[]).join('\n'));}catch(e){alert(e.message);}}));
}
render();
})();