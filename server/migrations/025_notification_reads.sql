-- 025 — per-user read state for a broadcast, so the bell can light for one.
--
-- A broadcast is one notifications row with user_id NULL, read by many people,
-- and the table has a single read_at. There was nowhere to record that one
-- person had seen it, so notifications.php forced every broadcast to read = true
-- and unread_count() counted only rows targeted at the user.
--
-- The consequence is not cosmetic. A panic alert raised from the worker app
-- calls notify($conn, null, ...) — a broadcast. It therefore arrived already
-- marked read, the bell never lit, and with MAIL_ENABLED false by default the
-- email path is latent too. The alert existed only as a row in a table.

CREATE TABLE IF NOT EXISTS notification_reads (
  notification_id INT NOT NULL,
  user_id INT NOT NULL,
  read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (notification_id, user_id),
  KEY idx_nr_user (user_id),
  CONSTRAINT fk_nr_notification FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
  CONSTRAINT fk_nr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Mark every broadcast that already exists as read, for everyone who exists.
--
-- Without this, switching the behaviour on turns every historical broadcast
-- unread at once and the first person to log in finds a wall of alerts about
-- things that were dealt with months ago. Only broadcasts raised from here on
-- should count.
INSERT IGNORE INTO notification_reads (notification_id, user_id)
SELECT n.id, u.id
FROM notifications n
CROSS JOIN users u
WHERE n.user_id IS NULL AND u.deleted_at IS NULL;
