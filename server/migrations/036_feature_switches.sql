-- 036: feature switches the owner can turn on and off in Settings.
--
-- The 2026 plan (file 02, "Keep, switch off, retire") keeps finance, online
-- payments, the built-in inbox and SMS/WhatsApp sending, but behind a switch, so
-- the owner decides whether each is in use rather than every account seeing it.
-- Nothing stored a decision like that: there was no settings table, and every
-- integration was either configured in config.php or not.
--
-- One row per switch. The names are fixed in code (rg_feature_defaults() in
-- server/pure.php); a name the code does not know is never read.
--
--   finance          Invoicing, quotes, client contracts and payroll in the
--                    office navigation. Interface only: the endpoints keep
--                    their role and zone checks and are not refused.
--   online_payments  Stripe pay links and checkout, GoCardless mandates and
--                    collections. Refused by payments.php and gocardless.php.
--                    Their webhooks keep working, so a payment started before
--                    the switch went off still settles.
--   sms_whatsapp     Outbound SMS and WhatsApp. send_sms() and send_whatsapp()
--                    log the attempt as skipped instead of sending.
--   inbox            The shared IMAP mailbox in the office app. Refused by
--                    inbox.php.
--   ai_assistant     The header AI assistant and every other AI call. Refused
--                    by ai.php. Being retired (plan section S9).
--
-- Seeds preserve what production does today, except the AI assistant, which
-- the plan retires: it starts off. The owner flips the rest in Settings.

CREATE TABLE IF NOT EXISTS feature_switches (
  name VARCHAR(40) NOT NULL,
  enabled TINYINT(1) NOT NULL,
  updated_by INT NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (name),
  KEY idx_fs_updated_by (updated_by),
  CONSTRAINT fk_fs_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- INSERT IGNORE: re-running the migration must not undo a choice the owner has
-- already made.
INSERT IGNORE INTO feature_switches (name, enabled) VALUES
  ('finance', 1),
  ('online_payments', 1),
  ('sms_whatsapp', 1),
  ('inbox', 1),
  ('ai_assistant', 0);
