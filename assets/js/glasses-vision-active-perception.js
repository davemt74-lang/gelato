(()=>{'use strict';
const boot=window.GELATO_VISION_LAB||{},root=document.getElementById('vlActivePerception');
if(!root)return;
let catalog=boot.catalog||{};
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
async function post(payload){const r=await fetch(boot.apiUrl,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({...payload,csrfToken:boot.csrfToken})});const j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Vision Lab request failed.');return j;}
async function refresh(){const r=await fetch(boot.apiUrl,{credentials:'same-origin'}),j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Refresh failed.');catalog=j.catalog||{};render();}
function render(){
 const data=catalog.activePerception||{},actions=data.actions||[];
 root.innerHTML=actions.length?actions.map(a=>'<div class="vl-perception-row"><div><strong>'+esc(a.primaryAction)+' · '+esc(a.status)+'</strong><small>'+esc(a.devicePublicId)+' · '+esc(a.buildSessionPublicId)+' · '+esc(a.modelPackagePublicId)+'</small><small>'+esc((a.instruction?.steps||[]).join(' → '))+' · expires '+esc(a.expiresAt)+'</small><code>'+esc(a.actionHash)+'</code></div><div class="vl-perception-actions">'+
 '<button data-verify="'+esc(a.publicId)+'">Verify</button>'+
 (a.requiresHuman&&!['completed','failed','expired','cancelled'].includes(a.status)?'<button data-resolve="'+esc(a.publicId)+'" data-resolution="confirmed">Confirm</button><button data-resolve="'+esc(a.publicId)+'" data-resolution="rejected">Reject</button>':'')+
 '</div></div>').join(''):'<div class="vl-empty">No active-perception recovery actions yet.</div>';
 root.querySelectorAll('[data-verify]').forEach(b=>b.addEventListener('click',async()=>{try{const j=await post({action:'active_perception.verify',publicId:b.dataset.verify});alert(j.verification.passed?'Active-perception action verified.':'Active-perception integrity failed.');}catch(e){alert(e.message);}}));
 root.querySelectorAll('[data-resolve]').forEach(b=>b.addEventListener('click',async()=>{const notes=prompt('Resolution notes','Reviewed in Vision Lab.')||'';try{await post({action:'active_perception.resolve_human',publicId:b.dataset.resolve,resolution:b.dataset.resolution,notes});await refresh();}catch(e){alert(e.message);}}));
}
render();
})();