'use strict';
// Run the shipped simulator UI in Chromium against controlled API responses.
const assert=require('node:assert/strict');
const {createHash}=require('node:crypto');
const fs=require('node:fs/promises');
const path=require('node:path');
const {chromium}=require('playwright');

const root=path.resolve(__dirname,'..');
const csrf='a'.repeat(64);
const device={publicId:'fixture-device',displayName:'Fixture device',locationId:1,locationName:'Fixture kitchen',
  stationPublicId:'pizza',stationName:'Pizza',simulatorWritable:true,kitchenWritable:true,virtualPreview:false};
const line={id:123,menuItemId:9,name:'Audit pizza',optionName:'12 inch',quantity:2,
  updatedAt:'2026-11-03 12:00:00.000000',specialInstructions:'No basil',modifiers:[{name:'Extra cheese'}]};
const item={kdsItemPublicId:'fixture-kds',kdsRevision:1,status:'in_progress',
  ticket:{checkPublicId:'fixture-check',checkNumber:'101',tableName:'Table 7',guestCount:2},posLine:line,menu:{ingredients:[]}};
const second={...item,kdsItemPublicId:'second-kds',posLine:{...line,id:124,name:'Second pizza'}};
const station={publicId:'pizza',name:'Pizza'};
const work={location:{id:1,name:'Fixture kitchen'},station,focusItem:item,items:[item,second],metrics:{},revision:'1'.repeat(64)};
const mozzarella='ingredient:9',customization='manual:order-customization';
const shadowArtifacts={champion:'fixture champion',challenger:'fixture challenger'};
function shadowPackage(name){
  const bytes=shadowArtifacts[name];
  return {publicId:name,modelName:name,modelVersion:'1',runtimeType:'onnx',artifactUrl:'/test-model/'+name,
    artifactBytes:Buffer.byteLength(bytes),artifactSha256:createHash('sha256').update(bytes).digest('hex'),
    metadata:{browserInference:{schema:'gelato.browser_onnx_detector.v1',decoder:'yolo_v8',labels:['Mozzarella'],
      input:{name:'input',width:32,height:32},output:{name:'output',layout:'rows',boxScale:'normalized'}}}};
}
const champion=shadowPackage('champion'),challenger=shadowPackage('challenger');
const components=[
  {componentKey:mozzarella,displayName:'Mozzarella',status:'waiting',expectedQuantity:2,detectedQuantity:0,unit:'portion',optional:false},
  {componentKey:customization,displayName:'Review ordered size and customizations',status:'verify',expectedQuantity:2,detectedQuantity:0,optional:false,
    metadata:{manualReviewRequired:true}},
  {componentKey:'ingredient:10',displayName:'Basil',status:'waiting',expectedQuantity:2,detectedQuantity:0,unit:'portion',optional:false,
    metadata:{advisoryForCustomizedOrder:true}}
];
function build(publicId='fixture-build',confirmed=new Set()){
  return {publicId,kdsItemPublicId:item.kdsItemPublicId,kdsStatus:item.status,status:'active',posCheckItemId:line.id,
    locationId:1,stationPublicId:'pizza',context:{...line,posLineUpdatedAt:line.updatedAt},summary:{},
    components:components.map(component=>confirmed.has(component.componentKey)
      ?{...component,status:'confirmed',detectedQuantity:component.expectedQuantity}:component)};
}
function renderPage(source){
  return source.slice(source.indexOf('<!doctype html>'))
    .replace(/<\?php.*?\?>/gs,'')
    .replace('<?=json_encode(app_csrf_token(),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>',JSON.stringify(csrf))
    .replace("<?=(int)$user['id']?>",'7')
    .replace("<?=json_encode(trim((string)($_GET['runtime']??'')),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>",'""')
    .replace("<?=app_has_permission('glasses.manage',$user)?'true':'false'?>",'true');
}
async function waitFor(queue,length){
  const deadline=Date.now()+6000;
  while(queue.length<length){
    assert.ok(Date.now()<deadline,'Expected controlled simulator request');
    await new Promise(resolve=>setTimeout(resolve,10));
  }
  return queue[length-1];
}
async function main(){
  const html=renderPage(await fs.readFile(path.join(root,'glasses-simulator.php'),'utf8'));
  assert.ok(!html.includes('<?'),'Simulator fixture must supply all PHP page values.');
  const browser=await chromium.launch({headless:true,executablePath:process.env.GELATO_CHROMIUM_EXECUTABLE||undefined});
  try{
    const page=await browser.newPage(),errors=[],requests=[],pendingStarts=[],confirmed=new Set();
    page.setDefaultTimeout(7000);
    await page.addInitScript(()=>{
      navigator.mediaDevices.getUserMedia=async()=>{
        const canvas=document.createElement('canvas');
        canvas.width=320;canvas.height=240;
        const context=canvas.getContext('2d');
        context.fillRect(0,0,canvas.width,canvas.height);
        const stream=canvas.captureStream(0),track=stream.getVideoTracks()[0];
        track.requestFrame();
        let paints=0;
        const timer=setInterval(()=>{
          if(track.readyState==='ended'){clearInterval(timer);return;}
          if(window.pauseFixtureCamera)return;
          context.fillStyle=++paints%2?'red':'blue';context.fillRect(0,0,320,240);track.requestFrame();
        },35);
        return stream;
      };
      window.ort={Tensor:class {},InferenceSession:{create:async bytes=>({
        inputNames:['input'],outputNames:['output'],release:async()=>{},
        run:async()=>{
          if(new TextDecoder().decode(bytes)==='fixture challenger'&&window.deferShadowInference)
            await new Promise(resolve=>{window.releaseShadowInference=resolve;});
          return {output:{dims:[1,1,5],data:[.5,.5,.25,.25,.95]}};
        }
      })}};
    });
    let deferStart=false,denyWork=false,validationRevision=0,shadowCount=29;
    const shadowSummary=()=>({status:'running',detectionFrameCount:shadowCount,frameCount:shadowCount});
    page.on('pageerror',error=>errors.push(error.message));
    await page.route('**/*',async route=>{
      const request=route.request(),url=new URL(request.url()),pathname=url.pathname;
      if(url.hostname!=='localhost')return route.abort();
      if(pathname.startsWith('/test-model/'))return route.fulfill({body:shadowArtifacts[pathname.split('/').at(-1)]});
      if(pathname==='/glasses-simulator.php')return route.fulfill({contentType:'text/html',body:html});
      if(pathname==='/api/glasses-camera-locations.php')return route.fulfill({json:{ok:true,configuration:{canManage:true,
        stations:[station],cameras:[{slot:1,displayName:'Fixture camera',enabled:true,role:'prep',stationPublicId:'pizza',
          rotation:0,revision:1,zone:{x:.1,y:.1,width:.8,height:.8}}]}}});
      if(pathname==='/api/glasses-simulator.php'){
        if(request.method()==='GET'){
          const isWork=url.searchParams.get('action')==='work';
          if(isWork&&denyWork)return route.fulfill({status:403,json:{ok:false,message:'Kitchen Display access is not assigned.'}});
          const data=isWork?{work}:{csrfToken:csrf,devices:[device]};
          return route.fulfill({json:{ok:true,...data}});
        }
        const data=request.postDataJSON();
        requests.push(data);
        if(data.action==='build.start'){
          if(deferStart){pendingStarts.push(route);return;}
          return route.fulfill({json:{ok:true,buildSession:build()}});
        }
        if(data.action==='build.confirm'){
          confirmed.add(data.componentKey);
          return route.fulfill({json:{ok:true,buildSession:build(undefined,confirmed)}});
        }
        if(data.action==='build.observe'){
          const observed=build();
          observed.components=observed.components.map(c=>c.componentKey===data.componentKey?{...c,status:'verify'}:c);
          return route.fulfill({json:{ok:true,buildSession:observed}});
        }
        if(data.action==='validation.evaluate'){
          const revision=++validationRevision;
          // The validation sees evidence changed on a second client after this tab's last build response.
          const checks=build(undefined,confirmed).components.map(c=>({componentKey:c.componentKey,displayName:c.displayName,
            expectedQuantity:c.expectedQuantity,detectedQuantity:c.componentKey===mozzarella?2.25:c.detectedQuantity,
            unit:c.unit||'',componentStatus:c.status,optional:c.optional||!!c.metadata?.advisoryForCustomizedOrder,confidence:1}));
          return route.fulfill({json:{ok:true,validation:{publicId:'validation',revision,
            buildSessionPublicId:'fixture-build',status:'ready_for_finishing',snapshotHash:'snapshot-'+revision,
            summary:{checks,requiredComponents:2,passedComponents:2,missingComponents:0,unexpectedComponents:0}}}});
        }
        if(data.action==='handoff.prepare'){
          return route.fulfill({json:{ok:true,handoffConfirmation:{
            token:'token-'+validationRevision,validationPublicId:data.validationPublicId,validationSnapshotHash:data.validationSnapshotHash}}});
        }
        if(data.action==='handoff.expo')return route.fulfill({json:{ok:true,handoff:{kdsStatus:'ready'}}});
        if(data.action==='vision.model_assignment')return route.fulfill({json:{ok:true,assignment:{
          action:'apply',detectorName:data.detectorName,buildSessionPublicId:data.buildSessionPublicId,package:champion,
          compatibility:{compatible:true}}}});
        if(data.action==='vision.profile')return route.fulfill({json:{ok:true,profile:{mappings:[]}}});
        if(data.action==='vision.shadow_assignment')return route.fulfill({json:{ok:true,shadow:{
          action:'shadow',runPublicId:'shadow-run',champion,challenger,summary:shadowSummary()}}});
        if(data.action==='vision.shadow_report'){
          shadowCount=Math.min(shadowCount+1,30);
          return route.fulfill({json:{ok:true,shadow:{summary:shadowSummary()}}});
        }
        return route.fulfill({json:{ok:true}});
      }
      if(pathname.startsWith('/api/'))return route.fulfill({json:{ok:false,message:'Unused fixture endpoint'}});
      const file=path.resolve(root,'.'+pathname);
      if(file.startsWith(root+path.sep)){
        try{if((await fs.stat(file)).isFile())return route.fulfill({path:file});}catch{}
      }
      return route.fulfill({status:404,body:'Unavailable fixture asset'});
    });
    await page.goto('http://localhost/glasses-simulator.php',{waitUntil:'domcontentloaded'});
    const waitText=(selector,value)=>page.waitForFunction(([selector,value])=>
      document.querySelector(selector)?.textContent.includes(value),[selector,value],{timeout:6000});
    const click=selector=>page.locator(selector).evaluate(button=>button.click());
    const action=name=>requests.filter(r=>r.action===name);
    await waitText('#consoleItem','Audit pizza');
    await click('#startBuild');
    await waitText('#buildBadge','fixture-build');
    await click('#sgv-tab-build');
    assert.equal(await page.locator('[data-detect]').count(),0,'Live UI must never offer scripted Detect.');
    assert.equal(await page.locator('#injectUnexpected').isDisabled(),true,'Live UI must disable scripted exceptions.');
    await page.locator('#injectUnexpected').dispatchEvent('click');
    assert.equal(action('build.observe').length,0,'Scripted controls must never POST production observations.');

    const ingredientButton='[data-confirm-build-component="'+mozzarella+'"]';
    const customizationButton='[data-confirm-build-component="'+customization+'"]';
    assert.equal(await page.locator(ingredientButton).textContent(),'Confirm full quantity');
    const ordered=await page.locator(customizationButton).locator('..').textContent();
    for(const detail of ['Ordered quantity: 2','12 inch','Extra cheese','No basil'])assert.ok(ordered.includes(detail),detail);
    assert.equal(await page.locator('[data-confirm-build-component="ingredient:10"]').count(),0,'Base recipe references are advisory.');

    // Feed a synthetic video stream through the actual browser camera controls.
    await click('#sgv-tab-camera');
    await page.waitForFunction(()=>window.GelatoCameraConfiguration?.isEnabled(1));
    await page.selectOption('#sceneSourceSelect','camera',{force:true});
    await waitText('#eventLog','Browser camera preview started.').catch(async error=>{
      throw new Error(await page.locator('#eventLog').textContent(),{cause:error});
    });
    await click('#sgv-tab-build');
    await click('#cameraManualTarget');
    await page.locator('#glassesStage').evaluate(stage=>{
      const rect=stage.getBoundingClientRect();
      stage.dispatchEvent(new MouseEvent('click',{bubbles:true,clientX:rect.left+rect.width/2,clientY:rect.top+rect.height/2}));
    });
    await click('#submitCameraTarget');
    await waitText('#eventLog','Confirm the full quantity after review.');
    assert.equal(action('build.observe').length,1);
    const camera=action('build.observe')[0];
    assert.equal(camera.componentKey,mozzarella);
    assert.equal(camera.confidence,.74);
    assert.deepEqual(camera.metadata,{source:'web_glasses_simulator_camera',manualTarget:true});
    assert.match(await page.locator(ingredientButton).locator('..').textContent(),/verify · 0 \/ 2 portion/);
    await click('#stopCamera');
    await click(ingredientButton);
    await page.locator(ingredientButton).waitFor({state:'detached'});
    await click(customizationButton);
    await page.locator(customizationButton).waitFor({state:'detached'});
    assert.deepEqual(action('build.confirm').map(r=>r.componentKey),[mozzarella,customization]);

    // Manual multi-unit verification remains available even in Automatic vision mode.
    await click('#sgv-tab-runtime');
    await page.selectOption('#visionMode','automatic',{force:true});
    await click('#sgv-tab-build');
    await click('#evaluateBuild');
    await waitText('#eventLog','Validation result: ready_for_finishing');
    await waitText('#componentControls','2.25 / 2 portion');
    await waitText('#hudValidationCount','2 / 2 accounted');
    await click('#handoffExpo');
    await waitText('#handoffExpo','Confirm Send to Expo');
    assert.equal(action('handoff.expo').length,0,'Preparing must never advance kitchen work.');
    assert.equal(action('handoff.prepare')[0].validationSnapshotHash,'snapshot-1');
    await page.selectOption('#visionMode','assisted',{force:true});
    await waitText('#handoffExpo','Send to Expo / Finishing');
    await click('#handoffExpo');
    await waitText('#handoffExpo','Confirm Send to Expo');
    assert.equal(action('handoff.expo').length,0,'Switching vision mode retires the pending confirmation.');
    assert.equal(action('handoff.prepare').at(-1).validationSnapshotHash,'snapshot-1');
    item.kdsRevision++;
    work.revision='2'.repeat(64);
    await click('#refreshWork');
    await waitText('#handoffExpo','Send to Expo / Finishing');
    assert.equal(await page.locator('#handoffExpo').isDisabled(),true,'An intervening KDS revision retires the reviewed validation.');
    await click('#evaluateBuild');
    await waitText('#handoffExpo','Send to Expo / Finishing');
    await page.waitForFunction(()=>!document.getElementById('handoffExpo').disabled);
    await click('#handoffExpo');
    await waitText('#handoffExpo','Confirm Send to Expo');
    assert.equal(action('handoff.prepare').at(-1).validationSnapshotHash,'snapshot-2');
    await click('#handoffExpo');
    await waitText('#eventLog','Explicit Expo handoff completed');
    assert.deepEqual(action('handoff.expo').map(r=>[r.buildSessionPublicId,r.confirmationToken]),[['fixture-build','token-2']]);

    // A POS edit to the same active KDS item retires its old quantity checklist.
    await click('#sgv-tab-overview');
    await page.waitForFunction(()=>!document.getElementById('startBuild').disabled);
    await click('#startBuild');
    await waitText('#buildBadge','fixture-build');
    line.updatedAt='2026-11-03 12:00:01.000000';
    line.quantity=3;
    work.revision='3'.repeat(64);
    await click('#refreshWork');
    await waitText('#eventLog','The order or station changed. Start a new build for the current order.');
    await waitText('#buildBadge','no glasses build active');
    assert.equal(await page.locator('#startBuild').isEnabled(),true,'Cook can restart the same item after the edit.');

    // A revoked Kitchen Display grant clears previously rendered order details.
    await click('#startBuild');
    await waitText('#buildBadge','fixture-build');
    denyWork=true;
    await click('#refreshWork');
    await waitText('#syncBadge','ACCESS DENIED');
    await waitText('#buildBadge','no glasses build active');
    assert.doesNotMatch(await page.locator('#consoleItem').textContent(),/Audit pizza/);
    assert.equal(await page.locator('#startBuild').isDisabled(),true);
    denyWork=false;
    await click('#refreshWork');
    await waitText('#consoleItem','Audit pizza');

    // Only fresh camera frames under the exact governed ONNX champion can report to Shadow.
    await click('#startBuild');
    await waitText('#buildBadge','fixture-build');
    await click('#startCamera');
    await waitText('#eventLog','Browser camera preview started.');
    await page.selectOption('#visionMode','assisted',{force:true});
    await click('#loadVisionModel');
    await waitText('#visionModelStatus','READY');
    await page.selectOption('#visionAdapterSelect','fixture',{force:true});
    assert.equal(await page.locator('#startShadowModel').isDisabled(),true);
    assert.equal(action('vision.shadow_assignment').length,0);
    await page.evaluate(()=>{window.pauseFixtureCamera=true;});
    await page.selectOption('#visionAdapterSelect','onnx',{force:true});
    await click('#startShadowModel');
    await waitText('#shadowSummary','29/30 detection frames');
    assert.equal(await page.locator('#completeShadowModel').isDisabled(),true);
    await click('#frameBurst');
    await waitText('#eventLog','frame burst into bounded pipeline');
    await page.waitForTimeout(700);
    assert.equal(action('vision.shadow_report').length,0,'Injected burst frames cannot qualify a shadow run.');
    await page.evaluate(()=>{window.pauseFixtureCamera=false;});
    await waitText('#shadowSummary','30/30 detection frames');
    assert.equal(await page.locator('#completeShadowModel').isEnabled(),true);
    const [report]=action('vision.shadow_report');
    assert.deepEqual(report.metadata,{source:'web_glasses_simulator_shadow',authoritative:false,adapter:'onnx',
      cameraSource:'browser',championArtifactSha256:champion.artifactSha256,challengerArtifactSha256:challenger.artifactSha256});
    await page.evaluate(()=>{window.deferShadowInference=true;});
    await page.waitForFunction(()=>typeof window.releaseShadowInference==='function');
    const reportCount=action('vision.shadow_report').length;
    await page.selectOption('#visionAdapterSelect','fixture',{force:true});
    await page.evaluate(()=>window.releaseShadowInference());
    await waitText('#shadowModelStatus','Shadow session released');
    await page.waitForTimeout(700);
    assert.equal(action('vision.shadow_report').length,reportCount,'Switching to fixture retires the run and drops in-flight inference.');
    assert.equal(await page.locator('#completeShadowModel').isDisabled(),true);
    await click('#stopCamera');
    await click('#resetSimulator');
    await waitText('#consoleItem','Audit pizza');

    // Switching item, then display mode, invalidates a delayed Build Start response.
    deferStart=true;
    await page.waitForFunction(()=>!document.getElementById('startBuild').disabled);
    await click('#startBuild');
    const firstStart=await waitFor(pendingStarts,1);
    await page.selectOption('#workItemSelect',second.kdsItemPublicId);
    await waitText('#consoleItem','Second pizza');
    await firstStart.fulfill({json:{ok:true,buildSession:build('late-item-build')}});
    await click('#startBuild');
    const secondStart=await waitFor(pendingStarts,2);
    assert.equal(action('build.start').at(-1).kdsItemPublicId,second.kdsItemPublicId);
    await page.selectOption('#modeSelect','mock');
    await waitText('#connectionBadge','MOCK');
    await waitText('#consoleItem','Club Sandwich');
    await secondStart.fulfill({json:{ok:true,buildSession:build('late-live-build')}});
    await click('#startBuild');
    await waitText('#buildBadge','build-mock-1');
    await click('#sgv-tab-build');
    await click('[data-detect="mock:0"]');
    await waitText('#eventLog','Detected Toasted Bread in mock mode.');
    assert.equal(await page.locator('#buildBadge').textContent(),'Build build-mock-1','Late Live Build Start cannot replace the Mock build.');
    assert.equal(action('build.observe').length,1,'Mock evidence remains local.');
    assert.deepEqual(errors,[]);
    console.log('PASS: Chromium Live build, Expo, scoped access, and governed Shadow evidence.');
  }finally{await browser.close();}
}
main().catch(error=>{console.error(error);process.exitCode=1;});
