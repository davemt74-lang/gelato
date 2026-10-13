'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const files={
 sql:fs.readFileSync('database/20261211_section260_customer_photo_notifications.sql','utf8'),
 core:fs.readFileSync('includes/glasses-customer-photo-notifications.php','utf8'),
 api:fs.readFileSync('api/glasses-photo-notifications.php','utf8'),
 client:fs.readFileSync('assets/js/glasses-customer-photo-notifications.js','utf8'),
 portal:fs.readFileSync('customer-order-photos.php','utf8'),
 image:fs.readFileSync('customer-order-photo.php','utf8'),
 customer:fs.readFileSync('customer-account.php','utf8'),
 lifecycle:fs.readFileSync('includes/online-order-lifecycle.php','utf8'),
 simulator:fs.readFileSync('glasses-simulator.php','utf8')
};
assert.match(files.sql,/enabled TINYINT\(1\) NOT NULL DEFAULT 0/g);
assert.match(files.sql,/UNIQUE KEY uq_gphoto_share_stage \(organization_id,stage_id\)/);
assert.match(files.sql,/UNIQUE KEY uq_gphoto_notify_order \(organization_id,online_order_id\)/);
assert.match(files.sql,/photo_library_id BIGINT UNSIGNED NOT NULL/);
assert.match(files.sql,/fk_gphoto_share_stage FOREIGN KEY \(stage_id\) REFERENCES glasses_order_photo_stages\(id\) ON DELETE CASCADE/);
assert.match(files.api,/app_require_auth\(\)/);
assert.match(files.api,/app_verify_request_csrf\(\$data\)/);
assert.match(files.api,/Cache-Control: private, no-store/);
assert.match(files.core,/glasses_customer_photo_delivery_gate/);
assert.match(files.core,/glasses_customer_photo_customer_opted/);
assert.match(files.core,/glasses_customer_photo_location_enabled/);
assert.match(files.core,/glasses_customer_photo_staff_auth/);
assert.match(files.core,/glasses_camera_location_authorize\(\$pdo,\$user,\$locationId,\$write\)/);
assert.match(files.core,/FOR UPDATE/);
assert.match(files.core,/customer_inbox_send_direct/);
assert.match(files.core,/share\.photo_library_id/);
assert.match(files.core,/s\.photo_library_id=share\.photo_library_id/);
assert.match(files.core,/p\.id=share\.photo_library_id/);
assert.match(files.core,/m\.status='active'/);
assert.match(files.core,/m\.retention_until IS NULL OR m\.retention_until>NOW\(6\)/);
assert.match(files.core,/oo\.customer_id=\?/);
assert.match(files.core,/oo\.status IN \('ready','kitchen_complete','completed'\)/);
assert.match(files.core,/share\.revoked_at IS NULL/);
assert.match(files.core,/app_audit/);
assert.match(files.lifecycle,/glasses_customer_photo_notify_safe/);
assert.match(files.lifecycle,/in_array\(\$next,\['ready','kitchen_complete','completed'\]/);
assert.match(files.portal,/customer_account_require/);
assert.match(files.portal,/glasses_customer_photo_visible/);
assert.match(files.image,/customer_account_require/);
assert.match(files.image,/glasses_customer_photo_visible/);
assert.match(files.image,/realpath/);
assert.match(files.image,/Content-Security-Policy/);
assert.match(files.image,/image\/jpeg/);
assert.match(files.image,/Cache-Control: private, no-store/);
assert.match(files.customer,/save_photo_preferences/);
assert.match(files.customer,/production_photos_enabled/);
assert.match(files.customer,/glasses_customer_photo_preference_save/);
assert.match(files.simulator,/glasses-customer-photo-notifications\.js/);
assert.ok(!/Twilio|SendGrid|sms_send|mail\(/.test(files.core+files.api),'not claiming external transport');
assert.ok(!/build\.observe|kds_transition/.test(files.core),'photo notification cannot change kitchen status');
const start=files.client.indexOf('  const state='),end=files.client.indexOf('  function paint()',start);
assert.ok(start>0&&end>start,'presentation policy exists');
const snippet=files.client.slice(start,end);
const context={window:{GelatoCameraConfiguration:{locationId:5}},document:{getElementById:()=>null},
  URL,Number,String,Math,Date,JSON,Set,Map,Array};
vm.runInNewContext(snippet+'\nthis.detail=detail;this.state=state;',context);
const photo={customerOptedIn:true,approved:true,orderStatus:'ready',notified:false};
context.state.enabled=true;
assert.equal(context.detail(photo),'Eligible for customer Inbox notification');
assert.match(context.detail({...photo,customerOptedIn:false}),/not opted in/);
assert.match(context.detail({...photo,approved:false}),/approval required/);
assert.match(context.detail({...photo,orderStatus:'preparing'}),/Waiting/);
assert.match(context.detail({...photo,notified:true}),/sent/);
context.state.enabled=false;
assert.match(context.detail(photo),/sharing OFF/);
console.log('section260-customer-photo-privacy-and-notification-contract=ok');
