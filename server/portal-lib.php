<?php
// Reads for the staff portal (/m), section S4.
//
// Not an endpoint. me.php calls these with the worker id it took from the login
// (users.worker_id) and never from the request, which is the whole of the
// ownership rule: every query here is keyed by that id or by the person it
// belongs to, so there is no parameter a worker could change to read somebody
// else.
//
// Nothing here decides anything new. Statuses come from the same functions the
// office screens use (rg_load_rules, rg_load_evidence, rg_evaluate_eligibility,
// rg_requirement_chip, rg_deployment_check), so the portal and the person
// profile cannot disagree about one person.

/** The worker behind this login, with the facts the portal needs. */
function rg_portal_worker(mysqli $conn, int $workerId): array
{
    $stmt = $conn->prepare(
        'SELECT w.id, w.person_id, w.name, w.role, w.division, w.status, w.payroll_no, w.approved_at,
                p.full_name, p.preferred_name
           FROM workers w LEFT JOIN people p ON p.id = w.person_id
          WHERE w.id = ? AND w.deleted_at IS NULL LIMIT 1'
    );
    $stmt->bind_param('i', $workerId);
    $stmt->execute();
    $w = $stmt->get_result()->fetch_assoc();
    if (!$w) fail('Worker not found.', 404);
    if (!$w['person_id']) fail('This worker is not linked to a person record.', 409);
    $w['id'] = (int) $w['id'];
    $w['person_id'] = (int) $w['person_id'];
    return $w;
}

/**
 * Checks the office already has in hand for this person: requirement key =>
 * the newest open verification's status and id.
 *
 * @return array<string,array{id:int,status:string}>
 */
function rg_portal_open_checks(mysqli $conn, int $personId): array
{
    $stmt = $conn->prepare(
        'SELECT id, requirement_key, status FROM verifications
          WHERE person_id = ? AND status IN ("requested","received","under_review") ORDER BY id DESC'
    );
    $stmt->bind_param('i', $personId);
    $stmt->execute();
    $open = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $v) {
        $open[$v['requirement_key']] ??= ['id' => (int) $v['id'], 'status' => $v['status']];
    }
    return $open;
}

/**
 * The worker's own documents and checks for their service, with the chip the
 * office profile shows for the same requirement.
 *
 * @return list<array<string,mixed>>
 */
function rg_portal_checks(mysqli $conn, array $worker, ?string $today = null): array
{
    $personId = (int) $worker['person_id'];
    $today ??= date('Y-m-d');
    $evidence = rg_load_evidence($conn, $personId);
    $verdict = rg_evaluate_eligibility(rg_load_rules($conn, (string) $worker['division'], $worker['role']), $evidence, $today);
    $open = rg_portal_open_checks($conn, $personId);

    // The newest decided verification per key, for a rejection reason the worker
    // needs to see to put it right.
    $stmt = $conn->prepare(
        'SELECT requirement_key, status, rejection_reason FROM verifications
          WHERE person_id = ? AND status IN ("verified","rejected") ORDER BY id DESC'
    );
    $stmt->bind_param('i', $personId);
    $stmt->execute();
    $decided = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $v) $decided[$v['requirement_key']] ??= $v;

    $items = [];
    foreach ($verdict['items'] as $item) {
        $key = $item['key'];
        $openStatus = $open[$key]['status'] ?? null;
        $chip = rg_requirement_chip($item['state'], $openStatus);
        $last = $decided[$key] ?? null;
        $items[] = [
            'key' => $key,
            'label' => $item['label'],
            'mandatory' => $item['weight'] === RG_MANDATORY,
            'chip' => $chip['chip'],
            'chip_label' => $chip['label'],
            'expiry' => $evidence[$key]['expiry'] ?? null,
            'days' => $item['days'],
            'waiting' => in_array($openStatus, ['received', 'under_review'], true),
            'rejection_reason' => ($last['status'] ?? null) === 'rejected' && $openStatus === null ? $last['rejection_reason'] : null,
            'can_upload' => rg_portal_upload_category($key) !== null && !in_array($openStatus, ['received', 'under_review'], true),
        ];
    }
    return $items;
}

/**
 * Training records and certificates, the person's own.
 *
 * @return list<array<string,mixed>>
 */
