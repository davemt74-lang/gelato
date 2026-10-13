'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const html=fs.readFileSync('glasses-simulator.php','utf8');
const tabs=fs.readFileSync('assets/js/glasses-simulator-tabs.js','utf8');
const runtimes=fs.readFileSync('assets/js/glasses-employee-runtimes.js','utf8');
const prefs=fs.readFileSync('assets/js/glasses-user-display-preferences.js','utf8');
const api=fs.readFileSync('api/glasses-user-preferences.php','utf8');
const css=fs.readFileSync('assets/css/glasses-simulator-device-first.css','utf8');
assert.match(html,/id="glassesRuntimeTabs"[^>]*role="tablist"/,'runtime tabs are accessible');
assert.match(html,/id="glassesRuntimeSelectLabel"/,'existing runtime select preserved as fallback');
assert.match(tabs,/move\(\$\('glassesRuntimeTabs'\),section\('runtime'\)\)/,'subtabs are inside Runtime');
assert.match(tabs,/telemetry\.hidden=true/,'runtime diagnostic values not displayed');
assert.match(tabs,/move\(\$\('simLiveGuide'\),telemetry\)/,'verbose preview text hidden without breaking state');
assert.match(tabs,/move\(stage\.querySelector\('\.sim-status-row'\),telemetry\)/,'live sync logic remains in DOM');
assert.match(prefs,/toolbar\.appendChild\(group\)/,'save preferences inside Runtime controls');
assert.match(prefs,/id="sgvSaveUserPrefs"/);
assert.match(prefs,/id="sgvUserPrefsStatus"/);
assert.doesNotMatch(prefs,/runtime\.prepend\(card\)/,'remove duplicated Personal Display Settings card');
assert.match(prefs,/const endpoint='api\/glasses-user-preferences.php'/,'existing per-account endpoint reused');
assert.match(prefs,/bridge\(\)\?\.snapshot\?\.\(\)/,'existing safe snapshot reused');
assert.match(api,/glasses_user_display_preferences/,'no second preferences table');
assert.match(prefs,/window\.GelatoGlassesUserPreferences=\{save,queueSave/,'existing autosave API preserved');
assert.match(css,/\.glasses-runtime-tabs\[hidden\]\{display:none!important\}/,'no tabs for 0 or 1 runtime');
assert.match(css,/#sgvUserPrefsStatus\[data-state="error"\]/,'save errors remain visible');
// Exercise the actual runtime-tab renderer; no browser/DOM dependency.
const start=runtimes.indexOf('function renderRuntimeTabs(choices,current){');
const end=runtimes.indexOf('function render(){',start);
assert.ok(start>0&&end>start,'runtime subtab renderer exists');
const byIdMap={};
function element(){
 return {hidden:false,children:[],attributes:{},listeners:{},dataset:{},
   appendChild(child){this.children.push(child);return child},
   append(...children){this.children.push(...children)},
   replaceChildren(){this.children=[]},
   setAttribute(k,v){this.attributes[k]=v},
   addEventListener(k,fn){this.listeners[k]=fn},
   focus(){this.focused=true},
   querySelectorAll(selector){return selector==='[role="tab"]'?this.children:[]}};
}
byIdMap.glassesRuntimeTabs=element();
byIdMap.glassesRuntimeSelectLabel=element();
byIdMap.glassesRuntimeSelect={value:'',disabled:false};
const cfg={runtimePublicId:''},opened=[];
let renders=0;
const context={byId:k=>byIdMap[k],document:{
 createElement:()=>element(),createTextNode:s=>({textContent:s})
 },cfg,location:{href:'https://example.test/glasses-simulator.php'},
 URL,window:{open:(...args)=>opened.push(args)},render:()=>{renders++}};
vm.runInNewContext(runtimes.slice(start,end)+'\nthis.renderTabs=renderRuntimeTabs;',context);
const one={publicId:'runtime-1',employeeName:'Cook One',deviceName:'Headset A',stationName:'Pizza Line'};
const two={publicId:'runtime-2',employeeName:'Cook Two',deviceName:'Headset B',stationName:'Prep'};
context.renderTabs([], '');
assert.equal(byIdMap.glassesRuntimeTabs.hidden,true,'zero runtimes hide subtabs');
context.renderTabs([one],'runtime-1');
assert.equal(byIdMap.glassesRuntimeTabs.hidden,true,'one runtime does not create unnecessary tab row');
assert.equal(byIdMap.glassesRuntimeSelectLabel.hidden,false,'fallback dropdown remains');
context.renderTabs([one,two],'runtime-1');
assert.equal(byIdMap.glassesRuntimeTabs.hidden,false,'two runtimes show tabs');
assert.equal(byIdMap.glassesRuntimeSelectLabel.hidden,true,'dropdown not duplicated');
assert.equal(byIdMap.glassesRuntimeTabs.children.length,3,'two runtimes and Create tab');
assert.equal(byIdMap.glassesRuntimeTabs.children[0].attributes['aria-selected'],'true');
byIdMap.glassesRuntimeTabs.children[1].listeners.click();
assert.equal(byIdMap.glassesRuntimeSelect.value,'runtime-2','manager changes selected runtime only');
assert.equal(renders,1);
assert.equal(opened.length,0,'manager never silently opens or reassigns a running device');
byIdMap.glassesRuntimeTabs.children[2].listeners.click();
assert.equal(byIdMap.glassesRuntimeSelect.value,'','New resets selection to creation UI');
cfg.runtimePublicId='runtime-1';
context.renderTabs([one,two],'runtime-1');
assert.equal(byIdMap.glassesRuntimeTabs.children.length,2,'bound runtime cannot create extra runtime here');
byIdMap.glassesRuntimeTabs.children[1].listeners.click();
assert.equal(opened.length,1,'changing runtime opens isolated session');
assert.equal(opened[0][1],'_blank');
assert.equal(new URL(opened[0][0]).searchParams.get('runtime'),'runtime-2');
assert.equal(renders,2,'bound runtime does not re-render another device into active KDS');
console.log('section272-runtime-tabs-inline-display-settings=ok');
