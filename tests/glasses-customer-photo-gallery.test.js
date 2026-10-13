'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict');
const code=Object.fromEntries([
 ['sql','database/20261212_section262_customer_photo_feedback.sql'],
 ['core','includes/glasses-customer-photo-notifications.php'],
 ['feedback','includes/glasses-customer-photo-feedback.php'],
 ['page','customer-order-photos.php'],
 ['image','customer-order-photo.php'],
 ['staffApi','api/glasses-photo-notifications.php'],
 ['staffUI','assets/js/glasses-customer-photo-notifications.js'],
 ['css','assets/css/customer-photo-gallery.css'],
 ['simulator','glasses-simulator.php']
].map(([name,path])=>[name,fs.readFileSync(path,'utf8')]));
assert.match(code.sql,/UNIQUE KEY uq_gphoto_feedback_customer_share \(organization_id,customer_id,share_id\)/);
assert.match(code.sql,/CHECK \(rating BETWEEN 1 AND 5\)/);
assert.match(code.sql,/FOREIGN KEY \(share_id\) REFERENCES glasses_order_photo_shares\(id\) ON DELETE CASCADE/);
assert.ok(!/ADD COLUMN IF NOT EXISTS/i.test(code.sql));
assert.match(code.feedback,/glasses_customer_photo_visible\(\$pdo,\$org,\$customerId,\$orderPublic\)/);
assert.match(code.feedback,/glasses_customer_photo_feedback_validate/);
assert.match(code.feedback,/shareId/);
assert.match(code.feedback,/ON DUPLICATE KEY UPDATE rating=VALUES\(rating\)/);
assert.match(code.feedback,/glasses_customer_photo_feedback_staff/);
assert.match(code.feedback,/oo\.customer_id=f\.customer_id/);
assert.match(code.feedback,/share\.revoked_at IS NULL/);
assert.match(code.core,/glasses_customer_photo_stage_camera_valid/);
assert.match(code.core,/s\.stage IN \('pre_oven','post_oven'\)/);
assert.match(code.core,/s\.camera_slot=CASE WHEN s\.stage='pre_oven' THEN 2 ELSE 3 END/);
assert.match(code.core,/s\.stage='post_oven' AND s\.camera_slot=3/); // customer Inbox remains finished-only
assert.match(code.core,/s\.photo_library_id=share\.photo_library_id/);
assert.match(code.core,/share\.revoked_at IS NULL/);
assert.match(code.core,/oo\.customer_id=\?/);
assert.match(code.page,/customer_account_require\(\$pdo,\$org\)/);
assert.match(code.page,/glasses_customer_photo_group_by_item/);
assert.match(code.page,/glasses_customer_photo_feedback_save/);
assert.match(code.page,/app_verify_csrf/);
assert.match(code.page,/name="csrf_token"/);
assert.match(code.page,/name="share_id"/);
assert.match(code.page,/name="order"/);
assert.match(code.page,/Before Oven/);
assert.match(code.page,/After Oven/);
assert.match(code.page,/name="rating"/);
assert.match(code.page,/maxlength="500"/);
assert.match(code.image,/glasses_customer_photo_visible/);
assert.match(code.image,/Cache-Control: private, no-store/);
assert.match(code.staffApi,/glasses_customer_photo_feedback_staff/);
assert.match(code.staffUI,/Customer Photo Ratings/);
assert.match(code.staffUI,/Email and SMS photo delivery are unavailable/);
assert.ok(!/sendSms|sendEmail|mail\(/.test(code.staffUI+code.feedback),'unsupported channel must never claim delivery');
console.log('section262-before-after-private-gallery-and-feedback-contract=ok');
