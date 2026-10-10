<?php
// Writes about a person that more than one endpoint makes.
//
// Not an endpoint: no auth and no output beyond fail(). The caller has already
// decided the current account may do this to this person -- service scope, zone,
// or "it is their own record" for the worker portal -- and this file only does
// the write, the validation and the audit, the same way every time.
//
// people.php uses it for the office profile. The worker portal (S4) will use the
// same functions through me.php, so an emergency contact typed by a worker and
// one typed by the office are held to exactly the same rules.

/**
 * Save a person's emergency contacts.
 *
 * Validated by rg_validate_emergency_contacts() in pure.php, which refuses a
 * name with no number, a number with no name and a number nobody could ring.
 *
 * Audited with the names of the fields that changed and not their values. The
 * values are a third party's name and telephone number, and the audit log is
 * kept for a different period and read by different people than the record it
 * describes; a copy there would be a second copy to find, correct and delete.
 *
 * @return array<string,?string> the saved values
 */
function rg_save_emergency_contacts(mysqli $conn, int $personId, array $data, int $actorId, string $via = 'office'): array
{
    $check = rg_validate_emergency_contacts($data);
    if (!$check['ok']) fail(implode(' ', $check['errors']), 422);
    $values = $check['values'];

    $stmt = $conn->prepare(
        'SELECT ' . implode(', ', RG_EMERGENCY_FIELDS) . ' FROM people WHERE id = ? AND deleted_at IS NULL LIMIT 1'
    );
    $stmt->bind_param('i', $personId);
    $stmt->execute();
    $before = $stmt->get_result()->fetch_assoc();
    if (!$before) fail('Person not found.', 404);

    $set = implode(', ', array_map(static fn($f) => "$f = ?", RG_EMERGENCY_FIELDS));
    $stmt = $conn->prepare("UPDATE people SET $set WHERE id = ?");
    $params = array_values($values);
    $params[] = $personId;
    $stmt->bind_param(str_repeat('s', count(RG_EMERGENCY_FIELDS)) . 'i', ...$params);
    $stmt->execute();

    [, $after] = audit_diff($before, $values, RG_EMERGENCY_FIELDS);
    if ($after) {
        audit($conn, $actorId, 'person.emergency_contacts', "person #$personId", 'person', $personId,
            null, ['changed' => array_keys($after), 'via' => $via]);
    }
    return $values;
}

/**
 * Record a piece of training somebody has completed.
 *
 * Training here means a record and its evidence: provider, course, when it was
 * completed, when it expires, and the certificate. Not lessons, not quizzes, not
 * progress through a course (docs/Plans 2026/01, "Out of scope").
 *
 * Written to enrolments with the person's id and no programme. The name is read
 * from people, never copied here (migration 037 made learner_name nullable for
 * exactly this).
 *
 * @return int the new record's id
 */
