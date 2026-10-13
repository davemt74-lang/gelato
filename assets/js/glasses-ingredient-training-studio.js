(()=>{'use strict';
/* Section 267 — uses existing camera history, V11 training session and V5 Vision Lab.
 * Captures never imply AI recognition, approval, or KDS item completion. */
const cfg=window.GELATO_GLASSES_SIMULATOR||{},plan=window.GelatoIngredientTrainingPlan;
const $=id=>document.getElementById(id);
let catalog=null,busy=false,file=null,objectUrl=null,active=false,selectedPhotoKey='',draftBox=null,pointer=null;
const b64=async blob=>{
 const bytes=new Uint8Array(await blob.arrayBuffer());
 let out='';for(let p=0;p<bytes.length;p+=8192)out+=String.fromCharCode(...bytes.subarray(p,p+8192));
 return btoa(out);
};
function note(message,error=false){const e=$('gitFeedback');if(e){e.textContent=message;e.dataset.error=error?'1':'0';}}
function choose(){return plan.choice(catalog,Number($('gitMenu')?.value||0),$('gitIngredient')?.value,$('gitPhase')?.value);}
function setPreview(blob){
 if(objectUrl)URL.revokeObjectURL(objectUrl);
 objectUrl=blob?URL.createObjectURL(blob):null;
 const image=$('gitPreview');image.src=objectUrl||'';image.hidden=!objectUrl;
 draftBox=null;paint();
}
function imageContentRect(){
 const image=$('gitPreview'),canvas=$('gitBox');
 if(!image?.naturalWidth||!image?.naturalHeight||!canvas)return null;
 const rect=image.getBoundingClientRect(),target=canvas.getBoundingClientRect();
 const scale=Math.min(rect.width/image.naturalWidth,rect.height/image.naturalHeight);
 const w=image.naturalWidth*scale,h=image.naturalHeight*scale;
 return {x:rect.left-target.left+(rect.width-w)/2,
   y:rect.top-target.top+(rect.height-h)/2,width:w,height:h};
}
function paint(){
 const canvas=$('gitBox');if(!canvas)return;
 const rect=canvas.getBoundingClientRect();
 canvas.width=Math.max(1,Math.round(rect.width));canvas.height=Math.max(1,Math.round(rect.height));
 const ctx=canvas.getContext('2d');ctx.clearRect(0,0,canvas.width,canvas.height);
 const region=imageContentRect();
 if(!draftBox||!region)return;
 ctx.fillStyle='rgba(71,220,123,.14)';ctx.strokeStyle='#7dffaf';ctx.lineWidth=2;
 const x=region.x+draftBox.x*region.width,y=region.y+draftBox.y*region.height;
 const w=draftBox.width*region.width,h=draftBox.height*region.height;
 ctx.fillRect(x,y,w,h);ctx.strokeRect(x,y,w,h);
}
function captured(){
 const currentLocation=Number(catalog?.locationId||0);
 return window.GelatoCameraWorkspace?.getCapturedPhotos?.()?.filter(x=>Number(x.locationId)===currentLocation)||[];
}
function renderPhotos(){
 const list=captured(),input=$('gitCapturedPhoto');if(!input)return;
 const previous=input.value||selectedPhotoKey;
 input.replaceChildren(new Option('Choose an unsaved camera photo…',''));
 for(const p of list){input.add(new Option('Camera '+p.slot+' · '+new Date(p.capturedAt).toLocaleString(),p.key));}
 if(list.some(p=>p.key===previous))input.value=previous;
 else selectedPhotoKey='';
}
function selectPhoto(){
 file=null;$('gitFile').value='';
 selectedPhotoKey=$('gitCapturedPhoto').value;
 const photo=captured().find(x=>x.key===selectedPhotoKey);
 if(photo){setPreview(photo.blob);note('Camera '+photo.slot+' photo selected. Draw the ingredient box if this is a positive phase.');}
 else setPreview(null);
}
function drawMenu(){
 const e=$('gitMenu'),prior=e.value;e.replaceChildren(new Option('Select a configured menu item…',''));
 for(const item of catalog?.menuItems||[])e.add(new Option(item.name,item.id));
 if((catalog?.menuItems||[]).some(x=>String(x.id)===prior))e.value=prior;
 updateIngredient();
}
function updateIngredient(){
 const menu=(catalog?.menuItems||[]).find(m=>m.id===Number($('gitMenu').value));
 const e=$('gitIngredient'),prior=e.value;e.replaceChildren(new Option('Select menu ingredient…',''));
 for(const ingredient of menu?.ingredients||[])e.add(new Option(ingredient.name+' · '+ingredient.canonicalName,ingredient.key));
 if((menu?.ingredients||[]).some(x=>x.key===prior))e.value=prior;
 updatePhase();
}
function updatePhase(){
 const picked=choose(),tip=$('gitGuidance');
 if(tip)tip.textContent=picked?.guide.hint||'Choose a configured ingredient and phase.';
 $('gitBox').hidden=!picked?.guide.positive;
 if(!picked?.guide.positive)draftBox=null;
 paint();
}
function render(){
 const valid=!!catalog&&!active;
 const el=$('gitContainer');if(!el)return;
 el.hidden=false;
 $('gitSubmit').disabled=!valid;
 $('gitRuntime').textContent=valid?catalog.employeeName+' · '+catalog.programName:'';
 $('gitSession').textContent=valid?'Supervised session · '+catalog.sessionPublicId:'';
 drawMenu();
 const mission=$('gitMission'),old=mission.value;mission.replaceChildren(new Option('No mission (general training)',''));
 for(const m of catalog?.missions||[])mission.add(new Option(m.title,m.publicId));
 if((catalog?.missions||[]).some(x=>x.publicId===old))mission.value=old;
 renderPhotos();
}
async function reload(){
 const runtime=cfg.runtimePublicId;
 if(!runtime){active=true;catalog=null;render();note('Open an assigned employee glasses runtime and start its V11 training session.');return;}
 try{
   const response=await fetch('api/glasses-ingredient-training.php?runtimePublicId='+encodeURIComponent(runtime),{credentials:'same-origin',cache:'no-store'});
   const result=await response.json().catch(()=>({}));
   if(!response.ok||!result.ok)throw Error(result.message||'Training catalog unavailable.');
   if(/^[0-9a-f]{64}$/i.test(result.csrfToken||''))cfg.csrf=result.csrfToken;
   active=false;catalog=result.catalog;render();
   note('Ready: capture each ingredient in several phases; all uploads require review.');
 }catch(error){active=true;catalog=null;render();note(error.message||'Training unavailable',true);}
}
function pointerCoord(e){
 const canvas=$('gitBox'),bounds=canvas.getBoundingClientRect(),region=imageContentRect();
 if(!region)return null;
 return {x:Math.max(0,Math.min(1,(e.clientX-bounds.left-region.x)/region.width)),
   y:Math.max(0,Math.min(1,(e.clientY-bounds.top-region.y)/region.height))};
}
function markStart(e){
 if(!$('gitPreview').src||!choose()?.guide.positive)return;
 e.preventDefault();const p=pointerCoord(e);if(!p)return;
 pointer={id:e.pointerId,x:p.x,y:p.y};
 $('gitBox').setPointerCapture(e.pointerId);
}
function markMove(e){
 if(!pointer||pointer.id!==e.pointerId)return;
 const p=pointerCoord(e);if(!p)return;
 draftBox={x:Math.min(p.x,pointer.x),y:Math.min(p.y,pointer.y),
   width:Math.abs(p.x-pointer.x),height:Math.abs(p.y-pointer.y)};
 paint();
}
function markEnd(e){if(!pointer||pointer.id!==e.pointerId)return;markMove(e);pointer=null;}
async function save(){
 if(busy||!catalog)return;
 const photo=captured().find(p=>p.key===selectedPhotoKey);
 const blob=file||photo?.blob||null;
 const selected=choose(),source=file?'file_upload':'runtime_camera';
 const context={
   runtimePublicId:cfg.runtimePublicId,sessionPublicId:catalog.sessionPublicId,
   catalog,menuItemId:Number($('gitMenu').value),ingredientKey:$('gitIngredient').value,
   phase:$('gitPhase').value,blob,source,
   bbox:selected?.guide.positive?draftBox:null,
   cameraSlot:file?0:Number(photo?.slot||0),consent:$('gitConsent').checked
 };
 if(file){context.cameraSlot=0;} 
 const validation=plan.validate(context);if(validation){note(validation,true);return;}
 if(file&&file.size>12582912){note('Image exceeds Vision Lab’s 12 MB upload limit.',true);return;}
 if(!file&&(!photo||photo.locationId!==catalog.locationId)){note('Captured photo location or camera changed. Select again.',true);return;}
 busy=true;$('gitSubmit').disabled=true;note('Saving to existing Vision Lab pending-review queue…');
 try{
   const fp=photo?.fingerprint||(window.GelatoCameraSimilarity?.fingerprint?await window.GelatoCameraSimilarity.fingerprint(blob):null);
   const response=await fetch('api/glasses-ingredient-training.php',{
     method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':cfg.csrf},
     body:JSON.stringify({action:'capture',csrf_token:cfg.csrf,
       runtimePublicId:cfg.runtimePublicId,sessionPublicId:catalog.sessionPublicId,
       menuItemId:context.menuItemId,ingredientKey:context.ingredientKey,phase:context.phase,
       bbox:context.bbox,cameraSlot:context.cameraSlot,source,consentBasis:'training_media_opt_in',
       reviewRequired:true,missionPublicId:$('gitMission').value||null,
       distanceBucket:$('gitDistance').value,occlusionBucket:$('gitOcclusion').value,
       fingerprint:fp?{hash:fp.hash,brightnessMean:fp.brightnessMean,contrastMean:fp.contrastMean,blurScore:fp.blurScore}:{},
       imageBase64:await b64(blob)})
   });
   const json=await response.json().catch(()=>({}));
   if(!response.ok||!json.ok)throw Error(json.message||'Vision Lab rejected this sample.');
   const capture=json.capture;
   note('Saved pending review · '+capture.samplePublicId+' · quality '+capture.qualityState+
     (capture.exactDuplicates?.length?' · duplicate exists; review before training':'')+
     '. No model or kitchen state changed.');
   $('gitConsent').checked=false;draftBox=null;paint();
 }catch(error){note(error.message||'Capture failed; no sample confirmed.',true);}
 finally{busy=false;$('gitSubmit').disabled=false;}
}
function mount(){
 if(!plan||!$('sgv-panel-camera'))return;
 const root=document.createElement('section');root.id='gitContainer';root.className='git-studio';root.hidden=true;
 root.innerHTML='<header class="git-head"><div><small>SECTION 267 · EMPLOYEE TRAINING</small><strong>Ingredient Capture Studio</strong></div><button id="gitReload" type="button">Refresh session</button></header>'+
 '<div id="gitRuntime" class="git-context"></div><div id="gitSession" class="git-context"></div>'+
 '<p class="git-explainer">Use actual menu ingredients, never preparation recipes. Photos stay local until you consent to a pending human-reviewed Vision Lab sample.</p>'+
 '<label>Menu item<select id="gitMenu"><option value="">Loading…</option></select></label>'+
 '<label>Ingredient<select id="gitIngredient"><option value="">Choose item first</option></select></label>'+
 '<label>Capture phase<select id="gitPhase"></select></label>'+
 '<p id="gitGuidance" class="git-guidance"></p>'+
 '<div class="git-source-grid"><label>Unsaved camera photo<select id="gitCapturedPhoto"><option>Choose capture…</option></select></label>'+
 '<label>Or upload photo file<input type="file" id="gitFile" accept="image/jpeg,image/png,image/webp"></label></div>'+
 '<div class="git-frame"><img id="gitPreview" alt="Training sample preview" hidden><canvas id="gitBox" aria-label="Draw ingredient bounding box"></canvas></div>'+
 '<button type="button" id="gitClearBox">Clear ingredient box</button>'+
 '<div class="git-source-grid"><label>Distance<select id="gitDistance"><option value="medium">Medium</option><option value="close">Close</option><option value="far">Far</option></select></label>'+
 '<label>Occlusion<select id="gitOcclusion"><option value="none">None</option><option value="partial">Partial</option><option value="heavy">Heavy</option></select></label></div>'+
 '<label>Existing Vision Lab mission<select id="gitMission"><option value="">None</option></select></label>'+
 '<label class="git-optin"><input id="gitConsent" type="checkbox"> I approve private Vision Lab storage and human review. No automatic training.</label>'+
 '<button id="gitSubmit" type="button" class="git-save">Submit sample for review</button>'+
 '<p id="gitFeedback" role="status" aria-live="polite">Start an active employee training session to begin.</p>';
 const panel=$('gcPaneTraining')||$('sgv-panel-camera');panel.prepend(root);
 const phases=$('gitPhase');phases.add(new Option('Select capture phase…',''));
 for(const p of plan.PHASES)phases.add(new Option(p.name,p.id));
 $('gitMenu').addEventListener('change',updateIngredient);
 $('gitIngredient').addEventListener('change',updatePhase);
 phases.addEventListener('change',updatePhase);
 $('gitCapturedPhoto').addEventListener('change',selectPhoto);
 $('gitFile').addEventListener('change',e=>{
   file=e.target.files?.[0]||null;selectedPhotoKey='';$('gitCapturedPhoto').value='';
   if(file&&!['image/jpeg','image/png','image/webp'].includes(file.type)){note('Use a JPEG, PNG, or WebP photo.',true);file=null;}
   setPreview(file);if(file)note('Local uploaded file selected; camera source will be marked as file upload.');
 });
 $('gitPreview').addEventListener('load',paint);
 const box=$('gitBox');box.addEventListener('pointerdown',markStart);box.addEventListener('pointermove',markMove);
 box.addEventListener('pointerup',markEnd);box.addEventListener('pointercancel',()=>{pointer=null;});
 $('gitClearBox').addEventListener('click',()=>{draftBox=null;paint();});
 $('gitReload').addEventListener('click',()=>void reload());
 $('gitSubmit').addEventListener('click',()=>void save());
 window.addEventListener('gelato-camera-photo-captured',()=>{renderPhotos();});
 window.addEventListener('gelato-glasses-runtime-revoked',()=>{catalog=null;active=true;root.hidden=true;note('Runtime revoked. Training stopped.',true);});
 window.addEventListener('resize',paint);
 window.addEventListener('gelato-glasses-tab-select',e=>{if(e.detail?.tab==='camera')renderPhotos();});
 void reload();
}
window.GelatoIngredientTrainingStudio={mount,reload};
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount,{once:true});else mount();
})();
