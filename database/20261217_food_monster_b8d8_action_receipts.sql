-- FM-B8D8: immutable receipt for each authenticated Agent Chat routine action.
-- Prevents changing tool arguments on retry from producing multiple datasets.
CREATE TABLE food_monster_agent_action_receipts (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 organization_id BIGINT UNSIGNED NOT NULL,
 actor_user_id BIGINT UNSIGNED NOT NULL,
 turn_public_id VARCHAR(24) NOT NULL,
 action VARCHAR(80) NOT NULL,
 resource_public_id VARCHAR(190) NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY (id),
 UNIQUE KEY uq_fm_agent_turn_action (organization_id,actor_user_id,turn_public_id,action),
 KEY ix_fm_agent_receipt_resource (organization_id,action,resource_public_id),
 CONSTRAINT fk_fm_agent_receipt_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
 CONSTRAINT fk_fm_agent_receipt_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
