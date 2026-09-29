(()=>{'use strict';
const boot=window.GELATO_VISION_LAB||{},root=document.getElementById('vlCalibrationProfiles'),select=document.getElementById('vlCalibrationProfileSelect');
if(!root)return;
let catalog=boot.catalog||{};
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
async function post(payload){const r=await fetch(boot.apiUrl,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({...payload,csrfToken:boot.csrfToken})});const j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Vision Lab request failed.');return j;}
async function refresh(){const r=await fetch(boot.apiUrl,{credentials:'same-origin'}),j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Refresh failed.');catalog=j.catalog||{};render();}
function render(){
 const cp=catalog.calibrationProfiles||{},profiles=cp.profiles||[],selections=cp.selections||[];
 root.innerHTML='<div class="vl-calibration-actions"><button id="vlCalibrationCreate">Register profile</button></div>'+
 '<div class="vl-calibration-grid">'+(profiles.length?profiles.map(p=>'<div class="vl-calibration-card"><strong>'+esc(p.profileKey)+' · '+esc(p.status)+'</strong><small>'+esc(p.stationPublicId)+' · '+esc(p.calibrationPublicId)+' · priority '+Number(p.priority||0)+'</small><small>'+esc(p.modelPackagePublicId||'all models')+' · '+esc(p.platform)+'</small><code>'+esc(p.profileHash)+'</code><div class="vl-calibration-actions">'+(p.status==='draft'?'<button data-activate="'+esc(p.publicId)+'">Activate</button>':'')+(p.status==='active'?'<button data-retire="'+esc(p.publicId)+'">Retire</button>':'')+'</div></div>').join(''):'<div class="vl-empty">No governed calibration profiles registered yet.</div>')+'</div>'+
 (selections.length?'<div class="vl-calibration-selection"><strong>Latest selection</strong><small>'+esc(selections[0].decision)+' · '+esc(selections[0].devicePublicId)+' · '+esc(selections[0].selectedProfilePublicId||selections[0].fallbackCalibrationPublicId||'none')+'</small><code>'+esc(selections[0].selectionHash)+'</code></div>':'');
 document.getElementById('vlCalibrationCreate')?.addEventListener('click',async()=>{
  const calibrationPublicId=prompt('Existing station calibration public ID');if(!calibrationPublicId)return;
  const profileKey=prompt('Profile key','default-context');if(!profileKey)return;
  const priority=Number(prompt('Priority','0')||0);
  const modelPackagePublicId=prompt('Optional model package public ID','')||'';
  const brightnessMin=prompt('Optional brightness minimum (0-1)','');
  const brightnessMax=prompt('Optional brightness maximum (0-1)','');
  const contrastMin=prompt('Optional contrast minimum (0-1)','');
  const contrastMax=prompt('Optional contrast maximum (0-1)','');
  const context={};
  if(brightnessMin!==''||brightnessMax!=='')context.brightness={min:brightnessMin===''?0:Number(brightnessMin),max:brightnessMax===''?1:Number(brightnessMax)};
  if(contrastMin!==''||contrastMax!=='')context.contrast={min:contrastMin===''?0:Number(contrastMin),max:contrastMax===''?1:Number(contrastMax)};
  try{await post({action:'calibration_profile.create',calibrationPublicId,profileKey,priority,modelPackagePublicId,context});await refresh();}catch(e){alert(e.message);}
 });
 root.querySelectorAll('[data-activate]').forEach(b=>b.addEventListener('click',async()=>{try{await post({action:'calibration_profile.activate',publicId:b.dataset.activate});await refresh();}catch(e){alert(e.message);}}));
 root.querySelectorAll('[data-retire]').forEach(b=>b.addEventListener('click',async()=>{try{await post({action:'calibration_profile.retire',publicId:b.dataset.retire});await refresh();}catch(e){alert(e.message);}}));
}
select?.addEventListener('click',async()=>{
 const devices=catalog.devices||[];const d=devices[0];const devicePublicId=prompt('Device public ID',d?.publicId||'');if(!devicePublicId)return;
 const analyses=catalog.contextDrift?.analyses||[];const contextDriftAnalysisPublicId=prompt('Optional context-drift analysis public ID',analyses[0]?.publicId||'')||'';
 const modelPackagePublicId=prompt('Optional model package public ID','')||'';
 const frameWidth=Number(prompt('Current camera frame width','640')||0),frameHeight=Number(prompt('Current camera frame height','480')||0);
 const pixelFormat=prompt('Current camera pixel format','grayscale8')||'';
 const context={};
 if(!contextDriftAnalysisPublicId){
   const brightness=prompt('Current brightness mean (optional)',''),contrast=prompt('Current contrast mean (optional)','');
   if(brightness!=='')context.brightnessMean=Number(brightness);if(contrast!=='')context.contrastMean=Number(contrast);
 }
 try{await post({action:'calibration_profile.select',devicePublicId,contextDriftAnalysisPublicId,modelPackagePublicId,runtime:{frameWidth,frameHeight,pixelFormat},context});await refresh();}catch(e){alert(e.message);}
});
render();
})();