function rg_portal_training(mysqli $conn, int $personId, int $workerId, ?string $today = null): array
{
    $today ??= date('Y-m-d');
    $stmt = $conn->prepare(
        'SELECT e.id, e.status, e.completed_on, e.expires_on, e.evidence_file_id,
                f.original_name AS evidence_file_name,
                COALESCE(pr.title, e.external_course) AS course, tp.name AS provider
           FROM enrolments e
           LEFT JOIN programmes pr ON pr.id = e.programme_id
           LEFT JOIN training_providers tp ON tp.id = e.provider_id
           LEFT JOIN files f ON f.id = e.evidence_file_id AND f.deleted_at IS NULL
          WHERE e.person_id = ? ORDER BY e.completed_on IS NULL, e.completed_on DESC, e.id DESC'
    );
    $stmt->bind_param('i', $personId);
    $stmt->execute();
    $out = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $e) {
        $class = rg_classify($e['expires_on'], $today);
        $out[] = [
            'id' => 'e' . $e['id'],
            'course' => $e['course'],
            'provider' => $e['provider'],
            'status' => $e['status'],
            'completed_on' => $e['completed_on'],
            'expires_on' => $e['expires_on'],
            'rag' => $e['expires_on'] !== null ? $class['status'] : null,
            'days' => $class['days'],
            'file_id' => $e['evidence_file_name'] !== null ? (int) $e['evidence_file_id'] : null,
            'file_name' => $e['evidence_file_name'],
        ];
    }
    // Certificates recorded on the worker record before training records existed.
    $stmt = $conn->prepare('SELECT id, type, expiry FROM certifications WHERE worker_id = ? ORDER BY expiry IS NULL, expiry');
    $stmt->bind_param('i', $workerId);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $c) {
        $class = rg_classify($c['expiry'], $today);
        $out[] = [
            'id' => 'c' . $c['id'],
            'course' => $c['type'],
            'provider' => null,
            'status' => 'completed',
            'completed_on' => null,
            'expires_on' => $c['expiry'],
            'rag' => $c['expiry'] !== null ? $class['status'] : null,
            'days' => $class['days'],
            'file_id' => null,
            'file_name' => null,
        ];
    }
    return $out;
}

/**
 * Offers waiting on this worker's answer: sent, not lapsed, on a published shift
 * that is still open and has not started.
 *
 * @return list<array<string,mixed>>
 */
function rg_portal_offers(mysqli $conn, int $workerId): array
{
    $stmt = $conn->prepare(
        'SELECT o.id, o.expires_at, s.id AS shift_id, s.title, s.division, s.starts_at, s.ends_at,
                COALESCE(t.name, s.site_name) AS site_name, t.address AS site_address, t.postcode AS site_postcode
           FROM shift_offers o
           JOIN shifts s ON s.id = o.shift_id
           LEFT JOIN sites t ON t.id = s.site_id
          WHERE o.worker_id = ? AND o.status = "sent"
            AND (o.expires_at IS NULL OR o.expires_at > NOW())
            AND s.status = "open" AND s.is_draft = 0 AND s.deleted_at IS NULL
          ORDER BY s.starts_at LIMIT 20'
    );
    $stmt->bind_param('i', $workerId);
    $stmt->execute();
    $out = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $o) {
        $o['id'] = (int) $o['id'];
        $o['shift_id'] = (int) $o['shift_id'];
        $out[] = $o;
    }
    return $out;
}

/**
 * May this worker download this file?
 *
 * Narrower than "anything filed against them". The vault can hold documents the
 * office files about a person (a disciplinary letter, a reference) that are not
 * the portal's to hand out; those go through a subject access request. A worker
 * gets back what they uploaded themselves and the certificates on their own
 * training records.
 */
function rg_portal_file_for(mysqli $conn, int $fileId, int $personId, int $userId): ?array
{
    $stmt = $conn->prepare(
        'SELECT f.id, f.stored_name, f.original_name, f.mime FROM files f
          WHERE f.id = ? AND f.deleted_at IS NULL
            AND ((f.uploaded_by = ? AND f.person_id = ?)
                 OR EXISTS (SELECT 1 FROM enrolments e WHERE e.evidence_file_id = f.id AND e.person_id = ?))
          LIMIT 1'
    );
    $stmt->bind_param('iiii', $fileId, $userId, $personId, $personId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}
