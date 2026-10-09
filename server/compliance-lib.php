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
    // Memoised per request. Screening a division for a shift asks for the same
    // rule set once per worker -- identical every time for a given service and
    // role -- so a 200-worker division ran this query 200 times to get 200
    // identical answers.
    static $cache = [];
    $key = $service . '|' . ($workerRole ?? '');
    if (isset($cache[$key])) return $cache[$key];

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

    // A completed verification is stronger evidence than a field somebody typed
    // in, so where one exists it wins.
    //
    // It AUGMENTS rather than replaces: a check still sitting at 'received' does
    // not downgrade the underlying record, because every migrated SIA and DBS
    // entry starts there. Downgrading them all at once would block the whole
    // workforce on the day this shipped -- the same trap right to work hit, and
    // the same answer: the evidence model improves, and enforcement follows once
    // the backlog is worked through.
    $stmt = $conn->prepare(
        'SELECT requirement_key, status, expires_on, verified_at
         FROM verifications
         WHERE person_id = ? AND status IN ("verified", "rejected")
         ORDER BY id DESC'
    );
    $stmt->bind_param('i', $personId);
    $stmt->execute();
    $res = $stmt->get_result();

    // Which requirements a reviewer has ruled on. Tracked separately from
    // $evidence because a rejection REMOVES the entry, and "there is nothing
    // here" and "somebody looked and said no" are not the same fact.
    //
    // They were the same fact, and it was the defect this whole function turns
    // on. The newest-wins test used to read `$evidence[$key]['from_verification']`
    // -- but a rejection unsets the key, so the test was false for the next row,
    // and the next row is an OLDER verification for the same requirement. A
    // person rejected today, having been verified last year, read as verified.
    // Reproduced in server/tests/db/cases/100_evidence_precedence.php.
    $decided = [];

    while ($row = $res->fetch_assoc()) {
        $key = $row['requirement_key'];
        // Ordered by id DESC, so the first row for a key is the newest decision
        // and every later one is older. Newest wins, whichever way it went.
        if (isset($decided[$key])) continue;
        $decided[$key] = $row['status'];

        if ($row['status'] === 'rejected') {
            // Worse than no evidence: somebody looked and said no. Remove
            // whatever the field-based guess had put here.
            unset($evidence[$key]);
            continue;
        }
        $evidence[$key] = [
            // A verification with no expiry of its own does not erase a date
            // already known from elsewhere; it is silent about expiry, not a
            // claim that there is none.
            'expiry' => $row['expires_on'] ?? ($evidence[$key]['expiry'] ?? null),
            'verified' => true,
            'from_verification' => true,
        ];
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

        // A reviewed decision outranks a certificate row. This pass runs after
        // the verifications pass and used to overwrite whatever it found, so a
        // certificate whose free-text type happened to match a requirement label
        // could revive a rejection -- the key having been unset, there was
        // nothing left for it to disagree with -- or quietly re-date a reviewed
        // expiry to one nobody had checked.
        //
        // The join is a lower-cased label match. Free text is a poor join key,
        // which is exactly why it must not outrank somebody's decision.
        if (isset($decided[$key])) continue;

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
    // Same rule: a reviewer who rejected the references is not overruled by the
    // reference rows themselves still sitting there marked verified.
    if (!isset($decided['references']) && (int) $stmt->get_result()->fetch_row()[0] > 0) {
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
    // A file in the vault is a document received. If a reviewer has ruled on the
    // contract requirement, that ruling stands over the presence of a file.
    if (!isset($decided['contract']) && (int) $stmt->get_result()->fetch_row()[0] > 0) {
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
function rg_assess_person(mysqli $conn, int $personId, string $service, ?string $workerRole = null, bool $persist = true): array
{
    $rules = rg_load_rules($conn, $service, $workerRole);
    $evidence = rg_load_evidence($conn, $personId);
    $verdict = rg_evaluate_eligibility($rules, $evidence);
    $reason = rg_eligibility_reason($verdict);

    // $persist false when screening a list rather than deciding about a person.
    //
    // Recording the verdict is worth doing when the office assigns somebody:
    // person_services then says what was decided and when. It is not worth doing
    // once per worker every time a calendar moves -- "Find eligible" on a
    // 200-worker division wrote 200 rows on a GET, and broadcast did it again.
    if (!$persist) {
        return $verdict + ['reason' => $reason, 'service' => $service, 'person_id' => $personId];
    }

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
function rg_deployment_check(mysqli $conn, int $workerId, ?string $service = null, bool $persist = true): array
{
    $stmt = $conn->prepare(
        'SELECT person_id, name, role, division, status, left_on, approved_at, deleted_at
           FROM workers WHERE id = ? AND deleted_at IS NULL'
    );
    $stmt->bind_param('i', $workerId);
    $stmt->execute();
    $w = $stmt->get_result()->fetch_assoc();
    if (!$w) {
        return ['eligible' => false, 'reason' => 'That worker does not exist.', 'status' => 'not_eligible'];
    }
    if (!$w['person_id']) {
        return ['eligible' => false, 'reason' => 'That worker is not linked to a person record.', 'status' => 'not_eligible'];
    }

    // Employment state first, credentials second.
    //
    // This assessed compliance evidence and nothing else, so a worker who was
    // suspended, had left, was still half way through onboarding, or had never
    // been approved for work passed the gate as long as their DBS and licence
    // were in date. Every assignment path goes through this function -- that is
    // why the clash test and the claim check live in this file rather than in an
    // endpoint -- so checking it here checks it everywhere.
    $gate = rg_employment_gate($w, [
        'onboarding_outstanding' => rg_onboarding_outstanding($conn, (int) $w['person_id']),
    ]);
    if (!$gate['ok']) {
        return [
            'eligible' => false,
            'status' => 'not_eligible',
            'reason' => $gate['reason'],
            'worker' => $w['name'],
            // Named separately from a credential failure because the two need
            // different people to fix them: one is HR, the other is compliance.
            'blocked_by' => 'employment',
            'blocking' => [],
            'warnings' => [],
            'items' => [],
        ];
    }

    $verdict = rg_assess_person($conn, (int) $w['person_id'], $service ?? $w['division'], $w['role'], $persist);
    $verdict['worker'] = $w['name'];
    $verdict['blocked_by'] = ($verdict['status'] ?? '') === RG_NOT_ELIGIBLE ? 'evidence' : null;
    return $verdict;
}

/**
 * How many onboarding tasks are still outstanding for this person.
 *
 * Tasks hang off the APPLICATION, not the worker, so this reaches them through
 * applicants.person_id. A worker created directly in workers.php has no
 * application and therefore no checklist -- and no outstanding tasks, which is
 * the correct answer for somebody the office entered by hand.
 */
function rg_onboarding_outstanding(mysqli $conn, int $personId): int
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) FROM onboarding_tasks t
           JOIN applicants a ON a.id = t.applicant_id
          WHERE a.person_id = ? AND a.deleted_at IS NULL AND t.done = 0'
    );
    $stmt->bind_param('i', $personId);
    $stmt->execute();
    return (int) $stmt->get_result()->fetch_row()[0];
}

/**
 * Does this person have a signed employment contract that somebody has reviewed?
 *
 * A signature is evidence that the worker agreed; a review is evidence that the
 * company did. Approval needs both (migration 034).
 */
function rg_has_reviewed_contract(mysqli $conn, int $personId): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) FROM employment_contracts
          WHERE person_id = ? AND status = "signed" AND reviewed_at IS NOT NULL'
    );
    $stmt->bind_param('i', $personId);
    $stmt->execute();
    return (int) $stmt->get_result()->fetch_row()[0] > 0;
}

