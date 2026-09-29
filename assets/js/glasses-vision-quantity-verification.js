(()=>{'use strict';
const boot=window.GELATO_VISION_LAB||{},root=document.getElementById('vlQuantityVerifications'),button=document.getElementById('vlQuantityVerify');
if(!root)return;
let catalog=boot.catalog||{};
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
async function post(payload){const r=await fetch(boot.apiUrl,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({...payload,csrfToken:boot.csrfToken})});const j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Vision Lab request failed.');return j;}
async function refresh(){const r=await fetch(boot.apiUrl,{credentials:'same-origin'}),j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Refresh failed.');catalog=j.catalog||{};render();}
function render(){
 const rows=catalog.quantityVerifications||[];
 root.innerHTML=rows.length?rows.map(r=>{const x=r.result||{};return '<div class="vl-step-row"><div><strong><span class="vl-step-state">'+esc(r.state)+'</span> · '+esc(r.componentKey||'No current component')+'</strong><small>Expected '+esc(r.expectedQuantity??'—')+' '+esc(r.unit||'')+' · observed '+esc(r.observedQuantity??'—')+' · confidence '+Number(r.confidence||0).toFixed(3)+'</small><small>'+esc(x.reason||'')+(x.deviation!==null&&x.deviation!==undefined?' · deviation '+esc(x.deviation):'')+'</small><code>'+esc(r.verificationHash)+'</code></div><div class="vl-step-actions"><button data-verify="'+esc(r.publicId)+'">Verify</button></div></div>'}).join(''):'<div class="vl-empty">No portion / quantity verifications yet.</div>';
 root.querySelectorAll('[data-verify]').forEach(b=>b.addEventListener('click',async()=>{try{const j=await post({action:'quantity.verify',publicId:b.dataset.verify});alert(j.verification.passed?'Quantity verification verified.':'Quantity verification integrity failed.');}catch(e){alert(e.message);}}));
}
button?.addEventListener('click',async()=>{
 const scene=(catalog.liveScenes?.scenes||[])[0];
 if(!scene){alert('No live scene is available yet.');return;}
 try{await post({action:'quantity.verify_scene',scenePublicId:scene.publicId});await refresh();}catch(e){alert(e.message);}
});
render();
})();