function rg_add_training_record(mysqli $conn, int $personId, array $data, int $actorId): int
{
    $course = trim((string) ($data['course'] ?? ''));
    $completed = trim((string) ($data['completed_on'] ?? ''));
    $expires = trim((string) ($data['expires_on'] ?? ''));
    $rawProvider = $data['provider_id'] ?? null;
    $providerId = ($rawProvider === null || $rawProvider === '') ? null : (int) $rawProvider;
    $rawFile = $data['evidence_file_id'] ?? null;
    $fileId = ($rawFile === null || $rawFile === '' || (int) $rawFile <= 0) ? null : (int) $rawFile;

    if ($course === '') fail('Name the course.');
    if (mb_strlen($course) > 190) fail('Please keep the course name under 190 characters.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $completed)) fail('The completion date must be YYYY-MM-DD.');
    if ($completed > date('Y-m-d')) fail('Training cannot be completed in the future.');
    if ($expires !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires)) fail('The expiry date must be YYYY-MM-DD.');
        if ($expires <= $completed) fail('The expiry date must be after the completion date.');
    }

    if ($providerId !== null) {
        $stmt = $conn->prepare('SELECT id FROM training_providers WHERE id = ? AND active = 1 LIMIT 1');
        $stmt->bind_param('i', $providerId);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) fail('That training provider is not on the list.', 422);
    }

    // The certificate has to be filed against this person: a training record
    // pointing at somebody else's certificate is evidence about the wrong human.
    if ($fileId !== null) {
        $stmt = $conn->prepare(
            'SELECT f.id FROM files f LEFT JOIN workers w ON w.id = f.worker_id
              WHERE f.id = ? AND f.deleted_at IS NULL AND (f.person_id = ? OR w.person_id = ?) LIMIT 1'
        );
        $stmt->bind_param('iii', $fileId, $personId, $personId);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) fail('That certificate is not filed against this person.', 422);
    }

    $expiresVal = $expires !== '' ? $expires : null;
    $stmt = $conn->prepare(
        'INSERT INTO enrolments (person_id, programme_id, provider_id, external_course, status, completed_on, expires_on, evidence_file_id)
         VALUES (?, NULL, ?, ?, "completed", ?, ?, ?)'
    );
    $stmt->bind_param('iisssi', $personId, $providerId, $course, $completed, $expiresVal, $fileId);
    $stmt->execute();
    $id = (int) $conn->insert_id;

    audit($conn, $actorId, 'training.record', $course, 'enrolment', $id,
        null, ['person_id' => $personId, 'provider_id' => $providerId, 'completed_on' => $completed, 'expires_on' => $expiresVal]);
    return $id;
}

/**
 * Open an application for a person who is already resolved.
 *
 * The one way an application is created by an intake route that is not the
 * public careers form: the Google Forms feed (forms-intake.php) and the
 * spreadsheet import (sheet-import.php) both come through here, so a candidate
 * from either has the same row and the same first history entry.
 *
 * The person must already be resolved through find_or_create_person() (or, for
 * an import, chosen by a person). applicants still carries name, email and phone
 * beside person_id, as every intake route has always written them; the person
 * record is the truth.
 *
 * @param array{name:string,email:string,phone:?string,role:?string,service:string,source:string,notes:?string} $a
 * @return int the new applicant's id
 */
