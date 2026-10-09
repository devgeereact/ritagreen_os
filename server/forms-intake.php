<?php
// Google Forms intake.
//
//   POST /forms-intake.php?key=<integration_key>
//     Headers: X-RitaGreen-Secret: <shared secret>
//     Body:    {"response_id":"...", "answers": {"Full name":"...", ...}}
//
//   GET  /forms-intake.php?action=health            integration status (staff)
//   GET  /forms-intake.php?action=submissions       recent submissions (staff)
//   POST /forms-intake.php?action=retry&id=N        re-map a failed submission
//   POST /forms-intake.php?action=set_secret&id=N   new shared secret, shown once (owner)
//
// Setting up a form end to end: docs/runbooks/google-forms-intake.md.
//
// Called by an Apps Script on the form's submit trigger. The CMS owns the
// record; Google Forms is an integration, which is what makes it replaceable.
//
// The raw payload is stored BEFORE any mapping and kept whether or not it maps.
// A submission that fails to map is a person who applied and would otherwise
// vanish; the payload is what makes them recoverable once the mapping is fixed.
require __DIR__ . '/lib.php';

$action = $_GET['action'] ?? '';

// ---- Staff views -----------------------------------------------------------
if (method() === 'GET') {
    $user = require_auth($conn);
    require_staff($user);

    if ($action === 'health') {
        $res = $conn->query(
            'SELECT id, integration_key, name, active, default_service, last_success_at,
                    last_failure_at, last_failure_reason, submissions_total, expect_within_hours,
                    secret_hash IS NOT NULL AS configured,
                    TIMESTAMPDIFF(HOUR, last_success_at, NOW()) AS hours_since_success
             FROM integrations ORDER BY name'
        );
        $items = [];
        while ($row = $res->fetch_assoc()) {
            $row['id'] = (int) $row['id'];
            $row['active'] = (bool) $row['active'];
            $row['configured'] = (bool) $row['configured'];
            $row['submissions_total'] = (int) $row['submissions_total'];
            $hours = $row['hours_since_success'];
            // Silence is the failure that matters: a form that stops feeding the
            // system looks exactly like a quiet week.
            $row['silent'] = $row['active'] && $row['expect_within_hours'] !== null
                && ($hours === null || (int) $hours > (int) $row['expect_within_hours']);
            $row['status'] = !$row['configured'] ? 'not_configured'
                : (!$row['active'] ? 'paused' : ($row['silent'] ? 'silent' : 'ok'));
            $items[] = $row;
        }
        json_out(['integrations' => $items]);
    }

    if ($action === 'submissions') {
        $status = trim((string) ($_GET['status'] ?? ''));
        $sql = 'SELECT s.id, s.external_id, s.status, s.failure_reason, s.received_at,
                       s.applicant_id, i.name AS integration
                FROM integration_submissions s JOIN integrations i ON i.id = s.integration_id';
        // Prepared, not escaped. The value was allowlisted first so this was not
        // injectable, but rule 4 is "prepared statements always, for any value" --
        // and a rule with one documented exception is a rule people stop trusting.
        $filtered = in_array($status, ['received', 'mapped', 'failed', 'ignored'], true);
        if ($filtered) $sql .= ' WHERE s.status = ?';
        $sql .= ' ORDER BY s.id DESC LIMIT 100';
        $stmt = $conn->prepare($sql);
        if ($filtered) $stmt->bind_param('s', $status);
        $stmt->execute();
        $res = $stmt->get_result();
        $items = [];
        while ($row = $res->fetch_assoc()) {
            $row['id'] = (int) $row['id'];
            $items[] = $row;
        }
        json_out(['submissions' => $items]);
    }

    fail('Unknown action.', 404);
}

if (method() !== 'POST') fail('Method not allowed.', 405);

