<?php
// Drain the email queue.
//
//   CLI:  php /home/<user>/ritagreenservices.com/send-queued-mail.php
//   HTTP: wget -qO- "https://.../send-queued-mail.php?key=YOUR_ALERTS_KEY"
//
// Run by cron, every few minutes. This is the only place SMTP is spoken, which
// is the point: a web request that opens a socket to a mail server is a web
// request that hangs when the mail server does.
//
// Every attempt is recorded in email_log whatever the outcome, because "did we
// tell them" is a question asked months later, and a failure nobody can see is
// a failure nobody fixes.
require __DIR__ . '/lib.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    $key = $_GET['key'] ?? '';
    $expected = defined('ALERTS_KEY') ? ALERTS_KEY : '';
    if ($expected === '' || $expected === 'CHANGE_ME' || !hash_equals($expected, (string) $key)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
}

// A message that has failed this many times is not going to succeed. Stop
// retrying and leave it visible as failed rather than churning forever.
const MAIL_MAX_ATTEMPTS = 5;
// A cautious batch: a cron run that tries to push a thousand emails through one
// SMTP connection is a cron run that gets throttled.
const MAIL_BATCH = 50;

function record(mysqli $conn, array $row, string $outcome, ?string $detail, int $attempts): void
{
    $stmt = $conn->prepare(
        'INSERT INTO email_log (queue_id, recipient, subject, template_key, related_type, related_id, outcome, detail, attempts)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $id = (int) $row['id'];
    $stmt->bind_param('isssssssi', $id, $row['recipient'], $row['subject'], $row['template_key'],
        $row['related_type'], $row['related_id'], $outcome, $detail, $attempts);
    $stmt->execute();
}

$res = $conn->query(
    'SELECT id, recipient, subject, body, reply_to, template_key, related_type, related_id, attempts
     FROM email_queue
     WHERE status = "queued"
       AND (send_after IS NULL OR send_after <= NOW())
       AND attempts < ' . MAIL_MAX_ATTEMPTS . '
     ORDER BY id
     LIMIT ' . MAIL_BATCH
);

$sent = 0;
$failed = 0;
$skipped = 0;

while ($row = $res->fetch_assoc()) {
    $id = (int) $row['id'];
    $attempts = (int) $row['attempts'] + 1;

    // Claim the row before attempting it, so two overlapping cron runs cannot
    // both send the same message.
    $claim = $conn->prepare('UPDATE email_queue SET status = "sending", attempts = ? WHERE id = ? AND status = "queued"');
    $claim->bind_param('ii', $attempts, $id);
    $claim->execute();
    if ($claim->affected_rows === 0) continue;

    if (!mail_enabled()) {
        // Mail is off. Record it and stop trying: this is a configuration state,
        // not a transient failure, so retrying five times helps nobody.
        $conn->query('UPDATE email_queue SET status = "cancelled", last_error = "Mail is disabled (MAIL_ENABLED false)" WHERE id = ' . $id);
        record($conn, $row, 'skipped', 'Mail is disabled in config.php', $attempts);
        $skipped++;
        continue;
    }

    $ok = false;
    $error = null;
    try {
        $ok = send_mail($row['recipient'], $row['subject'], $row['body'], $row['reply_to'] ?: null);
    } catch (Throwable $e) {
        $error = substr($e->getMessage(), 0, 480);
    }

    if ($ok) {
        $conn->query('UPDATE email_queue SET status = "sent", sent_at = NOW(), last_error = NULL WHERE id = ' . $id);
        record($conn, $row, 'sent', null, $attempts);
        $sent++;
        continue;
    }

    $detail = $error ?? 'send_mail returned false';
    $final = $attempts >= MAIL_MAX_ATTEMPTS;
    $stmt = $conn->prepare('UPDATE email_queue SET status = ?, last_error = ? WHERE id = ?');
    $status = $final ? 'failed' : 'queued';   // back on the queue for another go
    $stmt->bind_param('ssi', $status, $detail, $id);
    $stmt->execute();
    // Only log a terminal failure, or the log fills with retries of one message.
    if ($final) record($conn, $row, 'failed', $detail, $attempts);
    $failed++;
}

$summary = "sent $sent, failed $failed, skipped $skipped";
if ($isCli) {
    echo "Email queue: $summary\n";
} else {
    json_out(['ok' => true, 'sent' => $sent, 'failed' => $failed, 'skipped' => $skipped]);
}
