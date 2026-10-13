'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const workspace=fs.readFileSync('assets/js/glasses-camera-workspace.js','utf8');
const tabs=fs.readFileSync('assets/js/glasses-simulator-tabs.js','utf8');
const config=fs.readFileSync('assets/js/glasses-camera-config.js','utf8');
const voice=fs.readFileSync('assets/js/glasses-simulator-voice.js','utf8');
const main=fs.readFileSync('assets/js/glasses-web-simulator.js','utf8');
const css=fs.readFileSync('assets/css/glasses-simulator-device-first.css','utf8');
const ui=fs.readFileSync('assets/js/glasses-user-display-preferences.js','utf8');
const page=fs.readFileSync('glasses-simulator.php','utf8');
assert.match(tabs,/sgvCameraEnabledCount/);
assert.match(tabs,/sgvCameraUnreadDot/);
assert.match(tabs,/gelato-camera-session-updated/);
assert.match(tabs,/sgvVoiceStatusDot/);
assert.match(tabs,/simVoiceTranscriptText/);
assert.doesNotMatch(tabs,/simOverviewVoiceStatus/);
assert.match(tabs,/move\(stage\.querySelector\('\.sim-status-row'\),section\('runtime'\)\)/);
assert.match(config,/gelato-camera-configuration-changed/);
assert.match(config,/state\.ready&&x\.saved\.enabled===true/);
assert.match(workspace,/session=\{locationId:null,photoKeys:new Set\(\)/);
assert.match(workspace,/countSessionPhoto\(photo\)/);
assert.match(workspace,/session\.unread\[slot\]\+\+/);
assert.match(workspace,/session\.count\[slot\]\+\+/);
assert.match(workspace,/resetPhotoSession\(locationId\)/);
assert.match(workspace,/session\.unread\[Number\(name\.slice\(-1\)\)\]=0/);
assert.match(workspace,/window\.GelatoGlassesTabs\?\.active!=='camera'/);
assert.match(workspace,/gc-camera-status-dot/);
assert.match(workspace,/gc-session-new-count/);
assert.match(css,/gc-new-photo-pulse/);
assert.match(css,/@media\(prefers-reduced-motion:reduce\)/);
assert.match(css,/sgv-camera-active-count/);
assert.match(css,/gc-camera-status-dot\[data-state="enabled"\]/);
assert.match(css,/sgv-voice-dot\[data-state="active"\]/);
assert.match(voice,/active\.interimResults=true/);
assert.match(voice,/Listening: /);
assert.match(voice,/Heard: /);
assert.match(ui,/cameraResolution/);
assert.match(ui,/voiceReplies/);
assert.match(ui,/gelato-glasses-tab-select/);
assert.match(ui,/gelato-camera-tab-select/);
assert.match(main,/GelatoGlassesDisplayPreferences/);
assert.match(page,/userId:<\?=\(int\)\$user\['id'\]\?>/);

// Run actual workspace counters in an isolated environment, exercising dedup,
// off-panel unread, clearing on camera view, enabled-state aggregate and location reset.
const start=workspace.indexOf('  const session='),end=workspace.indexOf('  function feedback(',start);
assert.ok(start>0&&end>start,'session counter policy present');
let code=workspace.slice(start,end);
// select() is included; its native layout update requires only tiny stub tab controls.
let elements={};
const windows={GelatoCameraConfiguration:{isEnabled:slot=>[1,2,4].includes(slot)},
 GelatoGlassesTabs:{active:'overview'},dispatchEvent(evt){this.lastEvent=evt;}};
let storage={};
const context={window:windows,document:{getElementById:id=>elements[id]||null},
  CustomEvent:class{constructor(type,opt){this.type=type;this.detail=opt.detail;}},
  Set,Map,Number,Array,String,JSON,RegExp,
  controls:{},S:{active:'camera1'},$:(id)=>elements[id]};
const boilerplate="const controls={};const S={active:'camera1'};const $=id=>document.getElementById(id);";
vm.runInNewContext(boilerplate+code+'\nthis.__test={countSessionPhoto,resetPhotoSession,select,session,updateCameraBadges};',context);
const policy=context.__test;
for(let slot=1;slot<=5;slot++){
  const classes=new Set();
  const dot={dataset:{state:'disabled'}},num={textContent:''};
  context.controls; // controls are declared in vm scope
  elements['unused'+slot]={dot,num};
}
// The real counter should not count old library rows or duplicate capture events.
policy.resetPhotoSession(41);
const photo={slot:2,locationId:41,capturedAt:'2026-10-13T12:00:00Z',url:'blob:abc'};
policy.countSessionPhoto(photo);policy.countSessionPhoto(photo);
assert.equal(policy.session.count[2],1,'duplicate camera event cannot inflate session count');
assert.equal(policy.session.unread[2],1,'off-tab image is unread');
assert.equal(windows.lastEvent.detail.enabledCount,3,'main badge counts enabled configured cameras');
policy.countSessionPhoto({slot:4,locationId:41,capturedAt:'2026-10-13T12:01:00Z',url:'blob:def'});
assert.equal(windows.lastEvent.detail.unreadPhotos,2);
policy.resetPhotoSession(42);
assert.equal(policy.session.count[2],0,'new restaurant resets the session counters');
assert.equal(policy.session.unread[4],0);
console.log('glasses-session-camera-badges-and-voice-ui=ok');
