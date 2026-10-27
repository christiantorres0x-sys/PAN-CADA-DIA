<?php
require_once __DIR__ . '/db.php';
require_login();

$siteId = (int)($_GET['site_id'] ?? 0);
$siteSelected = isset($_GET['site_id']) && ctype_digit((string)$_GET['site_id']);
$programId = (int)($_GET['program_id'] ?? 0);
$status = trim($_GET['status'] ?? '');
$monthChoice = trim($_GET['month'] ?? '');
$yearChoice = trim($_GET['year'] ?? '');
$monthSelected = $monthChoice !== '';
$yearSelected = $yearChoice !== '';
$month = $monthChoice === 'all' ? '' : $monthChoice;
$year = $yearChoice === 'all' ? '' : $yearChoice;
if ($month !== '' && (!ctype_digit($month) || (int)$month < 1 || (int)$month > 12)) $month = '';
if ($year !== '' && (!ctype_digit($year) || (int)$year < 1900 || (int)$year > (int)date('Y'))) $year = '';
$monthSelected = $monthSelected && ($monthChoice === 'all' || $month !== '');
$yearSelected = $yearSelected && ($yearChoice === 'all' || $year !== '');
$reportFiltersComplete = $siteSelected && $monthSelected && $yearSelected;
$month = $month === '' ? '' : (string)(int)$month;
$year = $year === '' ? '' : (string)(int)$year;
$from = '';
$to = '';
if ($year !== '') {
    $from = $year . ($month !== '' ? '-' . str_pad($month, 2, '0', STR_PAD_LEFT) . '-01' : '-01-01');
    $to = $month !== ''
        ? (new DateTimeImmutable($from))->modify('last day of this month')->format('Y-m-d')
        : $year . '-12-31';
}
$monthName = $month !== '' ? date('F', mktime(0, 0, 0, (int)$month, 1)) : '';
$implementationPeriod = $monthName !== '' && $year !== ''
    ? $monthName . ' ' . $year
    : ($monthName !== '' ? $monthName . ', All Years' : ($year !== '' ? 'All Months ' . $year : 'All Dates'));
$generated = ($_GET['generated'] ?? '') === '1';

$sites = $pdo->query("SELECT * FROM feeding_sites ORDER BY site_name")->fetchAll();
$programs = $pdo->query("SELECT * FROM feeding_programs ORDER BY program_name")->fetchAll();
$reportYears = array_map('intval', $pdo->query("SELECT year_value FROM (SELECT YEAR(record_date) AS year_value FROM health_records UNION SELECT YEAR(attendance_date) AS year_value FROM attendance_records) report_years WHERE year_value IS NOT NULL ORDER BY year_value DESC")->fetchAll(PDO::FETCH_COLUMN));
if (!in_array((int)date('Y'), $reportYears, true)) $reportYears[] = (int)date('Y');
rsort($reportYears);

