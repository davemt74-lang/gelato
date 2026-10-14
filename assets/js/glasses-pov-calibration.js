/* Section 284B: explicitly triggered first-person POV alignment/quality checks.
 * Preview-only, local-only. Image statistics are never ingredient evidence.
 */
(()=>{
'use strict';
if(window.GelatoGlassesPovCalibration)return;
const $=id=>document.getElementById(id);
const cfg=window.GELATO_GLASSES_SIMULATOR||{};
const KEY='gelato.glasses.pov.guide.v1:u'+String(cfg.userId||0)+':rt'+String(cfg.runtimePublicId||'default');
const SIZE=64,HEIGHT=36;
function analyzePixels(data,width=SIZE,height=HEIGHT){
  if(!data||width<2||height<2||data.length<width*height*4)throw new TypeError('Invalid captured frame.');
  const luma=new Float64Array(width*height);
  let total=0,squares=0,edges=0,pairs=0;
  for(let i=0;i<luma.length;i++){
    const n=i*4,y=.2126*data[n]+.7152*data[n+1]+.0722*data[n+2];
    luma[i]=y;total+=y;squares+=y*y;
  }
  for(let y=1;y<height;y++)for(let x=1;x<width;x++){
    const index=y*width+x;edges+=Math.abs(luma[index]-luma[index-1])+Math.abs(luma[index]-luma[index-width]);pairs+=2;
  }
  const brightness=total/luma.length,contrast=Math.sqrt(Math.max(0,squares/luma.length-brightness*brightness));
  const detail=pairs?edges/pairs:0;
  const messages=[];
  if(brightness<48)messages.push('Too dark; increase prep-area lighting.');
  if(brightness>215)messages.push('Overexposed; reduce glare.');
  if(contrast<16)messages.push('Low scene contrast; aim at the prep surface.');
  if(detail<6)messages.push('Low fine detail; check focus, lens and camera movement.');
  return {brightness:Math.round(brightness),contrast:Math.round(contrast),detail:Math.round(detail),
    quality:messages.length?'review':'ready',messages};
}
function guide(enabled){
  const el=$('glassesPovAimGuide');if(el)el.hidden=!enabled;
  try{localStorage.setItem(KEY,enabled?'1':'0');}catch{}
}
function capture(){
  const video=$('cameraVideo'),out=$('glassesPovCalibrationStatus');
  if(!video||!out)return null;
  if(!video.srcObject||!video.videoWidth||!video.videoHeight){
    out.textContent='Start Camera 1, aim at your working view, then run this check.';
    out.dataset.state='warning';return null;
  }
  try{
    const canvas=document.createElement('canvas');canvas.width=SIZE;canvas.height=HEIGHT;
    const ctx=canvas.getContext('2d',{willReadFrequently:true});
    if(!ctx)throw new Error('Canvas unavailable');
    ctx.drawImage(video,0,0,SIZE,HEIGHT);
    const result=analyzePixels(ctx.getImageData(0,0,SIZE,HEIGHT).data);
    out.dataset.state=result.quality;
    out.textContent='POV frame: '+result.quality.toUpperCase()+' · brightness '+result.brightness+
      ' · contrast '+result.contrast+' · detail '+result.detail+'. '+
      (result.messages.join(' ')||'Image quality is suitable for collecting reviewed training samples.')+
      ' Camera alignment follows your head direction, not verified eye gaze.';
    window.dispatchEvent(new CustomEvent('gelato-glasses-pov-calibrated',{detail:{
      slot:1,quality:result.quality,brightness:result.brightness,contrast:result.contrast,
      detail:result.detail,checkedAt:new Date().toISOString(),
      sourceKind:window.GelatoGlassesPrimaryCamera?.source?.()?.kind||'browser'
    }}));
    return result;
  }catch(error){
    out.textContent='Unable to sample the current camera frame. Check camera access and try again.';
    out.dataset.state='error';return null;
  }
}
function mount(){
  const check=$('glassesPovCheckFrame'),toggle=$('glassesPovShowAimGuide');
  if(!check||!toggle)return;
  let enabled=false;try{enabled=localStorage.getItem(KEY)==='1';}catch{}
  toggle.checked=enabled;guide(enabled);
  toggle.addEventListener('change',()=>guide(toggle.checked));
  check.addEventListener('click',capture);
}
window.GelatoGlassesPovCalibration=Object.freeze({analyzePixels,capture});
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount,{once:true});else mount();
})();
