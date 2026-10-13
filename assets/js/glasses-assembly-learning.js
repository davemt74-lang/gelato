(()=>{'use strict';
/* Section 268 — voluntary human training reports. Never auto-label a camera frame. */
const cfg=window.GELATO_GLASSES_SIMULATOR||{},byId=id=>document.getElementById(id);
let busy=false,selectedItemId='',selectedIngredientId='';
const TYPES=[
 ['low_confidence','Recognition confidence low'],
 ['wrong_ingredient','Wrong ingredient detected'],
 ['missed_ingredient','Ingredient not recognized'],
 ['difficult_view','Difficult view / collect photos']
];
function context(){return window.GelatoGlassesAssemblyContext?.current?.()||null;}
function note(s,error=false){const el=byId('galFeedback');if(el){el.textContent=s;el.dataset.error=error?'1':'0';}}
function render(){
 const item=context(),field=byId('galIngredient'),root=byId('galStudio');
 if(!root)return;
 const ready=!!item?.ingredients?.length;
 root.dataset.ready=ready?'1':'0';
 const label=byId('galContext');
 if(label)label.textContent=ready
   ?'Ticket #'+item.ticketNumber+' · '+item.itemName+' · '+item.stationPublicId
   :cfg.runtimePublicId?'Choose an active station ticket with menu ingredients.':'Open an assigned employee glasses runtime to record learning feedback.';
 const changed=item?.kdsItemPublicId!==selectedItemId;
 if(changed||JSON.stringify(item?.ingredients?.map(x=>x.id))!==field.dataset.keys){
   selectedItemId=item?.kdsItemPublicId||'';
   const previous=changed?'':field.value;
   field.replaceChildren(new Option('Select configured ingredient…',''));
   for(const i of item?.ingredients||[])field.add(new Option(i.name,i.id));
   const next=(item?.ingredients||[]).find(i=>i.id===previous)?.id||item?.currentIngredientId||'';
   if(next)field.value=next;selectedIngredientId=next;
   field.dataset.keys=JSON.stringify(item?.ingredients?.map(x=>x.id));
 }
 const enabled=ready&&!!field.value&&!busy;
 field.disabled=!ready;
 byId('galFeedbackType').disabled=!ready;
 byId('galSubmit').disabled=!enabled;
}
async function submit(){
 if(busy)return;
 const item=context(),ingredientKey=byId('galIngredient')?.value||'',feedbackType=byId('galFeedbackType')?.value||'';
 if(!item||!ingredientKey||!cfg.runtimePublicId){note('Choose an active employee runtime, item and ingredient.',true);return;}
 if(!(item.ingredients||[]).some(x=>x.id===ingredientKey)){note('Ingredient changed. Select the current menu ingredient.',true);return;}
 busy=true;render();note('Submitting operator report to existing Vision Lab active-learning queue…');
 try{
   const response=await fetch('api/glasses-assembly-learning.php',{
     method:'POST',credentials:'same-origin',cache:'no-store',
     headers:{'Content-Type':'application/json','X-CSRF-Token':cfg.csrf},
     body:JSON.stringify({action:'flag_ingredient',csrf_token:cfg.csrf,
       runtimePublicId:cfg.runtimePublicId,kdsItemPublicId:item.kdsItemPublicId,
       ingredientKey,feedbackType,note:byId('galNote').value})
   });
   const result=await response.json().catch(()=>({}));
   if(!response.ok||!result.ok)throw Error(result.message||'Could not queue review candidate.');
   note((result.candidate.created?'New':'Existing')+' Vision Lab candidate · '+
     result.candidate.candidatePublicId+' · '+result.candidate.status+
     '. No photo attached; human review and guided capture remain necessary.');
   byId('galNote').value='';
 }catch(e){note(e.message||'Unable to queue feedback.',true);}
 finally{busy=false;render();}
}
function mount(){
 const parent=byId('gcPaneTraining')||byId('sgv-panel-camera');
 if(!parent)return;
 const root=document.createElement('section');root.id='galStudio';root.className='gal-studio';
 root.innerHTML='<header class="gal-head"><div><small>SECTION 268 · EXISTING VISION LAB</small><strong>Flag ingredient for learning</strong></div></header>'+
   '<p>Report difficult or incorrect recognition. This queues a human-review candidate; it does not approve a label, upload a photo, or change a ticket.</p>'+
   '<div id="galContext" class="gal-context" role="status"></div>'+
   '<label>Configured item ingredient<select id="galIngredient"><option value="">Select a ticket first…</option></select></label>'+
   '<label>What happened?<select id="galFeedbackType"></select></label>'+
   '<label>Optional explanation<input id="galNote" maxlength="160" placeholder="What should the trainer check?"></label>'+
   '<button id="galSubmit" type="button" disabled>Flag for Vision Lab review</button>'+
   '<p id="galFeedback" role="status" aria-live="polite">No camera image is uploaded by this action.</p>';
 parent.prepend(root);
 for(const [value,label] of TYPES)byId('galFeedbackType').add(new Option(label,value));
 byId('galSubmit').addEventListener('click',()=>void submit());
 byId('galIngredient').addEventListener('change',e=>{selectedIngredientId=e.target.value;render();});
 window.addEventListener('gelato-glasses-work-change',render);
 window.addEventListener('gelato-glasses-runtime-revoked',()=>{
   busy=true;render();note('Runtime ended. Learning feedback disabled.',true);
 });
 window.setInterval(render,1700);
 render();
}
window.GelatoGlassesAssemblyLearningUI={mount,render,submit};
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount,{once:true});else mount();
})();
