(() => {
  'use strict';
  if(window.GelatoCameraWorkspace)return;
  const S={active:'camera1',locationId:0,photos:[],menu:[],local:[],busy:false,seq:0};
  const $=id=>document.getElementById(id);
  const controls={};
  const api='api/glasses-camera-photo-library.php';
  const mediaApi='api/glasses-vision-media.php';
  const core=()=>window.GELATO_GLASSES_SIMULATOR||{};
  const metric=()=>window.GelatoCameraSimilarity;
  const bySlot=(slot)=>S.photos.filter(p=>p.slot===slot).concat(S.local.filter(p=>p.slot===slot));
  function text(id,value){const e=$(id);if(e)e.textContent=value;}
  function select(name){
    if(!controls[name])return;
    S.active=name;
    for(const [key,c] of Object.entries(controls)){
      c.button.setAttribute('aria-selected',key===name?'true':'false');
      c.button.tabIndex=key===name?0:-1;c.pane.hidden=key!==name;
    }
  }
  function feedback(msg){text('gcTrainingFeedback',msg);}
  function canSave(){return core().canManageMedia===true&&S.locationId>0;}
  function idOf(photo){return photo?.id?'remote:'+photo.id:'local:'+photo?.capturedAt+':'+photo?.slot;}
  function qualityLabel(photo){
    if(photo?.fingerprint?.qualityScore!==undefined)return photo.fingerprint.qualityScore+'/100';
    const q=photo?.quality||{},brightness=Number(q.brightness),contrast=Number(q.contrast),blur=Number(q.blur);
    if(Number.isFinite(brightness)&&Number.isFinite(contrast)&&Number.isFinite(blur)&&q.brightness!==null&&q.contrast!==null&&q.blur!==null){
      const exposure=Math.max(0,1-Math.abs(brightness-.52)/.52);
      const score=Math.round(100*(exposure*.3+Math.min(1,contrast/.17)*.3+Math.min(1,(blur/400)/.11)*.4));
      return score+'/100 · '+(q.state||'review');
    }
    return q.state?'Vision Lab: '+q.state:'Not scored';
  }
  function match(photo){
    const hash=photo?.fingerprint?.hash||photo?.hash;
    const results=metric()?.compare?.(hash,S.photos.filter(p=>p.isReference),{slot:photo?.slot})||[];
    return results.length?results[0]:null;
  }
  function photoSource(photo){return photo.url||mediaApi+'?publicId='+encodeURIComponent(photo.mediaPublicId);}
  function menuName(photo){return photo.menuItemName||S.menu.find(m=>m.id===photo.menuItemId)?.name||'Unassigned';}
  function newCard(photo){
    const card=document.createElement('article');card.className='gc-photo';
    const img=document.createElement('img');img.src=photoSource(photo);img.loading='lazy';img.alt='Camera '+photo.slot+' captured photo';
    const heading=document.createElement('div');heading.className='gc-photo-heading';
    const title=document.createElement('strong');
    title.textContent=photo.isReference?'TRAINING REFERENCE · '+menuName(photo):'Camera '+photo.slot+' · '+(photo.role||'capture');
    const ts=document.createElement('time');ts.textContent=new Date(photo.capturedAt).toLocaleString();
    heading.append(title,ts);
    const info=document.createElement('div');info.className='gc-photo-results';
    const quality=document.createElement('span');quality.textContent='Photo quality: '+qualityLabel(photo);
    const rating=document.createElement('span');rating.textContent=photo.rating?'Employee: '+photo.rating+' / 5 stars':'Employee: not rated';
    const best=match(photo),similarity=document.createElement('span');
    similarity.textContent=photo.isReference?'Saved training photo':best?
      'Closest '+(best.sameCamera?'same-stage':'other-stage')+' reference: '+menuName(best)+' · '+best.similarityScore+'% visual similarity':
      'No comparison yet · save a menu reference photo';
    info.append(quality,rating,similarity);
    const actions=document.createElement('div');actions.className='gc-actions';
    const train=document.createElement('button');train.type='button';train.textContent='Review / Train';
    train.addEventListener('click',()=>{select('training');setTraining(photo);});
    actions.appendChild(train);
    if(photo.url){
      const save=document.createElement('button');save.type='button';save.textContent='Download';
      save.addEventListener('click',()=>{const a=document.createElement('a');a.href=photo.url;a.download='gelato-camera-'+photo.slot+'-'+photo.capturedAt.replace(/[:.]/g,'-')+'.jpg';a.click();});
      actions.appendChild(save);
    }
    card.append(img,heading,info,actions);
    return card;
  }
  function renderGallery(slot){
    const target=$('gcGallery'+slot);
    if(!target)return;
    target.replaceChildren();
    const photos=bySlot(slot).sort((a,b)=>new Date(b.capturedAt)-new Date(a.capturedAt)).slice(0,60);
    if(!photos.length){const empty=document.createElement('p');empty.className='sim-muted';empty.textContent='No photos yet. Start the camera, capture a frame, and review it here.';target.appendChild(empty);}
    else photos.forEach(p=>target.appendChild(newCard(p)));
    text('gcCount'+slot,photos.length+' photos');
  }
  function updateTrainingOptions(){
    const selector=$('gcTrainingPhoto');if(!selector)return;
    const previous=selector.value;selector.replaceChildren();
    const first=document.createElement('option');first.value='';first.textContent='Choose captured photo';selector.append(first);
    for(const photo of [...S.local,...S.photos].sort((a,b)=>new Date(b.capturedAt)-new Date(a.capturedAt))){
      const option=document.createElement('option');option.value=idOf(photo);
      option.textContent='Cam '+photo.slot+' · '+new Date(photo.capturedAt).toLocaleString()+(photo.id?' (saved)':' (local)');
      selector.append(option);
    }
    selector.value=[...selector.options].some(o=>o.value===previous)?previous:'';
    const menu=$('gcMenuItem');if(menu){
      const current=menu.value;menu.replaceChildren();
      const first=document.createElement('option');first.value='';first.textContent='Select a menu item';menu.append(first);
      for(const item of S.menu){const opt=document.createElement('option');opt.value=String(item.id);opt.textContent=item.name;menu.append(opt);}
      menu.value=[...menu.options].some(o=>o.value===current)?current:'';
    }
    renderReferences();
  }
  function selected(){
    const id=$('gcTrainingPhoto')?.value;
    return [...S.local,...S.photos].find(p=>idOf(p)===id)||null;
  }
  function setTraining(photo){
    const selector=$('gcTrainingPhoto');if(!selector)return;
    selector.value=idOf(photo);if(selector.value!==idOf(photo))updateTrainingOptions();
    selector.value=idOf(photo);
    $('gcMenuItem').value=photo.menuItemId?String(photo.menuItemId):'';
    $('gcReference').checked=!!photo.isReference;
    $('gcEmployeeRating').value=photo.rating?String(photo.rating):'';
    $('gcReviewNote').value=photo.note||'';
    const preview=$('gcTrainingPreview');preview.replaceChildren();
    const img=document.createElement('img');img.src=photoSource(photo);img.alt='Selected photo for menu training';preview.append(img);
    const best=match(photo);
    feedback(photo.id?'Saved library photo · edit its review or reference assignment.':
      'Local photo · '+(best?'closest saved reference '+menuName(best)+' at '+best.similarityScore+'% visual similarity.':'no training references yet.'));
    $('gcSaveTraining').textContent=photo.id?'Update Review':'Save to Vision Lab';
    $('gcConsentWrap').hidden=!!photo.id;
  }
  function renderReferences(){
    const root=$('gcReferences');if(!root)return;root.replaceChildren();
    const refs=S.photos.filter(p=>p.isReference);
    if(!refs.length){const p=document.createElement('p');p.className='sim-muted';p.textContent='No menu-item training photos saved yet. Choose a capture, assign a menu item, and confirm it as a reference.';root.append(p);return;}
    for(const ref of refs.slice(0,80)){
      const row=document.createElement('button');row.type='button';row.className='gc-reference';
      const img=document.createElement('img');img.src=photoSource(ref);img.loading='lazy';img.alt='Saved '+menuName(ref)+' reference';
      const desc=document.createElement('span');desc.textContent='Camera '+ref.slot+' · '+menuName(ref)+' · '+(ref.rating?ref.rating+'/5 stars':'unrated');
      row.append(img,desc);row.addEventListener('click',()=>setTraining(ref));root.append(row);
    }
  }
  async function refresh(){
    const location=Number(window.GelatoCameraConfiguration?.locationId||0);
    const epoch=++S.seq;S.locationId=location;
    S.local=S.local.filter(p=>p.locationId===location);
    if(!location){S.photos=[];S.menu=[];draw();feedback('Select a restaurant location first.');return;}
    try{
      const response=await fetch(api+'?locationId='+encodeURIComponent(location),{credentials:'same-origin',cache:'no-store'});
      const data=await response.json();
      if(epoch!==S.seq)return;
      if(!response.ok||!data.ok)throw new Error(data.message||'Photo library unavailable.');
      if(/^[0-9a-f]{64}$/i.test(String(data.csrfToken||'')))core().csrf=data.csrfToken;
      S.photos=data.library.photos||[];S.menu=data.library.menuItems||[];
      draw();text('gcLibraryState','SAVED · '+S.photos.length+' PHOTOS');
    }catch(e){
      S.photos=[];S.menu=[];draw();text('gcLibraryState','UNAVAILABLE');
      feedback(e.message||'Photo library needs Section 258 database upgrade and glasses.manage permission.');
    }
  }
  function draw(){for(let i=1;i<=5;i++)renderGallery(i);updateTrainingOptions();}
  async function addLocal(photo){
    if(!photo||photo.locationId!==S.locationId)return;
    S.local.push(photo);
    while(S.local.length>45)S.local.shift();
    draw();
    const flag=$('gcLibraryState');if(flag)flag.textContent='CAPTURED · NOT SAVED';
    try{
      photo.fingerprint=await metric().fingerprint(photo.blob);
      if(photo.locationId===S.locationId)draw();
    }catch{photo.fingerprint=null;draw();}
  }
  async function bytes64(blob){
    const buffer=new Uint8Array(await blob.arrayBuffer());let result='';
    for(let i=0;i<buffer.length;i+=8192){
      result+=String.fromCharCode(...buffer.subarray(i,i+8192));
    }
    return btoa(result);
  }
  async function post(url,payload){
    const response=await fetch(url,{method:'POST',credentials:'same-origin',cache:'no-store',
      headers:{'Content-Type':'application/json','X-CSRF-Token':core().csrf},
      body:JSON.stringify({...payload,csrf_token:core().csrf})});
    const data=await response.json().catch(()=>({ok:false,message:'Invalid server response.'}));
    if(!response.ok||!data.ok){
      if(response.status===419)throw new Error('Security token expired. Refresh the camera library before retrying; no photo was automatically resubmitted.');
      throw new Error(data.message||'Photo review action failed.');
    }
    return data;
  }
  async function saveTraining(){
    if(S.busy)return;
    if(!canSave())return feedback('Saving photos requires glasses.manage at an authorized location.');
    const photo=selected();if(!photo)return feedback('Select a camera photo first.');
    const menuId=$('gcMenuItem').value?Number($('gcMenuItem').value):null;
    const isReference=$('gcReference').checked,rating=$('gcEmployeeRating').value?Number($('gcEmployeeRating').value):null;
    const note=$('gcReviewNote').value.trim();
    if(isReference&&!menuId)return feedback('Assign a menu item before marking a training reference.');
    const epoch=S.seq;S.busy=true;$('gcSaveTraining').disabled=true;
    try{
      if(photo.id){
        await post(api,{action:'review',locationId:S.locationId,photoId:photo.id,menuItemId:menuId,isReference,rating,note});
      }else{
        if(!$('gcConsent').checked)throw new Error('Explicitly opt into private Vision Lab photo storage first.');
        const fingerprint=photo.fingerprint||await metric().fingerprint(photo.blob);
        const upload=photo.uploadedMediaPublicId?{media:{publicId:photo.uploadedMediaPublicId}}:
          await post(mediaApi,{action:'upload',consentBasis:'training_media_opt_in',
          imageBase64:await bytes64(photo.blob),annotations:[],
          perceptualHash:fingerprint.hash,brightnessMean:fingerprint.brightnessMean,
          contrastMean:fingerprint.contrastMean,blurScore:fingerprint.blurScore,
          capturedAt:photo.capturedAt.slice(0,19).replace('T',' '),
          retentionDays:365,
          metadata:{source:'camera_stage_gallery',cameraSlot:photo.slot,
            locationId:photo.locationId,productionStage:photo.role,reviewRequired:true}});
        const mediaPublicId=upload.media?.publicId;
        if(!mediaPublicId)throw new Error('Vision Lab did not return a saved media reference.');
        photo.uploadedMediaPublicId=mediaPublicId;
        await post(api,{action:'register',locationId:S.locationId,slot:photo.slot,
          mediaPublicId,menuItemId:menuId,isReference,rating,note});
        S.local=S.local.filter(x=>x!==photo);
      }
      await refresh();$('gcConsent').checked=false;feedback('Photo saved to private Vision Lab and reviewed in the camera library. No order or customer was changed.');
    }catch(e){if(epoch===S.seq)feedback(e.message||'Could not save photo.');}
    finally{S.busy=false;$('gcSaveTraining').disabled=false;}
  }
  function mount(){
    if(!window.GelatoCameraConfiguration||!window.GelatoGlassesMultiCamera)return;
    const panel=$('sgv-panel-camera'),card=panel?.querySelector('.camera-card');
    if(!panel||!card)return;
    const shell=document.createElement('div');shell.className='gc-workspace';
    const nav=document.createElement('div');nav.className='gc-camera-tabs';nav.setAttribute('role','tablist');nav.setAttribute('aria-label','Camera views and training');
    const panes=document.createElement('div');panes.className='gc-camera-panels';
    shell.append(nav,panes);panel.prepend(shell);
    for(let slot=1;slot<=5;slot++){
      const name='camera'+slot,button=document.createElement('button');button.type='button';button.textContent='Camera '+slot;
      button.id='gcTab'+slot;button.setAttribute('role','tab');button.setAttribute('aria-controls','gcPane'+slot);
      const view=document.createElement('section');view.id='gcPane'+slot;view.className='gc-camera-panel';
      view.setAttribute('role','tabpanel');view.setAttribute('aria-labelledby',button.id);view.hidden=true;
      button.addEventListener('click',()=>select(name));
      button.addEventListener('keydown',event=>{
        const keys=['camera1','camera2','camera3','camera4','camera5','training'];
        const idx=keys.indexOf(name),next=event.key==='ArrowRight'?keys[(idx+1)%6]:
          event.key==='ArrowLeft'?keys[(idx+5)%6]:null;
        if(next){event.preventDefault();select(next);controls[next].button.focus();}
      });
      nav.append(button);panes.append(view);controls[name]={button,pane:view};
      if(slot===1)view.appendChild(card);
      else {
        const item=document.querySelector('.sim-aux-camera[data-slot="'+slot+'"]');
        if(item)view.appendChild(item);
      }
      const section=document.createElement('section');section.className='gc-gallery-section';
      section.innerHTML='<div class="gc-heading"><strong>Camera '+slot+' · Photo History & Gallery</strong><span id="gcCount'+slot+'">0 photos</span></div>'+
        '<p class="sim-muted">Photos stay local until you explicitly save to private Vision Lab. Automatic visual scores are not ingredient or order verification.</p>'+
        '<div class="gc-gallery" id="gcGallery'+slot+'"></div>';
      const take=document.createElement('button');take.type='button';take.className='gc-capture-button';
      take.textContent='Capture Photo · Camera '+slot;take.addEventListener('click',()=>void window.GelatoCameraConfiguration.capture(slot));
      if(slot===1){
        view.insertBefore(section,view.firstChild);
        view.insertBefore(take,section);
        const advanced=card.querySelector('.vision-runtime-panel');
        if(advanced){
          const details=document.createElement('details');details.className='gc-advanced';
          const summary=document.createElement('summary');summary.textContent='Advanced detection, inference & vision controls';
          details.append(summary,advanced);card.append(details);
        }
      }else{view.appendChild(section);section.before(take);}
    }
    const tb=document.createElement('button');tb.type='button';tb.textContent='Training';tb.id='gcTabTraining';
    tb.setAttribute('role','tab');tb.setAttribute('aria-controls','gcPaneTraining');
    const train=document.createElement('section');train.id='gcPaneTraining';train.className='gc-camera-panel';train.hidden=true;
    train.setAttribute('role','tabpanel');train.setAttribute('aria-labelledby',tb.id);
    tb.addEventListener('click',()=>select('training'));
    tb.addEventListener('keydown',event=>{
      if(event.key==='ArrowRight'||event.key==='ArrowLeft'){
        event.preventDefault();const next=event.key==='ArrowRight'?'camera1':'camera5';
        select(next);controls[next].button.focus();
      }
    });
    nav.append(tb);panes.append(train);
    controls.training={button:tb,pane:train};
    const old=$('simMultiCameraPanel');if(old)train.appendChild(old);
    const originalDataset=$('datasetCaptureSample')?.closest('.dataset-capture-panel');
    if(originalDataset)train.appendChild(originalDataset);
    const legacy=$('simCameraPhotoHistory')?.closest('.sim-camera-photo-history');if(legacy)legacy.hidden=true;
    const block=document.createElement('section');block.className='gc-training-workflow';
    block.innerHTML='<div class="gc-heading"><strong>Menu Item Photo Training</strong><span id="gcLibraryState">LOADING</span></div>'+
      '<p class="sim-muted">Choose a captured image, assign it to an existing menu item, and optionally make it a saved reference. New photos are compared with the saved reference images by perceptual similarity; these are review aids, not certified AI detections.</p>'+
      '<label>Captured photo <select id="gcTrainingPhoto"><option value="">Choose captured photo</option></select></label>'+
      '<div id="gcTrainingPreview" class="gc-training-preview"></div>'+
      '<label>Menu item <select id="gcMenuItem"><option value="">Select menu item</option></select></label>'+
      '<label>Employee quality rating <select id="gcEmployeeRating"><option value="">Not rated</option>'+
      [1,2,3,4,5].map(x=>'<option value="'+x+'">'+x+' / 5 stars</option>').join('')+'</select></label>'+
      '<label>Review notes <textarea id="gcReviewNote" rows="2" maxlength="300" placeholder="Visual quality, toppings, angle, lighting…"></textarea></label>'+
      '<label class="gc-checkbox"><input type="checkbox" id="gcReference"> Save as menu-item training reference</label>'+
      '<label class="gc-checkbox" id="gcConsentWrap"><input type="checkbox" id="gcConsent"> I approve saving this photo in private Vision Lab media</label>'+
      '<button type="button" id="gcSaveTraining">Save to Vision Lab</button>'+
      '<p id="gcTrainingFeedback" class="sim-muted" role="status" aria-live="polite">Capture a frame, then select it for review.</p>'+
      '<div class="gc-heading"><strong>Saved Menu References</strong><span>Visual baseline</span></div>'+
      '<div id="gcReferences" class="gc-references"></div>';
    train.prepend(block);
    $('gcTrainingPhoto').addEventListener('change',()=>{const p=selected();if(p)setTraining(p);});
    $('gcSaveTraining').addEventListener('click',()=>void saveTraining());
    window.addEventListener('gelato-camera-photo-captured',event=>{void addLocal(event.detail?.photo);});
    window.addEventListener('gelato-glasses-location-change',()=>{void refresh();});
    const freeze=document.querySelectorAll('[data-freeze]');freeze.forEach(b=>b.addEventListener('click',()=>select('training')));
    select('camera1');void refresh();
  }
  async function persistOrderPhoto(photo,menuItemId,{optIn=false}={}){
    if(!canSave())throw new Error('Camera photo storage requires glasses.manage at this location.');
    if(!photo||photo.locationId!==S.locationId)throw new Error('Photo belongs to another location.');
    if(photo.id)return {id:photo.id,mediaPublicId:photo.mediaPublicId};
    if(!optIn)throw new Error('Check the private Vision Lab storage consent before attaching a photo.');
    if(!Number.isInteger(menuItemId)||menuItemId<1)throw new Error('Select an item with an active menu ID.');
    if(!photo.blob)throw new Error('Capture a photo from the active camera first.');
    const fingerprints=photo.fingerprint||await metric().fingerprint(photo.blob);
    if(!photo.uploadedMediaPublicId){
      const upload=await post(mediaApi,{action:'upload',consentBasis:'training_media_opt_in',
        imageBase64:await bytes64(photo.blob),annotations:[],
        perceptualHash:fingerprints.hash,brightnessMean:fingerprints.brightnessMean,
        contrastMean:fingerprints.contrastMean,blurScore:fingerprints.blurScore,
        capturedAt:photo.capturedAt.slice(0,19).replace('T',' '),retentionDays:365,
        metadata:{source:'camera_stage_gallery',cameraSlot:photo.slot,
          locationId:photo.locationId,productionStage:photo.role,reviewRequired:true}
      });
      photo.uploadedMediaPublicId=upload.media?.publicId;
      if(!photo.uploadedMediaPublicId)throw new Error('Vision Lab did not return a saved media ID.');
    }
    const response=await post(api,{action:'register',locationId:S.locationId,slot:photo.slot,
      mediaPublicId:photo.uploadedMediaPublicId,menuItemId,isReference:false,rating:null,note:'Production photo candidate'});
    const id=response.result?.id;
    if(!Number.isInteger(id)||id<1)throw new Error('Camera gallery registration was not confirmed.');
    photo.id=id;photo.mediaPublicId=photo.uploadedMediaPublicId;
    return {id,mediaPublicId:photo.uploadedMediaPublicId};
  }
  window.GelatoCameraWorkspace={mount,select,refresh,persistOrderPhoto,
    referenceMatches:(photo,menuItemId)=>metric()?.compare?.(photo?.fingerprint?.hash||photo?.hash,
      S.photos.filter(p=>p.isReference&&p.menuItemId===menuItemId),{slot:photo?.slot})||[],
    getSavedPhotos:slot=>S.photos.filter(p=>p.slot===slot),status:()=>({active:S.active,locationId:S.locationId,photos:S.photos.length,local:S.local.length})};
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount,{once:true});
  else mount();
})();