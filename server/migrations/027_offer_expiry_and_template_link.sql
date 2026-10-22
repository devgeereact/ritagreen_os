-- 027 — an offer that expires, and a shift that knows which template made it.
--
-- Two loose ends in scheduling.
--
-- 1. shift_offers has an 'expired' status and nothing that drives it. A
--    broadcast link is a bearer token in a text message: it stays valid until
--    somebody accepts or the shift is filled, so a link from a shift three
--    months ago still resolves. Accepting one now runs the deployment gate, so
--    it cannot book anybody who should not be booked, but the link should have
--    stopped working on its own. There was also no unique key, so re-broadcasting
--    a shift sent the same worker a second live link for the same shift.
--
-- 2. shift-templates.php deduplicates generated shifts on division + title +
--    start time, because shifts carries nothing that says which template made
--    it. Two active templates sharing a title, division and time therefore
--    collapse into one shift, and nothing can tell you which template a shift
--    on the rota came from.

ALTER TABLE shift_offers ADD COLUMN IF NOT EXISTS expires_at DATETIME NULL AFTER status;

-- Close the existing ones. They are all for shifts in the past or already
-- filled, and leaving a live link behind is the thing this is fixing.
UPDATE shift_offers o
  JOIN shifts s ON s.id = o.shift_id
   SET o.expires_at = s.starts_at
 WHERE o.expires_at IS NULL;

-- One live offer per worker per shift. Re-broadcasting refreshes the existing
-- row rather than sending a second link for the same shift.
ALTER TABLE shift_offers ADD UNIQUE KEY IF NOT EXISTS uniq_offer_shift_worker (shift_id, worker_id);

ALTER TABLE shifts ADD COLUMN IF NOT EXISTS template_id INT NULL AFTER client_id;
ALTER TABLE shifts ADD INDEX IF NOT EXISTS idx_shifts_template (template_id, starts_at);
ALTER TABLE shifts
  ADD CONSTRAINT fk_shifts_template FOREIGN KEY (template_id) REFERENCES shift_templates(id) ON DELETE SET NULL;
