-- 014 — constrain what the build passes could not.
--
-- Every column added by a later `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` in
-- schema.sql is unconstrained, because that syntax cannot carry a constraint.
-- That is the mechanical cause of the foreign-key gaps, not an oversight by
-- anyone: users.client_id, users.worker_id, clients.account_manager_id and
-- shifts.client_id were all added that way and none of them has a key.
--
-- audit_log.user_id is joined in audit.php and was neither constrained nor
-- indexed, so every audit query was a full scan against a growing table.
--
-- ON DELETE SET NULL throughout rather than CASCADE. A deleted user must not
-- take their audit entries with them -- that is the opposite of what an audit
-- log is for -- and a deleted client must not silently remove the shifts worked
-- at their sites.

ALTER TABLE users
  ADD CONSTRAINT fk_users_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL;
ALTER TABLE users
  ADD CONSTRAINT fk_users_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE SET NULL;
ALTER TABLE clients
  ADD CONSTRAINT fk_clients_account_manager FOREIGN KEY (account_manager_id) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE shifts
  ADD CONSTRAINT fk_shifts_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL;
ALTER TABLE service_requests
  ADD CONSTRAINT fk_sr_shift FOREIGN KEY (shift_id) REFERENCES shifts(id) ON DELETE SET NULL;
ALTER TABLE audit_log
  ADD CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;

-- ------------------------------------------------------- client services
-- clients.divisions is a comma-separated VARCHAR(120). It cannot be joined,
-- indexed or constrained, and "care" matches "healthcare" under LIKE. The
-- schema already contains the right pattern -- worker_skills -- so this is that
-- pattern applied to the one place it was solved a second, worse way.
--
-- The CSV column is NOT dropped here. Endpoints still read it, and it is kept in
-- step below; dropping it is a later migration once nothing does.
CREATE TABLE IF NOT EXISTS client_services (
  client_id INT NOT NULL,
  service ENUM('care','academy','cleaning','security') NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (client_id, service),
  KEY idx_cs_service (service),
  CONSTRAINT fk_cs_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Split the existing CSV. FIND_IN_SET does the work MySQL can actually index
-- against, once the values are rows.
INSERT IGNORE INTO client_services (client_id, service)
SELECT c.id, s.service
FROM clients c
JOIN (
  SELECT 'care' AS service UNION ALL SELECT 'academy'
  UNION ALL SELECT 'cleaning' UNION ALL SELECT 'security'
) AS s
WHERE c.divisions IS NOT NULL
  AND FIND_IN_SET(s.service, REPLACE(c.divisions, ' ', '')) > 0;
