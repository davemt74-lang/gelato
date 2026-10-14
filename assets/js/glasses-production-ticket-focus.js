/* Section 285A — KDS-scoped read-only ticket navigation.
 * All selections are view-local. KDS transitions remain in the separately
 * authorized kitchenCommand() path. No cameras, microphones or writes here. */
((root,factory)=>{
'use strict';
const api=factory();
if(typeof module==='object'&&module.exports)module.exports=api;
if(root)root.GelatoProductionTicketFocus=api;
})(typeof window!=='undefined'?window:null,()=>{'use strict';
const ACTIVE=new Set(['queued','in_progress','ready','held']);
function viewable(groups){
 return (Array.isArray(groups)?groups:[]).filter(group=>group&&group.key&&
   Array.isArray(group.items)&&group.items.some(item=>item?.kdsItemPublicId&&ACTIVE.has(item.status)));
}
function preferredItem(group){
 const items=(group?.items||[]).filter(item=>item?.kdsItemPublicId&&ACTIVE.has(item.status));
 if(!items.length)return null;
 return items.find(item=>item.status==='in_progress')||
   items.find(item=>item.status==='queued')||
   items.find(item=>item.status==='ready')||items[0];
}
function navigate(groups,currentItemId,direction){
 if(direction!==1&&direction!==-1)throw new TypeError('Ticket direction must be 1 or -1.');
 const available=viewable(groups);
 if(!available.length)return {ok:false,reason:'no_tickets'};
 if(available.length===1)return {ok:false,reason:'only_one_ticket'};
 const index=available.findIndex(group=>group.items.some(item=>item.kdsItemPublicId===currentItemId));
 const nextIndex=index<0?(direction===1?0:available.length-1):
   (index+direction+available.length)%available.length;
 const group=available[nextIndex],item=preferredItem(group);
 return item?{ok:true,group,item}:{ok:false,reason:'no_items'};
}
function findByNumber(groups,raw){
 const number=String(raw||'').trim();
 if(!/^[0-9]{1,24}$/.test(number))return {ok:false,reason:'invalid_number'};
 const matches=viewable(groups).filter(group=>group.items.some(item=>
   String(item?.ticket?.checkNumber||'').trim()===number));
 if(matches.length!==1)return {ok:false,reason:matches.length?'ambiguous_ticket':'ticket_not_found'};
 const item=preferredItem(matches[0]);
 return item?{ok:true,group:matches[0],item}:{ok:false,reason:'no_items'};
}
function readiness({mode,device,work,item,cameraLive,modelReady,recoveryState,hidden}={}){
 if(mode!=='live'||!device)return {state:'inactive',message:'Connect or select a live glasses runtime.'};
 if(device.virtualPreview)return {state:'preview',message:'Browser preview · not a paired glasses production runtime.'};
 if(hidden||recoveryState==='blocked'||recoveryState==='disconnected'||recoveryState==='recovering')
   return {state:'paused',message:'Production assistance paused until the runtime recovers.'};
 if(!work||!item)return {state:'waiting',message:'Connected · waiting for active station KDS work.'};
 if(!cameraLive)return {state:'ready',message:'KDS ticket available · enable Camera 1 for visual recognition.'};
 if(!modelReady)return {state:'observing',message:'Camera and KDS active · ingredient model not loaded.'};
 return {state:'recognizing',message:'KDS and verified ingredient model active · read-only recognition.'};
}
return Object.freeze({navigate,findByNumber,readiness,preferredItem,viewable});
});
