(() => {
  'use strict';
  if(window.GelatoCustomerPhotoNotifications)return;
  const $=id=>document.getElementById(id);
  const url='api/glasses-photo-notifications.php';
  const state={locationId:0,seq:0,busy:false,refreshing:false,enabled:false,photos:[],feedback:[],canManage:false};
  const cfg=()=>window.GELATO_GLASSES_SIMULATOR||{};
  const location=()=>Number(window.GelatoCameraConfiguration?.locationId||0);
  const msg=value=>{const node=$('gcDeliveryStatus');if(node)node.textContent=value;};
  const detail=photo=>{
    const enabled=state.enabled&&photo.customerOptedIn;
    if(photo.stage==='pre_oven'&&photo.approved)return 'Before-oven photo approved for private gallery; Inbox update waits for finished-pizza photo';
    return photo.notified?'Customer Inbox photo update sent':
      !state.enabled?'Restaurant sharing OFF':
      !photo.customerOptedIn?'Customer has not opted in':
      !photo.approved?'Staff approval required':
      !['ready','kitchen_complete','completed'].includes(photo.orderStatus)?
        'Waiting for order to be ready':'Eligible for customer Inbox notification';
  };
  function paint(){
    const enabled=$('gcDeliveryEnabled'),submit=$('gcDeliverySave');
    if(enabled){enabled.checked=state.enabled;enabled.disabled=!state.canManage||state.busy;}
    if(submit)submit.disabled=!state.canManage||state.busy;
    const count=$('gcDeliveryCount');if(count)count.textContent=state.photos.length+' photo candidates';
    const list=$('gcDeliveryCandidates');if(!list)return;list.replaceChildren();
    if(!state.photos.length){const empty=document.createElement('p');empty.className='sim-muted';
      empty.textContent='No confirmed post-oven photos for online orders. Confirm a Camera 3 photo first.';
      list.append(empty);}
    for(const photo of state.photos){
      const row=document.createElement('article');row.className='gc-delivery-item';
      const img=document.createElement('img');img.loading='lazy';img.alt='Confirmed post-oven pizza';
      img.src='api/glasses-vision-media.php?publicId='+encodeURIComponent(photo.mediaPublicId);
      const info=document.createElement('div');info.className='gc-delivery-info';
      const heading=document.createElement('strong');
      heading.textContent='Ticket #'+photo.checkNumber+' · '+(photo.stage==='pre_oven'?'Before Oven':'After Oven')+' · '+photo.orderStatus;
      const status=document.createElement('span');status.textContent=detail(photo);
      const approval=document.createElement('span');
      approval.textContent=photo.approved?'Approved for customer sharing':'Not approved for customer sharing';
      info.append(heading,status,approval);
      const actions=document.createElement('div');actions.className='gc-delivery-actions';
      const approvalButton=document.createElement('button');approvalButton.type='button';
      approvalButton.textContent=photo.approved?'Revoke Photo':'Approve Photo';
      approvalButton.disabled=!state.canManage||state.busy;
      approvalButton.addEventListener('click',()=>{
        if(!photo.approved&&!window.confirm('Share this specific confirmed pizza photo with the owning customer after opt-in?'))return;
        void change(photo.approved?'revoke':'approve',photo);
      });
      actions.append(approvalButton);
      if(photo.approved&&!photo.notified&&photo.stage==='post_oven'){
        const retry=document.createElement('button');retry.type='button';retry.textContent='Retry Inbox';
        retry.disabled=!state.canManage||state.busy||!state.enabled;
        retry.addEventListener('click',()=>void change('retry',photo));actions.append(retry);
      }
      row.append(img,info,actions);list.append(row);
    }
    const feedbackList=$('gcDeliveryFeedback');
    if(feedbackList){
      feedbackList.replaceChildren();
      if(!state.feedback.length)feedbackList.textContent='No customer photo ratings yet.';
      for(const review of state.feedback.slice(0,30)){
        const row=document.createElement('article');row.className='gc-delivery-review';
        const head=document.createElement('strong');head.textContent='Ticket #'+review.checkNumber+
          ' · '+(review.stage==='pre_oven'?'Before Oven':'After Oven')+' · '+review.rating+'/5 stars';
        const comment=document.createElement('p');comment.textContent=review.comment||'Rating only';
        row.append(head,comment);feedbackList.append(row);
      }
    }
  }
  async function refresh({quiet=false}={}){
    const id=location();
    if(id!==state.locationId){state.locationId=id;state.seq++;state.photos=[];state.enabled=false;paint();}
    if(!id||state.refreshing)return;
    const epoch=state.seq;state.refreshing=true;
    try{
      const response=await fetch(url+'?locationId='+encodeURIComponent(id),{
        credentials:'same-origin',method:'GET',cache:'no-store'});
      const data=await response.json().catch(()=>({ok:false}));
      if(epoch!==state.seq||id!==state.locationId)return;
      if(!response.ok||!data.ok)throw new Error(data.message||'Could not load photo delivery settings.');
      if(/^[0-9a-f]{64}$/i.test(String(data.csrfToken||'')))cfg().csrf=data.csrfToken;
      state.enabled=!!data.delivery.enabled;
      state.photos=data.delivery.photos||[];state.feedback=data.delivery.feedback||[];
      state.canManage=!!data.delivery.canManage;paint();
      if(!quiet)msg('Customer photo delivery settings synchronized. Existing order updates remain independent.');
    }catch(e){
      state.photos=[];state.feedback=[];state.enabled=false;state.canManage=false;paint();
      if(!quiet)msg(e.message||'Customer photo delivery requires database upgrade and appropriate permissions.');
    }finally{state.refreshing=false;}
  }
  async function post(body){
    const response=await fetch(url,{method:'POST',credentials:'same-origin',cache:'no-store',
      headers:{'Content-Type':'application/json','X-CSRF-Token':cfg().csrf},
      body:JSON.stringify({...body,csrf_token:cfg().csrf,locationId:state.locationId})});
    const data=await response.json().catch(()=>({ok:false}));
    if(!response.ok||!data.ok)throw new Error(response.status===419?
      'Security token expired; refresh before retrying. No photo was automatically sent.':
      data.message||'Customer photo action failed.');
    return data;
  }
  async function change(action,photo){
    if(state.busy||!state.locationId)return;
    const epoch=state.seq;state.busy=true;paint();
    try{
      const body=action==='settings'?{action,enabled:!!$('gcDeliveryEnabled').checked}:
        {action,onlineOrderPublicId:photo.onlineOrderPublicId,
          stageId:photo.stageId,expectedRevision:photo.revision};
      const data=await post(body);
      if(epoch!==state.seq)return;
      state.enabled=!!data.delivery.enabled;
      state.photos=data.delivery.photos||[];state.feedback=data.delivery.feedback||[];
      state.canManage=!!data.delivery.canManage;
      const result=data.result||{};
      const delivered=result.delivery||result;
      msg(action==='settings'?(state.enabled?'Photo Inbox sharing enabled at this location.':'Photo Inbox sharing disabled at this location.'):
        action==='revoke'?'Photo sharing revoked; customer access is immediately blocked.':
        delivered.sent?'Customer photo message delivered to account Inbox.':
        'Action saved. Inbox delivery: '+(delivered.reason||'waiting for customer opt-in, approved photo and ready order')+'.');
    }catch(e){
      msg(e.message||'Customer photo action failed.');await refresh({quiet:true});
    }finally{state.busy=false;paint();}
  }
  function mount(){
    const overview=$('sgv-panel-overview');if(!overview)return;
    const card=document.createElement('section');card.className='console-card gc-delivery-section';
    card.innerHTML='<div class="console-heading"><div><small>SECTION 260 · CUSTOMER INBOX</small>'+
      '<strong>Approved Pizza Photo Notifications</strong></div><span id="gcDeliveryCount">0 photo candidates</span></div>'+
      '<p class="sim-muted">Customer photo sharing defaults OFF. Photos require a confirmed Camera 3 match, staff approval, customer opt-in and a ready/completed online order. Never sends SMS or email.</p>'+
      '<label class="gc-delivery-toggle"><input type="checkbox" id="gcDeliveryEnabled"> Enable private photo sharing at this restaurant location</label>'+
      '<div class="inline-actions"><button id="gcDeliverySave" type="button">Save Sharing Setting</button>'+
      '<button id="gcDeliveryRefresh" type="button">Refresh Photos</button></div>'+
      '<p id="gcDeliveryStatus" role="status" aria-live="polite" class="sim-muted">Photo notifications are off until explicitly enabled.</p>'+
      '<div id="gcDeliveryCandidates" class="gc-delivery-candidates"></div>'+
      '<div class="gc-heading"><strong>Customer Photo Ratings</strong></div>'+
      '<div id="gcDeliveryFeedback" class="gc-delivery-feedback"></div>'+
      '<p class="sim-muted">Delivery: customer Inbox is supported. Email and SMS photo delivery are unavailable until an approved provider integration and separate consent controls are configured.</p>';
    overview.appendChild(card);
    $('gcDeliverySave').addEventListener('click',()=>{
      if($('gcDeliveryEnabled').checked&&
        !window.confirm('Enable customer Inbox photo sharing for this restaurant? Staff approval and each customer opt-in are still required.')){
        $('gcDeliveryEnabled').checked=state.enabled;return;
      }
      void change('settings');
    });
    $('gcDeliveryRefresh').addEventListener('click',()=>void refresh());
    window.addEventListener('gelato-glasses-location-change',()=>void refresh());
    window.addEventListener('gelato-glasses-work-change',()=>{if(!document.hidden)void refresh({quiet:true});});
    setInterval(()=>{if(!document.hidden&&state.locationId&&!state.busy)void refresh({quiet:true});},12000);
    void refresh();
  }
  window.GelatoCustomerPhotoNotifications={mount,refresh,status:()=>({
    locationId:state.locationId,enabled:state.enabled,photos:state.photos.length})};
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount,{once:true});
  else mount();
})();