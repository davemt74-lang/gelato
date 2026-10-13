'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const html=fs.readFileSync('glasses-simulator.php','utf8'),
  main=fs.readFileSync('assets/js/glasses-web-simulator.js','utf8'),
  css=fs.readFileSync('assets/css/glasses-simulator-device-first.css','utf8'),
  notices=fs.readFileSync('assets/js/glasses-hud-notifications.js','utf8');
function single(id){assert.equal((html.match(new RegExp('id="'+id+'"','g'))||[]).length,1,'one #'+id);}
for(const id of ['simHudEditorDock','calibrationToggle','simHudEditorToolbar','calibrationPanel','hudOpacity','hudBrightness',
  'leftWidth','rightWidth','hudOpacityValue','hudBrightnessValue','leftWidthValue','rightWidthValue','hudOrdersRegion',
  'hudRight','hudBuildSteps','hudOrdersCount','simTicketTimeSync','glassesHudNotification','glassesHudNotificationTitle',
  'glassesHudNotificationMeta','glassesHudNotificationMessage'])single(id);
assert.match(html,/id="simHudEditorDock"[\s\S]*?id="glassesStage"/,'calibration dock is above canvas');
assert.match(html,/id="simHudEditorToolbar"[^>]*hidden/,'editor controls hidden until Edit Mode');
assert.match(html,/id="simHudEditorAdvanced"/,'advanced settings use compact disclosure');
assert.match(html,/class="hud-panel-title hud-ticket-diagnostic" hidden/,'left ticket heading is not displayed');
assert.match(html,/id="simTicketTimeSync" hidden/,'POS sync diagnostic is not displayed');
assert.doesNotMatch(html,/id="hudIngredientAdded"|id="hudIngredientSkip"/,'no big Added/Skip buttons on lens');
assert.match(main,/data-hud-ingredient-index=/,'row buttons address one ingredient');
assert.match(main,/operator_row_icon/,'row changes preserve manual attribution');
assert.match(main,/options\.ingredientId/,'non-next ingredient can be acknowledged');
assert.match(main,/if\(previous===result&&!options\.governedQualified\)/,'same status toggles to pending');
assert.match(main,/state\.build&&!options\.governedQualified&&!options\.operatorConfirmed/,'governed build still blocks arbitrary clicks');
assert.match(main,/event\.target\.closest\('\[data-hud-ingredient-action\]'\)/,'delegated icon click');
assert.match(css,/#glassesStage \.hud-ingredient-mark/,'compact action icons');
assert.match(css,/#glassesStage \.hud-ingredient-mark\.added\.selected/);
assert.match(css,/#glassesStage \.hud-ingredient-mark\.skipped\.selected/);
assert.match(css,/#glassesStage \.hud-ticket-diagnostic\{display:none!important\}/);
assert.match(main,/function setCalibrationEnabled\(enabled\)/);
assert.match(main,/dock\.dataset\.editing=calibrationEnabled/);
assert.match(main,/tools\.hidden=!calibrationEnabled/,'toolbar follows Edit Mode');
assert.match(main,/function bindRegionEditor\(regionName\)/);
assert.match(main,/projection\.getBoundingClientRect\(\)/,'drag relative to scaled lens projection');
assert.match(main,/window\.addEventListener\('pointermove',onMove\)/,'pointer drag');
assert.match(main,/move\.addEventListener\('keydown'/,'keyboard placement');
assert.match(main,/resize\.addEventListener\('keydown'/,'keyboard resize');
assert.match(main,/saveCalibration\(\)/,'existing per-runtime persistence');
assert.match(css,/\.sim-hud-editor-dock\{/,'toolbar style');
assert.match(css,/\.sim-hud-editor-primary\{/,'four top controls');
assert.match(css,/\.sim-hud-editor-advanced/);
assert.match(notices,/positionUnderLeftTickets/);
assert.match(notices,/box\.bottom-parent\.top/,'alerts are below the left ticket rail');
assert.match(main,/GelatoGlassesHudNotifications\?\.positionUnderLeftTickets\?\.\(\)/,'drag/calibration updates notifications');
assert.match(css,/\.glasses-hud-notice-message\{font:700 11px\/1\.4 system-ui;color:#ffffff!important/,'readable high-contrast notice');
assert.doesNotMatch(notices,/root\.textContent=''/,'hide must not destroy notice children');
// Exercise the actual positioning code with two independent draggable ticket layouts.
const begin=notices.indexOf('function positionUnderLeftTickets(){');
const end=notices.indexOf('\nfunction render(){',begin);
assert.ok(begin>0&&end>begin,'notification reflow implementation exists');
const notice={hidden:false,style:{},offsetHeight:48};
let region={left:80,bottom:180,width:212};
const ticket={getBoundingClientRect:()=>region};
const projection={clientWidth:900,clientHeight:540,getBoundingClientRect:()=>({left:40,top:30,width:900,height:540})};
const nodes={glassesHudNotification:notice,hudOrdersRegion:ticket,lensProjection:projection};
const ctx={$:key=>nodes[key],Math};
vm.runInNewContext(notices.slice(begin,end)+'\nthis.position=positionUnderLeftTickets;',ctx);
ctx.position();
assert.equal(notice.style.left,'40px');
assert.equal(notice.style.top,'156px');
assert.equal(notice.style.width,'212px');
region={left:115,bottom:263,width:246};
ctx.position();
assert.equal(notice.style.left,'75px','notification follows dragged left rail');
assert.equal(notice.style.top,'239px','notification follows dragged rail height');
assert.equal(notice.style.width,'246px','notification width follows left rail');
notice.hidden=true;ctx.position();assert.equal(notice.style.top,'239px','hidden notice does not move');
console.log('gelato-hud-runtime-canvas-editor-contract=ok');
