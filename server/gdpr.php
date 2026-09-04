<?php
// UK GDPR data-subject tooling (admin only).
//
//   POST /gdpr.php?action=export {email}   -> everything held about that person
//   POST /gdpr.php?action=erase  {email}   -> erase what has no basis to be kept
//
// Consent is recorded at capture time (see consent_log); this covers access and
// erasure. Requests arrive through data-request.php, which is where the record
// of who asked and what was decided lives — this file is the tool that answers
// one, not the process around it.
require __DIR__ . '/lib.php';

$user = require_auth($conn);
require_role($user, 'super_admin', 'admin');
$action = $_GET['action'] ?? '';
$data = body();
$email = trim((string) ($data['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('A valid email is required.');

/** Rows for a query with one bound parameter. */
function rows_for(mysqli $conn, string $sql, $param, string $type = 's'): array
{
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($type, $param);
    $stmt->execute();
    $out = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) $out[] = $row;
    return $out;
}

/**
 * Rows for `WHERE <col> IN (…)`, safely.
 *
 * The ids come from our own queries, but they are still interpolated, so they
 * are cast to int on the way in and the list is rejected if it is empty rather
 * than producing `IN ()`.
 */
function rows_in(mysqli $conn, string $sql, array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) return [];
    $res = $conn->query(str_replace('{ids}', implode(',', $ids), $sql));
    $out = [];
    while ($row = $res->fetch_assoc()) $out[] = $row;
    return $out;
}

if ($action === 'export' && method() === 'POST') {
    // Resolve the subject through the people spine first.
    //
    // The previous version keyed five queries off the email column of five
    // different tables and returned whatever matched. That missed most of the
    // record: every check, credential, contract, absence, shift, document and
    // message is filed against person_id or worker_id, none of which carry an
    // email at all. An access request answered with five collections out of
    // twenty-five is not an answer.
    $people = rows_for($conn, 'SELECT * FROM people WHERE email = ?', $email);
    $personIds = array_map('intval', array_column($people, 'id'));

    $workerCols = 'id, person_id, payroll_no, name, email, phone, role, division, status, derived_status,
                   derived_status_reason, left_on, right_to_work, dbs_status, dbs_expiry, dbs_update_service,
                   sia_number, sia_expiry, recheck_due, pay_rate_pence, pay_type, holiday_hours,
                   home_postcode, travel_radius_mi, created_at, deleted_at';
    $applicantCols = 'id, person_id, name, email, phone, role_applied, division, stage, source, notes, created_at, deleted_at';
    $userCols = 'id, person_id, name, email, role, division, client_id, worker_id, created_at, deleted_at';

    $workers = rows_for($conn, "SELECT $workerCols FROM workers WHERE email = ?", $email);
    $applicants = rows_for($conn, "SELECT $applicantCols FROM applicants WHERE email = ?", $email);
    $userRows = rows_for($conn, "SELECT $userCols FROM users WHERE email = ?", $email);

    // Resolution walks the spine in BOTH directions, and this is not academic:
    // people.email is frequently empty because the row was created from an
    // application that only ever carried the address on the applicant record.
    // Resolving from people.email alone found the person for nobody like that,
    // and silently returned an export with no right-to-work checks, no absence
    // and no contract in it.
    foreach ([$workers, $applicants, $userRows] as $set) {
        foreach ($set as $row) {
            if (!empty($row['person_id'])) $personIds[] = (int) $row['person_id'];
        }
    }
    $personIds = array_values(array_unique($personIds));

    // And back down again: a person found by any route brings their other
    // records with them.
    if ($personIds) {
        foreach (rows_in($conn, "SELECT $workerCols FROM workers WHERE person_id IN ({ids})", $personIds) as $r) $workers[] = $r;
        foreach (rows_in($conn, "SELECT $applicantCols FROM applicants WHERE person_id IN ({ids})", $personIds) as $r) $applicants[] = $r;
        foreach (rows_in($conn, "SELECT $userCols FROM users WHERE person_id IN ({ids})", $personIds) as $r) $userRows[] = $r;
        foreach (rows_in($conn, 'SELECT * FROM people WHERE id IN ({ids})', $personIds) as $r) $people[] = $r;
    }

    // De-duplicate: a record can match on both its email and its person_id.
    $dedupe = static function (array $rows): array {
        $byId = [];
        foreach ($rows as $r) $byId[(int) $r['id']] = $r;
        return $byId;
    };
    $people = $dedupe($people);
    $workers = $dedupe($workers);
    $applicants = $dedupe($applicants);
    $userRows = $dedupe($userRows);

    $workerIds = array_keys($workers);
    $applicantIds = array_keys($applicants);
    $userIds = array_keys($userRows);
    $people = array_values($people);
    $workers = array_values($workers);
    $applicants = array_values($applicants);
    $userRows = array_values($userRows);

    $bundle = [
        'email' => $email,
        'generated_at' => date('c'),
        // What was searched, so somebody reading the export can see how the
        // records were found and say "you have missed my other address".
        'resolved' => [
            'person_ids' => $personIds,
            'worker_ids' => $workerIds,
            'applicant_ids' => $applicantIds,
            'user_ids' => $userIds,
        ],

        // Identity
        'people' => $people,
        'user_accounts' => $userRows,
        'worker_records' => $workers,
        'applications' => $applicants,
        'client_records' => rows_for($conn, 'SELECT id, name, type, contact_name, email, phone, divisions, status, created_at, deleted_at FROM clients WHERE email = ?', $email),

        // Checks and credentials
        'right_to_work_checks' => rows_in($conn, 'SELECT * FROM right_to_work_checks WHERE person_id IN ({ids})', $personIds),
        'verifications' => rows_in($conn, 'SELECT * FROM verifications WHERE person_id IN ({ids})', $personIds),
        'certifications' => rows_in($conn, 'SELECT * FROM certifications WHERE worker_id IN ({ids})', $workerIds),
        'eligibility' => rows_in($conn, 'SELECT * FROM person_services WHERE person_id IN ({ids})', $personIds),
        // The referees' own details. Included because they are on this person's
        // file, flagged because they are somebody else's personal data and a
        // disclosure decision of its own.
        'references_taken' => rows_in($conn, 'SELECT * FROM worker_references WHERE worker_id IN ({ids})', $workerIds),

        // Employment
        'employment_contracts' => rows_in($conn, 'SELECT id, person_id, worker_id, reference, version, engagement_type, status,
                                                         job_title, start_date, end_date, place_of_work, pay_rate_pence,
                                                         pay_frequency, hours_pattern, guaranteed_hours, holiday_entitlement_days,
                                                         sick_pay_terms, notice_period_worker, notice_period_employer,
                                                         probation_terms, pension_terms, signed_name, signed_at, created_at
                                                  FROM employment_contracts WHERE person_id IN ({ids})', $personIds),
        'leave_and_absence' => rows_in($conn, 'SELECT * FROM leave_records WHERE person_id IN ({ids})', $personIds),
        'timesheets' => rows_in($conn, 'SELECT * FROM timesheets WHERE worker_id IN ({ids})', $workerIds),
        'shifts' => rows_in($conn, 'SELECT * FROM shifts WHERE worker_id IN ({ids})', $workerIds),
        // Includes the clock-in and clock-out positions.
        'shift_events' => rows_in($conn, 'SELECT * FROM shift_events WHERE worker_id IN ({ids})', $workerIds),
        'incidents' => rows_in($conn, 'SELECT * FROM incidents WHERE worker_id IN ({ids})', $workerIds),

        // Training
        'enrolments' => rows_in($conn, 'SELECT * FROM enrolments WHERE person_id IN ({ids})', $personIds),
        'certificates' => rows_for($conn, 'SELECT id, public_id, learner_name, course, issued_on, status FROM certificates WHERE learner_name IN (SELECT full_name FROM people WHERE email = ?)', $email),

        // Documents. Metadata only: the files themselves are released
        // separately, after the identity check, not through an API response.
        'documents' => array_merge(
            rows_in($conn, 'SELECT id, original_name, mime, size_bytes, category, issued_on, expires_on, verification_status, created_at, deleted_at FROM files WHERE person_id IN ({ids})', $personIds),
            rows_in($conn, 'SELECT id, original_name, mime, size_bytes, category, issued_on, expires_on, verification_status, created_at, deleted_at FROM files WHERE worker_id IN ({ids})', $workerIds)
        ),

        // Contact, consent and messages
        'enquiries' => rows_for($conn, 'SELECT id, name, email, phone, division, message, created_at FROM contact_enquiries WHERE email = ?', $email),
        'service_requests' => rows_for($conn, 'SELECT id, contact_name, email, phone, division, site_name, detail, preferred_start, created_at FROM service_requests WHERE email = ?', $email),
        'consent' => rows_for($conn, 'SELECT kind, granted, source, created_at FROM consent_log WHERE email = ?', $email),
        'data_requests' => rows_for($conn, 'SELECT ref, kind, status, created_at, completed_at, outcome_note FROM data_requests WHERE email = ?', $email),
        'form_submissions' => rows_in($conn, 'SELECT id, integration_id, external_id, status, received_at FROM integration_submissions WHERE person_id IN ({ids})', $personIds),
        'emails_sent' => rows_for($conn, 'SELECT id, recipient, subject, template_key, outcome, created_at FROM email_log WHERE recipient = ?', $email),
        'emails_queued' => rows_for($conn, 'SELECT id, recipient, subject, status, created_at, sent_at FROM email_queue WHERE recipient = ?', $email),
        'notifications' => rows_in($conn, 'SELECT id, type, title, body, created_at FROM notifications WHERE user_id IN ({ids})', $userIds),
    ];

    // Text messages are keyed on a phone number, not an email.
    $phones = array_values(array_filter(array_merge(
        array_column($people, 'phone'),
        array_column($workers, 'phone')
    )));
    $sms = [];
    foreach (array_unique($phones) as $p) {
        foreach (rows_for($conn, 'SELECT id, recipient, body, status, created_at FROM sms_log WHERE recipient = ?', $p) as $row) {
            $sms[] = $row;
        }
    }
    $bundle['text_messages'] = $sms;

    // Things this export deliberately does not include, said out loud rather
    // than silently omitted.
    $bundle['not_included'] = [
        'audit_log' => 'Records of actions taken in the system. Available on request; it also names the staff who acted.',
        'file_contents' => 'Document metadata is listed above. The files themselves are released separately once identity is confirmed.',
        'server_logs' => 'Web server request logs, which are not queryable per person and are kept briefly.',
        'backups' => 'Database backups, which cannot be edited per person and age out on their own schedule.',
    ];

    // Logged as an export, in the export log, like every other.
    $uid = (int) $user['id'];
    $count = 0;
    foreach ($bundle as $k => $v) if (is_array($v) && $k !== 'resolved' && $k !== 'not_included') $count += count($v);
    $type = 'gdpr_subject_access';
    $scope = 'email=' . $email;
    $purpose = 'Data subject access request';
    $ip = client_ip();
    $stmt = $conn->prepare(
        'INSERT INTO export_log (user_id, export_type, record_count, scope, purpose, ip, contains_personal_data)
         VALUES (?, ?, ?, ?, ?, ?, 1)'
    );
    $stmt->bind_param('isisss', $uid, $type, $count, $scope, $purpose, $ip);
    $stmt->execute();

    audit($conn, $uid, 'gdpr.export', "$email ($count records)", 'person', $personIds ? (int) $personIds[0] : null);
    json_out($bundle);
}

if ($action === 'erase' && method() === 'POST') {
    // What can be erased here, and what cannot.
    //
    // Public-form data has no ongoing basis once it is dealt with, so it is
    // anonymised in place. Everything filed against a person — employment,
    // compliance, safeguarding — goes through the deletion workflow in
    // governance.php instead, where a reason is required and a second person
    // approves. This endpoint used to erase immediately with neither, which
    // meant one administrator could quietly remove somebody's record while a
    // colleague deleting a single file needed sign-off.
    $anon = 'erased@redacted.invalid';

    $q = $conn->prepare("UPDATE contact_enquiries SET name = '[erased]', email = ?, phone = NULL, message = '[erased]' WHERE email = ?");
    $q->bind_param('ss', $anon, $email);
    $q->execute();
    $n1 = $conn->affected_rows;

    $q = $conn->prepare("UPDATE service_requests SET contact_name = '[erased]', email = ?, phone = NULL, detail = '[erased]' WHERE email = ?");
    $q->bind_param('ss', $anon, $email);
    $q->execute();
    $n2 = $conn->affected_rows;

    // Consent records are NOT deleted. They used to be.
    //
    // Deleting the row that says somebody opted out is exactly how they end up
    // back on the list after the next import, and the record that consent was
    // given or withdrawn is evidence for a decision we made about them —
    // erasing it removes our accountability, not their data.
    //
    // So the address is anonymised, EXCEPT on a marketing opt-out. That one row
    // has to keep the address to do its job: a suppression record that no
    // longer names anybody suppresses nobody. It holds an email address and the
    // fact that this person does not want marketing, which is the minimum
    // needed to honour what they asked for.
    $q = $conn->prepare(
        'UPDATE consent_log SET email = ?
         WHERE email = ? AND NOT (kind = "marketing" AND granted = 0)'
    );
    $q->bind_param('ss', $anon, $email);
    $q->execute();
    $n3 = $conn->affected_rows;

    $q = $conn->prepare('SELECT COUNT(*) c FROM consent_log WHERE email = ? AND kind = "marketing" AND granted = 0');
    $q->bind_param('s', $email);
    $q->execute();
    $suppressed = (int) ($q->get_result()->fetch_assoc()['c'] ?? 0);

    // Surface, do not touch. These need a decision and an approver.
    $needsWorkflow = [
        'people' => rows_for($conn, 'SELECT id, full_name FROM people WHERE email = ? AND deleted_at IS NULL', $email),
        'clients' => rows_for($conn, 'SELECT id, name FROM clients WHERE email = ? AND deleted_at IS NULL', $email),
        'workers' => rows_for($conn, 'SELECT id, name FROM workers WHERE email = ? AND deleted_at IS NULL', $email),
        'applicants' => rows_for($conn, 'SELECT id, name FROM applicants WHERE email = ? AND deleted_at IS NULL', $email),
        'users' => rows_for($conn, 'SELECT id, name FROM users WHERE email = ? AND deleted_at IS NULL', $email),
    ];

    audit($conn, (int) $user['id'], 'gdpr.erase', "$email enquiries=$n1 requests=$n2 consent=$n3");
    json_out([
        'ok' => true,
        'erased' => ['enquiries' => $n1, 'service_requests' => $n2, 'consent_anonymised' => $n3],
        'marketing_suppression_kept' => $suppressed,
        'needs_approval' => $needsWorkflow,
        'note' => 'Records above are not erased here. Raise them through the deletion workflow '
            . '(governance.php?action=request_deletion), which requires a reason and a second '
            . 'person to approve. Some may have to be kept: say so in the reason.',
    ]);
}

fail('Method not allowed.', 405);
