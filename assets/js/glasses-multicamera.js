(() => {
  'use strict';
  if(window.GelatoGlassesMultiCamera)return;
  const MAX=5,AUX=[2,3,4,5],devices=new Map(),slots=new Map();
  const $=id=>document.getElementById(id);
  const safe=text=>String(text??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  function setStatus(slot,status,message) {
    const s=slots.get(slot);if(!s)return;s.status=status;s.state.textContent=message;
    s.state.dataset.state=status;updateSummary();
  }
  function updateSummary(){
    const n=Array.from(slots.values()).filter(s=>s.stream).length;
    const el=$('simMultiCameraCount');if(el)el.textContent=n+' / 4 AUX ACTIVE';
  }
  function stop(slot,{reason='OFF',quiet=false}={}){
    const s=slots.get(slot);if(!s)return;
    ++s.epoch;
    const stream=s.stream;s.stream=null;s.frozen=false;
    if(stream)for(const track of stream.getTracks())try{track.stop();}catch{}
    s.video.srcObject=null;s.video.hidden=true;s.freeze.disabled=true;s.start.disabled=false;s.stop.disabled=true;
    s.preview.hidden=false;
    if(!quiet)setStatus(slot,'off',reason);
    if(s.activeTraining){hideEditor();s.activeTraining=false;}
  }
  function stopAll(reason='OFF'){for(const slot of AUX)stop(slot,{reason});}
  function conflict(deviceId,slot){
    if(!deviceId)return 'Select a camera before starting.';
    const primary=window.GelatoGlassesTraining?.activePrimaryDeviceId||'';
    if(primary&&primary===deviceId)return 'That camera is already being used by Camera 1. Choose another device.';
    for(const [key,s] of slots)if(key!==slot&&s.stream&&s.device.value===deviceId)
      return 'Camera '+key+' is already using that device.';
    return '';
  }
  async function start(slot) {
    const s=slots.get(slot);if(!s||s.busy)return;
    if(window.GelatoCameraConfiguration?.isEnabled(slot)!==true){setStatus(slot,'blocked','Camera location is switched OFF. Enable it before starting.');return;}
    if(!navigator.mediaDevices?.getUserMedia){setStatus(slot,'failed','Browser camera API unavailable. Use HTTPS or localhost.');return;}
    const id=s.device.value,issue=conflict(id,slot);
    if(issue){setStatus(slot,'blocked',issue);return;}
    stop(slot,{quiet:true});const token=s.epoch;s.busy=true;s.start.disabled=true;
    setStatus(slot,'starting','Requesting Camera '+slot+' permission…');
    let stream=null;
    try{
      stream=await navigator.mediaDevices.getUserMedia({video:{deviceId:{exact:id},width:{ideal:1280},height:{ideal:720},frameRate:{ideal:15,max:30}},audio:false});
      if(token!==s.epoch||document.hidden||window.GelatoCameraConfiguration?.isEnabled(slot)!==true){stream.getTracks().forEach(t=>t.stop());return;}
      s.stream=stream;s.video.srcObject=stream;s.video.hidden=false;s.preview.hidden=true;
      await s.video.play();
      if(token!==s.epoch||document.hidden||window.GelatoCameraConfiguration?.isEnabled(slot)!==true){stop(slot,{reason:'PAUSED / DISABLED'});return;}
      s.stop.disabled=false;s.freeze.disabled=false;
      const videoTrack=stream.getVideoTracks()[0];
      videoTrack?.addEventListener?.('ended',()=>{if(s.stream===stream){stop(slot,{reason:'DEVICE DISCONNECTED'});window.GelatoCameraRuntime?.trackStopped?.(slot,'device_disconnected');}},{once:true});
      setStatus(slot,'ready','LIVE · '+(videoTrack?.label||'Camera '+slot));
    }catch(e){
      if(stream){stream.getTracks().forEach(t=>t.stop());if(s.stream===stream){s.stream=null;s.video.srcObject=null;s.video.hidden=true;s.preview.hidden=false;}}
      const name=String(e?.name||'');
      window.GelatoCameraRuntime?.trackStopped?.(slot,/NotAllowed|Security/.test(name)?'permission_denied':'start_failed');
      setStatus(slot,'failed',/NotAllowed|Security/.test(name)?'Permission denied. Allow this camera in the browser.':
        /NotFound|Overconstrained/.test(name)?'Device unavailable. Refresh the camera list.':
        'Camera could not start. Close other apps using this input.');
    }finally{s.busy=false;if(!s.stream)s.start.disabled=false;updateSummary();}
  }
  async function refreshDevices() {
    const opts=[];
    try{
      const all=await navigator.mediaDevices.enumerateDevices();
      for(const [index,d] of all.filter(d=>d.kind==='videoinput').entries())
        if(d.deviceId)opts.push({id:d.deviceId,label:d.label||'Camera '+(index+1)});
    }catch{for(const slot of AUX)setStatus(slot,'failed','Could not enumerate cameras. Check browser permissions.');}
    devices.clear();opts.forEach(d=>devices.set(d.id,d));
    for(const slot of AUX){
      const s=slots.get(slot),selected=s.device.value;
      s.device.replaceChildren();
      const placeholder=document.createElement('option');placeholder.value='';placeholder.textContent='Choose camera';s.device.append(placeholder);
      for(const opt of opts){
        const el=document.createElement('option');el.value=opt.id;el.textContent=opt.label;s.device.append(el);
      }
      if(devices.has(selected))s.device.value=selected;
    }
    const c=$('simMultiCameraCount');if(!opts.length&&c)c.textContent='NO CAMERA SOURCES DETECTED';
    else updateSummary();
  }
  let editor=null;
  function hideEditor(){
    const e=$('simCameraTraining');if(e)e.hidden=true;editor=null;
    for(const s of slots.values())s.activeTraining=false;
  }
  function overlayDraw(){
    if(!editor)return;
    const {overlay,boxes,draft}=editor;
    const w=overlay.width,h=overlay.height,ctx=overlay.getContext('2d');ctx.clearRect(0,0,w,h);
    ctx.lineWidth=Math.max(2,w/320);ctx.strokeStyle='#a9ffc4';ctx.font=Math.max(12,w/45)+'px sans-serif';
    for(const a of boxes){
      const b=a.bbox;ctx.strokeRect(b.x*w,b.y*h,b.width*w,b.height*h);
      ctx.fillStyle='rgba(8,20,12,.82)';const tw=ctx.measureText(a.label).width+12;
      ctx.fillRect(b.x*w,Math.max(0,b.y*h-25),tw,24);ctx.fillStyle='#a9ffc4';ctx.fillText(a.label,b.x*w+5,Math.max(15,b.y*h-7));
    }
    if(draft){ctx.setLineDash([8,5]);ctx.strokeStyle='#ffe6a7';ctx.strokeRect(draft.x*w,draft.y*h,draft.width*w,draft.height*h);ctx.setLineDash([]);}
    const save=$('simCameraTrainingSave');if(save)save.disabled=!boxes.length;
    const info=$('simCameraTrainingFeedback');
    if(info)info.textContent=boxes.length+' reviewed object(s) · Camera '+editor.slot+
      ' · drag to label, then Add Labeled Sample';
  }
  function pointerCoord(event){
    if(!editor)return null;
    const rect=editor.overlay.getBoundingClientRect();if(!rect.width||!rect.height)return null;
    return {x:Math.max(0,Math.min(1,(event.clientX-rect.left)/rect.width)),
      y:Math.max(0,Math.min(1,(event.clientY-rect.top)/rect.height))};
  }
  function beginBox(event){
    if(!editor||event.button!==0)return;
    const label=$('simCameraTrainingLabel')?.value?.trim();
    if(!label){$('simCameraTrainingFeedback').textContent='Enter an object class before drawing a box.';return;}
    const origin=pointerCoord(event);if(!origin)return;
    editor.pointer={id:event.pointerId,origin,label};
    try{editor.overlay.setPointerCapture(event.pointerId);}catch{}
    event.preventDefault();
  }
  function moveBox(event){
    if(!editor?.pointer||editor.pointer.id!==event.pointerId)return;
    const target=pointerCoord(event);if(!target)return;
    const o=editor.pointer.origin;
    editor.draft={x:Math.min(o.x,target.x),y:Math.min(o.y,target.y),
      width:Math.abs(target.x-o.x),height:Math.abs(target.y-o.y)};
    overlayDraw();
  }
  function endBox(event){
    if(!editor?.pointer||editor.pointer.id!==event.pointerId)return;
    moveBox(event);
    if(editor.draft&&editor.draft.width>.009&&editor.draft.height>.009)
      editor.boxes.push({label:editor.pointer.label,bbox:editor.draft});
    editor.pointer=null;editor.draft=null;overlayDraw();
  }
  function freeze(slot){
    const s=slots.get(slot);if(!s?.stream||!s.video.videoWidth||!s.video.videoHeight){
      setStatus(slot,'warning','Wait until the camera has a live frame.');return;
    }
    hideEditor();s.activeTraining=true;
    const frame=$('simCameraTrainingImage'),overlay=$('simCameraTrainingOverlay'),section=$('simCameraTraining');
    frame.width=overlay.width=s.video.videoWidth;frame.height=overlay.height=s.video.videoHeight;
    frame.getContext('2d').drawImage(s.video,0,0,frame.width,frame.height);
    editor={slot,frame,overlay,boxes:[],draft:null,pointer:null};
    section.hidden=false;overlayDraw();
    section.scrollIntoView?.({block:'nearest',behavior:'smooth'});
  }
  async function accept(){
    if(!editor||!editor.boxes.length)return;
    const button=$('simCameraTrainingSave');button.disabled=true;
    try{
      const result=await window.GelatoGlassesTraining?.acceptReviewedAuxiliaryFrame?.(
        editor.frame,editor.boxes,{slot:editor.slot});
      if(!result)throw new Error('Training dataset is not ready. Reload the simulator.');
      $('simCameraTrainingFeedback').textContent='Saved reviewed sample #'+result.index+
        ' with '+result.annotations+' label(s). Export the YOLO dataset or explicitly opt in to Vision Lab upload.';
      const from=editor.slot;slots.get(from).activeTraining=false;editor.boxes=[];overlayDraw();
    }catch(error){$('simCameraTrainingFeedback').textContent=error?.message||'Could not save sample.';overlayDraw();}
  }
  function mount(){
    const root=$('simAuxCameraSlots');if(!root)return;
    for(const slot of AUX){
      const card=document.createElement('article');card.className='sim-aux-camera';card.dataset.slot=String(slot);
      card.innerHTML='<header><strong>Camera '+slot+'</strong><span data-status data-state="off">OFF</span></header>'+
        '<label>Input device <select data-device aria-label="Camera '+slot+' input"><option value="">Choose camera</option></select></label>'+
        '<div class="sim-aux-preview"><video autoplay muted playsinline hidden aria-label="Camera '+slot+' preview"></video>'+
        '<p data-placeholder>Preview only · does not render glasses</p></div>'+
        '<div class="inline-actions"><button type="button" data-start>Start Camera</button>'+
        '<button type="button" data-stop disabled>Stop Camera</button></div>'+
        '<button type="button" data-freeze disabled>Freeze for Training</button>';
      root.appendChild(card);
      const s={card,device:card.querySelector('[data-device]'),video:card.querySelector('video'),
        preview:card.querySelector('[data-placeholder]'),state:card.querySelector('[data-status]'),
        start:card.querySelector('[data-start]'),stop:card.querySelector('[data-stop]'),
        freeze:card.querySelector('[data-freeze]'),stream:null,status:'off',epoch:0,busy:false,activeTraining:false,frozen:false};
      slots.set(slot,s);
      s.start.addEventListener('click',()=>void (window.GelatoCameraRuntime?.start?.(slot)||start(slot)));
      s.stop.addEventListener('click',()=>window.GelatoCameraRuntime?.stop?.(slot,'manual_stop')||stop(slot));
      s.freeze.addEventListener('click',()=>freeze(slot));
    }
    const panel=$('simMultiCameraPanel');
    const refresh=document.createElement('button');refresh.type='button';refresh.id='simCameraRefreshDevices';
    refresh.textContent='Refresh Camera List';refresh.addEventListener('click',()=>void refreshDevices());
    panel.insertBefore(refresh,root);
    const overlay=$('simCameraTrainingOverlay');
    overlay.addEventListener('pointerdown',beginBox);
    overlay.addEventListener('pointermove',moveBox);
    overlay.addEventListener('pointerup',endBox);
    overlay.addEventListener('pointercancel',endBox);
    $('simCameraTrainingUndo')?.addEventListener('click',()=>{editor?.boxes.pop();overlayDraw();});
    $('simCameraTrainingSave')?.addEventListener('click',()=>void accept());
    document.addEventListener('visibilitychange',()=>{if(document.hidden)stopAll('PAUSED · TAB HIDDEN');});
    window.addEventListener('pagehide',()=>stopAll('PAGE CLOSED'));
    navigator.mediaDevices?.addEventListener?.('devicechange',()=>void refreshDevices());
    void refreshDevices();
  }
  window.GelatoGlassesMultiCamera={MAX,start,stop,stopAll,refreshDevices,
    video:slot=>slots.get(slot)?.stream?slots.get(slot).video:null,
    status:()=>AUX.map(slot=>({slot,active:!!slots.get(slot)?.stream,state:slots.get(slot)?.status||'off'}))};
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount,{once:true});
  else mount();
})();