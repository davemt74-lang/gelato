/* User-visible detector state: the primary Camera 1 remains controlled by Gelato. */
(()=>{'use strict';
const base=window.GelatoMediaPipeHands;
if(!base)return;
const $=id=>document.getElementById(id);
const controller=base.createRuntime({onStatus:(phase,note)=>{
  const status=$('glassesHandsModelStatus'),toggle=$('glassesHandsToggle');
  if(status){status.dataset.state=phase;status.textContent=note||phase.toUpperCase();}
  if(toggle){
    toggle.textContent=phase==='running'?'Stop Hand Tracking':
      phase==='loading'?'Loading detector…':phase==='error'?'Retry Hand Tracking':'Start Hand Tracking';
    toggle.disabled=phase==='loading'||!(window.GelatoGlassesNavigation?.primaryCamera?.()?.srcObject);
  }
}});
window.GelatoMediaPipeHandsRuntime=controller;
function context(){
  // The simulator supplies the authenticated KDS focus and per-item checklist.
  // A standalone retry button doesn't synthesize either value.
  return window.GelatoGlassesNavigation?.handContext?.()||{};
}
function mount(){
 const toggle=$('glassesHandsToggle');if(!toggle)return;
 toggle.addEventListener('click',()=>{
   if(controller.isActive()){controller.stop();return;}
   const video=window.GelatoGlassesNavigation?.primaryCamera?.();
   if(video?.srcObject)void controller.start(video,context);
 });
 window.addEventListener('pagehide',()=>controller.close());
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount,{once:true});else mount();
})();