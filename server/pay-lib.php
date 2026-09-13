<?php
// Shared pay computation — used by payroll.php (pay run / payslips) and
// margin.php (pay-vs-charge reconciliation) so labour cost is calculated the
// same way in both places, premiums and overtime included.
//
// Pure functions only: no auth, no output. Callers handle access + JSON.

const PAY_OT_WEEKLY_THRESHOLD = 40.0;
const PAY_HOLIDAY_RATE = 0.1207;

function pay_valid_date(string $d): bool
{
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
}

// Load configurable multipliers, with safe fallbacks if a row is missing.
function pay_load_rules(mysqli $conn): array
{
    $rules = ['night' => 1.30, 'weekend' => 1.25, 'bank_holiday' => 2.00, 'overtime' => 1.50];
    $res = $conn->query('SELECT rule_key, multiplier FROM pay_rules');
    if ($res) {
        while ($r = $res->fetch_assoc()) $rules[$r['rule_key']] = (float) $r['multiplier'];
    }
    return $rules;
}

/**
 * The pay floor in force on a date, or null when none is configured.
 *
 * A floor, not the National Minimum Wage: NMW has age bands and an apprentice
 * rate, and workers carries no date of birth. See migration 024. An unset floor
 * checks nothing, which is the default.
 */
function pay_floor_on(mysqli $conn, string $date): ?int
{
    $stmt = $conn->prepare(
        'SELECT rate_pence FROM pay_floors WHERE effective_from <= ? ORDER BY effective_from DESC LIMIT 1'
    );
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? (int) $row['rate_pence'] : null;
}

function pay_load_bank_holidays(mysqli $conn, string $from, string $to): array
{
    $set = [];
    $stmt = $conn->prepare('SELECT holiday_date FROM bank_holidays WHERE holiday_date BETWEEN ? AND ?');
    $stmt->bind_param('ss', $from, $to);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $set[$r['holiday_date']] = true;
    return $set;
}

// pay_day_premium() lived here: one premium for a whole entry, chosen from its
// start hour and its work_date. See rg_premium_segments() in pure.php for why
// that had to go once a shift could cross midnight. Removed rather than left,
// so there is one answer to what an hour is worth.

/**
 * Overtime is a weekly threshold, so the whole week has to be counted even when
 * the pay period cuts through it.
 *
 * A monthly run used to read only the entries inside from..to while keying the
 * 40-hour accumulator by ISO week. A week straddling the month end was therefore
 * counted twice from zero: a worker doing 8 hours on Monday 31 August and 40
 * more across the rest of that week reached 48, and the September run saw 40 and
 * paid no overtime at all. These two widen the *scan* to whole ISO weeks; the
 * entries outside the period still only fill the threshold, they are never paid.
 */
function pay_week_scan_start(string $from): string
{
    return date('Y-m-d', strtotime('monday this week', strtotime($from)));
}

function pay_week_scan_end(string $to): string
{
    return date('Y-m-d', strtotime('sunday this week', strtotime($to)));
}

/**
 * The money for one timesheet entry. Pure, so it can be tested.
 *
 * All of the arithmetic used to live inside pay_compute()'s result loop, which
 * takes a mysqli and is therefore untestable without a database -- so base pay,
 * the overtime split, the premium application and both roundings had no test at
 * all on a payroll system. Splitting the sums out changes no behaviour; it makes
 * them checkable.
 *
 * Takes the ordered premium segments from rg_premium_segments() rather than a
 * single premium for the whole entry, so a shift that runs from a weekday night
 * into a Saturday is paid night for the part that is night and weekend for the
 * part that is weekend.
 *
 * $priorWeekHours is what this worker has already worked in the same ISO week.
 * Overtime applies to the part of THIS entry that crosses the weekly threshold,
 * taken from the END of the shift, which is where the extra hours actually are.
 *
 * Money is pence, rounded once per entry. Hours stay floats: they are a
 * quantity, not an amount.
 *
 * @param list<array{key:string,minutes:int}> $segments in time order
 * @param array<string,float> $rules multipliers, keyed as in pay_rules
 * @return array{hours:float,normal_hours:float,overtime_hours:float,base_pence:int,gross_pence:int,premium:string,breakdown:array<string,float>}
 */
