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
assert.match(page,/id="cameraInputStatus"/);
assert.match(page,/HDMI capture adapter/);
assert.match(app,/navigator\.mediaDevices\?\.addEventListener\?\.\('devicechange'/);
assert.match(app,/getUserMedia\(\{video:videoConstraints,audio:false\}\)/);
assert.match(app,/videoConstraints\.deviceId=\{exact:deviceId\}/);
assert.match(app,/savedId&&!state\.cameraDevices\.some\(d=>d\.deviceId===savedId\)/);
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
const state={cameraDevices:[]};
const context={
 state,navigator:{mediaDevices:{enumerateDevices:async()=>connected}},
 cameraSupported:()=>true,cameraPrefs:()=>({deviceId:saved}),
 $:key=>key==='cameraDeviceSelect'?picker:key==='cameraInputStatus'?notice:null,
 escapeHtml:value=>String(value).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;'),
 Error
};
vm.createContext(context);
vm.runInContext(app.slice(start,end)+'\nthis.refresh=enumerateCameras;',context);

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

 console.log('glasses-usb-hdmi-inputs=ok');
}
run().catch(error=>{console.error(error);process.exitCode=1;});
