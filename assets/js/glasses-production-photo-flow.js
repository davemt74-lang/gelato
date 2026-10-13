(() => {
  'use strict';
  if(window.GelatoProductionPhotoFlow)return;
  const STEPS=[
    {stage:'pre_oven',slot:2,title:'Step 1 · Pre-Oven',status:'in_progress'},
    {stage:'post_oven',slot:3,title:'Step 2 · Post-Oven',status:'ready'}
  ];
  const $=id=>document.getElementById(id);
  const API='api/glasses-order-photo-stages.php';
  const state={locationId:0,seq:0,candidates:[],records:[],pending:new Map(),attempted:new Set(),
    automatic:{pre_oven:true,post_oven:true},busy:false,refreshing:false,lastRefresh:0};
  const safeNumber=n=>Number.isInteger(n)&&n>0?n:0;
  const config=()=>window.GelatoCameraConfiguration;
  const workspace=()=>window.GelatoCameraWorkspace;
  const token=()=>window.GELATO_GLASSES_SIMULATOR?.csrf||'';
  const note=(stage,message)=>{
    const target=$('gpfNote'+stage);if(target)target.textContent=message;
  };
  function candidates(stage){
    const needed=stage==='pre_oven'?'in_progress':'ready';
    return state.candidates.filter(c=>c.status===needed);
  }
  function eligibleAutomatic(stage){
    const list=candidates(stage);
    // An image can be present even when two tickets are active; never guess identity
    // when the stage has more than one candidate or one POS line has multiple pizzas.
    return list.length===1&&Number(list[0].quantity)===1?list[0]:null;
  }
  function record(stage,item){
    return state.records.find(x=>x.stage===stage&&x.kdsItemPublicId===item)||null;
  }
  function getPhoto(slot,key){
    const local=config()?.getPhotos?.(slot)||[];
    const saved=workspace()?.getSavedPhotos?.(slot)||[];
    return [...local,...saved].find(p=>
      (p.url?'local:'+p.capturedAt+':'+p.slot:'saved:'+p.id)===key)||null;
  }
  function listPhotos(stage){
    const step=STEPS.find(x=>x.stage===stage),select=$('gpfPhoto'+stage);
    if(!step||!select)return;
    const previous=select.value;select.replaceChildren();
    const placeholder=document.createElement('option');placeholder.value='';placeholder.textContent='Choose a stage photo';select.append(placeholder);
    const photos=[...(config()?.getPhotos?.(step.slot)||[]),...(workspace()?.getSavedPhotos?.(step.slot)||[])];
    for(const p of photos.slice().reverse().slice(0,60)){
      const option=document.createElement('option');option.value=p.url?'local:'+p.capturedAt+':'+p.slot:'saved:'+p.id;
      option.textContent=(p.id?'Saved':'Local')+' · '+new Date(p.capturedAt).toLocaleTimeString()+(p.menuItemName?' · '+p.menuItemName:'');
      select.append(option);
    }
    select.value=[...select.options].some(x=>x.value===previous)?previous:'';
    const candidate=state.pending.get(stage);
    if(candidate?.photo&&photos.includes(candidate.photo)){
      select.value=candidate.photo.url?'local:'+candidate.photo.capturedAt+':'+candidate.photo.slot:'saved:'+candidate.photo.id;
    }
    renderPhoto(stage);
  }
  function renderPhoto(stage){
    const step=STEPS.find(x=>x.stage===stage),photo=getPhoto(step.slot,$('gpfPhoto'+stage)?.value);
    const box=$('gpfPreview'+stage);if(!box)return;
    box.replaceChildren();
    if(photo){
      const img=document.createElement('img');
      img.src=photo.url||'api/glasses-vision-media.php?publicId='+encodeURIComponent(photo.mediaPublicId);
      img.alt=step.title+' candidate image';box.append(img);
      const label=document.createElement('span');
      label.textContent=photo.id?'Saved Vision Lab image':'Captured locally · requires storage consent';
      box.append(label);
    }else box.textContent='No photo selected — capture automatically or manually.';
  }
  function render(){
    for(const step of STEPS){
      const current=$('gpfItem'+step.stage);
      if(!current)continue;
      const previous=current.value;
      current.replaceChildren();
      const first=document.createElement('option');first.value='';first.textContent='Select exact KDS item';current.append(first);
      for(const candidate of state.candidates){
        const option=document.createElement('option');option.value=candidate.kdsItemPublicId;
        option.textContent='#'+candidate.checkNumber+' · '+candidate.name+' · '+candidate.status+
          (Number(candidate.quantity)>1?' · Qty '+candidate.quantity:'');
        current.append(option);
      }
      if([...current.options].some(x=>x.value===previous))current.value=previous;
      else if(eligibleAutomatic(step.stage))current.value=eligibleAutomatic(step.stage).kdsItemPublicId;
      const selected=current.value,done=record(step.stage,selected);
      const status=$('gpfState'+step.stage);
      if(status)status.textContent=done?done.status.toUpperCase()+' · rev '+done.revision:
        state.pending.get(step.stage)?.candidateId===selected?'CAPTURED · AWAITING CONFIRMATION':
        eligibleAutomatic(step.stage)?'AUTO READY':'MANUAL SELECTION';
      $('gpfReopen'+step.stage).disabled=!done||state.busy;
      $('gpfConfirm'+step.stage).disabled=!selected||!!done||state.busy;
      $('gpfBypass'+step.stage).disabled=!selected||!!done||state.busy;
      $('gpfCapture'+step.stage).disabled=!config()?.isEnabled(step.slot)||state.busy;
      const panel=$('gpfAuto'+step.stage);
      if(panel)panel.checked=state.automatic[step.stage];
      listPhotos(step.stage);
    }
    const root=$('gpfTimeline');if(root){
      root.replaceChildren();
      if(!state.records.length){root.textContent='No stage photos or bypasses recorded yet.';}
      for(const row of state.records.slice(0,55)){
        const line=document.createElement('article');line.className='gpf-event';
        const heading=document.createElement('strong');
        heading.textContent='#'+row.checkNumber+' · '+(row.stage==='pre_oven'?'Pre-Oven':'Post-Oven')+' · '+row.status;
        const meta=document.createElement('span');
        meta.textContent=new Date(row.reviewedAt.replace(' ','T')).toLocaleString()+' · '+row.reviewedBy+
          (row.bypassReason?' · '+row.bypassReason:'');
        line.append(heading,meta);
        if(row.status==='confirmed'&&row.mediaPublicId){
          const img=document.createElement('img');img.alt='Confirmed pizza stage photo';
          img.src='api/glasses-vision-media.php?publicId='+encodeURIComponent(row.mediaPublicId);img.loading='lazy';line.append(img);
        }
        root.append(line);
      }
    }
  }
  async function refresh({silent=false}={}){
    const location=safeNumber(config()?.locationId);
    if(location!==state.locationId){
      state.locationId=location;state.pending.clear();state.attempted.clear();
      for(const step of STEPS){const el=$('gpfItem'+step.stage);if(el)el.value='';}
    }
    if(!location||state.refreshing)return;
    const epoch=state.seq;
    state.refreshing=true;
    try{
      const r=await fetch(API+'?locationId='+encodeURIComponent(location),{
        method:'GET',credentials:'same-origin',cache:'no-store'});
      const d=await r.json().catch(()=>({ok:false}));
      if(epoch!==state.seq||location!==state.locationId)return;
      if(!r.ok||!d.ok)throw new Error(d.message||'Production timeline unavailable.');
      if(/^[0-9a-f]{64}$/i.test(String(d.csrfToken||'')))window.GELATO_GLASSES_SIMULATOR.csrf=d.csrfToken;
      state.candidates=d.timeline.candidates||[];state.records=d.timeline.records||[];
      state.lastRefresh=Date.now();render();
      if(!silent)note('pre_oven','KDS timeline synchronized. Automatic capture proposes a match; confirm the actual pizza.');
      void automaticTick();
    }catch(e){if(!silent)note('pre_oven',e.message||'Unable to refresh photo stages.');}
    finally{state.refreshing=false;}
  }
  async function post(data){
    const r=await fetch(API,{method:'POST',credentials:'same-origin',cache:'no-store',
      headers:{'Content-Type':'application/json','X-CSRF-Token':token()},
      body:JSON.stringify({...data,csrf_token:token(),locationId:state.locationId})});
    const result=await r.json().catch(()=>({ok:false}));
    if(!r.ok||!result.ok)throw new Error(r.status===419?
      'Security token expired. Refresh the stage timeline, then repeat the action manually.':
      result.message||'Stage action failed.');
    return result;
  }
  async function capture(step,{automatic=false}={}){
    if(!config()?.isEnabled(step.slot))throw new Error('Camera '+step.slot+' is OFF. Enable it first.');
    const before=new Set(config()?.getPhotos?.(step.slot)||[]);
    const photo=await config().capture(step.slot);
    if(!photo||before.has(photo)||photo.locationId!==state.locationId)throw new Error('Camera did not produce a valid frame.');
    const key=automatic?'AUTO':'MANUAL';
    note(step.stage,key+' capture taken at '+new Date(photo.capturedAt).toLocaleTimeString()+
      '. Review the photo and confirm its KDS item, or bypass this step.');
    listPhotos(step.stage);
    return photo;
  }
  async function automaticTick(){
    if(document.hidden||state.busy||state.refreshing||!state.locationId)return;
    for(const step of STEPS){
      if(!state.automatic[step.stage]||!config()?.isEnabled(step.slot))continue;
      const candidate=eligibleAutomatic(step.stage);
      if(!candidate||record(step.stage,candidate.kdsItemPublicId))continue;
      if(!config()?.isStreaming?.(step.slot))continue;
      const cameraPolicy=window.GelatoCameraRuntime?.policy?.(step.slot);
      // Require an observed scene change followed by stable, quality-checked frames.
      // This is NOT a trained pizza detection or an assertion of item identity.
      if(!cameraPolicy?.sceneCandidate||cameraPolicy.quality<45)continue;
      const id=step.stage+':'+candidate.kdsItemPublicId;
      if(state.attempted.has(id))continue;
      if(!window.GelatoCameraRuntime?.consumeCapture?.(step.slot))continue;
      state.attempted.add(id);
      try{
        const photo=await capture(step,{automatic:true});
        if(photo.locationId!==state.locationId)return;
        state.pending.set(step.stage,{candidateId:candidate.kdsItemPublicId,photo});
        $('gpfItem'+step.stage).value=candidate.kdsItemPublicId;
        listPhotos(step.stage);
        note(step.stage,'Stable scene candidate (not verified pizza detection), photo quality '+cameraPolicy.quality+
          '/100, sole eligible KDS ticket #'+candidate.checkNumber+'. Confirm pizza identity manually.');
      }catch(e){state.attempted.delete(id);note(step.stage,e.message||'Auto capture paused.');}
    }
  }
  async function take(stage){
    const step=STEPS.find(x=>x.stage===stage);if(!step)return;
    try{
      const photo=await capture(step);
      const item=$('gpfItem'+stage).value;
      if(item)state.pending.set(stage,{candidateId:item,photo});
      listPhotos(stage);
    }catch(e){note(stage,e.message||'Capture failed.');}
  }
  async function confirmStage(stage){
    const step=STEPS.find(x=>x.stage===stage),id=$('gpfItem'+stage).value;
    const chosen=state.candidates.find(x=>x.kdsItemPublicId===id);
    if(!chosen)return note(stage,'Select a live, exact kitchen item first.');
    const photo=getPhoto(step.slot,$('gpfPhoto'+stage).value);
    if(!photo)return note(stage,'Capture or choose a photo from the correct camera.');
    if(record(stage,id))return note(stage,'This stage was already resolved. Reopen it before changing the link.');
    if(!photo.id&&!$('gpfOptIn'+stage).checked)
      return note(stage,'Check private photo storage consent before confirming.');
    if(state.busy)return;
    const location=state.locationId,epoch=state.seq;state.busy=true;render();
    try{
      const saved=await workspace().persistOrderPhoto(photo,chosen.menuItemId,{optIn:!!$('gpfOptIn'+stage).checked});
      if(location!==state.locationId||epoch!==state.seq)throw new Error('Location changed. Photo was saved but not linked.');
      await post({action:'confirm',stage,kdsItemPublicId:id,expectedRevision:0,photoLibraryId:saved.id});
      state.pending.delete(stage);$('gpfOptIn'+stage).checked=false;
      note(stage,'Confirmed photo association for ticket #'+chosen.checkNumber+'. No POS state or customer notification changed.');
      await refresh({silent:true});
    }catch(e){note(stage,e.message||'Photo could not be confirmed.');}
    finally{state.busy=false;render();}
  }
  async function changeStage(stage,action){
    const item=$('gpfItem'+stage).value,found=record(stage,item);
    if(!item)return note(stage,'Choose a live kitchen item before bypassing.');
    if(action==='bypass'&&found)return note(stage,'This stage is already completed. Reopen it to change the decision.');
    if(action==='reopen'&&!found)return note(stage,'No completed stage to reopen.');
    const reason=$('gpfBypassReason'+stage).value.trim();
    if(action==='bypass'&&(reason.length<5||reason.length>250))
      return note(stage,'Enter a meaningful bypass reason (5–250 characters).');
    if(state.busy)return;
    state.busy=true;render();
    try{
      await post({action,stage,kdsItemPublicId:item,expectedRevision:found?.revision||0,bypassReason:reason});
      state.pending.delete(stage);
      if(action==='bypass')state.attempted.add(stage+':'+item);
      else state.attempted.delete(stage+':'+item);
      note(stage,action==='bypass'?'Stage bypassed and audited; the order can proceed without a photo.':
        'Stage reopened. Manual or automatic capture can run again.');
      await refresh({silent:true});
    }catch(e){note(stage,e.message||'Could not update production stage.');}
    finally{state.busy=false;render();}
  }
  function onWork(e){
    if(e.detail?.locationId!==state.locationId)return;
    state.candidates=Array.isArray(e.detail?.items)?
      e.detail.items.filter(x=>['queued','in_progress','ready'].includes(x.status)).map(x=>({
        kdsItemPublicId:x.kdsItemPublicId,status:x.status,checkNumber:x.ticket?.checkNumber||'?',
        checkPublicId:x.ticket?.checkPublicId||'',name:x.posLine?.name||'',quantity:x.posLine?.quantity||1,
        menuItemId:x.posLine?.menuItemId||null
      })):state.candidates;
    render();void automaticTick();
  }
  function mount(){
    for(const step of STEPS){
      const view=$('gcPane'+step.slot);if(!view)continue;
      const box=document.createElement('section');box.className='gpf-step';
      box.innerHTML='<div class="gc-heading"><strong>'+step.title+' · Live KDS Photo</strong>'+
        '<span id="gpfState'+step.stage+'">WAITING</span></div>'+
        '<label class="gpf-check"><input type="checkbox" id="gpfAuto'+step.stage+'" checked>'+
        ' Auto-capture when exactly one item reaches this stage</label>'+
        '<label>Live ticket / pizza <select id="gpfItem'+step.stage+'"><option>Select kitchen item</option></select></label>'+
        '<label>Photo <select id="gpfPhoto'+step.stage+'"><option>Select photo</option></select></label>'+
        '<div id="gpfPreview'+step.stage+'" class="gpf-preview">Capture a photo first.</div>'+
        '<label class="gpf-check"><input id="gpfOptIn'+step.stage+'" type="checkbox">'+
        ' I approve private Vision Lab photo storage for this confirmed order item</label>'+
        '<div class="inline-actions"><button id="gpfCapture'+step.stage+'" type="button">Capture Now</button>'+
        '<button id="gpfConfirm'+step.stage+'" type="button">Confirm Photo & Ticket</button></div>'+
        '<label>Bypass reason <input type="text" id="gpfBypassReason'+step.stage+'" maxlength="250" '+
        'placeholder="Camera unavailable, already passed stage, etc."></label>'+
        '<div class="inline-actions"><button id="gpfBypass'+step.stage+'" type="button">Bypass '+step.title.split(' · ')[0]+'</button>'+
        '<button id="gpfReopen'+step.stage+'" type="button">Reopen Step</button></div>'+
        '<p id="gpfNote'+step.stage+'" class="sim-muted" role="status" aria-live="polite">'+
        'Automatic capture requires an active, enabled camera. Order matching must be confirmed.</p>';
      view.prepend(box);
      const auto=$('gpfAuto'+step.stage),preference='gelato-production-auto-'+step.stage+'-'+(state.locationId||0);
      try{
        const saved=localStorage.getItem(preference);
        state.automatic[step.stage]=saved===null?true:saved==='true';
      }catch{}
      auto.checked=state.automatic[step.stage];
      auto.addEventListener('change',()=>{
        state.automatic[step.stage]=auto.checked;
        try{localStorage.setItem('gelato-production-auto-'+step.stage+'-'+state.locationId,String(auto.checked));}catch{}
        if(auto.checked)void automaticTick();
      });
      $('gpfItem'+step.stage).addEventListener('change',()=>{state.pending.delete(step.stage);render();});
      $('gpfPhoto'+step.stage).addEventListener('change',()=>renderPhoto(step.stage));
      $('gpfCapture'+step.stage).addEventListener('click',()=>void take(step.stage));
      $('gpfConfirm'+step.stage).addEventListener('click',()=>void confirmStage(step.stage));
      $('gpfBypass'+step.stage).addEventListener('click',()=>void changeStage(step.stage,'bypass'));
      $('gpfReopen'+step.stage).addEventListener('click',()=>void changeStage(step.stage,'reopen'));
    }
    const overview=$('sgv-panel-overview');
    if(overview){
      const panel=document.createElement('section');panel.className='console-card gpf-timeline';
      panel.innerHTML='<div class="console-heading"><div><small>PRODUCTION PHOTOS</small>'+
        '<strong>Order Photo Timeline</strong></div><button id="gpfRefresh" type="button">Refresh</button></div>'+
        '<div id="gpfTimeline" class="gpf-history">Loading approved production photos…</div>';
      overview.appendChild(panel);
      $('gpfRefresh').addEventListener('click',()=>void refresh());
    }
    window.addEventListener('gelato-glasses-location-change',()=>{
      state.seq++;state.pending.clear();state.attempted.clear();
      const loc=safeNumber(config()?.locationId);state.locationId=loc;
      for(const step of STEPS){
        try{const saved=localStorage.getItem('gelato-production-auto-'+step.stage+'-'+loc);
          state.automatic[step.stage]=saved===null?true:saved==='true';}catch{}
      }
      void refresh();
    });
    window.addEventListener('gelato-glasses-work-change',onWork);
    window.addEventListener('gelato-camera-photo-captured',()=>{for(const s of STEPS)listPhotos(s.stage);});
    window.addEventListener('gelato-camera-runtime-ready',()=>void automaticTick());
    window.addEventListener('pagehide',()=>{state.seq++;state.pending.clear();});
    setInterval(()=>{if(!document.hidden&&state.locationId&&!state.busy&&Date.now()-state.lastRefresh>7500)
      void refresh({silent:true});else void automaticTick();},2200);
    state.locationId=safeNumber(config()?.locationId);
    if(state.locationId)void refresh();
  }
  window.GelatoProductionPhotoFlow={mount,refresh,
    policy:{stages:STEPS,automaticRequiresUniqueItem:true,manualConfirmationRequired:true,
      bypassRequiresReason:true,automaticCustomerDelivery:false},
    status:()=>({locationId:state.locationId,pending:state.pending.size,confirmed:state.records.filter(r=>r.status==='confirmed').length,
      bypassed:state.records.filter(r=>r.status==='bypassed').length})};
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount,{once:true});
  else mount();
})();