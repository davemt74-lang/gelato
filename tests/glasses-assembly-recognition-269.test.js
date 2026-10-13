'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const code=fs.readFileSync('assets/js/glasses-assembly-recognition.js','utf8');
const js=fs.readFileSync('assets/js/glasses-web-simulator.js','utf8');
const html=fs.readFileSync('glasses-simulator.php','utf8');
const css=fs.readFileSync('assets/css/glasses-simulator-device-first.css','utf8');
const sandbox={window:{}};vm.runInNewContext(code,sandbox,{filename:'glasses-assembly-recognition.js'});
const recognition=sandbox.window.GelatoGlassesAssemblyRecognition;
assert.equal(typeof recognition.evaluate,'function');
const now=20000,sha='a'.repeat(64);
const component={componentKey:'component-1',displayName:'Mozzarella',status:'confirmed',confidence:.96};
const track={componentKey:'component-1',label:'Mozzarella',trackId:'frame-track-1',
  present:true,stable:true,profileMatched:true,profileMinimumConfidence:.82,
  frames:4,firstSeen:19500,lastSeen:19920,confidence:.97,submitted:true,removed:false};
const base={
  mode:'assisted',adapter:'onnx',modelReady:true,cameraReady:true,runtimeReady:true,
  item:{kdsItemPublicId:'kds-01'},ingredient:{id:'1:mozzarella',name:'Mozzarella',canonicalName:'mozzarella'},
  build:{status:'active',kdsItemPublicId:'kds-01',components:[component]},
  modelSha256:sha,modelAssignment:{action:'apply',compatibility:{compatible:true}},
  tracks:[track],threshold:.75,now,barrierAt:18000,allowAutomatic:false
};
const check=(changes={},status)=>recognition.evaluate({...base,...changes}).status===status;
assert.ok(check({mode:'manual'},'manual'),'manual mode is operator-only');
assert.ok(check({adapter:'fixture'},'waiting'),'fixture cannot propose a production match');
assert.ok(check({modelReady:false},'waiting'),'ungoverned unloaded model denied');
assert.ok(check({cameraReady:false},'waiting'),'no camera denied');
assert.ok(check({runtimeReady:false},'waiting'),'recovery / missing runtime denied');
assert.ok(check({build:null},'waiting'),'build association required');
assert.ok(check({build:{...base.build,status:'completed'}},'waiting'),'finished builds rejected');
assert.ok(check({build:{...base.build,kdsItemPublicId:'other'}},'waiting'),'cross-ticket evidence rejected');
assert.ok(check({modelSha256:'not-a-checksum'},'waiting'),'model digest required');
assert.ok(check({ingredient:{name:'Parmesan',canonicalName:'parmesan'}},'waiting'),'incorrect ingredient rejected');
assert.ok(check({build:{...base.build,components:[component,{...component,componentKey:'duplicate'}]}},'waiting'),'duplicate component labels rejected');
assert.ok(check({tracks:[{...track,profileMatched:false}]},'waiting'),'unprofiled labels rejected');
assert.ok(check({tracks:[{...track,frames:2}]},'waiting'),'single-frame snapshot rejected');
assert.ok(check({tracks:[{...track,firstSeen:17999}]},'waiting'),'pre-recovery track rejected');
assert.ok(check({tracks:[{...track,lastSeen:18500}]},'waiting'),'stale track rejected');
assert.ok(check({tracks:[{...track,confidence:.75}]},'waiting'),'profile minimum confidence enforced');
assert.ok(check({tracks:[{...track,removed:true}]},'waiting'),'removed ingredient track rejected');
assert.ok(check({},'suggested'),'assisted mode only proposes');
assert.equal(recognition.evaluate(base).automatic,false,'assisted never advances automatically');
assert.ok(check({mode:'automatic'},'suggested'),'auto mode without opt-in only proposes');
assert.ok(check({mode:'automatic',allowAutomatic:true,tracks:[{...track,confidence:.89}]},'suggested'),'auto requires 90%');
assert.ok(check({mode:'automatic',allowAutomatic:true,tracks:[{...track,submitted:false}]},'suggested'),'unsubmitted track cannot auto-complete');
assert.ok(check({mode:'automatic',allowAutomatic:true,build:{...base.build,components:[{...component,status:'verify'}]}},'suggested'),'backend Verify is not confirmation');
assert.ok(check({mode:'automatic',allowAutomatic:true,modelAssignment:{action:'hold',compatibility:{compatible:false}}},'suggested'),'revoked model assignment denies completion');
assert.equal(recognition.evaluate({...base,mode:'automatic',allowAutomatic:true}).automatic,true,'all governed gates qualify');
assert.match(js,/track.profileMatched=d.profileMatched===true/,'detector profile match flows into temporal tracks');
assert.match(js,/track.profileMinimumConfidence=Number/,'per-label confidence floor retained');
assert.match(js,/state.browserModel.verifiedSha256/,'runtime uses verified sha');
assert.match(js,/autoAssemblyOptIn&&|!autoAssemblyOptIn/,'automatic is explicitly opt in');
assert.match(js,/track.confidence<Math.max\(\.90/,'backend observations enforce 90%');
assert.match(js,/sequenceViolationFor\(track.componentKey\)/,'sequence violations block automatic writes');
assert.match(js,/item.status!=='in_progress'/,'only active kitchen item');
assert.match(js,/if\(!norm\|\|!assembly\(\)\?\.ingredientsFor\(item\)/,'menu items, not preparation recipe steps');
assert.match(js,/governedQualified:true/,'automatic updates only once policy passes');
assert.match(js,/if\(!fresh\?\.automatic/,'automatic revalidates evidence before mutation');
assert.match(js,/operatorConfirmed:true/,'assisted uses explicit operator confirmation');
assert.match(js,/renderBuildRail\(item\)/,'suggestion visible on lens');
assert.match(js,/assemblySources.set\(/,'local provenance distinguishes operator and governed recognition');
assert.match(js,/if\(next&&!state.build\)/,'build cannot silently switch ticket item');
assert.match(html,/id="visionAssemblyAutoOptIn"/,'per-runtime explicit opt-in exists');
assert.match(html,/id="hudAssemblySuggestion"/);
assert.match(html,/id="hudConfirmSuggestion"/);
assert.match(html,/id="hudRejectSuggestion"/);
assert.match(css,/#glassesStage \.hud-assembly-suggestion/);
console.log('section269-governed-recognition-integration=ok');
