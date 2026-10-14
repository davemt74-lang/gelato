(() => {
  'use strict';
  if(window.GelatoCameraConfiguration)return;
  const MAX=5;
  const roles={prep:'Prep / Glasses',pre_oven:'Pre-Oven',post_oven:'Post-Oven',cut_box:'Cut & Box',expo:'Expo',custom:'Custom'};
  const defaultRoles=['prep','pre_oven','post_oven','cut_box','expo'];
  const $=id=>document.getElementById(id);
  const state={locationId:null,canManage:false,stations:[],ready:false,slots:new Map(),photos:[],seq:0};
  const limited=(n,min,max)=>Math.min(max,Math.max(min,Number(n)||0));
  const defaults=slot=>({slot,displayName:roles[defaultRoles[slot-1]],role:defaultRoles[slot-1],
    enabled:false,stationPublicId:null,rotation:0,revision:0,zone:{x:.1,y:.1,width:.8,height:.8}});
  const copy=v=>JSON.parse(JSON.stringify(v));
  const setNote=(slot,message)=>{const node=$('camConfigMessage'+slot);if(node)node.textContent=message;};
  const cfg=()=>window.GELATO_GLASSES_SIMULATOR||{};
  function isEnabled(slot){return state.ready&&state.slots.get(slot)?.saved?.enabled===true;}
  function announceConfig(){
    window.dispatchEvent(new CustomEvent('gelato-camera-configuration-changed',
      {detail:{locationId:state.locationId,slots:Array.from(state.slots.values()).map(x=>({slot:x.saved.slot,enabled:state.ready&&x.saved.enabled===true}))}}));
  }
  function stop(slot){
    if(slot===1)window.GelatoGlassesPrimaryCamera?.stop?.();
    else window.GelatoGlassesMultiCamera?.stop?.(slot);
  }
  function stopAll(){for(let slot=1;slot<=MAX;slot++)stop(slot);}
  function cameraSource(slot){
    if(slot===1)return state.ready&&window.GelatoGlassesPrimaryCamera?.active?.()?$('cameraVideo'):null;
    return window.GelatoGlassesMultiCamera?.video?.(slot)||null;
  }
  function normalizeZone(box){
    const x=limited(box.x,0,.95),y=limited(box.y,0,.95);
    return {x,y,width:limited(box.width,.05,1-x),height:limited(box.height,.05,1-y)};
  }
  function zonePreview(slot){
    const value=state.slots.get(slot)?.draft,area=$('cameraZone'+slot);
    if(!value||!area)return;
    const b=value.zone;
    area.style.left=b.x*100+'%';area.style.top=b.y*100+'%';
    area.style.width=b.width*100+'%';area.style.height=b.height*100+'%';
    area.title='Capture zone · drag to reposition';
    for(const key of ['x','y','width','height']){
      const input=$('cam'+slot+key),out=$('cam'+slot+key+'Value');
      if(input&&document.activeElement!==input)input.value=String(Math.round(b[key]*100));
      if(out)out.textContent=Math.round(b[key]*100)+'%';
    }
  }
  function render(slot){
    const item=state.slots.get(slot);
    if(!item)return;
    const d=item.draft;
    const enabled=$('camEnabled'+slot);if(enabled){enabled.checked=!!d.enabled;enabled.disabled=!state.canManage||!state.ready;}
    const name=$('camName'+slot);if(name)name.value=d.displayName;
    const role=$('camRole'+slot);if(role)role.value=d.role;
    const rotation=$('camRotation'+slot);if(rotation)rotation.value=String(d.rotation);
    const station=$('camStation'+slot);
    if(station){
      const selected=d.stationPublicId||'';station.replaceChildren();
      const base=document.createElement('option');base.value='';base.textContent='Unassigned';station.appendChild(base);
      for(const st of state.stations){
        const option=document.createElement('option');option.value=st.publicId;option.textContent=st.name;
        station.appendChild(option);
      }
      station.value=selected;
    }
    for(const e of item.panel.querySelectorAll('input,select,button[data-config-save],button[data-config-capture]')){
      if(e.id!=='camEnabled'+slot)e.disabled=!state.ready||(!state.canManage&&e.dataset.configCapture!=='1');
    }
    zonePreview(slot);
    const action=$('camCapture'+slot);if(action)action.disabled=!isEnabled(slot)||!cameraSource(slot);
    if(slot===1){const button=$('startCamera');if(button&&!window.GelatoGlassesPrimaryCamera?.active?.())button.disabled=!isEnabled(slot);}
    else {const start=document.querySelector('.sim-aux-camera[data-slot="'+slot+'"] [data-start]');if(start)start.disabled=!isEnabled(slot)||!!window.GelatoGlassesMultiCamera?.video?.(slot);}
    setNote(slot,state.ready?(d.enabled?'Enabled · select Start Camera to begin capture':'Off · browser camera cannot start until enabled'):'Select a restaurant location to configure cameras.');
  }
  function createPanel(slot,container){
    const wrapper=document.createElement('div');
    wrapper.className='camera-setup-controls';wrapper.dataset.configSlot=String(slot);
    wrapper.innerHTML=
      '<div class="camera-setup-head"><strong>Camera '+slot+' · production stage</strong>'+
      '<label class="cam-onoff"><input type="checkbox" id="camEnabled'+slot+'"> <span>On / Off</span></label></div>'+
      '<label>Name <input id="camName'+slot+'" maxlength="100" autocomplete="off"></label>'+
      '<div class="camera-setup-fields"><label>Role <select id="camRole'+slot+'"></select></label>'+
      '<label>Station <select id="camStation'+slot+'"></select></label></div>'+
      '<div class="camera-setup-fields"><label>Rotation <select id="camRotation'+slot+'">'+
      '<option value="0">0°</option><option value="90">90°</option><option value="180">180°</option><option value="270">270°</option></select></label>'+
      '<div class="sim-muted">Capture zone · drag the outline or use sliders</div></div>'+
      '<div class="camera-setup-zones">'+['x','y','width','height'].map(key=>
        '<label>'+({x:'Left',y:'Top',width:'Width',height:'Height'}[key])+
        ' <input type="range" min="0" max="100" step="1" id="cam'+slot+key+'">'+
        '<output id="cam'+slot+key+'Value">0%</output></label>').join('')+'</div>'+
      '<button type="button" id="camEdit'+slot+'">Edit Capture Zone</button>'+ 
      '<div class="inline-actions"><button type="button" id="camSave'+slot+'" data-config-save="1">Save Setup</button>'+
      '<button type="button" id="camCapture'+slot+'" data-config-capture="1">Test Photo</button></div>'+
      '<div class="sim-muted" id="camConfigMessage'+slot+'" role="status"></div>';
    for(const key of Object.keys(roles)){
      const option=document.createElement('option');option.value=key;option.textContent=roles[key];
      wrapper.querySelector('#camRole'+slot).appendChild(option);
    }
    container.prepend(wrapper);
    const record={saved:defaults(slot),draft:defaults(slot),panel:wrapper,zone:null};
    state.slots.set(slot,record);
    $('camName'+slot).addEventListener('input',e=>{record.draft.displayName=e.target.value;});
    $('camRole'+slot).addEventListener('change',e=>{record.draft.role=e.target.value;});
    $('camStation'+slot).addEventListener('change',e=>{record.draft.stationPublicId=e.target.value||null;});
    $('camRotation'+slot).addEventListener('change',e=>{record.draft.rotation=Number(e.target.value);});
    for(const key of ['x','y','width','height']){
      $('cam'+slot+key).addEventListener('input',e=>{
        record.draft.zone[key]=Number(e.target.value)/100;
        record.draft.zone=normalizeZone(record.draft.zone);zonePreview(slot);
      });
    }
    $('camEnabled'+slot).addEventListener('change',async e=>{
      if(!e.target.checked)stop(slot);
      record.draft.enabled=e.target.checked;
      await save(slot);
    });
    $('camEdit'+slot).addEventListener('click',()=>{
      const preview=slot===1?$('glassesStage'):container.querySelector('.sim-aux-preview');
      if(!preview)return;
      const editing=preview.classList.toggle('camera-zone-editing');
      $('camEdit'+slot).textContent=editing?'Finish Zone Editing':'Edit Capture Zone';
      setNote(slot,editing?'Drag the capture outline, then Save Setup.':'Zone editing disabled. Save Setup to persist your changes.');
    });
    $('camSave'+slot).addEventListener('click',()=>void save(slot));
    $('camCapture'+slot).addEventListener('click',()=>void capture(slot));
    const preview=slot===1?$('glassesStage'):container.querySelector('.sim-aux-preview');
    if(preview){
      if(getComputedStyle(preview).position==='static')preview.style.position='relative';
      const frame=document.createElement('div');frame.id='cameraZone'+slot;frame.className='camera-capture-zone';
      frame.setAttribute('aria-label','Camera '+slot+' capture zone');
      preview.appendChild(frame);record.zone=frame;
      frame.addEventListener('pointerdown',event=>{
        if(event.button!==0||!state.canManage||!state.ready)return;
        const bounds=preview.getBoundingClientRect();if(!bounds.width||!bounds.height)return;
        const x=event.clientX,y=event.clientY,initial=copy(record.draft.zone);
        const move=e=>{
          const dx=(e.clientX-x)/bounds.width,dy=(e.clientY-y)/bounds.height;
          record.draft.zone.x=limited(initial.x+dx,0,1-initial.width);
          record.draft.zone.y=limited(initial.y+dy,0,1-initial.height);
          zonePreview(slot);
        };
        const finish=()=>{frame.removeEventListener('pointermove',move);frame.removeEventListener('pointerup',finish);frame.removeEventListener('pointercancel',finish);};
        frame.setPointerCapture(event.pointerId);
        frame.addEventListener('pointermove',move);frame.addEventListener('pointerup',finish);frame.addEventListener('pointercancel',finish);
        event.preventDefault();event.stopPropagation();
      });
    }
    render(slot);
  }
  async function load(locationId){
    if(!Number.isInteger(locationId)||locationId<1){
      state.ready=false;state.locationId=null;stopAll();
      for(let i=1;i<=MAX;i++)render(i);
      announceConfig();return;
    }
    if(state.locationId!==locationId)stopAll();
    const epoch=++state.seq;
    state.ready=false;state.locationId=locationId;
    try{
      const response=await fetch('api/glasses-camera-locations.php?locationId='+encodeURIComponent(locationId),
        {method:'GET',credentials:'same-origin',cache:'no-store'});
      const data=await response.json();
      if(epoch!==state.seq)return;
      if(!response.ok||!data.ok)throw new Error(data.message||'Could not load camera settings.');
      if(/^[a-f0-9]{64}$/i.test(String(data.csrfToken||'')))cfg().csrf=data.csrfToken;
      state.stations=data.configuration.stations||[];
      state.canManage=!!data.configuration.canManage;
      state.ready=true;
      for(const remote of data.configuration.cameras||[]){
        const target=state.slots.get(remote.slot);if(!target)continue;
        target.saved=copy(remote);target.draft=copy(remote);render(remote.slot);
      }
      const badge=$('simCameraConfigHealth');if(badge)badge.textContent='CONFIG LOADED';
      announceConfig();
    }catch(error){
      state.ready=false;state.canManage=false;
      const badge=$('simCameraConfigHealth');if(badge)badge.textContent='UPGRADE / SETUP NEEDED';
      for(let i=1;i<=MAX;i++){render(i);setNote(i,error.message||'Configuration unavailable.');}
      announceConfig();
    }
  }
  async function save(slot){
    const record=state.slots.get(slot);
    if(!state.ready||!state.canManage||!record)return false;
    const locationId=state.locationId,epoch=state.seq;
    const body={action:'save',csrf_token:cfg().csrf,locationId,slot,
      ...copy(record.draft),expectedRevision:record.saved.revision||0};
    try{
      const response=await fetch('api/glasses-camera-locations.php',{
        method:'POST',credentials:'same-origin',cache:'no-store',
        headers:{'Content-Type':'application/json','X-CSRF-Token':cfg().csrf},
        body:JSON.stringify(body)
      });
      const data=await response.json();
      if(epoch!==state.seq||locationId!==state.locationId)return false;
      if(!response.ok||!data.ok)throw new Error(data.message||'Camera settings were not saved.');
      for(const next of data.configuration.cameras||[]){
        const item=state.slots.get(next.slot);if(!item)continue;
        item.saved=copy(next);if(next.slot===slot)item.draft=copy(next);
        render(next.slot);
      }
      setNote(slot,'Saved to restaurant location · camera '+slot+' · '+(record.saved.enabled?'ON':'OFF'));
      announceConfig();
      return true;
    }catch(error){
      // Never revive capture after a rejected write: default to off and require reload.
      stop(slot);record.draft.enabled=false;record.saved.enabled=false;render(slot);announceConfig();
      setNote(slot,'Save failed: '+error.message+'. Camera remains OFF. Reload settings before retrying.');
      return false;
    }
  }
  async function capture(slot){
    if(!isEnabled(slot))return setNote(slot,'Camera location is OFF. Enable it before capture.');
    const src=cameraSource(slot);
    if(!src||!src.videoWidth||!src.videoHeight)return setNote(slot,'Start this camera and wait for a live frame.');
    const c=state.slots.get(slot).saved,zone=c.zone,rotation=c.rotation,epoch=state.seq,locationId=state.locationId;
    const canvas=document.createElement('canvas'),srcW=src.videoWidth,srcH=src.videoHeight;
    const cropW=Math.max(1,Math.round(srcW*zone.width)),cropH=Math.max(1,Math.round(srcH*zone.height));
    canvas.width=rotation%180===0?cropW:cropH;
    canvas.height=rotation%180===0?cropH:cropW;
    const ctx=canvas.getContext('2d');
    ctx.translate(canvas.width/2,canvas.height/2);
    ctx.rotate(rotation*Math.PI/180);
    ctx.drawImage(src,Math.round(zone.x*srcW),Math.round(zone.y*srcH),cropW,cropH,
      -cropW/2,-cropH/2,cropW,cropH);
    const blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/jpeg',.92));
    if(!blob)return setNote(slot,'Could not encode the frame.');
    if(epoch!==state.seq||locationId!==state.locationId||!isEnabled(slot))return;
    const url=URL.createObjectURL(blob),photo={
      url,slot,role:c.role,locationId:state.locationId,stationPublicId:c.stationPublicId||null,
      capturedAt:new Date().toISOString(),width:canvas.width,height:canvas.height,blob
    };
    state.photos.push(photo);while(state.photos.length>45){const old=state.photos.shift();URL.revokeObjectURL(old.url);}
    window.dispatchEvent(new CustomEvent('gelato-camera-photo-captured',{detail:{photo}}));
    const gallery=$('simCameraPhotoHistory');
    if(gallery){
      const card=document.createElement('div');card.className='camera-photo-entry';
      const image=document.createElement('img');image.src=url;image.alt='Camera '+slot+' test capture';
      const label=document.createElement('span');
      label.textContent='Camera '+slot+' · '+c.role+' · '+new Date(photo.capturedAt).toLocaleTimeString();
      const button=document.createElement('button');button.type='button';button.textContent='Save photo';
      button.addEventListener('click',()=>{
        const a=document.createElement('a');a.href=url;a.download='gelato-camera-'+slot+'-'+photo.capturedAt.replace(/[:.]/g,'-')+'.jpg';a.click();
      });
      card.append(image,label,button);gallery.prepend(card);
      while(gallery.children.length>10)gallery.lastElementChild.remove();
    }
    setNote(slot,'Captured to Camera '+slot+' history at '+new Date(photo.capturedAt).toLocaleTimeString()+
      '. To save the image permanently, review it in Training and explicitly opt in to Vision Lab storage.');
    return photo;
  }
  function mount(){
    const cameraCard=$('cameraDeviceSelect')?.closest('.camera-card');
    if(!cameraCard)return;
    const headline=document.createElement('p');headline.id='simCameraConfigHealth';headline.className='sim-muted';
    headline.textContent='Waiting for authorized location / camera configuration';
    cameraCard.prepend(headline);
    const primary=document.createElement('section');primary.className='sim-camera-setup-primary';
    const picker=$('cameraDeviceSelect')?.closest('label');
    if(picker)picker.before(primary);else cameraCard.prepend(primary);
    createPanel(1,primary);
    for(let slot=2;slot<=5;slot++){
      const auxiliary=document.querySelector('.sim-aux-camera[data-slot="'+slot+'"]');
      if(auxiliary)createPanel(slot,auxiliary);
    }
    const history=document.createElement('section');history.className='sim-camera-photo-history';
    history.innerHTML='<div class="console-heading"><div><small>LOCAL TEST CAPTURES</small><strong>Camera photo history</strong></div></div>'+
      '<p class="calibration-note">Manual test captures only. Nothing is uploaded or attached to customer orders.</p>'+
      '<div id="simCameraPhotoHistory" class="sim-camera-photo-grid"></div>';
    cameraCard.appendChild(history);
    window.addEventListener('gelato-glasses-location-change',event=>{void load(Number(event.detail?.locationId));});
    const current=Number(window.GelatoGlassesNavigation?.context?.()?.locationId||0);
    if(current)void load(current);
    window.addEventListener('pagehide',()=>{
      stopAll();for(const p of state.photos)URL.revokeObjectURL(p.url);state.photos=[];
    });
  }
  window.GelatoCameraConfiguration={MAX,isEnabled,stopAll,load,save,capture,
    isStreaming:slot=>!!cameraSource(slot)&&!!(cameraSource(slot)?.videoWidth),
    getPhotos:slot=>state.photos.filter(p=>slot===undefined||p.slot===slot).slice(),
    get locationId(){return state.locationId;},
    status:()=>({locationId:state.locationId,ready:state.ready,slots:Array.from(state.slots.values()).map(x=>({slot:x.saved.slot,enabled:x.saved.enabled,role:x.saved.role}))})};
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount,{once:true});
  else mount();
})();