function pay_entry(int $ratePence, array $segments, float $priorWeekHours, array $rules): array
{
    $overtimeMult = $rules['overtime'] ?? 1.5;
    $totalHours = 0.0;
    $normalHours = 0.0;
    $otHours = 0.0;
    $grossRaw = 0.0;
    $breakdown = [];
    $topKey = 'standard';
    $topMult = 1.0;

    $week = $priorWeekHours;
    foreach ($segments as $seg) {
        $segHours = $seg['minutes'] / 60.0;
        if ($segHours <= 0) continue;

        // The dearest applicable band, not a fixed order of precedence. A
        // Saturday at 22:00 is both weekend and night, and which of those pays
        // depends on the multipliers the business configured -- with the
        // defaults it is night, at 1.30 against weekend's 1.25. Deciding it here
        // rather than in rg_premium_segments() keeps the choice in pay_rules.
        $key = 'standard';
        $mult = 1.0;
        foreach ($seg['keys'] as $k) {
            $m = (float) ($rules[$k] ?? 1.0);
            if ($m > $mult) {
                $mult = $m;
                $key = $k;
            }
        }

        // The part of this segment still inside the weekly threshold, and the
        // part beyond it. A single segment can straddle the boundary.
        $segNormal = max(0.0, min($segHours, PAY_OT_WEEKLY_THRESHOLD - $week));
        $segOt = $segHours - $segNormal;

        // Overtime never pays less than the premium it displaces: a bank-holiday
        // hour does not get cheaper by being the forty-first of the week.
        $grossRaw += $ratePence * ($segNormal * $mult + $segOt * max($overtimeMult, $mult));

        $totalHours += $segHours;
        $normalHours += $segNormal;
        $otHours += $segOt;
        $week += $segHours;

        $breakdown[$key] = ($breakdown[$key] ?? 0.0) + $segHours;
        if ($mult > $topMult) {
            $topMult = $mult;
            $topKey = $key;
        }
    }

    foreach ($breakdown as $k => $v) $breakdown[$k] = round($v, 2);

    return [
        'hours' => $totalHours,
        'normal_hours' => $normalHours,
        'overtime_hours' => $otHours,
        'base_pence' => (int) round($ratePence * $totalHours),
        'gross_pence' => (int) round($grossRaw),
        // The dearest premium the entry touched, for a one-word label; the
        // breakdown beside it is what actually earned the money.
        'premium' => $topKey,
        'breakdown' => $breakdown,
    ];
}

/**
 * Rolled-up holiday accrual for a worked total, valued at base rate.
 *
 * Note for whoever owns the pay rules: this accrues on ALL worked hours
 * including those paid at overtime, and values them at base rather than at
 * average pay, which understates the statutory figure. That is the existing
 * behaviour, pinned here rather than changed.
 *
 * @return array{hours:float,pence:int}
 */
function pay_holiday(float $workedHours, int $ratePence): array
{
    $hours = $workedHours * PAY_HOLIDAY_RATE;
    return ['hours' => $hours, 'pence' => (int) round($ratePence * $hours)];
}