// ---- Re-map a failed submission --------------------------------------------
if ($action === 'retry') {
    $user = require_auth($conn);
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('A submission is required.');

    $stmt = $conn->prepare('SELECT integration_id, raw_payload, external_id FROM integration_submissions WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $sub = $stmt->get_result()->fetch_assoc();
    if (!$sub) fail('Submission not found.', 404);

    $payload = json_decode($sub['raw_payload'], true);
    $result = process_submission($conn, (int) $sub['integration_id'], $id, is_array($payload) ? $payload : []);
    audit($conn, (int) $user['id'], 'intake.retry', "#$id", 'integration_submission', $id);
    json_out($result);
}

// ---- Set a new shared secret (owner only) ------------------------------------
// The secret is generated here, shown once in the response and stored only as a
// hash. Nobody types one in, so nobody picks a weak one, and a leaked database
// still does not hand over the ability to post applications. Setting a new one
// replaces the old: the Apps Script must be given the new value, which is the
// point when a secret may have leaked. The secret is never written to the audit
// log.
if ($action === 'set_secret') {
    $user = require_auth($conn);
    require_role($user, 'super_admin', 'admin');
    $id = (int) ($_GET['id'] ?? 0);
    $stmt = $conn->prepare('SELECT id, integration_key, secret_hash IS NOT NULL AS had_secret FROM integrations WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $integration = $stmt->get_result()->fetch_assoc();
    if (!$integration) fail('Integration not found.', 404);

    $secret = bin2hex(random_bytes(24));
    $hash = hash('sha256', $secret);
    $stmt = $conn->prepare('UPDATE integrations SET secret_hash = ? WHERE id = ?');
    $stmt->bind_param('si', $hash, $id);
    $stmt->execute();
    audit($conn, (int) $user['id'], 'intake.secret_set', $integration['integration_key'], 'integration', $id,
        ['had_secret' => (bool) $integration['had_secret']], ['had_secret' => true]);
    json_out(['ok' => true, 'integration_key' => $integration['integration_key'], 'secret' => $secret]);
}

// ---- The webhook -----------------------------------------------------------
// Deliberately unauthenticated in the bearer-token sense: Google Apps Script has
// no login. It is authenticated by a shared secret compared with hash_equals,
// and rate limited, because the endpoint is public by necessity.
$key = trim((string) ($_GET['key'] ?? ''));
if ($key === '') fail('An integration key is required.', 400);

rate_limit($conn, 'intake', 120, 300);

$stmt = $conn->prepare('SELECT id, secret_hash, active, default_service, default_role FROM integrations WHERE integration_key = ? LIMIT 1');
$stmt->bind_param('s', $key);
$stmt->execute();
$integration = $stmt->get_result()->fetch_assoc();
if (!$integration) fail('Unknown integration.', 404);

$secret = (string) ($_SERVER['HTTP_X_RITAGREEN_SECRET'] ?? '');
if (empty($integration['secret_hash'])) fail('This integration has no secret set. Configure it in Settings.', 503);
if ($secret === '' || !hash_equals($integration['secret_hash'], hash('sha256', $secret))) {
    // Recorded, because repeated failures here are worth seeing.
    $stmt = $conn->prepare('UPDATE integrations SET last_failure_at = NOW(), last_failure_reason = ? WHERE id = ?');
    $reason = 'Bad or missing shared secret';
    $stmt->bind_param('si', $reason, $integration['id']);
    $stmt->execute();
    fail('Bad secret.', 401);
}
if (!$integration['active']) fail('This integration is paused.', 409);

$data = body();
$answers = $data['answers'] ?? null;
if (!is_array($answers)) fail('Expected an "answers" object.', 422);
$externalId = isset($data['response_id']) && $data['response_id'] !== ''
    ? substr((string) $data['response_id'], 0, 190) : null;

// Store the raw payload FIRST, before anything can go wrong with mapping.
$raw = json_encode($answers, JSON_UNESCAPED_UNICODE);
$integrationId = (int) $integration['id'];

// A redelivered submission must not create a second application.
if ($externalId !== null) {
    $stmt = $conn->prepare('SELECT id, status, applicant_id FROM integration_submissions WHERE integration_id = ? AND external_id = ? LIMIT 1');
    $stmt->bind_param('is', $integrationId, $externalId);
    $stmt->execute();
    if ($existing = $stmt->get_result()->fetch_assoc()) {
        json_out(['ok' => true, 'duplicate' => true, 'submission_id' => (int) $existing['id'],
                  'applicant_id' => $existing['applicant_id'] !== null ? (int) $existing['applicant_id'] : null]);
    }
}

$stmt = $conn->prepare('INSERT INTO integration_submissions (integration_id, external_id, raw_payload) VALUES (?, ?, ?)');
$stmt->bind_param('iss', $integrationId, $externalId, $raw);

try {
    $stmt->execute();
} catch (mysqli_sql_exception $e) {
    // 1062 on uniq_external (integration_id, external_id). The duplicate check
    // above and this insert are two statements with nothing between them, so two
    // deliveries of the same response -- which is exactly what a retrying Apps
    // Script produces -- can both find nothing and both insert. The unique key
    // refuses the second one correctly; unhandled, that refusal reached Google as
    // a 500, which it would then retry again.
    if ($e->getCode() !== 1062 || $externalId === null) throw $e;

    $stmt = $conn->prepare('SELECT id, applicant_id FROM integration_submissions WHERE integration_id = ? AND external_id = ? LIMIT 1');
    $stmt->bind_param('is', $integrationId, $externalId);
    $stmt->execute();
    $winner = $stmt->get_result()->fetch_assoc();
    if (!$winner) throw $e;
    json_out(['ok' => true, 'duplicate' => true, 'submission_id' => (int) $winner['id'],
              'applicant_id' => $winner['applicant_id'] !== null ? (int) $winner['applicant_id'] : null]);
}
$submissionId = (int) $conn->insert_id;

$result = process_submission($conn, $integrationId, $submissionId, $answers);
json_out($result, $result['ok'] ? 201 : 422);

/**
 * Map a stored submission onto a candidate.
 *
 * Success and failure are BOTH recorded against the integration, because the
 * question the office needs answered is "is this still working", and that cannot
 * be inferred from an empty applicant list.
 */
function process_submission(mysqli $conn, int $integrationId, int $submissionId, array $answers): array
{
    // Already mapped: return what it mapped to, and map nothing again.
    //
    // The retry branch re-ran this on any submission it was given, so pressing
    // "retry" on a submission that had already produced a candidate produced a
    // SECOND candidate for the same person -- a duplicate application, a second
    // notification email, and an integration_submissions row repointed at the
    // newer of the two. Retry is for a submission that FAILED to map; making the
    // guard part of the mapping rather than part of the branch means a future
    // caller cannot reintroduce the same fault.
    $stmt = $conn->prepare(
        'SELECT status, applicant_id, person_id FROM integration_submissions WHERE id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $submissionId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    if ($existing && $existing['applicant_id'] !== null) {
        return [
            'ok' => true,
            'already_mapped' => true,
            'submission_id' => $submissionId,
            'applicant_id' => (int) $existing['applicant_id'],
            'person_id' => $existing['person_id'] !== null ? (int) $existing['person_id'] : null,
        ];
    }

    $stmt = $conn->prepare('SELECT default_service, default_role FROM integrations WHERE id = ?');
    $stmt->bind_param('i', $integrationId);
    $stmt->execute();
    $integration = $stmt->get_result()->fetch_assoc() ?: [];

    $stmt = $conn->prepare('SELECT source_field, target_field, required, value_map FROM integration_field_maps WHERE integration_id = ?');
    $stmt->bind_param('i', $integrationId);
    $stmt->execute();
    $res = $stmt->get_result();
    $maps = [];
    while ($row = $res->fetch_assoc()) $maps[] = $row;

    $mapped = rg_map_submission($answers, $maps);
    $fields = $mapped['fields'];

    $failWith = function (string $reason) use ($conn, $integrationId, $submissionId, $mapped): array {
        $stmt = $conn->prepare('UPDATE integration_submissions SET status = "failed", failure_reason = ?, processed_at = NOW() WHERE id = ?');
        $stmt->bind_param('si', $reason, $submissionId);
        $stmt->execute();
        $stmt = $conn->prepare('UPDATE integrations SET last_failure_at = NOW(), last_failure_reason = ? WHERE id = ?');
        $stmt->bind_param('si', $reason, $integrationId);
        $stmt->execute();
        return ['ok' => false, 'submission_id' => $submissionId, 'error' => $reason,
                'missing' => $mapped['missing'], 'unmapped' => $mapped['unmapped']];
    };

    if ($mapped['missing']) {
        return $failWith('Required answers were empty: ' . implode(', ', $mapped['missing']));
    }
    $name = trim((string) ($fields['name'] ?? ''));
    $email = trim((string) ($fields['email'] ?? ''));
    if ($name === '') return $failWith('No name could be mapped from this submission.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return $failWith('The mapped email address is not valid: ' . $email);

    $valid = ['care', 'academy', 'cleaning', 'security'];
    $service = strtolower(trim((string) ($fields['service'] ?? '')));
    if (!in_array($service, $valid, true)) $service = $integration['default_service'] ?? 'care';
    $phone = trim((string) ($fields['phone'] ?? '')) ?: null;
    $role = trim((string) ($fields['role_applied'] ?? '')) ?: ($integration['default_role'] ?? null);

    // Keep everything the mapping could not place, on the record rather than
    // discarded. A question added to the form should be visible as an unmapped
    // answer, not silently lost.
    $notes = trim((string) ($fields['notes'] ?? ''));
    foreach (['sia_number', 'previous_employer', 'training', 'references', 'postcode', 'right_to_work'] as $extra) {
        if (!empty($fields[$extra])) $notes .= ($notes ? "\n" : '') . ucfirst(str_replace('_', ' ', $extra)) . ': ' . $fields[$extra];
    }
    foreach ($mapped['unmapped'] as $question) {
        $answer = null;
        foreach ($answers as $q => $a) {
            if (strtolower(trim((string) $q)) === $question) { $answer = is_array($a) ? implode(', ', $a) : $a; break; }
        }
        if ($answer !== null && trim((string) $answer) !== '') {
            $notes .= ($notes ? "\n" : '') . "[unmapped] $question: $answer";
        }
    }
    $notesVal = $notes !== '' ? $notes : null;

    // Same person resolution as every other intake route.
    $personId = find_or_create_person($conn, $name, $email, $phone);

    $stmt = $conn->prepare(
        'INSERT INTO applicants (person_id, name, email, phone, role_applied, division, source, notes)
         VALUES (?, ?, ?, ?, ?, ?, "google_form", ?)'
    );
    $stmt->bind_param('issssss', $personId, $name, $email, $phone, $role, $service, $notesVal);
    $stmt->execute();
    $applicantId = (int) $conn->insert_id;

    // The first history row, so a candidate who arrived through a form has the
    // same timeline as one who used the careers page. changed_by is NULL: an
    // integration has no user behind it.
    $stmt = $conn->prepare(
        'INSERT INTO applicant_stage_history (applicant_id, from_stage, to_stage, reason, changed_by)
         VALUES (?, NULL, "applied", "Submitted through a linked form", NULL)'
    );
    $stmt->bind_param('i', $applicantId);
    $stmt->execute();

    $stmt = $conn->prepare(
        'UPDATE integration_submissions SET status = "mapped", applicant_id = ?, person_id = ?, failure_reason = NULL, processed_at = NOW() WHERE id = ?'
    );
    $stmt->bind_param('iii', $applicantId, $personId, $submissionId);
    $stmt->execute();

    $conn->query('UPDATE integrations SET last_success_at = NOW(), submissions_total = submissions_total + 1,
                  last_failure_reason = NULL WHERE id = ' . $integrationId);

    queue_mail($conn, MAIL_TO, "New application ($service) — $name",
        "$name <$email>" . ($phone ? " ($phone)" : '') . "\nRole: " . ($role ?: 'not stated') . "\nVia: Google Form",
        $email, null, 'applicant', $applicantId);

    audit($conn, null, 'intake.mapped', "submission #$submissionId", 'applicant', $applicantId,
        null, ['person_id' => $personId, 'service' => $service, 'source' => 'google_form']);

    return ['ok' => true, 'submission_id' => $submissionId, 'applicant_id' => $applicantId,
            'person_id' => $personId, 'unmapped' => $mapped['unmapped']];
}
