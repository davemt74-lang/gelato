(()=>{'use strict';
const boot=window.GELATO_VISION_LAB||{},root=document.getElementById('vlLiveScenes');
if(!root)return;
let catalog=boot.catalog||{};
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
async function post(payload){const r=await fetch(boot.apiUrl,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({...payload,csrfToken:boot.csrfToken})});const j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Vision Lab request failed.');return j;}
function render(){
 const scenes=catalog.liveScenes?.scenes||[];
 root.innerHTML=scenes.length?scenes.map(s=>{
   const counts=s.summary?.entityKinds||{};
   const badges=Object.keys(counts).sort().map(k=>'<span>'+esc(k)+' '+Number(counts[k]||0)+'</span>').join('');
   return '<div class="vl-scene-row"><div><strong>'+esc(s.sceneState)+' · '+esc(s.buildSessionPublicId)+'</strong><small>'+esc(s.devicePublicId)+' · '+esc(s.modelPackagePublicId)+' · '+esc(s.frameKey)+'</small><div class="vl-scene-counts">'+badges+'</div><small>current expected: '+esc(s.summary?.currentExpectedComponentKey||'—')+' · relationships '+Number(s.summary?.relationshipCount||0)+'</small><code>'+esc(s.sceneHash)+'</code></div><div class="vl-scene-actions"><button data-verify="'+esc(s.publicId)+'">Verify</button></div></div>';
 }).join(''):'<div class="vl-empty">No V9 scene snapshots yet. AIR3 or the browser simulator can submit governed scene frames through the device API.</div>';
 root.querySelectorAll('[data-verify]').forEach(b=>b.addEventListener('click',async()=>{try{const j=await post({action:'scene.verify',publicId:b.dataset.verify});alert(j.verification.passed?'Live scene verified.':'Live scene integrity failed.');}catch(e){alert(e.message);}}));
}
render();
})();