'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const similarity=fs.readFileSync('assets/js/glasses-camera-similarity.js','utf8');
const workspace=fs.readFileSync('assets/js/glasses-camera-workspace.js','utf8');
const app=fs.readFileSync('assets/js/glasses-camera-config.js','utf8');
const page=fs.readFileSync('glasses-simulator.php','utf8');
const api=fs.readFileSync('api/glasses-camera-photo-library.php','utf8');
const server=fs.readFileSync('includes/glasses-camera-photo-library.php','utf8');
const migration=fs.readFileSync('database/20261209_section258_camera_photo_library.sql','utf8');
const context={window:{},Math,Number,String,Map,JSON,Date,Promise,parseInt,Float32Array,
  createImageBitmap:async()=>({width:320,height:240,close(){}})};
vm.runInNewContext(similarity,context,{filename:'glasses-camera-similarity.js'});
const matcher=context.window.GelatoCameraSimilarity;
assert.equal(matcher.bitCount('0000000000000000','ffffffffffffffff'),64);
assert.equal(matcher.similarity('aaaaaaaaaaaaaaaa','aaaaaaaaaaaaaaaa'),100);
assert.equal(matcher.similarity('0000000000000000','ffffffffffffffff'),0);
assert.equal(matcher.similarity('broken','0000000000000000'),null);
let scored=matcher.compare('aaaaaaaaaaaaaaaa',[
  {id:1,slot:2,hash:'aaaaaaaaaaaaaaaa',isReference:true},
  {id:2,slot:3,hash:'aaaaaaaaaaaaaaaa',isReference:true},
  {id:3,slot:2,hash:'0000000000000000',isReference:false}
],{slot:2});
assert.deepEqual(scored.map(x=>x.id),[1,2]);
assert.equal(scored[0].sameCamera,true);
const first=new Uint8ClampedArray(9*8*4),second=new Uint8ClampedArray(64*64*4);
first.fill(128);second.fill(128);
for(let i=3;i<first.length;i+=4)first[i]=255;
for(let i=3;i<second.length;i+=4)second[i]=255;
const canvases=[];
context.document={createElement(){
  const canvas={width:0,height:0,
    getContext(){return {drawImage(){},getImageData(){return {data:canvas.width===9?first:second};}}}};
  canvases.push(canvas);return canvas;
}};
(async()=>{
  const fp=await matcher.fingerprint({});
  assert.equal(fp.hash,'0000000000000000','uniform scene dHash is stable');
  assert.equal(fp.width,320);assert.equal(fp.height,240);
  assert.ok(fp.qualityScore>=0&&fp.qualityScore<=100);
  assert.equal(typeof fp.blurScore,'number');
  assert.match(page,/glasses-camera-workspace\.js/);
  assert.match(page,/glasses-camera-similarity\.js/);
  assert.match(workspace,/for\(let slot=1;slot<=5;slot\+\+\)/);
  assert.match(workspace,/const name='camera'\+slot/);
  assert.match(workspace,/controls\.training/);
  assert.match(workspace,/role','tab'/);
  assert.match(workspace,/gcGallery'\+slot/);
  assert.match(workspace,/gcReference/);
  assert.match(workspace,/gcEmployeeRating/);
  assert.match(workspace,/gcConsent/);
  assert.match(workspace,/action:'upload',consentBasis:'training_media_opt_in'/);
  assert.match(workspace,/action:'register'/);
  assert.match(workspace,/action:'review'/);
  assert.match(workspace,/window\.GelatoCameraConfiguration\.capture\(slot\)/);
  assert.match(app,/gelato-camera-photo-captured/);
  assert.match(migration,/FOREIGN KEY \(media_id\) REFERENCES glasses_vision_training_media\(id\)/);
  assert.match(migration,/FOREIGN KEY \(menu_item_id\) REFERENCES menu_items\(id\)/);
  assert.match(migration,/UNIQUE KEY uq_glasses_camera_media/);
  assert.match(api,/app_verify_request_csrf\(\$in\)/);
  assert.match(api,/Cache-Control: private, no-store/);
  assert.match(server,/glasses_camera_location_authorize\(\$pdo,\$user,\$locationId,true\)/);
  assert.match(server,/consent_basis/);
  assert.match(server,/organization_id=\? AND location_id=\?/);
  assert.match(server,/app_audit\(/);
  assert.ok(!/customer.notification|sms.send|pos_check\.update|build.observe/.test(workspace),'gallery is read/review only');
  console.log('section258-camera-menu-reference-comparison=ok');
})().catch(e=>{console.error(e);process.exitCode=1;});