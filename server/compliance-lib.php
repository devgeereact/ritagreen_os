<?php
// Loading the compliance engine's inputs from the database.
//
// The decision itself lives in pure.php (rg_evaluate_eligibility) and is tested
// without any database. This file is the boundary: it turns rows into the shapes
// that evaluator expects, and writes the verdict back. No auth, no output --
// callers handle those.

/**
 * The rule set for a service, and optionally a specific worker role.
 *
 * A rule with worker_role NULL applies to everyone in the service; a rule naming
 * a role applies in addition. Returns the shape rg_evaluate_eligibility wants.
 */
function rg_load_rules(mysqli $conn, string $service, ?string $workerRole = null): array
{
    $sql = 'SELECT r.req_key, r.label, r.expires, r.requires_verification, r.evidence_source, c.weight
            FROM compliance_rules c
            JOIN compliance_requirements r ON r.id = c.requirement_id
            WHERE c.service = ? AND c.active = 1 AND r.active = 1
              AND (c.worker_role IS NULL' . ($workerRole !== null ? ' OR c.worker_role = ?' : '') . ')';

    $stmt = $conn->prepare($sql);
    if ($workerRole !== null) {
        $stmt->bind_param('ss', $service, $workerRole);
    } else {
        $stmt->bind_param('s', $service);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    // A role-specific rule outranks the service-wide one for the same
    // requirement, so the stricter, more specific setting is the one that counts.
    $rules = [];
    while ($row = $res->fetch_assoc()) {
        $key = $row['req_key'];
        if (isset($rules[$key]) && $rules[$key]['weight'] === 'mandatory') continue;
        $rules[$key] = [
            'key' => $key,
            'label' => $row['label'],
            'weight' => $row['weight'],
            'expires' => (bool) $row['expires'],
            'verify' => (bool) $row['requires_verification'],
            'source' => $row['evidence_source'],
        ];
    }
    return array_values($rules);
}

/**
 * Everything on file for a person, keyed by requirement.
 *
 * Evidence still lives on the worker record and in `certifications` -- Milestone
 * 3 moves right to work, SIA and DBS into proper verification records, and this
 * function is the single place that will need to change when it does.
 *
 * Absent from the returned map means "nothing on file", which the evaluator
 * treats as `missing`. That is deliberately distinct from present-but-lapsed.
 */
function rg_load_evidence(mysqli $conn, int $personId): array
{
    $evidence = [];

    $stmt = $conn->prepare(
        'SELECT right_to_work, dbs_status, dbs_expiry, dbs_update_service, sia_number, sia_expiry
         FROM workers WHERE person_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1'
    );
    $stmt->bind_param('i', $personId);
    $stmt->execute();
    $w = $stmt->get_result()->fetch_assoc();

    if ($w) {
        if ($w['dbs_status'] || $w['dbs_expiry']) {
            $evidence['dbs'] = ['expiry' => $w['dbs_expiry'], 'verified' => $w['dbs_status'] === 'valid'];
        }
        if ($w['sia_number'] || $w['sia_expiry']) {
            $evidence['sia_licence'] = ['expiry' => $w['sia_expiry'], 'verified' => (bool) $w['sia_number']];
        }
    }

    // Right to work comes from its check record, not the legacy boolean. The most
    // recent check wins; its recheck_due is the expiry, and `verified` reflects
    // whether a named person actually signed it off. A check carried over from
    // the old boolean has no verifier, so it reports as unverified -- which is
    // the truth, not a defect.
    $stmt = $conn->prepare(
        'SELECT outcome, recheck_due, verified_at
         FROM right_to_work_checks
         WHERE person_id = ? ORDER BY checked_on DESC, id DESC LIMIT 1'
    );
    $stmt->bind_param('i', $personId);
    $stmt->execute();
    if ($rtw = $stmt->get_result()->fetch_assoc()) {
        if ($rtw['outcome'] !== 'invalid') {
            $evidence['right_to_work'] = [
                'expiry' => $rtw['recheck_due'],
                'verified' => $rtw['verified_at'] !== null,
            ];
        }
    } elseif ($w && $w['right_to_work']) {
        // No check record yet: fall back to the legacy flag so nothing regresses
        // before migration 006 has run everywhere.
        $evidence['right_to_work'] = ['expiry' => null, 'verified' => false];
    }

    // Certifications are matched to requirements by label, case-insensitively.
    // Free text is a poor join key; a certification_requirement_id belongs on the
    // table, and is deliberately left for the milestone that rebuilds training.
    $stmt = $conn->prepare(
        'SELECT c.type, c.expiry, c.status
         FROM certifications c
         JOIN workers w ON w.id = c.worker_id
         WHERE w.person_id = ? AND w.deleted_at IS NULL'
    );
    $stmt->bind_param('i', $personId);
    $stmt->execute();
    $res = $stmt->get_result();

    $byLabel = [];
    $lookup = $conn->query('SELECT req_key, label FROM compliance_requirements WHERE active = 1');
    while ($row = $lookup->fetch_assoc()) $byLabel[strtolower($row['label'])] = $row['req_key'];

    while ($row = $res->fetch_assoc()) {
        $key = $byLabel[strtolower(trim($row['type']))] ?? null;
        if ($key === null) continue;
        // Where somebody holds the same credential twice, the later expiry wins.
        $existing = $evidence[$key]['expiry'] ?? null;
        if ($existing === null || ($row['expiry'] !== null && $row['expiry'] > $existing)) {
            $evidence[$key] = ['expiry' => $row['expiry'], 'verified' => $row['status'] === 'valid'];
        }
    }

    // References count as met once at least one is verified.
    $stmt = $conn->prepare(
        'SELECT COUNT(*) FROM worker_references r
         JOIN workers w ON w.id = r.worker_id
         WHERE w.person_id = ? AND r.status IN ("verified", "gap_explained")'
    );
    $stmt->bind_param('i', $personId);
    $stmt->execute();
    if ((int) $stmt->get_result()->fetch_row()[0] > 0) {
        $evidence['references'] = ['expiry' => null, 'verified' => true];
    }

    // A signed contract on file.
    $stmt = $conn->prepare(
        'SELECT COUNT(*) FROM files f
         JOIN workers w ON w.id = f.worker_id
         WHERE w.person_id = ? AND f.category = "contract" AND f.deleted_at IS NULL'
    );
    $stmt->bind_param('i', $personId);
    $stmt->execute();
    if ((int) $stmt->get_result()->fetch_row()[0] > 0) {
        $evidence['contract'] = ['expiry' => null, 'verified' => true];
    }

    return $evidence;
}

/**
 * Assess a person for a service and record the verdict.
 *
 * The verdict is CALCULATED here and written to person_services. Nothing else
 * may write those columns -- see docs/RULES.md rule 1.
 */
function rg_assess_person(mysqli $conn, int $personId, string $service, ?string $workerRole = null): array
{
    $rules = rg_load_rules($conn, $service, $workerRole);
    $evidence = rg_load_evidence($conn, $personId);
    $verdict = rg_evaluate_eligibility($rules, $evidence);
    $reason = rg_eligibility_reason($verdict);

    $stmt = $conn->prepare(
        'INSERT INTO person_services (person_id, service, eligibility_status, eligibility_reason, eligibility_score, assessed_at)
         VALUES (?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            eligibility_status = VALUES(eligibility_status),
            eligibility_reason = VALUES(eligibility_reason),
            eligibility_score  = VALUES(eligibility_score),
            assessed_at        = VALUES(assessed_at)'
    );
    $score = $verdict['score'];
    $status = $verdict['status'];
    $stmt->bind_param('isssi', $personId, $service, $status, $reason, $score);
    $stmt->execute();

    return $verdict + ['reason' => $reason, 'service' => $service, 'person_id' => $personId];
}

/**
 * May this worker be deployed in this service right now?
 *
 * The single gate every assignment path must call. Returns the verdict plus a
 * refusal reason, never a bare boolean -- a refusal nobody can act on is a
 * refusal that gets worked around.
 */
function rg_deployment_check(mysqli $conn, int $workerId, ?string $service = null): array
{
    $stmt = $conn->prepare('SELECT person_id, name, role, division FROM workers WHERE id = ? AND deleted_at IS NULL');
    $stmt->bind_param('i', $workerId);
    $stmt->execute();
    $w = $stmt->get_result()->fetch_assoc();
    if (!$w) {
        return ['eligible' => false, 'reason' => 'That worker does not exist.', 'status' => 'not_eligible'];
    }
    if (!$w['person_id']) {
        return ['eligible' => false, 'reason' => 'That worker is not linked to a person record.', 'status' => 'not_eligible'];
    }

    $verdict = rg_assess_person($conn, (int) $w['person_id'], $service ?? $w['division'], $w['role']);
    $verdict['worker'] = $w['name'];
    return $verdict;
}
