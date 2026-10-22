-- 026 — a panic alert can be acknowledged.
--
-- field.php derived the board's panic flag from "is there a panic event on this
-- shift", so once one was raised the banner stayed up for the rest of the day
-- with nothing that could take it down. A duty manager who had responded, sent
-- somebody and resolved it had no way to say so, and the next alert on another
-- shift arrived into a board already shouting.
--
-- An acknowledgement is another event on the same timeline rather than a column
-- on the shift, because what matters afterwards is the sequence: raised at
-- 21:04, acknowledged at 21:06 by whom, with what note. A column records only
-- the last state.

ALTER TABLE shift_events
  MODIFY COLUMN kind ENUM('clock_in','clock_out','check_in','panic','panic_ack','note','task','signoff')
  NOT NULL DEFAULT 'note';

-- The board asks "is there a panic with no later acknowledgement" per shift.
ALTER TABLE shift_events ADD INDEX IF NOT EXISTS idx_se_shift_kind (shift_id, kind, id);