/**
 * What stands between this worker and an approval, from the database.
 *
 * The facts rg_approval_blockers() needs, gathered in one place so the approve
 * action and the profile that offers it ask the same question the same way.
 * $worker is a workers row with person_id, status, left_on, approved_at and
 * deleted_at.
 *
 * @return list<array{code:string,reason:string}>
 */
function rg_approval_readiness(mysqli $conn, array $worker): array
{
    $personId = (int) $worker['person_id'];
    return rg_approval_blockers(
        $worker,
        rg_onboarding_outstanding($conn, $personId),
        rg_has_reviewed_contract($conn, $personId)
    );
}

/**
 * Is this worker already booked over this window?
 *
 * Lived in scheduling.php, which is an endpoint and cannot be required, so the
 * two paths that fill a shift without going through the office screen -- a
 * worker accepting an SMS offer (offer.php) and a worker claiming an open shift
 * in the app (me.php) -- had no way to ask and did not.
 *
 * $exceptShiftId is the shift being reassigned: a shift must not clash with
 * itself. Archived rows are excluded, because an archived booking is not a
 * booking and one left behind would block the slot forever.
 */
function rg_shift_clash(mysqli $conn, int $workerId, string $startSql, string $endSql, int $exceptShiftId = 0): bool
{
    $stmt = $conn->prepare(
        'SELECT id FROM shifts
         WHERE worker_id = ? AND status = "scheduled" AND deleted_at IS NULL
           AND id <> ? AND starts_at < ? AND ends_at > ? LIMIT 1'
    );
    $stmt->bind_param('iiss', $workerId, $exceptShiftId, $endSql, $startSql);
    $stmt->execute();
    return (bool) $stmt->get_result()->fetch_assoc();
}