$where = [];
$params = [];
if ($siteId > 0) {
    $where[] = "b.site_id=?";
    $params[] = $siteId;
}
if ($programId > 0) {
    $where[] = "b.program_id=?";
    $params[] = $programId;
}
if ($status !== '') {
    $where[] = "b.program_status=?";
    $params[] = $status;
}
if ($month !== '' && $year === '') {
    $where[] = "MONTH(hr.record_date)=?";
    $params[] = (int)$month;
}
if ($from !== '') {
    $where[] = "hr.record_date>=?";
    $params[] = $from;
}
if ($to !== '') {
    $where[] = "hr.record_date<=?";
    $params[] = $to;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT hr.record_id, hr.record_date, hr.milestone, b.beneficiary_id, b.full_name, b.beneficiary_code, b.birth_date, b.sex,
           b.program_status, b.inactive_since, s.site_name, p.program_name, hr.height_cm, hr.weight_kg, hr.bmi,
           COALESCE(hr.nutritional_status, 'Pending child reference') nutritional_status
    FROM health_records hr
    JOIN beneficiaries b ON b.beneficiary_id = hr.beneficiary_id
    LEFT JOIN feeding_sites s ON s.site_id = b.site_id
    LEFT JOIN feeding_programs p ON p.program_id = b.program_id
    $whereSql
    ORDER BY hr.record_date DESC, hr.record_id DESC
    LIMIT 1000
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$totalRecords = count($rows);
$uniqueBeneficiaries = count(array_unique(array_column($rows, 'beneficiary_id')));
$avgBmi = $totalRecords ? round(array_sum(array_map(fn($r) => (float)$r['bmi'], $rows)) / $totalRecords, 2) : null;

$siteSummary = [];
foreach ($rows as $r) {
    $key = $r['site_name'] ?: 'Unassigned';
    if (!isset($siteSummary[$key])) {
        $siteSummary[$key] = ['beneficiaries' => [], 'records' => 0, 'bmi' => []];
    }
    $siteSummary[$key]['beneficiaries'][$r['beneficiary_id']] = true;
    $siteSummary[$key]['records']++;
    $siteSummary[$key]['bmi'][] = (float)$r['bmi'];
}
foreach ($siteSummary as $k => $v) {
    $siteSummary[$k]['beneficiary_count'] = count($v['beneficiaries']);
    $siteSummary[$k]['avg_bmi'] = count($v['bmi']) ? round(array_sum($v['bmi']) / count($v['bmi']), 2) : null;
}

// Health Monitoring Records: one row per beneficiary, with a Baseline,
// Midline and Endline column group side by side, each holding its own
// date / height / weight / BMI / weight status, so a child's progress reads
// left to right. A milestone with no record yet shows as Pending. Older
// records saved without a milestone get a small note row under the child
// so nothing disappears.
$recordGroups = [];
foreach ($rows as $r) {
    $bid = (int)$r['beneficiary_id'];
    if (!isset($recordGroups[$bid])) {
        $recordGroups[$bid] = ['info' => $r, 'by' => array_fill_keys(MILESTONES, null), 'other' => []];
    }
    $m = strtoupper((string)($r['milestone'] ?? ''));
    if (array_key_exists($m, $recordGroups[$bid]['by']) && $recordGroups[$bid]['by'][$m] === null) {
        $recordGroups[$bid]['by'][$m] = $r;
    } else {
        $recordGroups[$bid]['other'][] = $r;
    }
}
$healthTableRowCount = 0;
foreach ($recordGroups as $group) {
    $healthTableRowCount += 1 + count($group['other']);
}
$dateFilterActive = ($month !== '' || $year !== '');

// Renders one milestone's three compact cells for a beneficiary:
//   Date | Height + Weight (stacked) | BMI + Weight Status (stacked)
// Stacking keeps the whole Baseline / Midline / Endline comparison on one
// screen without sideways scrolling. A milestone with no record becomes a
// single "Pending" cell spanning its three columns.
$fmtDate = fn(string $d): string => format_display_date($d);
$fmtNum = fn($n): string => rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.');
$renderMilestoneCells = function (?array $r) use ($dateFilterActive, $fmtDate, $fmtNum): string {
    if ($r === null) {
        $note = $dateFilterActive ? 'No record in date range' : 'Pending';
        return '<td colspan="3" class="ms-start ms-pending">' . e($note) . '</td>';
    }
    $lower = strtolower($r['nutritional_status']);
    $flag = str_contains($lower, 'under') || str_contains($lower, 'severe');
    $status = $r['nutritional_status'] !== 'Pending child reference' ? pcd_badge($r['nutritional_status']) : '<span class="muted">Pending</span>';
    return '<td class="ms-start ms-date' . ($flag ? ' is-flag' : '') . '">' . e($fmtDate($r['record_date'])) . '</td>'
        . '<td class="ms-hw"><span>' . e($fmtNum($r['height_cm'])) . ' cm</span><span>' . e($fmtNum($r['weight_kg'])) . ' kg</span></td>'
        . '<td class="ms-bmi"><strong>' . e(number_format((float)$r['bmi'], 2, '.', '')) . '</strong><span>' . $status . '</span></td>';
};

// Chips shown above the tables summarizing which filters are actually
// narrowing the results (and a quick link to drop just that one).
$activeFilters = [];
if ($siteId > 0) {
    $siteName = '';
    foreach ($sites as $s) { if ((int)$s['site_id'] === $siteId) { $siteName = $s['site_name']; break; } }
    $activeFilters[] = ['label' => $siteName, 'clear' => array_diff_key($_GET, ['site_id' => ''])];
}
if ($programId > 0) {
    $programName = '';
    foreach ($programs as $p) { if ((int)$p['program_id'] === $programId) { $programName = $p['program_name']; break; } }
    $activeFilters[] = ['label' => 'Program: ' . $programName, 'clear' => array_diff_key($_GET, ['program_id' => ''])];
}
if ($status !== '') {
    $activeFilters[] = ['label' => 'Status: ' . $status, 'clear' => array_diff_key($_GET, ['status' => ''])];
}
if ($month !== '') $activeFilters[] = ['label' => $monthName, 'clear' => array_diff_key($_GET, ['month' => ''])];
if ($year !== '') $activeFilters[] = ['label' => $year, 'clear' => array_diff_key($_GET, ['year' => ''])];

// Attendance participation, scoped by the same site/program/status/date
// filters as the tables above (using attendance_date for the date range
// instead of a health record date). Uses real attendance_records data —
// no chart is drawn if there's nothing recorded yet.
$attWhere = [];
$attParams = [];
if ($siteId > 0) { $attWhere[] = 'b.site_id=?'; $attParams[] = $siteId; }
if ($programId > 0) { $attWhere[] = 'b.program_id=?'; $attParams[] = $programId; }
if ($status !== '') { $attWhere[] = 'b.program_status=?'; $attParams[] = $status; }
if ($month !== '' && $year === '') { $attWhere[] = 'MONTH(ar.attendance_date)=?'; $attParams[] = (int)$month; }
if ($from !== '') { $attWhere[] = 'ar.attendance_date>=?'; $attParams[] = $from; }
if ($to !== '') { $attWhere[] = 'ar.attendance_date<=?'; $attParams[] = $to; }
$attWhereSql = $attWhere ? 'WHERE ' . implode(' AND ', $attWhere) : '';
$attStmt = $pdo->prepare("
    SELECT ar.attendance_status, COUNT(*) c
    FROM attendance_records ar
    JOIN beneficiaries b ON b.beneficiary_id = ar.beneficiary_id
    $attWhereSql
    GROUP BY ar.attendance_status
");
$attStmt->execute($attParams);
$attCounts = ['PRESENT' => 0, 'ABSENT' => 0];
foreach ($attStmt->fetchAll() as $row) {
    $attCounts[$row['attendance_status']] = (int)$row['c'];
}

$attendanceScope = [];
$attendanceScopeParams = [];
if ($siteId > 0) { $attendanceScope[] = 'b.site_id=?'; $attendanceScopeParams[] = $siteId; }
if ($programId > 0) { $attendanceScope[] = 'b.program_id=?'; $attendanceScopeParams[] = $programId; }
if ($status !== '') { $attendanceScope[] = 'b.program_status=?'; $attendanceScopeParams[] = $status; }
$attendanceScopeSql = $attendanceScope ? 'WHERE ' . implode(' AND ', $attendanceScope) : '';

$attendanceDateWhere = $attendanceScope;
$attendanceDateParams = $attendanceScopeParams;
if ($from !== '') { $attendanceDateWhere[] = 'ar.attendance_date>=?'; $attendanceDateParams[] = $from; }
if ($to !== '') { $attendanceDateWhere[] = 'ar.attendance_date<=?'; $attendanceDateParams[] = $to; }
if ($month !== '' && $year === '') { $attendanceDateWhere[] = 'MONTH(ar.attendance_date)=?'; $attendanceDateParams[] = (int)$month; }
$attendanceDateSql = $attendanceDateWhere ? 'WHERE ' . implode(' AND ', $attendanceDateWhere) : '';
if ($from !== '' && $to !== '') {
    $attendanceDates = [];
    $dateCursor = new DateTimeImmutable($from);
    $dateEnd = new DateTimeImmutable($to);
    while ($dateCursor <= $dateEnd) {
        $attendanceDates[] = $dateCursor->format('Y-m-d');
        $dateCursor = $dateCursor->modify('+1 day');
    }
} else {
    $attendanceDateStmt = $pdo->prepare("SELECT DISTINCT ar.attendance_date FROM attendance_records ar JOIN beneficiaries b ON b.beneficiary_id = ar.beneficiary_id $attendanceDateSql ORDER BY ar.attendance_date");
    $attendanceDateStmt->execute($attendanceDateParams);
    $attendanceDates = $attendanceDateStmt->fetchAll(PDO::FETCH_COLUMN);
}

$attendanceBeneficiaryStmt = $pdo->prepare("SELECT b.beneficiary_id, b.beneficiary_code, b.full_name FROM beneficiaries b $attendanceScopeSql ORDER BY b.full_name");
$attendanceBeneficiaryStmt->execute($attendanceScopeParams);
$attendanceBeneficiaries = $attendanceBeneficiaryStmt->fetchAll();

$attendanceMatrix = [];
$attendanceRowsWhere = $attendanceScope;
$attendanceRowsParams = $attendanceScopeParams;
if ($from !== '') { $attendanceRowsWhere[] = 'ar.attendance_date>=?'; $attendanceRowsParams[] = $from; }
if ($to !== '') { $attendanceRowsWhere[] = 'ar.attendance_date<=?'; $attendanceRowsParams[] = $to; }
if ($month !== '' && $year === '') { $attendanceRowsWhere[] = 'MONTH(ar.attendance_date)=?'; $attendanceRowsParams[] = (int)$month; }
$attendanceRowsSql = $attendanceRowsWhere ? 'WHERE ' . implode(' AND ', $attendanceRowsWhere) : '';
$attendanceRowsStmt = $pdo->prepare("SELECT ar.beneficiary_id, ar.attendance_date, ar.attendance_status FROM attendance_records ar JOIN beneficiaries b ON b.beneficiary_id = ar.beneficiary_id $attendanceRowsSql");
$attendanceRowsStmt->execute($attendanceRowsParams);
foreach ($attendanceRowsStmt->fetchAll() as $attendanceRow) {
    $attendanceMatrix[(int)$attendanceRow['beneficiary_id']][$attendanceRow['attendance_date']] = $attendanceRow['attendance_status'];
}

$attTotal = array_sum($attCounts);
$attColors = ['PRESENT' => '#1a9c53', 'ABSENT' => '#e53e3e'];
$attLabels = ['PRESENT' => 'Present', 'ABSENT' => 'Absent'];
$attGradientParts = [];
$attCursor = 0;
foreach ($attCounts as $key => $count) {
    if ($attTotal === 0) break;
    $pct = $count / $attTotal * 100;
    $attGradientParts[] = $attColors[$key] . ' ' . round($attCursor, 2) . '% ' . round($attCursor + $pct, 2) . '%';
    $attCursor += $pct;
}
$attGradient = $attGradientParts ? implode(', ', $attGradientParts) : '#eef1f6 0% 100%';

// Age-bracket distribution for the pie chart, respecting the same site/
// program/status scope as the tables above but independent of whether a
// health record exists yet.
$ageWhere = [];
$ageParams = [];
if ($siteId > 0) { $ageWhere[] = 'site_id=?'; $ageParams[] = $siteId; }
if ($programId > 0) { $ageWhere[] = 'program_id=?'; $ageParams[] = $programId; }
if ($status !== '') { $ageWhere[] = 'program_status=?'; $ageParams[] = $status; }
$ageWhereSql = $ageWhere ? 'WHERE ' . implode(' AND ', $ageWhere) : '';
$ageStmt = $pdo->prepare("SELECT birth_date FROM beneficiaries $ageWhereSql");
$ageStmt->execute($ageParams);
$ageBuckets = ['Under 6' => 0, '6–12' => 0, '13+' => 0];
foreach ($ageStmt->fetchAll(PDO::FETCH_COLUMN) as $bd) {
    $age = age_in_years($bd);
    if ($age < 6) $ageBuckets['Under 6']++;
    elseif ($age <= 12) $ageBuckets['6–12']++;
    else $ageBuckets['13+']++;
}
$ageTotal = array_sum($ageBuckets);
$ageColors = ['Under 6' => '#7c3aed', '6–12' => '#cf9319', '13+' => '#14b8a6'];
$gradientParts = [];
$cursor = 0;
foreach ($ageBuckets as $label => $count) {
    if ($ageTotal === 0) break;
    $pct = $count / $ageTotal * 100;
    $gradientParts[] = $ageColors[$label] . ' ' . round($cursor, 2) . '% ' . round($cursor + $pct, 2) . '%';
    $cursor += $pct;
}
$pieGradient = $gradientParts ? implode(', ', $gradientParts) : '#eef1f6 0% 100%';

// Gender (sex) distribution of beneficiaries, scoped by the same site/
// program/status filters as the age chart and likewise independent of
// whether a health record exists yet. Reads beneficiaries.sex (Male/Female).
$sexStmt = $pdo->prepare("SELECT sex, COUNT(*) c FROM beneficiaries $ageWhereSql GROUP BY sex");
$sexStmt->execute($ageParams);
$sexCounts = ['Male' => 0, 'Female' => 0];
foreach ($sexStmt->fetchAll() as $row) {
    if (isset($sexCounts[$row['sex']])) $sexCounts[$row['sex']] = (int)$row['c'];
}
$sexTotal = array_sum($sexCounts);
$sexColors = ['Male' => '#2f6fdb', 'Female' => '#b4457a'];
$sexGradientParts = [];
$sexCursor = 0;
foreach ($sexCounts as $label => $count) {
    if ($sexTotal === 0) break;
    $pct = $count / $sexTotal * 100;
    $sexGradientParts[] = $sexColors[$label] . ' ' . round($sexCursor, 2) . '% ' . round($sexCursor + $pct, 2) . '%';
    $sexCursor += $pct;
}
$sexGradient = $sexGradientParts ? implode(', ', $sexGradientParts) : '#eef1f6 0% 100%';

// BMI-category breakdown per growth milestone (Baseline / Midline / Endline).
// Categories are whatever nutritional_status text was recorded at each visit —
// set automatically from BMI with the Asian cut-offs in bmi_status.php.
$milestoneLabels = ['BASELINE' => 'Baseline', 'MIDLINE' => 'Midline', 'ENDLINE' => 'Endline'];
$categoryPalette = ['#e8b93f', '#e2665a', '#1fa896', '#7c3aed', '#64748b', '#2f6fdb', '#b4457a'];
$milestoneBuckets = ['BASELINE' => [], 'MIDLINE' => [], 'ENDLINE' => []];
foreach ($rows as $r) {
    $m = strtoupper($r['milestone'] ?? '');
    if (!isset($milestoneBuckets[$m])) continue;
    $cat = $r['nutritional_status'] === 'Pending child reference' ? 'Not yet classified' : $r['nutritional_status'];
    $milestoneBuckets[$m][$cat] = ($milestoneBuckets[$m][$cat] ?? 0) + 1;
}
$milestoneCharts = [];
foreach ($milestoneBuckets as $key => $counts) {
    arsort($counts);
    $total = array_sum($counts);
    $colorMap = [];
    $i = 0;
    foreach ($counts as $cat => $n) {
        $colorMap[$cat] = $categoryPalette[$i % count($categoryPalette)];
        $i++;
    }
    $cursor = 0;
    $parts = [];
    foreach ($counts as $cat => $n) {
        if ($total === 0) break;
        $pct = $n / $total * 100;
        $parts[] = $colorMap[$cat] . ' ' . round($cursor, 2) . '% ' . round($cursor + $pct, 2) . '%';
        $cursor += $pct;
    }
    $milestoneCharts[$key] = [
        'label' => $milestoneLabels[$key],
        'counts' => $counts,
        'colors' => $colorMap,
        'total' => $total,
        'gradient' => $parts ? implode(', ', $parts) : '#eef1f6 0% 100%',
    ];
}

// Printable formal report (long bond paper) — same filtered data, own template.
if ($generated && ($_GET['print'] ?? '') === '1') {
    require __DIR__ . '/report_print.php';
    exit;
}

$pageTitle = 'Reports & Analysis';
$printUrl = 'reports.php?' . http_build_query(array_merge($_GET, ['generated' => '1', 'print' => '1']));
$pageActionsHtml = $generated
    ? '<a class="btn secondary attendance-change-filters" href="reports.php?' . e(http_build_query(array_diff_key($_GET, ['generated' => '', 'age' => '', 'print' => '']))) . '">Change Filters</a>'
    : null;
$activeNav = 'reports.php';
require __DIR__ . '/partials/header.php';
?>

<?php if (!$generated): ?>
<section class="panel filters-panel reports-filter-panel">
    <span class="filter-label">Filter</span>
    <form class="scope-row" method="get" data-required-filters data-required-labels>
        <input type="hidden" name="generated" value="1">
        <div class="field filter-field <?= $siteSelected && $siteId > 0 ? 'is-active' : '' ?>">
            <label for="report_site">Feeding Site *</label>
            <select id="report_site" name="site_id" data-filter-required>
                <option value="" <?= !$siteSelected ? 'selected' : '' ?>>Select feeding site</option>
                <option value="0" <?= $siteSelected && $siteId === 0 ? 'selected' : '' ?>>All sites</option>
                <?php foreach ($sites as $s): ?>
                    <option value="<?= $s['site_id'] ?>" <?= $siteId === (int)$s['site_id'] ? 'selected' : '' ?>><?= e($s['site_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field filter-field <?= $month !== '' ? 'is-active' : '' ?>">
            <label for="report_month">Month *</label>
            <select id="report_month" name="month" data-filter-required>
                <option value="" <?= !$monthSelected ? 'selected' : '' ?>>Select month</option>
                <option value="all" <?= $monthChoice === 'all' ? 'selected' : '' ?>>All months</option>
                <?php for ($monthOption = 1; $monthOption <= 12; $monthOption++): ?>
                    <option value="<?= $monthOption ?>" <?= (int)$month === $monthOption ? 'selected' : '' ?>><?= e(date('F', mktime(0, 0, 0, $monthOption, 1))) ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="field filter-field <?= $year !== '' ? 'is-active' : '' ?>">
            <label for="report_year">Year *</label>
            <select id="report_year" name="year" data-filter-required>
                <option value="" <?= !$yearSelected ? 'selected' : '' ?>>Select year</option>
                <option value="all" <?= $yearChoice === 'all' ? 'selected' : '' ?>>All years</option>
                <?php foreach ($reportYears as $yearOption): ?>
                    <option value="<?= $yearOption ?>" <?= (int)$year === $yearOption ? 'selected' : '' ?>><?= $yearOption ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn primary" type="submit"<?= $reportFiltersComplete ? '' : ' disabled' ?>>Generate</button>
    </form>
    <?php if ($activeFilters): ?>
    <div class="active-filters">
        <span class="label">Active filters:</span>
        <?php foreach ($activeFilters as $af): ?>
            <span class="filter-chip"><?= e($af['label']) ?> <a href="reports.php?<?= e(http_build_query($af['clear'])) ?>" title="Remove this filter">&times;</a></span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>
<?php else: ?>

<section class="panel report-shell" id="report">
<?php if ($activeFilters): ?>
<div class="report-shell-head">
    <div class="active-filters">
        <span class="label">Filters:</span>
        <?php foreach ($activeFilters as $af): ?><span class="filter-chip"><?= e($af['label']) ?></span><?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<section class="panel bp-card" id="records">
    <div class="panel-head report-analysis-head">
        <h2>Health Monitoring Records</h2>
    </div>
    <div class="table-wrap reports-health-records-wrap<?= $healthTableRowCount >= 10 ? ' is-scrollable' : '' ?>">
        <table class="milestone-table">
            <thead>
                <tr>
                    <th rowspan="2">ID</th><th rowspan="2">Name</th>
                    <?php foreach (MILESTONES as $m): ?>
                    <th colspan="3" class="ms-start ms-group"><?= e(milestone_label($m)) ?></th>
                    <?php endforeach; ?>
                    <th rowspan="2" class="ms-start">Birth Date</th><th rowspan="2">Age</th><th rowspan="2">Sex</th><th rowspan="2">Program Status</th>
                </tr>
                <tr>
                    <?php foreach (MILESTONES as $m): ?>
                    <th class="ms-start">Date</th><th>Height / Weight</th><th>BMI / Status</th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recordGroups as $g): $info = $g['info']; $span = 1 + count($g['other']); ?>
                <tr class="group-start">
                    <td rowspan="<?= $span ?>"><?= $info['beneficiary_code'] ? '<span class="code-tag">' . e($info['beneficiary_code']) . '</span>' : '<span class="muted">—</span>' ?></td>
                    <td rowspan="<?= $span ?>"><strong><a href="beneficiary.php?id=<?= (int)$info['beneficiary_id'] ?>"><?= e($info['full_name']) ?></a></strong></td>
                    <?php foreach (MILESTONES as $m): ?><?= $renderMilestoneCells($g['by'][$m]) ?><?php endforeach; ?>
                    <td rowspan="<?= $span ?>" class="single-line-cell"><?= e($fmtDate($info['birth_date'])) ?></td>
                    <td rowspan="<?= $span ?>" class="ms-start single-line-cell"><?= e(age_label($info['birth_date'])) ?></td>
                    <td rowspan="<?= $span ?>"><?= e($info['sex']) ?></td>
                    <td rowspan="<?= $span ?>"><?= pcd_badge($info['program_status'] ?? 'ACTIVE') ?><?php if (($info['program_status'] ?? '') === 'INACTIVE' && !empty($info['inactive_since'])): ?><br><small class="muted">since <?= e($fmtDate($info['inactive_since'])) ?></small><?php endif; ?></td>
                </tr>
                <?php foreach ($g['other'] as $r): ?>
                <tr class="unlabeled-row">
                    <td colspan="9" class="muted">Record without a milestone &middot; <?= e($fmtDate($r['record_date'])) ?> &middot; <?= e($fmtNum($r['height_cm'])) ?> cm &middot; <?= e($fmtNum($r['weight_kg'])) ?> kg &middot; BMI <?= e(number_format((float)$r['bmi'], 2, '.', '')) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$rows): ?>
            <p class="empty">No health data to report yet.</p>
        <?php endif; ?>
    </div>
</section>

<section class="panel bp-card report-analysis-card" id="bmi-charts">
    <div class="panel-head report-analysis-head">
        <h2>Report Analysis</h2>
    </div>
    <div class="grid-3">
        <?php foreach ($milestoneCharts as $chart): ?>
        <div class="milestone-chart">
            <h3><?= e($chart['label']) ?> BMI Categories</h3>
            <div class="pie-chart sm" role="img" aria-label="<?= e($chart['label'] . ' BMI categories: ' . implode(', ', array_map(fn($c, $n) => $c . ' ' . $n, array_keys($chart['counts']), $chart['counts']))) ?>" style="background: conic-gradient(<?= $chart['gradient'] ?>);"></div>
            <div class="pie-legend">
                <?php foreach ($chart['counts'] as $cat => $n): ?>
                <div class="pie-legend-item"><span class="swatch" style="background:<?= $chart['colors'][$cat] ?>;"></span><?= e($cat) ?> <strong><?= $n ?></strong></div>
                <?php endforeach; ?>
                <?php if ($chart['total'] === 0): ?><p class="muted" style="margin:2px 0 0;">No records yet.</p><?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

<div class="chart-pair chart-trio">
<section class="panel bp-card report-summary-chart" id="attendance">
    <div class="panel-head">
        <h2>Attendance Participation</h2>
    </div>
    <div class="pie-wrap">
        <div class="pie-chart sm" role="img" aria-label="<?= e('Attendance: ' . implode(', ', array_map(fn($k, $n) => $attLabels[$k] . ' ' . $n, array_keys($attCounts), $attCounts))) ?>" style="background: conic-gradient(<?= $attGradient ?>);"></div>
        <div class="pie-legend">
            <?php foreach ($attCounts as $key => $count): ?>
            <div class="pie-legend-item"><span class="swatch" style="background:<?= $attColors[$key] ?>;"></span><?= e($attLabels[$key]) ?> <strong><?= $count ?></strong></div>
            <?php endforeach; ?>
            <?php if ($attTotal === 0): ?><p class="muted" style="margin:4px 0 0;">No attendance records match this scope.</p><?php endif; ?>
        </div>
    </div>
</section>

<section class="panel bp-card report-summary-chart" id="age">
    <div class="panel-head">
        <h2>Age Distribution</h2>
    </div>
    <div class="pie-wrap">
        <div class="pie-chart sm" role="img" aria-label="<?= e('Age distribution: ' . implode(', ', array_map(fn($l, $n) => $l . ' years ' . $n, array_keys($ageBuckets), $ageBuckets))) ?>" style="background: conic-gradient(<?= $pieGradient ?>);"></div>
        <div class="pie-legend">
            <?php foreach ($ageBuckets as $label => $count): ?>
            <div class="pie-legend-item"><span class="swatch" style="background:<?= $ageColors[$label] ?>;"></span><?= e($label) ?> years <strong><?= $count ?></strong></div>
            <?php endforeach; ?>
            <?php if ($ageTotal === 0): ?><p class="muted" style="margin:4px 0 0;">No beneficiaries match this scope.</p><?php endif; ?>
        </div>
    </div>
</section>

<section class="panel bp-card report-summary-chart" id="gender">
    <div class="panel-head">
        <h2>Gender Distribution</h2>
    </div>
    <div class="pie-wrap">
        <div class="pie-chart sm" role="img" aria-label="<?= e('Gender distribution: ' . implode(', ', array_map(fn($l, $n) => $l . ' ' . $n, array_keys($sexCounts), $sexCounts))) ?>" style="background: conic-gradient(<?= $sexGradient ?>);"></div>
        <div class="pie-legend">
            <?php foreach ($sexCounts as $label => $count): ?>
            <div class="pie-legend-item"><span class="swatch" style="background:<?= $sexColors[$label] ?>;"></span><?= e($label) ?> <strong><?= $count ?></strong></div>
            <?php endforeach; ?>
            <?php if ($sexTotal === 0): ?><p class="muted" style="margin:4px 0 0;">No beneficiaries match this scope.</p><?php endif; ?>
        </div>
    </div>
</section>
</div>
</section><!-- /#bmi-charts (Report Analysis) -->

<section class="panel bp-card report-attendance-register" id="attendance-register">
    <div class="panel-head report-analysis-head">
        <h2>Attendance</h2>
    </div>
    <?php if (!$attendanceDates): ?>
        <p class="empty">No attendance days match the selected filters.</p>
    <?php elseif (!$attendanceBeneficiaries): ?>
        <p class="empty">No beneficiaries match the selected filters.</p>
    <?php else: ?>
    <div class="table-wrap reports-attendance-wrap">
        <table class="reports-attendance-table">
            <colgroup>
                <col class="attendance-id-column">
                <col class="attendance-beneficiary-column">
                <?php foreach ($attendanceDates as $_): ?><col class="attendance-day-col"><?php endforeach; ?>
            </colgroup>
            <thead>
                <tr>
                    <th rowspan="2">ID</th>
                    <th rowspan="2">Beneficiary</th>
                    <th colspan="<?= count($attendanceDates) ?>" class="attendance-days-heading">Implementation Date (<?= e($implementationPeriod) ?>)</th>
                </tr>
                <tr>
                    <?php foreach ($attendanceDates as $attendanceDate): ?>
                    <th class="attendance-day-column" title="<?= e($fmtDate($attendanceDate)) ?>">
                        <?= (int)date('j', strtotime($attendanceDate)) ?>
                    </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($attendanceBeneficiaries as $attendanceBeneficiary): ?>
                <tr>
                    <td><?= $attendanceBeneficiary['beneficiary_code'] ? '<span class="code-tag">' . e($attendanceBeneficiary['beneficiary_code']) . '</span>' : '<span class="muted">—</span>' ?></td>
                    <td><a href="beneficiary.php?id=<?= (int)$attendanceBeneficiary['beneficiary_id'] ?>"><?= e($attendanceBeneficiary['full_name']) ?></a></td>
                    <?php foreach ($attendanceDates as $attendanceDate): ?>
                        <?php $dayStatus = $attendanceMatrix[(int)$attendanceBeneficiary['beneficiary_id']][$attendanceDate] ?? ''; ?>
                        <td class="attendance-day-cell">
                            <?php if ($dayStatus === 'PRESENT'): ?>
                                <span class="attendance-mark present" aria-label="Present" title="Present">&#10003;</span>
                            <?php elseif ($dayStatus === 'ABSENT'): ?>
                                <span class="attendance-mark absent" aria-label="Absent" title="Absent">&times;</span>
                            <?php else: ?>
                                <span class="muted" aria-label="Not marked">—</span>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<div class="report-shell-foot">
    <a class="btn primary" target="_blank" rel="noopener" href="<?= e($printUrl) ?>"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v11M7 10.5l5 5 5-5M5 20h14"/></svg><span>Download Report</span></a>
</div>

</section><!-- /.report-shell -->

<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>