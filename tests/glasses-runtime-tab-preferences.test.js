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
assert.match(css,/\.glasses-runtime-tabs\[hidden\]\{display:none!important\}/,'hidden tab CSS remains safe');
assert.match(css,/#sgvUserPrefsStatus\[data-state="error"\]/,'save errors remain visible');
// New contract: one visible preview runtime by default, no synthetic saved tabs,
// add only after explicit + / Save, and switch within the same browser tab.
const start=runtimes.indexOf('function renderRuntimeTabs(choices,current){');
const end=runtimes.indexOf('function render(){',start);
assert.ok(start>0&&end>start,'runtime tab renderer exists');
const map={};let renders=0,adding=false;
function el(){
 return {hidden:false,children:[],attributes:{},listeners:{},dataset:{},
  append(...nodes){this.children.push(...nodes)},
  appendChild(node){this.children.push(node);return node},
  replaceChildren(){this.children=[]},
  setAttribute(key,value){this.attributes[key]=value},
  addEventListener(name,fn){this.listeners[name]=fn},
  focus(){this.focused=true},
  querySelectorAll(){return this.children.filter(n=>n.attributes?.role==='tab')}};
}
map.glassesRuntimeTabs=el();map.glassesRuntimeSelectLabel=el();
map.glassesRuntimeSelect={value:''};map.glassesRuntimeAddCancel=el();
map.glassesRuntimePairSelect=el();
const navigation=[],cfg={runtimePublicId:'',canManageMedia:true};
const location={href:'https://example.test/glasses-simulator.php'};
const context={byId:id=>map[id],document:{createElement:()=>el(),createTextNode:text=>({text})},
 URL,cfg,location,adding:false,render:()=>{renders++},
 status:()=>{},window:{location:{assign:url=>navigation.push(url)}}};
vm.runInNewContext(runtimes.slice(start,end)+'\nthis.renderTabs=renderRuntimeTabs;',context);
const one={publicId:'runtime-one',employeeName:'Cook One',deviceName:'Glasses A',stationName:'Pizza'};
const two={publicId:'runtime-two',employeeName:'Cook Two',deviceName:'Glasses B',stationName:'Prep'};
context.renderTabs([], '');
assert.equal(map.glassesRuntimeTabs.hidden,false,'default preview runtime is visible');
assert.equal(map.glassesRuntimeTabs.children.length,2,'default preview and + only');
assert.equal(map.glassesRuntimeTabs.children[0].attributes['aria-selected'],'true','default preview selected');
assert.equal(map.glassesRuntimeTabs.children[1].textContent,'+','only + starts creation');
assert.equal(navigation.length,0,'load never creates extra runtime');
map.glassesRuntimeTabs.children[1].listeners.click();
assert.equal(context.adding,true,'explicit + activates add form');
assert.equal(map.glassesRuntimePairSelect.focused,true,'paired device selector receives focus');
assert.equal(renders,1);
context.adding=false;
context.renderTabs([one], '');
assert.equal(map.glassesRuntimeTabs.children.length,3,'only a saved pair adds a second numbered tab');
assert.equal(map.glassesRuntimeTabs.children[1].attributes['aria-selected'],'false');
map.glassesRuntimeTabs.children[1].listeners.click();
assert.equal(navigation.length,1,'switch uses current tab URL instead of spawning browser window');
assert.equal(new URL(navigation[0]).searchParams.get('runtime'),'runtime-one');
context.renderTabs([one,two], 'runtime-two');
assert.equal(map.glassesRuntimeTabs.children.length,4,'preview and two saved pairs and +');
assert.equal(map.glassesRuntimeTabs.children[2].attributes['aria-selected'],'true');
cfg.runtimePublicId='runtime-two';
map.glassesRuntimeTabs.children[0].listeners.click();
assert.equal(new URL(navigation[1]).searchParams.has('runtime'),false,'preview navigation unbinds selected physical runtime');
assert.doesNotMatch(html,/id="glassesConnectionManager"|id="glassesSoftwareManager"/,'fleet management not in Runtime');
assert.match(html,/href="glasses-fleet.php"/,'device administration reachable separately');
console.log('section276-runtime-one-preview-plus-save-same-tab=ok');
