-- Section 262: private customer reviews attached to exact, approved shared photos.
-- Feedback is not an ML label and must never trigger automatic retraining.
CREATE TABLE glasses_customer_photo_feedback (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 organization_id BIGINT UNSIGNED NOT NULL,
 customer_id BIGINT UNSIGNED NOT NULL,
 share_id BIGINT UNSIGNED NOT NULL,
 rating TINYINT UNSIGNED NOT NULL,
 comment VARCHAR(500) NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 PRIMARY KEY (id),
 UNIQUE KEY uq_gphoto_feedback_customer_share (organization_id,customer_id,share_id),
 KEY idx_gphoto_feedback_share (organization_id,share_id,updated_at),
 CONSTRAINT fk_gphoto_feedback_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
 CONSTRAINT fk_gphoto_feedback_customer FOREIGN KEY (customer_id) REFERENCES crm_customers(id) ON DELETE CASCADE,
 CONSTRAINT fk_gphoto_feedback_share FOREIGN KEY (share_id) REFERENCES glasses_order_photo_shares(id) ON DELETE CASCADE,
 CONSTRAINT chk_gphoto_feedback_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
