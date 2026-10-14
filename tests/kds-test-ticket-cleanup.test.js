'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),vm=require('node:vm');
const page=fs.readFileSync('kds-dashboard.php','utf8');
const js=fs.readFileSync('js/kds-dashboard.js','utf8');
const pos=fs.readFileSync('api/pos.php','utf8'),kds=fs.readFileSync('includes/kds-core.php','utf8');
assert.match(page,/app_has_permission\('pos\.use'/);
assert.match(page,/app_has_permission\('pos\.void'/);
assert.match(page,/app_has_permission\('kds\.configure'/);
assert.match(page,/id="kdsTestCleanupList"/);
assert.match(page,/id="kdsTestCleanupReview"/);
assert.match(js,/checkStatus==='open'/);
assert.match(js,/t\.unrouted===t\.items\.length/,'only fully unrouted');
assert.match(js,/t\.items\.every\(i=>!i\.station_public_id/,'no routed items');
assert.match(js,/action:'check\.cancel'/,'existing POS endpoint');
assert.match(js,/reason:'Owner-requested cleanup of unrouted test KDS ticket'/);
assert.match(js,/REMOVE TEST TICKETS/,'explicit confirmation');
assert.match(pos,/if\(\$action==='check\.cancel'\)/);
assert.match(pos,/kds_cancel_check\(/,'POS cancellation cascades to kitchen');
assert.match(kds,/function kds_cancel_check/);
assert.doesNotMatch(js,/DELETE FROM|DROP TABLE|localStorage\.removeItem/,'no raw SQL or client-only deletion');
const start=js.indexOf('function removableTestTickets(){'),end=js.indexOf('function renderCleanup(){',start);
assert.ok(start>0&&end>start);
const ctx={S:{data:{board:{tickets:[
 {checkPublicId:'old-a',checkStatus:'open',unrouted:2,items:[{station_public_id:null},{station_public_id:null}]},
 {checkPublicId:'routed',checkStatus:'open',unrouted:1,items:[{station_public_id:null},{station_public_id:'pizza'}]},
 {checkPublicId:'closed',checkStatus:'cancelled',unrouted:1,items:[{station_public_id:null}]},
 {checkPublicId:'old-b',checkStatus:'open',unrouted:1,items:[{station_public_id:null}]},
 {checkPublicId:'empty',checkStatus:'open',unrouted:0,items:[]}
]}}}};
vm.runInNewContext(js.slice(start,end)+'\nthis.eligible=removableTestTickets;',ctx);
assert.deepEqual(Array.from(ctx.eligible(),x=>x.checkPublicId),['old-a','old-b']);
console.log('kds-test-ticket-cleanup=ok');