/* FM-G8C: guided participant onboarding; authenticated goal state lives on Gelato. */
(()=>{
'use strict';
if(!document.body.classList.contains('food-monster-stage-page')||window.GelatoFoodMonsterGoals)return;
const host=document.getElementById('food-monster-stage');if(!host)return;
const csrf=()=>document.querySelector('meta[name="gelato-csrf"]')?.content||'';
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const state={goal:null,roles:[],accounts:[],tracking:'',opened:false,busy:false,nameDraft:'',lastPrompt:''};
const panel=document.createElement('section');panel.className='fm-goal-panel';panel.hidden=true;
panel.setAttribute('aria-label','Participant onboarding goal');
panel.innerHTML='<header class="fm-goal-head"><div><strong>Agent Brain · Participant Setup</strong><small>Guided onboarding goal</small></div><button type="button" data-fm-goal-hide aria-label="Close participant setup panel">×</button></header>'+
 '<p class="fm-goal-prompt" role="status" aria-live="polite">Start by introducing yourself.</p><div class="fm-goal-body"></div>'+
 '<footer class="fm-goal-footer"><button type="button" data-fm-goal-refresh>Refresh goal</button><button type="button" data-fm-goal-cancel>Cancel goal</button></footer>';
host.append(panel);
const body=panel.querySelector('.fm-goal-body'),prompt=panel.querySelector('.fm-goal-prompt');
const launch=document.createElement('button');launch.type='button';launch.className='fm-goal-launch';launch.textContent='Participant setup';
launch.setAttribute('aria-label','Start participant onboarding goal');host.append(launch);
function note(message){prompt.textContent=message;state.lastPrompt=message;window.GelatoFoodMonster?.greet?.(message);}
function show(){state.opened=true;panel.hidden=false;document.body.classList.add('fm-goal-open');render();}
function hide(){state.opened=false;panel.hidden=true;document.body.classList.remove('fm-goal-open');}
async function request(action,payload={}){
 const response=await fetch('api/food-monster-onboarding.php',{method:'POST',cache:'no-store',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-Token':csrf()},body:JSON.stringify({action,csrf_token:csrf(),...payload})});
 const data=await response.json().catch(()=>({message:'Invalid onboarding response'}));
 if(!response.ok||!data.ok)throw Error(data.message||'Onboarding request failed');
 if(data.goal)state.goal=data.goal;
 render();return data;
}
async function load(){
 try{
  const response=await fetch('api/food-monster-onboarding.php?action=list',{credentials:'same-origin',cache:'no-store'});
  const data=await response.json();if(!response.ok||!data.ok)throw Error(data.message||'Goals unavailable');
  state.roles=Array.isArray(data.roles)?data.roles:[];
  state.accounts=Array.isArray(data.accounts)?data.accounts:[];
  const active=data.goals?.find(g=>g.status==='in_progress');
  if(active){state.goal=active;show();}else render();
 }catch(error){if(state.opened)note('Onboarding unavailable: '+error.message);}
}
function field(label,name,type='text',value=''){
 return '<label class="fm-goal-field">'+esc(label)+'<input type="'+type+'" name="'+esc(name)+'" value="'+esc(value)+'" autocomplete="off" required></label>';
}
function button(action,label,disabled=false){return '<button type="button" data-goal-action="'+action+'"'+(disabled?' disabled':'')+'>'+esc(label)+'</button>';}
function gallery(goal){
 return '<div class="fm-goal-gallery">'+goal.photos.map(id=>'<figure><img loading="lazy" src="api/food-monster-onboarding.php?action=photo&amp;goal='+encodeURIComponent(goal.id)+'&amp;photo='+id+'" alt="Consented participant gallery photo">'+button('remove-'+id,'Remove')+'</figure>').join('')+'</div>';
}
function render(){
 if(!state.opened)return;
 const g=state.goal;
 if(!g){note('Hello! Welcome. What is your name?');body.innerHTML=field('Participant name','name')+button('start','Start setup');return;}
 const title=g.name||('Unverified '+(g.trackLabel||'participant'));
 const base='<div class="fm-goal-progress"><strong>'+esc(title)+'</strong><small>'+esc(g.type==='unknown'?'Participant type not set':g.type)+' · '+esc(g.phase.replaceAll('_',' '))+' · '+esc(g.status)+'</small></div>';
 if(g.status!=='in_progress'){body.innerHTML=base+'<p>This goal is '+esc(g.status)+'.</p>'+button('new','Start another participant');return;}
 let html=base;
 if(!g.name){note('What name would you like me to use?');html+=field('Name provided by participant','name')+button('name','Confirm name');}
 else if(g.type==='unknown'){
  note('Thanks, '+g.name+'. Are you here as an employee or a guest?');
  html+=button('type-employee','Employee')+button('type-guest','Guest');
 }else{
  html+='<p class="fm-goal-safety">This name was introduced, not biometric identity verified. Photo storage and voice enrollment require informed consent.</p>';
  if(!g.photoConsent){note('May I save photos to your participant profile? You can decline.');html+=button('photo-consent','Yes, save photos')+button('skip-photo','Skip photos');}
  else{
   html+='<p>Three guided photos: '+g.photos.length+'/3. Front, slight left, slight right. AI captures clear frames automatically.</p>';
   html+=button('auto-photos','Automatically capture 3 photos',g.photos.length>=3);
   html+=button('defer-photos','Low light · voice-first setup');
   html+='<small>Capture starts after consent; poor or ambiguous frames are skipped.</small>';
   html+=gallery(g);
  }
  if(!g.voiceConsent){html+='<p>Voice enrollment works when lighting is poor.</p>'+button('voice-consent','Consent to voice setup');}
  else{
   html+='<p>Voice: '+(g.voiceVerified?'Enrolled':'Not yet enrolled')+'. Enrollment belongs to the authenticated account holder.</p>';
   if(g.employeeUserId===Number(window.GelatoGlobalAgent?.user?.id)&&g.employeeUserId){
    if(g.photoConsent)html+=button('quick-enroll','Quick photo + voice enrollment · 20s target');
    html+=button('auto-voice','Record short voice sample');
    if(g.voiceVerified)html+=button('voice-check','Check my enrolled voice');
   }
  }
  if(g.type==='employee'){
   if(!g.employeeUserId){
    if(state.accounts.length){
     html+='<h3>Link existing Gelato account</h3><label class="fm-goal-field">Employee or my account<select name="existing">'+state.accounts.map(a=>'<option value="'+a.id+'"'+(a.self?' selected':'')+'>'+esc(a.name)+(a.self?' (my account)':'')+'</option>').join('')+'</select></label>';
     html+=button('link-existing','Use existing account without changing roles');
    }
    if(state.roles.length){
     html+='<h3>Super-admin employee account review</h3>'+field('Employee email','email','email',g.email||'')+
      '<label class="fm-goal-field">Employee role<select name="role">'+state.roles.map(r=>'<option value="'+r.id+'"'+(r.id===g.proposedRoleId?' selected':'')+'>'+esc(r.name)+'</option>').join('')+'</select></label>';
     html+=button('propose','Prepare account');
     if(g.phase==='account_review'){
      const selected=state.roles.find(r=>r.id===g.proposedRoleId);
      html+='<p class="fm-goal-review">Pending approval: '+esc(g.name)+' · '+esc(g.email)+' · '+esc(selected?.name||'Role unavailable')+'. Confirm to create an employee account.</p>';
      html+=button('confirm','Super Admin: approve employee account',!selected);
     }
    }else html+='<p>Super admin can create and assign the employee account after reviewing the role.</p>';
   }else{
    html+='<p>Gelato account linked (ID '+g.employeeUserId+'). Existing login, role and permissions are unchanged.</p>';
    if(g.employeeUserId===Number(window.GelatoGlobalAgent?.user?.id))html+=button('enroll-own-face','Train my likeness');
    else html+='<p>Biometrics require the linked employee to sign in and consent personally.</p>';
    html+='<p><a href="timeclock.php">Enroll and verify your voice in Time Clock</a> · <a href="profile-pin.php">Face + PIN device setup</a></p>';
   }
  }else{
   if(!g.crmCustomerId){
    html+='<h3>Guest to CRM (optional)</h3>'+field('Guest email, if supplied','email','email');
    html+='<label class="fm-goal-consent"><input type="checkbox" name="crm-consent"> Guest explicitly agrees to CRM contact creation; no marketing subscription.</label>';
    html+=button('crm','Create CRM contact with consent');
   }else html+='<p>CRM contact linked. Marketing subscriptions require separate consent.</p>';
  }
  html+=button('complete','Finish onboarding goal',g.type==='employee'&&(!g.employeeUserId||(!g.photosDeferred&&g.photos.length<3)||!g.voiceVerified));
  if(g.type==='employee'&&!g.voiceVerified)html+='<p>The linked employee needs an active saved voice profile to complete enrollment.</p>';
  if(g.photosDeferred)html+='<p>Photos deferred in low light. Voice match identifies only a possible speaker, not a face or login.</p>';
 }
 body.innerHTML=html;
}
async function action(which){
 if(state.busy)return;state.busy=true;
 try{
  const get=name=>body.querySelector('[name="'+name+'"]')?.value?.trim()||'';
  if(which==='start'||which==='new'){const intro=get('name');const data=await request('start',{trackLabel:state.tracking});state.goal=data.goal;if(intro)await request('name',{goal:state.goal.id,name:intro});note('Onboarding goal created. Tell me your name.');}
  else if(which==='name'){await request('name',{goal:state.goal.id,name:get('name')});}
  else if(which.startsWith('type-'))await request('type',{goal:state.goal.id,type:which.slice(5)});
  else if(which==='photo-consent')await request('photo_consent',{goal:state.goal.id,consent:true});
  else if(which==='skip-photo')note('Photo enrollment skipped. You may complete it later.');
  else if(which==='voice-consent')await request('voice_consent',{goal:state.goal.id,consent:true});
  else if(which==='link-existing'){await request('link_existing',{goal:state.goal.id,userId:Number(get('existing'))});note('Existing Gelato account linked without role changes.');}
  else if(which==='auto-photos'){await window.GelatoFoodMonsterFastEnrollment.capturePhotos();}
  else if(which==='quick-enroll'){await window.GelatoFoodMonsterFastEnrollment.runQuickSetup();}
  else if(which==='auto-voice'){await window.GelatoFoodMonsterFastEnrollment.enrollVoice();}
  else if(which==='voice-check'){await window.GelatoFoodMonsterFastEnrollment.verifyVoice();}
  else if(which==='defer-photos'){await request('defer_photos',{goal:state.goal.id,lowLight:true});note('Photos deferred until lighting improves. Continue with voice.');}
  else if(which==='enroll-own-face'){
   if(state.goal.employeeUserId!==Number(window.GelatoGlobalAgent?.user?.id))throw Error('Only your own account can train your face.');
   const camera=window.GelatoFoodMonsterCamera;
   if(!camera)throw Error('Camera is unavailable.');
   if(!camera.active)await camera.start();
   await camera.enroll({persist:true});
   note(camera.enrolled?'Face trained and saved securely on this device.':'Face needs retraining in better lighting.');
  }
  else if(which==='photo'){
   const data=window.GelatoFoodMonsterParticipants?.captureGoalPhoto?.(state.tracking);
   if(!data)throw Error('Turn the camera on, select a participant, and wait for a stable face.');
   await request('photo',{goal:state.goal.id,image:data});
  }else if(which.startsWith('remove-'))await request('remove_photo',{goal:state.goal.id,photo:Number(which.slice(7))});
  else if(which==='propose'){
   const response=await request('propose_employee',{goal:state.goal.id,email:get('email'),roleId:Number(get('role'))});
   note('Review the employee email and role, then approve using the Super Admin account.');
  }else if(which==='confirm'){
   const goal=state.goal,role=state.roles.find(r=>r.id===goal.proposedRoleId);
   if(!role||!confirm('Create '+goal.name+' ('+goal.email+') with '+role.name+' permissions? The employee will set their own password and PIN.'))return;
   const data=await request('confirm_employee',{goal:state.goal.id});
   note(data.message||'Employee account created.');
  }else if(which==='crm'){
   if(!body.querySelector('[name="crm-consent"]')?.checked)throw Error('Ask the guest and check their explicit CRM consent first.');
   await request('convert_guest',{goal:state.goal.id,email:get('email'),consent:true});
  }else if(which==='complete')await request('close',{goal:state.goal.id,status:'completed'});
  else if(which==='cancel')await request('close',{goal:state.goal.id,status:'cancelled'});
 }catch(e){note(e.message||'Could not advance onboarding.');}
 finally{state.busy=false;}
}
panel.addEventListener('click',e=>{
 if(e.target.closest('[data-fm-goal-hide]'))hide();
 else if(e.target.closest('[data-fm-goal-refresh]'))void load();
 else if(e.target.closest('[data-fm-goal-cancel]')){if(state.goal&&confirm('Cancel this onboarding goal?'))void action('cancel');}
 else{const btn=e.target.closest('[data-goal-action]');if(btn)void action(btn.dataset.goalAction);}
});
launch.onclick=()=>{show();if(!state.goal)note('Hello! Welcome. What is your name?');};
function introductionText(text){
 text=String(text||'').trim();const matched=text.match(/^(?:(?:my name is|i(?:'m| am)|call me|this is)\s+)([\p{L}\p{M}' -]{2,75})[.!]?$/iu);
 if(matched)return matched[1].trim();
 if(/^[\p{L}\p{M}'-]+(?:\s+[\p{L}\p{M}'-]+){0,3}$/u.test(text)&&!/\b(?:sales|clock|orders|inventory|menu|please|help|stop|next|today)\b/i.test(text))return text;
 return '';
}
window.addEventListener('gelato-agent-before-send',event=>{
 const text=String(event.detail?.text||'');
 if(!state.opened)return;
 const g=state.goal;
 if(!g||!g.name){
  const name=introductionText(text);
  if(!name)return;
  event.preventDefault();
  void (async()=>{try{
   if(!state.goal){const data=await request('start',{trackLabel:state.tracking});state.goal=data.goal;}
   await request('name',{goal:state.goal.id,name});
   note('Nice to meet you, '+name+'. Are you an employee or a guest?');
  }catch(err){note(err.message);}})();
 }else if(g.type==='unknown'){
  const match=text.trim().toLowerCase().match(/^(?:i(?:'m| am) (?:an? )?)?(employee|guest)[.!]?$/);
  if(match){event.preventDefault();void request('type',{goal:g.id,type:match[1]}).catch(err=>note(err.message));}
 }
});
window.addEventListener('gelato-food-monster-new-track',event=>{
 const id=String(event.detail?.id||'');
 if(!id||state.goal?.status==='in_progress')return;
 state.tracking=id;
 show();note('Hello! Welcome. What is your name?');
 // Wait for a response before persisting a person's presence.
});
window.GelatoFoodMonsterGoals={show,hide,load,request,note,get trackId(){return state.tracking},get goal(){return state.goal},get opened(){return state.opened},begin(id=''){state.tracking=id;state.goal=null;show();}};
load();
})();