function rg_create_applicant(mysqli $conn, int $personId, array $a, string $historyReason, ?int $actorId = null): int
{
    $stmt = $conn->prepare(
        'INSERT INTO applicants (person_id, name, email, phone, role_applied, division, source, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $phone = $a['phone'] ?? null;
    $role = $a['role'] ?? null;
    $notes = $a['notes'] ?? null;
    $stmt->bind_param('isssssss', $personId, $a['name'], $a['email'], $phone, $role, $a['service'], $a['source'], $notes);
    $stmt->execute();
    // Captured before anything else inserts: insert_id tracks the last insert.
    $applicantId = (int) $conn->insert_id;

    // The first history row, so the candidate's timeline starts where they did.
    $stmt = $conn->prepare(
        'INSERT INTO applicant_stage_history (applicant_id, from_stage, to_stage, reason, changed_by)
         VALUES (?, NULL, "applied", ?, ?)'
    );
    $stmt->bind_param('isi', $applicantId, $historyReason, $actorId);
    $stmt->execute();
    return $applicantId;
}

/**
 * Change the contact details the spreadsheet import may change, and nothing else.
 *
 * $changes is what rg_import_contact_changes() in pure.php proposed and a person
 * approved: email, phone, address, postcode, the first emergency contact and
 * availability. Compliance, verification, pay and employment status cannot be
 * reached from here.
 *
 * Changing an email or phone changes the person's match key, and the key is
 * UNIQUE, so an address that already belongs to somebody else is refused by the
 * database rather than by a lookup that a second request could race past.
 *
 * Availability lives on the worker record, so it is written to the worker
 * records listed in $workerIds only: the caller passes the ones inside the
 * account's service scope.
 *
 * Returns null on success or a reason a person can act on. Never calls fail():
 * the import applies rows one by one and a bad row must not stop the rest.
 *
 * @param list<array{field:string,old:?string,new:string}> $changes
 * @param list<int> $workerIds
 */
function rg_save_contact_details(mysqli $conn, int $personId, array $changes, array $workerIds, int $actorId, string $via): ?string
{
    $by = [];
    foreach ($changes as $c) $by[$c['field']] = $c['new'];
    if ($by === []) return null;

    $stmt = $conn->prepare(
        'SELECT email, phone, address_line1, postcode, match_key, ' . implode(', ', RG_EMERGENCY_FIELDS)
        . ' FROM people WHERE id = ? AND deleted_at IS NULL LIMIT 1 FOR UPDATE'
    );
    $stmt->bind_param('i', $personId);
    $stmt->execute();
    $current = $stmt->get_result()->fetch_assoc();
    if (!$current) return 'The person is no longer on record.';

    // Emergency contact: the first contact from the sheet, the second kept as it
    // is, through the same validation the profile and the staff portal use.
    $emergency = null;
    foreach (['emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relationship'] as $f) {
        if (array_key_exists($f, $by)) {
            $emergency ??= array_intersect_key($current, array_flip(RG_EMERGENCY_FIELDS));
            $emergency[$f] = $by[$f];
        }
    }
    if ($emergency !== null) {
        $check = rg_validate_emergency_contacts($emergency);
        if (!$check['ok']) return implode(' ', $check['errors']);
    }

    $set = [];
    $params = [];
    foreach (['email', 'phone', 'address_line1', 'postcode'] as $f) {
        if (!array_key_exists($f, $by)) continue;
        $set[] = "$f = ?";
        $params[] = $f === 'email' ? rg_normalise_email($by[$f]) : $by[$f];
    }
    if ($set) {
        $email = array_key_exists('email', $by) ? $by['email'] : $current['email'];
        $phone = array_key_exists('phone', $by) ? $by['phone'] : $current['phone'];
        $key = rg_person_match_key(['email' => $email, 'phone' => $phone])['key'];
        $set[] = 'match_key = ?';
        $params[] = $key;
        $params[] = $personId;
        $stmt = $conn->prepare('UPDATE people SET ' . implode(', ', $set) . ' WHERE id = ?');
        $stmt->bind_param(str_repeat('s', count($params) - 1) . 'i', ...$params);
        try {
            $stmt->execute();
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() === 1062) return 'That email or phone number already belongs to another person.';
            throw $e;
        }
    }

    // The worker row's phone is what text messages read, so it follows the
    // person record, as the staff portal's own change does (me.php). It is set
    // from the person, never the other way round.
    if (array_key_exists('phone', $by)) {
        $stmt = $conn->prepare('UPDATE workers SET phone = ? WHERE person_id = ? AND deleted_at IS NULL');
        $stmt->bind_param('si', $by['phone'], $personId);
        $stmt->execute();
    }

    if ($emergency !== null) rg_save_emergency_contacts($conn, $personId, $emergency, $actorId, $via);

    if (array_key_exists('availability', $by) && $workerIds) {
        $avail = rg_import_parse_availability($by['availability']);
        if ($avail !== null) {
            $d = (int) $avail['days'];
            $n = (int) $avail['nights'];
            $w = (int) $avail['weekends'];
            $stmt = $conn->prepare('UPDATE workers SET avail_days = ?, avail_nights = ?, avail_weekends = ? WHERE id = ? AND person_id = ?');
            foreach ($workerIds as $workerId) {
                $workerId = (int) $workerId;
                $stmt->bind_param('iiiii', $d, $n, $w, $workerId, $personId);
                $stmt->execute();
            }
        }
    }

    // Field names, not values: the values are a person's contact details, and
    // the audit log is kept for a different period and read by different people.
    audit($conn, $actorId, 'person.contact_details', "person #$personId", 'person', $personId,
        null, ['changed' => array_keys($by), 'via' => $via]);
    return null;
}
