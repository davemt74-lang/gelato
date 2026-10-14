'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

const app=fs.readFileSync('assets/js/glasses-web-simulator.js','utf8');
const page=fs.readFileSync('glasses-simulator.php','utf8');
const aux=fs.readFileSync('assets/js/glasses-multicamera.js','utf8');
const docs=fs.readFileSync('docs/GLASSES-SECTION281-LIVE-MEDIAPIPE-HANDS.md','utf8');

assert.match(page,/id="cameraDeviceSelect"/);
assert.match(page,/USB \/ HDMI CAMERA INPUT/);
assert.match(page,/id="refreshCameraInputs"/);
assert.match(page,/id="allowCameraDiscovery"/);
assert.match(app,/function requestCameraDiscoveryAccess\(\)/);
assert.match(app,/function applyCameraPresentation\(persist=true\)/);
assert.match(app,/cameraMirror'\)\.checked=!!cp\.mirror;applyCameraPresentation\(false\);await enumerateCameras\(\)/,
 'bootstrap should restore stored camera ID before enumerating devices');
assert.match(page,/id="cameraInputStatus"/);
assert.match(page,/HDMI capture adapter/);
assert.match(app,/navigator\.mediaDevices\?\.addEventListener\?\.\('devicechange'/);
assert.match(app,/getUserMedia\(\{video:videoConstraints,audio:false\}\)/);
assert.match(app,/videoConstraints\.deviceId=\{exact:deviceId\}/);
assert.match(app,/savedId&&!state\.cameraSelectableSources\.some\(d=>d\.key===savedId\)/);
assert.match(app,/id="cameraMeta"|\$\('cameraMeta'\)/);
assert.match(aux,/enumerateDevices\(\)/);
assert.match(aux,/getUserMedia\(\{video:\{deviceId:\{exact:id\}/);
assert.match(docs,/USB webcams and HDMI camera feeds/);
assert.ok(!/getDisplayMedia\(/.test(app.slice(app.indexOf('function cameraInputNote('),app.indexOf('let cameraStartEpoch=0;'))),
  'HDMI capture feeds use browser video inputs, not screen sharing');

const start=app.indexOf('function cameraInputNote('),end=app.indexOf('let cameraStartEpoch=0;',start);
assert.ok(start>0&&end>start,'camera picker logic exists');
let saved='usb-device';
let connected=[
  {kind:'videoinput',deviceId:'usb-device',label:'USB Camera'},
  {kind:'videoinput',deviceId:'hdmi-device',label:'USB Video Capture HDMI'},
  {kind:'audioinput',deviceId:'mic',label:'Microphone'}
];
const picker={value:'',_html:''};
Object.defineProperty(picker,'innerHTML',{get(){return this._html;},set(text){this._html=text;this.value='';}});
const notice={textContent:'',dataset:{}};
const scanButton={disabled:false};
let consentProbeCount=0,consentProbeStopped=0;
const state={cameraDevices:[],cameraSelectableSources:[]};
const context={
 state,window:{GelatoGlassesCameraSources:null},navigator:{mediaDevices:{enumerateDevices:async()=>connected}},
 cameraSupported:()=>true,cameraPrefs:()=>({deviceId:saved}),
 $:key=>key==='cameraDeviceSelect'?picker:key==='cameraInputStatus'?notice:key==='allowCameraDiscovery'?scanButton:null,
 escapeHtml:value=>String(value).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;'),
 Error
};
vm.createContext(context);
vm.runInContext(app.slice(start,end)+'\nthis.refresh=enumerateCameras;this.scan=requestCameraDiscoveryAccess;',context);

async function run(){
 await context.refresh();
 assert.equal(state.cameraDevices.length,2,'both USB and HDMI enumerate as video inputs');
 assert.match(picker.innerHTML,/USB Camera/);
 assert.match(picker.innerHTML,/USB Video Capture HDMI/);
 assert.ok(!picker.innerHTML.includes('Microphone'),'audio inputs are not offered as camera sources');
 assert.equal(picker.value,'usb-device','saved USB selection is restored');
 picker.value='hdmi-device';saved='hdmi-device';
 await context.refresh();
 assert.equal(picker.value,'hdmi-device','refresh preserves active HDMI selection');

 connected=[connected[0],connected[2]];
 await context.refresh();
 assert.equal(picker.value,'','disconnected HDMI source is not silently replaced by USB');
 assert.match(notice.textContent,/unavailable/i);
 assert.equal(notice.dataset.state,'warning');

 connected=[{kind:'videoinput',deviceId:'usb-device',label:'USB Camera'},
 {kind:'videoinput',deviceId:'hdmi-device',label:'HDMI Capture'}];
 await context.refresh();
 assert.equal(picker.value,'hdmi-device','reconnected HDMI source becomes selectable again');

 picker.value='usb-device';saved='usb-device';
 await context.refresh();
 assert.equal(picker.value,'usb-device','switching back to USB still works');

 // Refresh remains passive. Explicit permission scan enumerates visible
 // sources while a temporary capture stream is alive, then releases it.
 let permitted=false;
 context.navigator.mediaDevices.enumerateDevices=async()=>permitted?connected:[
   {kind:'videoinput',deviceId:'',label:''}
 ];
 context.navigator.mediaDevices.getUserMedia=async options=>{
   assert.equal(options.video,true);
   assert.equal(options.audio,false);
   consentProbeCount++;permitted=true;
   return {getTracks:()=>[{stop(){consentProbeStopped++;permitted=false;}}]};
 };
 saved='';picker.value='';
 await context.refresh();
 assert.equal(consentProbeCount,0,'plain refresh never requests a camera stream');
 assert.equal(state.cameraDevices.length,0,'hidden device is not fabricated into the picker');
 assert.match(notice.textContent,/Allow Camera Access/i);
 await context.scan();
 assert.equal(consentProbeCount,1,'permission scan is explicitly user-initiated');
 assert.equal(consentProbeStopped,1,'temporary video track always released');
 assert.equal(scanButton.disabled,false,'scan button re-enabled');
 assert.match(picker.innerHTML,/HDMI Capture/,'scan reveals HDMI input while permission probe is live');

 context.navigator.mediaDevices.getUserMedia=async()=>{const e=new Error('denied');e.name='NotAllowedError';throw e;};
 await context.scan();
 assert.equal(scanButton.disabled,false,'permission errors restore scan button');
 assert.equal(notice.dataset.state,'error');
 assert.match(notice.textContent,/denied/i);

 console.log('glasses-usb-hdmi-inputs=ok');
}
run().catch(error=>{console.error(error);process.exitCode=1;});
