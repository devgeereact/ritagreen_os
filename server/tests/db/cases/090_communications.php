<?php
// The outgoing email queue: visible, honest about what did not send, recoverable.
//
// queue_mail() has written to email_queue since migration 008 and nothing read it
// back, so "we emailed them" and "nobody emailed them" looked identical from
// inside the application.

/** @var RgtClient $client */
/** @var mysqli $db */

$admin = $client->login('admin@local.invalid');

Rgt::group('communications — applying queues a message');

$db->query('DELETE FROM rate_limits');
$before = (int) $db->query('SELECT COUNT(*) FROM email_queue')->fetch_row()[0];
$res = $client->post('/ats.php', [
    'name' => 'Queue Candidate', 'email' => 'queue.candidate@example.test',
    'role_applied' => 'Carer', 'division' => 'care', 'consent' => true,
]);
Rgt::status('an application is accepted', $res, 201);
Rgt::same('and queues exactly one message',
    (int) $db->query('SELECT COUNT(*) FROM email_queue')->fetch_row()[0], $before + 1);

$applicantId = (int) $db->query(
    "SELECT id FROM applicants WHERE email = 'queue.candidate@example.test' ORDER BY id DESC LIMIT 1"
)->fetch_row()[0];
$msg = $db->query(
    "SELECT id, related_type, related_id, status FROM email_queue ORDER BY id DESC LIMIT 1"
)->fetch_assoc();
// The queued message used to carry the notifications row's id, because
// $conn->insert_id had moved on by the time it was read.
Rgt::same('the message points at the candidate, not at some later row',
    [(string) $msg['related_type'], (int) $msg['related_id']], ['applicant', $applicantId]);
Rgt::same('and starts queued', $msg['status'], 'queued');

Rgt::group('communications — the queue can be read');

$queue = $admin->get('/comms.php?action=queue');
Rgt::status('office staff can read the queue', $queue, 200);
Rgt::ok('it reports the counts by status', is_array($queue->get('counts')));
// Said plainly rather than inferred from an empty sent list.
Rgt::same('and says whether mail is switched on at all', $queue->get('mail_enabled'), false);

$ids = array_map('intval', array_column($queue->get('messages', []), 'id'));
Rgt::ok('the new message is in it', in_array((int) $msg['id'], $ids, true));

$filtered = $admin->get('/comms.php?action=queue&status=queued');
Rgt::status('the queue can be filtered by status', $filtered, 200);
$statuses = array_values(array_unique(array_column($filtered->get('messages', []), 'status')));
Rgt::same('and returns only that status', $statuses, ['queued']);

Rgt::group('communications — the queue is correspondence, not a team notice');

// Recipients and subjects are correspondence with named people.
Rgt::denied('a trainer cannot read the queue', $client->login('trainer@local.invalid')->get('/comms.php?action=queue'));
Rgt::denied('nor an editor', $client->login('editor@local.invalid')->get('/comms.php?action=queue'));
Rgt::denied('nor a worker login', $client->login('amara.login@local.invalid')->get('/comms.php?action=queue'));
Rgt::denied('nor a client login', $client->login('priya@local.invalid')->get('/comms.php?action=queue'));
Rgt::denied('and not without a token at all', $client->get('/comms.php?action=queue'));

Rgt::group('communications — a stopped message can be put back, a live one cannot');

$id = (int) $msg['id'];

// Still queued: re-queueing it is how somebody gets the same email twice.
$res = $admin->post("/comms.php?action=requeue&id=$id", []);
Rgt::status('a queued message cannot be re-queued', $res, 409);
Rgt::ok('and says what state it is actually in', str_contains($res->error(), 'queued'), $res->error());

// What the drainer records when mail is switched off: cancelled, not failed.
// The two are different facts and must not read the same.
$db->query("UPDATE email_queue SET status = 'cancelled', attempts = 1,
            last_error = 'Mail is disabled (MAIL_ENABLED false)' WHERE id = $id");
$res = $admin->post("/comms.php?action=requeue&id=$id", []);
Rgt::status('a cancelled message can be put back', $res, 200);
$row = $db->query("SELECT status, attempts, last_error FROM email_queue WHERE id = $id")->fetch_assoc();
Rgt::same('it is queued again', $row['status'], 'queued');
// The drainer refuses anything at the attempt limit, so a retry that leaves the
// count where it was is not a retry.
Rgt::same('with its attempt count reset', (int) $row['attempts'], 0);
Rgt::same('and the old error cleared', $row['last_error'], null);

// A message that ran out of attempts.
$db->query("UPDATE email_queue SET status = 'failed', attempts = 5,
            last_error = 'SMTP connect failed' WHERE id = $id");
$res = $admin->post("/comms.php?action=requeue&id=$id", []);
Rgt::status('a failed message can be put back', $res, 200);
Rgt::same('and is attemptable again',
    (int) $db->query("SELECT attempts FROM email_queue WHERE id = $id")->fetch_row()[0], 0);

Rgt::status('re-queueing a message that does not exist is a 404',
    $admin->post('/comms.php?action=requeue&id=999999', []), 404);

Rgt::denied('a trainer cannot re-queue anything',
    $client->login('trainer@local.invalid')->post("/comms.php?action=requeue&id=$id", []));

Rgt::group('communications — putting a message back is audited');

$audited = (int) $db->query(
    "SELECT COUNT(*) FROM audit_log WHERE action = 'mail.requeue' AND entity_id = $id"
)->fetch_row()[0];
Rgt::ok("re-queueing leaves an audit entry ($audited)", $audited >= 2);
