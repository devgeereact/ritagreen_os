<?php
// The rota's shared writes: telling a worker about a change, keeping a shift's
// history, and turning clock events into a draft timesheet.
//
// Not an endpoint: no auth and no output beyond fail(). The caller has already
// decided the current account may do this -- an office role and service scope in
// scheduling.php, "it is their own shift" in me.php and field.php. The staff
// portal (S4) uses the same functions, so a change made in the office and one
// made by a worker are recorded and announced the same way.
//
// Who may be put on a shift is NOT decided here. That is rg_assignment_check()
// in compliance-lib.php, the one gate.

/**
 * One shift with the names the rota shows, or null.
 */
function rg_shift_row(mysqli $conn, int $shiftId): ?array
{
    $stmt = $conn->prepare(
        'SELECT s.id, s.worker_id, w.name AS worker, s.division, s.title, s.client_id, c.name AS client,
                s.site_id, COALESCE(t.name, s.site_name) AS site_name, s.starts_at, s.ends_at, s.status,
                s.is_draft, s.published_at, s.cancel_reason, s.cancelled_at, s.template_id
           FROM shifts s
           LEFT JOIN workers w ON w.id = s.worker_id
           LEFT JOIN clients c ON c.id = s.client_id
           LEFT JOIN sites t ON t.id = s.site_id
          WHERE s.id = ? AND s.deleted_at IS NULL'
    );
    $stmt->bind_param('i', $shiftId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

/**
 * Record something that happened to a shift.
 *
 * The audit log is the shift's history: every row carries entity 'shift' and the
 * id, so the rota's history panel is a read of the audit log rather than a
 * second record of the same events that could disagree with it.
 */
function rg_shift_audit(
    mysqli $conn,
    ?int $userId,
    string $action,
    int $shiftId,
    string $detail,
    ?array $before = null,
    ?array $after = null,
    ?string $reason = null
): void {
    audit($conn, $userId, $action, $detail, 'shift', $shiftId, $before, $after, $reason);
}

/**
 * A shift's history, newest first.
 *
 * @return list<array{action:string,detail:?string,reason:?string,by:?string,at:string}>
 */
function rg_shift_history(mysqli $conn, int $shiftId): array
{
    $stmt = $conn->prepare(
        'SELECT a.action, a.detail, a.reason, a.created_at, u.name AS by_name
           FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
          WHERE a.entity_type = "shift" AND a.entity_id = ?
          ORDER BY a.id DESC LIMIT 50'
    );
    $stmt->bind_param('i', $shiftId);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[] = [
            'action' => $row['action'],
            'detail' => $row['detail'],
            'reason' => $row['reason'],
            'by' => $row['by_name'],
            'at' => $row['created_at'],
        ];
    }
    return $out;
}

/**
 * Tell a worker something about their shifts: in the app, and by email.
 *
 * In-app goes to every live login linked to the worker. Email goes through the
 * queue (queue_mail), never a socket in the request, to the address on the
 * person record -- people is the truth for identity -- falling back to the
 * worker row for a record that predates the spine.
 *
 * Text messages are not sent for rota changes yet: plan file 03 lists SMS and
 * WhatsApp for these events as "later, when switched on". When they are, they go
 * through message_worker(), which already honours the sms_whatsapp switch.
 *
 * @return array{in_app:int,email:bool}
 */
function rg_notify_worker(
    mysqli $conn,
    int $workerId,
    string $title,
    string $body,
    ?int $shiftId = null,
    ?int $actorId = null
): array {
    $stmt = $conn->prepare('SELECT id FROM users WHERE worker_id = ? AND deleted_at IS NULL');
    $stmt->bind_param('i', $workerId);
    $stmt->execute();
    $res = $stmt->get_result();
    $inApp = 0;
    while ($u = $res->fetch_assoc()) {
        notify($conn, (int) $u['id'], $title, $body, 'shift', '/m');
        $inApp++;
    }

    $stmt = $conn->prepare(
        'SELECT w.name, COALESCE(NULLIF(p.email, ""), w.email) AS email
           FROM workers w LEFT JOIN people p ON p.id = w.person_id
          WHERE w.id = ? AND w.deleted_at IS NULL'
    );
    $stmt->bind_param('i', $workerId);
    $stmt->execute();
    $w = $stmt->get_result()->fetch_assoc();
    $emailed = false;
    if ($w && $w['email'] && filter_var($w['email'], FILTER_VALIDATE_EMAIL)) {
        $base = defined('PUBLIC_BASE_URL') ? PUBLIC_BASE_URL : '';
        $text = 'Hi ' . rg_first_name($w['name']) . ",\n\n" . $body
            . "\n\nYour rota is in the staff app: $base/m\n\nRitaGreen Services";
        queue_mail($conn, $w['email'], 'RitaGreen: ' . $title, $text, null, 'rota_change', 'shift', $shiftId, $actorId);
        $emailed = true;
    }
    return ['in_app' => $inApp, 'email' => $emailed];
}

/** One line describing a shift for a notice: "Day carer, Mon 12 Oct 08:00 to 16:00, Oakleaf". */
function rg_shift_summary(array $shift): string
{
    $site = trim((string) ($shift['site_name'] ?? ''));
    return $shift['title'] . ', ' . rg_shift_when($shift['starts_at']) . ' to ' . substr($shift['ends_at'], 11, 5)
        . ($site !== '' ? ', ' . $site : '');
}

/**
 * Make the draft timesheet for a shift from its clock-in and clock-out.
 *
 * Called when the worker clocks out. The worker then confirms their break and
 * submits it (me.php?action=submit_timesheet); the office approves or queries
 * it. One timesheet per shift (the rule timesheets.php already enforces), so a
 * shift that already has one is left alone and null is returned.
 */
function rg_timesheet_from_clock(mysqli $conn, int $shiftId, int $workerId): ?int
{
    $stmt = $conn->prepare('SELECT id FROM timesheets WHERE shift_id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->bind_param('i', $shiftId);
    $stmt->execute();
    if ($stmt->get_result()->fetch_row()) return null;

    // The first clock-in and the last clock-out. field.php refuses a second of
    // either, so there is one of each.
    $stmt = $conn->prepare(
        'SELECT MIN(CASE WHEN kind = "clock_in" THEN created_at END) AS clock_in,
                MAX(CASE WHEN kind = "clock_out" THEN created_at END) AS clock_out
           FROM shift_events WHERE shift_id = ? AND deleted_at IS NULL'
    );
    $stmt->bind_param('i', $shiftId);
    $stmt->execute();
    $clock = $stmt->get_result()->fetch_assoc();
    if (!$clock || !$clock['clock_in'] || !$clock['clock_out']) return null;

    $t = rg_clock_to_timesheet($clock['clock_in'], $clock['clock_out']);
    $minutes = rg_shift_minutes($t['work_date'], $t['start_time'], $t['end_time']);
    // In and out inside the same minute is nothing to pay; the office can still
    // log the hours by hand.
    if ($minutes <= 0) return null;

    $stmt = $conn->prepare(
        'INSERT INTO timesheets (worker_id, shift_id, work_date, start_time, end_time, minutes, break_minutes, status, source)
         VALUES (?, ?, ?, ?, ?, ?, 0, "draft", "clock")'
    );
    $stmt->bind_param('iisssi', $workerId, $shiftId, $t['work_date'], $t['start_time'], $t['end_time'], $minutes);
    $stmt->execute();
    $id = (int) $conn->insert_id;
    audit($conn, null, 'timesheet.from_clock', "shift #$shiftId {$t['work_date']} {$t['start_time']}-{$t['end_time']}", 'timesheet', $id);
    return $id;
}
