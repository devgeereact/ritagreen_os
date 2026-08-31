-- 015 — per-service scope and sensitive zones.
--
-- Two problems.
--
-- users.division exists and is set, but nothing filters on it. A Care recruiter
-- sees Security candidates, which is not what "scoped to a division" means.
--
-- And a worker profile is one undifferentiated record. Health information,
-- disciplinary matters and payroll are not ordinary fields, and anybody who can
-- open the profile can currently see all of it.
--
-- Both are configuration, so the business can widen or narrow access without a
-- deployment.

-- Which roles may see which category of information.
CREATE TABLE IF NOT EXISTS zone_permissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role_key VARCHAR(32) NOT NULL,
  zone ENUM('general','employment','compliance','health','payroll','disciplinary','legal') NOT NULL,
  can_view TINYINT(1) NOT NULL DEFAULT 0,
  can_edit TINYINT(1) NOT NULL DEFAULT 0,
  updated_by INT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_zone (role_key, zone),
  CONSTRAINT fk_zone_role FOREIGN KEY (role_key) REFERENCES roles(role_key) ON DELETE CASCADE,
  CONSTRAINT fk_zone_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Whether a staff account is limited to its own division.
--
-- Defaults to 0, so this migration changes nobody's access on the day it runs.
-- Narrowing access silently is how a Monday morning turns into "the system is
-- broken" -- it is switched on per account, deliberately.
ALTER TABLE users ADD COLUMN IF NOT EXISTS scope_to_division TINYINT(1) NOT NULL DEFAULT 0 AFTER division;

-- Seed the zone matrix.
--
-- super_admin sees everything. admin sees everything but is separated from
-- super_admin so the two can diverge later. manager runs operations and pay but
-- not health or disciplinary. Everyone else sees general and, where they need
-- it, compliance.
INSERT IGNORE INTO zone_permissions (role_key, zone, can_view, can_edit) VALUES
  ('super_admin','general',1,1), ('super_admin','employment',1,1), ('super_admin','compliance',1,1),
  ('super_admin','health',1,1), ('super_admin','payroll',1,1), ('super_admin','disciplinary',1,1),
  ('super_admin','legal',1,1),

  ('admin','general',1,1), ('admin','employment',1,1), ('admin','compliance',1,1),
  ('admin','health',1,1), ('admin','payroll',1,1), ('admin','disciplinary',1,1), ('admin','legal',1,1),

  ('manager','general',1,1), ('manager','employment',1,1), ('manager','compliance',1,1),
  ('manager','health',0,0), ('manager','payroll',1,0), ('manager','disciplinary',0,0), ('manager','legal',0,0),

  ('trainer','general',1,0), ('trainer','employment',0,0), ('trainer','compliance',1,0),
  ('trainer','health',0,0), ('trainer','payroll',0,0), ('trainer','disciplinary',0,0), ('trainer','legal',0,0),

  ('editor','general',1,0), ('editor','employment',0,0), ('editor','compliance',0,0),
  ('editor','health',0,0), ('editor','payroll',0,0), ('editor','disciplinary',0,0), ('editor','legal',0,0),

  ('employee','general',1,0), ('employee','employment',0,0), ('employee','compliance',1,0),
  ('employee','health',0,0), ('employee','payroll',0,0), ('employee','disciplinary',0,0), ('employee','legal',0,0),

  -- A client sees nothing about a worker beyond that they are assigned.
  ('client','general',0,0), ('client','employment',0,0), ('client','compliance',0,0),
  ('client','health',0,0), ('client','payroll',0,0), ('client','disciplinary',0,0), ('client','legal',0,0);
