(() => {
'use strict';
if(window.GelatoGlassesUserPreferences)return;
const endpoint='api/glasses-user-preferences.php',ids=new Set([
  'modeSelect','deviceSelect','frameModeSelect','opticalMaskToggle',
  'cameraResolution','cameraFit','cameraMirror','simVoiceReplies',
  'hudOpacity','hudBrightness','hudScale','safeWidth','safeHeight',
  'leftEyeX','leftEyeY','rightEyeX','rightEyeY','leftWidth','rightWidth',
  'presetSelect'
]);
const $=id=>document.getElementById(id);
let enabled=false,loading=false,revision=0,timer=null,status='waiting',lastError='';
function bridge(){return window.GelatoGlassesDisplayPreferences;}
function setStatus(message,state='idle'){
  status=state;lastError=state==='error'?message:'';
  const target=$('sgvUserPrefsStatus');
  if(target){target.textContent=message;target.dataset.state=state;}
}
function queueSave(){
  if(!enabled||loading||!bridge()?.ready)return;
  clearTimeout(timer);timer=setTimeout(()=>void save(),550);
}
async function fetchPreferences(){
  const res=await fetch(endpoint,{method:'GET',credentials:'same-origin',cache:'no-store'});
  const data=await res.json().catch(()=>({ok:false}));
  if(!res.ok||!data.ok)throw new Error(data.message||'Unable to load your UI settings.');
  if(/^[a-f0-9]{64}$/i.test(String(data.csrfToken||'')))
    window.GELATO_GLASSES_SIMULATOR.csrf=data.csrfToken;
  revision=Number(data.revision||0);
  return data.settings||{};
}
async function save(){
  if(!enabled||loading)return;
  const snapshot=bridge()?.snapshot?.();if(!snapshot)return;
  // Deliberately omit microphone listening, permissions, voice content, live streams,
  // and photo data. User UI preferences cannot silently start capture on reload.
  loading=true;setStatus('Saving personal display settings…','saving');
  try{
    const csrf=window.GELATO_GLASSES_SIMULATOR?.csrf||'';
    const response=await fetch(endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',
      headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
      body:JSON.stringify({settings:snapshot,csrf_token:csrf})});
    const data=await response.json().catch(()=>({ok:false}));
    if(!response.ok||!data.ok){
      if(response.status===419)throw new Error('Security token expired. Refresh before saving settings.');
      throw new Error(data.message||'User display settings could not be saved.');
    }
    revision++;setStatus('Personal display settings saved','saved');
  }catch(error){setStatus(error.message||'Save failed','error');}
  finally{loading=false;}
}
async function boot(){
  if(!bridge()?.ready){
    await new Promise(resolve=>{
      const done=()=>resolve();
      window.addEventListener('gelato-glasses-preferences-ready',done,{once:true});
      if(bridge()?.ready)resolve();
    });
  }
  try{
    const prefs=await fetchPreferences();
    await bridge()?.apply?.(prefs);
    setStatus(revision?'Personal camera/glasses layout restored':'Ready to save personal camera/glasses settings','ready');
  }catch(error){
    setStatus((error.message||'Unable to load personal preferences')+' · Run upgrade.php if needed.','error');
  }finally{enabled=true;}
}
function mount(){
  const runtime=$('sgv-panel-runtime');
  const toolbar=runtime?.querySelector('.glasses-runtime-toolbar');
  if(toolbar){
    // Reuse the existing per-account API and snapshot; no second preferences card,
    // no new table, and no automatic microphone/camera startup.
    const group=document.createElement('div');
    group.className='glasses-runtime-save-ui';
    group.innerHTML='<button id="sgvSaveUserPrefs" type="button" title="Save personal camera, lens and tab layout settings">Save My UI Settings</button>'+
      '<span id="sgvUserPrefsStatus" role="status" aria-live="polite">Loading settings…</span>';
    toolbar.appendChild(group);
    $('sgvSaveUserPrefs').addEventListener('click',()=>void save());
  }
  document.addEventListener('change',event=>{
    if(ids.has(event.target?.id))queueSave();
  });
  document.addEventListener('input',event=>{
    if(ids.has(event.target?.id))queueSave();
  });
  window.addEventListener('gelato-glasses-tab-select',queueSave);
  window.addEventListener('gelato-camera-tab-select',queueSave);
  window.addEventListener('gelato-glasses-location-change',queueSave);
  void boot();
}
window.GelatoGlassesUserPreferences={save,queueSave,status:()=>({enabled,loading,revision,status,lastError})};
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount,{once:true});
else mount();
})();