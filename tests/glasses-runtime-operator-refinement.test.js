'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const html=fs.readFileSync('glasses-simulator.php','utf8');
const js=fs.readFileSync('assets/js/glasses-employee-runtimes.js','utf8');
const main=fs.readFileSync('assets/js/glasses-web-simulator.js','utf8');
const prefs=fs.readFileSync('api/glasses-user-preferences.php','utf8');
const runtime=fs.readFileSync('includes/glasses-employee-runtimes.php','utf8');
const api=fs.readFileSync('api/glasses-simulator.php','utf8');
const css=fs.readFileSync('assets/css/glasses-runtime-workspace.css','utf8');
assert.match(html,/<label id="glassesRuntimeEmployeeGroup">Assigned Employee <select id="glassesRuntimeEmployee"/);
assert.match(html,/<label id="glassesRuntimeStationGroup">Kitchen Station <select id="glassesRuntimeStation"/);
assert.match(html,/<label id="glassesRuntimeDeviceGroup" hidden>/);
assert.match(css,/#sgv-panel-runtime #glassesRuntimeDeviceGroup\{display:none!important\}/);
assert.doesNotMatch(html,/id="glassesConnectionManager"|id="glassesSoftwareManager"/);
assert.match(js,/runtime\.employee_update/);
assert.match(main,/previewEmployeeUserId:window\.GelatoGlassesRuntimes\?\.previewEmployeeUserId/);
assert.match(main,/restorePreviewEmployee\?\.\(prefs\.previewEmployeeUserId\)/);
assert.match(prefs,/previewEmployeeUserId/);
assert.match(prefs,/organization_memberships m/);
assert.match(prefs,/employeeId!==\$uid&&!app_has_permission\('glasses.manage'/);
assert.match(runtime,/function glasses_employee_runtime_employee_update/);
assert.match(runtime,/wearer_assignment_created/);
assert.match(runtime,/runtime_employee_changed/);
assert.match(runtime,/profile_confirmed_at=NULL/);
assert.match(api,/action==='runtime.employee_update'/);
assert.match(api,/app_verify_request_csrf\(\$in\)/);
assert.match(js,/result\.runtime\?\.employeeUserId!==bound\.employeeUserId/);
const source=js.slice(js.indexOf('function selectedDevice(chosen=null){'),js.indexOf('function renderTraining(chosen){'));
const els={
 glassesRuntimeStation:{value:'',disabled:false,dataset:{},innerHTML:''},
 glassesRuntimeSaveStation:{disabled:false,dataset:{}},
 glassesRuntimeNew:{disabled:true},glassesRuntimeEmployee:{value:'8'},
 deviceSelect:{value:'preview:2:all'},glassesRuntimePairSelect:{value:''}
};
const devices=[
 {publicId:'preview:2:all',virtualPreview:true,stationPublicId:null,locationId:2},
 {publicId:'preview:2:pizza',virtualPreview:true,stationPublicId:'pizza',stationName:'Pizza',locationName:'Main',locationId:2},
 {publicId:'preview:4:prep',virtualPreview:true,stationPublicId:'prep',stationName:'Prep',locationName:'Annex',locationId:4},
 {publicId:'glasses-real',virtualPreview:false,status:'active',locationId:2,stationPublicId:'pizza'}
];
const catalog={stations:[{publicId:'pizza',locationId:2,name:'Pizza'},{publicId:'prep',locationId:4,name:'Prep'}],employees:[{id:8,displayName:'Cook'}],schemaReady:true};
const ctx={byId:id=>els[id],devices,catalog,adding:false,cfg:{runtimePublicId:''},
 Number,esc:v=>String(v),render:()=>{}};
vm.runInNewContext(source+'\nthis.select=selectedDevice;this.stationChoices=stationChoices;this.renderStation=renderStation;this.restorePreviewEmployee=restorePreviewEmployee;',ctx);
assert.deepEqual(Array.from(ctx.stationChoices(null),x=>x.publicId),['pizza','prep'],'preview sees authorized stations from existing KDS sources');
ctx.renderStation(null);
assert.match(els.glassesRuntimeStation.innerHTML,/All kitchen stations/);
assert.match(els.glassesRuntimeStation.innerHTML,/Main · Pizza/);
assert.match(els.glassesRuntimeStation.innerHTML,/Annex · Prep/);
assert.equal(els.glassesRuntimeStation.disabled,false);
ctx.adding=true;els.glassesRuntimePairSelect.value='glasses-real';ctx.renderStation(null);
assert.equal(ctx.stationChoices(null).length,1,'physical device restricted to its restaurant');
ctx.adding=false;
ctx.restorePreviewEmployee(8);assert.equal(ctx.previewEmployeeId,8);
ctx.restorePreviewEmployee(null);assert.equal(ctx.previewEmployeeId,null);
assert.match(js,/byId\('deviceSelect'\)\.dispatchEvent\(new Event\('change'\)\)/,'station maps back to canonical preview source');
console.log('runtime-operator-refinement=ok');
