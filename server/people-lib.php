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
 * from people, never copied here (migration 036 made learner_name nullable for
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