/**
 * May this worker take this specific shift?
 *
 * rg_deployment_check() answers "may they work at all"; this adds "and are they
 * free". Every path that puts a worker on a shift must call it -- the office
 * screen did, and the two self-service paths did not, so a worker whose DBS had
 * lapsed since the broadcast was booked by replying to the text, and one who had
 * since taken an overlapping shift was booked over the top of it. Verified
 * against a local database: a worker already scheduled 08:00-16:00 accepted an
 * overlapping 09:00-17:00 shift through /m and ended up on both.
 *
 * Returns the shift row alongside the verdict so the caller need not load it
 * twice.
 *
 * @return array{ok:bool,reason:string,shift:?array,gate:?array}
 */
function rg_claim_check(mysqli $conn, int $workerId, int $shiftId): array
{
    $stmt = $conn->prepare(
        'SELECT id, worker_id, division, title, starts_at, ends_at, status
         FROM shifts WHERE id = ? AND deleted_at IS NULL'
    );
    $stmt->bind_param('i', $shiftId);
    $stmt->execute();
    $shift = $stmt->get_result()->fetch_assoc();
    if (!$shift) {
        return ['ok' => false, 'reason' => 'That shift no longer exists.', 'shift' => null, 'gate' => null];
    }

    $gate = rg_deployment_check($conn, $workerId, $shift['division']);
    if (!$gate['eligible']) {
        return ['ok' => false, 'reason' => $gate['reason'], 'shift' => $shift, 'gate' => $gate];
    }

    if (rg_shift_clash($conn, $workerId, $shift['starts_at'], $shift['ends_at'], $shiftId)) {
        return [
            'ok' => false,
            'reason' => 'You are already booked for an overlapping shift.',
            'shift' => $shift,
            'gate' => $gate,
        ];
    }

    return ['ok' => true, 'reason' => '', 'shift' => $shift, 'gate' => $gate];
}

/**
 * Recalculate one worker's derived status, now.
 *
 * rg_derive_worker_status() was called from exactly one place: the nightly
 * expiry-alerts.php run. So a worker approved at 10am read as "pre-employment"
 * on every screen until the following morning, and a credential rejected at noon
 * left a dashboard saying "active" all afternoon. The plan's wording is
 * "recalculate when evidence changes, not only at the next daily cron".
 *
 * Deliberately the same facts, in the same order, as the cron gathers. The cron
 * still exists and still sweeps everybody: this is for the moment a decision is
 * made, not a replacement for the daily pass.
 *
 * Returns the derived status, or null when there is no such worker.
 */
function rg_refresh_worker_status(mysqli $conn, int $workerId): ?array
{
    $stmt = $conn->prepare(
        'SELECT w.id, w.person_id, w.status, w.left_on,
                (SELECT COUNT(*) FROM person_services ps
                  WHERE ps.person_id = w.person_id AND ps.active = 1
                    AND ps.eligibility_status IN ("eligible","eligible_with_warnings")) AS eligible_services,
                (SELECT COUNT(*) FROM leave_records l
                  WHERE l.person_id = w.person_id AND l.status IN ("approved","taken")
                    AND CURDATE() BETWEEN l.starts_on AND l.ends_on) AS on_leave_today,
                (SELECT COUNT(*) FROM onboarding_tasks ot
                  JOIN applicants a ON a.id = ot.applicant_id
                  WHERE a.person_id = w.person_id AND ot.done = 0) AS onboarding_outstanding,
                (SELECT COUNT(*) FROM files f
                  WHERE f.worker_id = w.id AND f.category = "contract" AND f.deleted_at IS NULL) AS contracts
           FROM workers w
          WHERE w.id = ? AND w.deleted_at IS NULL AND w.person_id IS NOT NULL'
    );
    $stmt->bind_param('i', $workerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) return null;

    $derived = rg_derive_worker_status(
        ['status' => $row['status'], 'left_on' => $row['left_on']],
        [
            'eligible_services' => (int) $row['eligible_services'],
            'on_leave_today' => (int) $row['on_leave_today'] > 0,
            'onboarding_outstanding' => (int) $row['onboarding_outstanding'],
            'has_contract' => (int) $row['contracts'] > 0,
        ]
    );

    $stmt = $conn->prepare(
        'UPDATE workers SET derived_status = ?, derived_status_reason = ?, derived_at = NOW() WHERE id = ?'
    );
    $stmt->bind_param('ssi', $derived['status'], $derived['reason'], $workerId);
    $stmt->execute();

    return $derived;
}

/**
 * Recalculate every worker who shares a person record.
 *
 * Evidence hangs off the PERSON, so verifying or rejecting one thing can change
 * the answer for more than one employment record.
 */
function rg_refresh_person_workers(mysqli $conn, int $personId): void
{
    $stmt = $conn->prepare('SELECT id FROM workers WHERE person_id = ? AND deleted_at IS NULL');
    $stmt->bind_param('i', $personId);
    $stmt->execute();
    $res = $stmt->get_result();
    $ids = [];
    while ($row = $res->fetch_assoc()) $ids[] = (int) $row['id'];
    foreach ($ids as $id) rg_refresh_worker_status($conn, $id);
}
