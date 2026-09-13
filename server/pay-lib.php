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

// Premium multiplier for a single entry (excludes overtime). Highest of the
// applicable day premiums: bank holiday > weekend > night.
function pay_day_premium(array $rules, string $workDate, string $startTime, array $bankHols): array
{
    $applicable = [];
    if (isset($bankHols[$workDate])) $applicable['bank_holiday'] = $rules['bank_holiday'];
    $dow = (int) date('N', strtotime($workDate)); // 6=Sat 7=Sun
    if ($dow >= 6) $applicable['weekend'] = $rules['weekend'];
    $hour = (int) substr($startTime, 0, 2);
    if ($hour >= 20 || $hour < 6) $applicable['night'] = $rules['night'];

    if (!$applicable) return ['key' => 'standard', 'mult' => 1.0];
    $key = array_keys($applicable, max($applicable))[0];
    return ['key' => $key, 'mult' => max($applicable)];
}

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
 * $priorWeekHours is what this worker has already worked in the same ISO week.
 * Overtime applies to the part of THIS entry that crosses the weekly threshold,
 * so an entry can be part normal and part overtime.
 *
 * Money is pence, rounded once per entry. Hours stay floats: they are a
 * quantity, not an amount.
 *
 * @param array{key:string,mult:float} $premium from pay_day_premium()
 * @return array{hours:float,normal_hours:float,overtime_hours:float,base_pence:int,gross_pence:int}
 */
function pay_entry(int $ratePence, int $minutes, float $priorWeekHours, array $premium, float $overtimeMult): array
{
    $hours = $minutes / 60.0;

    $normalHours = $hours;
    $otHours = 0.0;
    if ($priorWeekHours + $hours > PAY_OT_WEEKLY_THRESHOLD) {
        $normalHours = max(0.0, PAY_OT_WEEKLY_THRESHOLD - $priorWeekHours);
        $otHours = $hours - $normalHours;
    }

    // Overtime never pays less than the day premium it displaces: a bank-holiday
    // hour does not become cheaper by being the forty-first of the week.
    $otMult = max($overtimeMult, $premium['mult']);

    return [
        'hours' => $hours,
        'normal_hours' => $normalHours,
        'overtime_hours' => $otHours,
        'base_pence' => (int) round($ratePence * $hours),
        'gross_pence' => (int) round($ratePence * ($normalHours * $premium['mult'] + $otHours * $otMult)),
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

    $scanFrom = pay_week_scan_start($from);
    $scanTo = pay_week_scan_end($to);

    $sql =
        'SELECT t.id, t.worker_id, w.name AS worker, w.division, w.pay_type, w.pay_rate_pence,
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

        $prem = pay_day_premium($rules, $e['work_date'], $e['start_time'], $bankHols);
        $wkKey = $wid . '|' . date('oW', strtotime($e['work_date']));
        $priorWeek = $weekHours[$wkKey] ?? 0.0;
        $weekHours[$wkKey] = $priorWeek + $hours;

        $line = pay_entry($rate, (int) $e['minutes'], $priorWeek, $prem, $rules['overtime']);
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
            ];
        }
        $workers[$wid]['hours'] += $hours;
        $workers[$wid]['base_pence'] += $base;
        $workers[$wid]['gross_pence'] += $gross;

        $lines[] = [
            'work_date' => $e['work_date'], 'start_time' => substr($e['start_time'], 0, 5),
            'end_time' => substr($e['end_time'], 0, 5), 'hours' => round($hours, 2),
            'premium' => $prem['key'], 'overtime_hours' => round($otHours, 2),
            'gross_pence' => $gross,
        ];
    }

    foreach ($workers as &$w) {
        $hol = pay_holiday($w['hours'], $w['pay_rate_pence']);
        $w['holiday_hours'] = round($hol['hours'], 2);
        $w['holiday_pay_pence'] = $hol['pence'];
        $w['premium_pence'] = $w['gross_pence'] - $w['base_pence'];
        $w['hours'] = round($w['hours'], 2);
    }
    unset($w);

    return ['workers' => array_values($workers), 'lines' => $lines, 'rules' => $rules];
}
