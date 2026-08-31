-- 009 — an audit log that can answer a question.
--
-- audit_log held user_id, action, a 255-character detail string and a timestamp.
-- That records THAT something happened, but not what changed, to which record,
-- or from where. "Who marked this SIA licence verified, and what did it say
-- before?" was unanswerable.
--
-- Additive only. audit() keeps its existing signature, so all ~90 call sites
-- carry on working unchanged and gain the richer fields as they are updated.

ALTER TABLE audit_log ADD COLUMN IF NOT EXISTS entity_type VARCHAR(40) NULL AFTER action;
ALTER TABLE audit_log ADD COLUMN IF NOT EXISTS entity_id INT NULL AFTER entity_type;
-- What the record looked like before and after. JSON rather than columns because
-- the shape differs per entity, and the alternative is a column per field.
ALTER TABLE audit_log ADD COLUMN IF NOT EXISTS before_json TEXT NULL AFTER detail;
ALTER TABLE audit_log ADD COLUMN IF NOT EXISTS after_json TEXT NULL AFTER before_json;
-- Why, where a reason is required -- deletions, overrides, manual compliance
-- decisions.
ALTER TABLE audit_log ADD COLUMN IF NOT EXISTS reason VARCHAR(500) NULL AFTER after_json;
ALTER TABLE audit_log ADD COLUMN IF NOT EXISTS ip VARCHAR(45) NULL AFTER reason;
ALTER TABLE audit_log ADD COLUMN IF NOT EXISTS user_agent VARCHAR(255) NULL AFTER ip;

-- Finding everything that happened to one record is the commonest audit
-- question, and there was no index for it.
ALTER TABLE audit_log ADD KEY IF NOT EXISTS idx_audit_entity (entity_type, entity_id, created_at);
ALTER TABLE audit_log ADD KEY IF NOT EXISTS idx_audit_user (user_id, created_at);
ALTER TABLE audit_log ADD KEY IF NOT EXISTS idx_audit_created (created_at);