// Compute per-entry pay across approved timesheets in range.
// Returns ['workers' => [aggregate...], 'lines' => [entry breakdowns], 'rules' => [...]].
function pay_compute(mysqli $conn, string $from, string $to, ?int $onlyWorker = null): array
{
    $rules = pay_load_rules($conn);
    $bankHols = pay_load_bank_holidays($conn, $from, $to);

    $floor = pay_floor_on($conn, $from);
    $scanFrom = pay_week_scan_start($from);
    $scanTo = pay_week_scan_end($to);

    // The rate that was in force on the day the work was done, not today's.
    //
    // This read w.pay_rate_pence, so a rise re-priced every period the worker had
    // ever worked: re-opening a closed period after a rate change returned
    // different money for work already paid. COALESCE falls back to the current
    // rate for a worker with no history row, so nothing breaks if one is missing.
    $sql =
        'SELECT t.id, t.worker_id, w.name AS worker, w.division, w.pay_type,
                COALESCE(
                    (SELECT r.rate_pence FROM worker_pay_rates r
                      WHERE r.worker_id = t.worker_id AND r.effective_from <= t.work_date
                      ORDER BY r.effective_from DESC LIMIT 1),
                    w.pay_rate_pence
                ) AS pay_rate_pence,
                t.work_date, t.start_time, t.end_time, t.minutes
         FROM timesheets t JOIN workers w ON w.id = t.worker_id
         WHERE t.status = "approved" AND t.work_date BETWEEN ? AND ?
           AND t.deleted_at IS NULL AND w.deleted_at IS NULL'
        . ($onlyWorker ? ' AND t.worker_id = ?' : '')
        . ' ORDER BY t.worker_id, t.work_date, t.start_time';
    $stmt = $conn->prepare($sql);
    if ($onlyWorker) $stmt->bind_param('ssi', $scanFrom, $scanTo, $onlyWorker);
    else $stmt->bind_param('ss', $scanFrom, $scanTo);
    $stmt->execute();
    $res = $stmt->get_result();

    $workers = [];
    $lines = [];
    $weekHours = [];

    while ($e = $res->fetch_assoc()) {
        $wid = (int) $e['worker_id'];
        $rate = (int) $e['pay_rate_pence'];
        $hours = ((int) $e['minutes']) / 60.0;

        $segments = rg_premium_segments($e['work_date'], $e['start_time'], (int) $e['minutes'], $bankHols);
        $wkKey = $wid . '|' . date('oW', strtotime($e['work_date']));
        $priorWeek = $weekHours[$wkKey] ?? 0.0;
        $weekHours[$wkKey] = $priorWeek + $hours;

        $line = pay_entry($rate, $segments, $priorWeek, $rules);
        $otHours = $line['overtime_hours'];

        // Outside the period: it has filled the week's threshold above, which is
        // the whole reason it was read, and it belongs to a different pay run.
        if ($e['work_date'] < $from || $e['work_date'] > $to) continue;

        $gross = $line['gross_pence'];
        $base = $line['base_pence'];

        if (!isset($workers[$wid])) {
            $workers[$wid] = [
                'worker_id' => $wid, 'worker' => $e['worker'], 'division' => $e['division'],
                'pay_type' => $e['pay_type'], 'pay_rate_pence' => $rate,
                'hours' => 0.0, 'base_pence' => 0, 'gross_pence' => 0,
                'holiday_hours' => 0.0, 'holiday_raw_pence' => 0.0, 'rate_changed' => false,
                'below_floor' => false,
            ];
        }
        // Flagged, never blocked: the pay run still has to produce a figure, and
        // the office needs to see which rows to look at.
        if ($floor !== null && $rate < $floor) $workers[$wid]['below_floor'] = true;
        // A rate can change mid-period now, so one rate no longer describes the
        // row. Report the latest one in force and say that it moved.
        if ($rate !== $workers[$wid]['pay_rate_pence']) {
            $workers[$wid]['rate_changed'] = true;
            $workers[$wid]['pay_rate_pence'] = $rate;
        }
        $workers[$wid]['hours'] += $hours;
        $workers[$wid]['base_pence'] += $base;
        $workers[$wid]['gross_pence'] += $gross;

        // Accrued at the rate the hours were worked at, accumulated unrounded and
        // rounded once at the end rather than per entry.
        $hol = pay_holiday($hours, $rate);
        $workers[$wid]['holiday_hours'] += $hol['hours'];
        $workers[$wid]['holiday_raw_pence'] += $rate * $hol['hours'];

        $lines[] = [
            'work_date' => $e['work_date'], 'start_time' => substr($e['start_time'], 0, 5),
            'end_time' => substr($e['end_time'], 0, 5), 'hours' => round($hours, 2),
            'premium' => $line['premium'], 'overtime_hours' => round($otHours, 2),
            // Hours per premium band, so a mixed shift can show why it was paid
            // what it was instead of carrying one misleading label.
            'breakdown' => $line['breakdown'],
            'gross_pence' => $gross,
        ];
    }

    foreach ($workers as &$w) {
        $w['holiday_pay_pence'] = (int) round($w['holiday_raw_pence']);
        unset($w['holiday_raw_pence']);
        $w['holiday_hours'] = round($w['holiday_hours'], 2);
        $w['premium_pence'] = $w['gross_pence'] - $w['base_pence'];
        $w['hours'] = round($w['hours'], 2);
    }
    unset($w);

    return [
        'workers' => array_values($workers),
        'lines' => $lines,
        'rules' => $rules,
        'floor_pence' => $floor,
    ];
}
