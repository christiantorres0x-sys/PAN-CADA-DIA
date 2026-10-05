<?php
require_once __DIR__ . '/db.php';
require_login();

$siteId = (int)($_GET['site_id'] ?? 0);
$programId = (int)($_GET['program_id'] ?? 0);
$status = trim($_GET['status'] ?? '');
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');

$sites = $pdo->query("SELECT * FROM feeding_sites ORDER BY site_name")->fetchAll();
$programs = $pdo->query("SELECT * FROM feeding_programs ORDER BY program_name")->fetchAll();

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
    SELECT hr.record_id, hr.record_date, hr.milestone, b.beneficiary_id, b.full_name, b.beneficiary_code, b.birth_date, b.sex, b.grade_level,
           b.program_status, s.site_name, p.program_name, hr.height_cm, hr.weight_kg, hr.bmi,
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

// Chips shown above the tables summarizing which filters are actually
// narrowing the results (and a quick link to drop just that one).
$activeFilters = [];
if ($siteId > 0) {
    $siteName = '';
    foreach ($sites as $s) { if ((int)$s['site_id'] === $siteId) { $siteName = $s['site_name']; break; } }
    $activeFilters[] = ['label' => 'Site: ' . $siteName, 'clear' => array_diff_key($_GET, ['site_id' => ''])];
}
if ($programId > 0) {
    $programName = '';
    foreach ($programs as $p) { if ((int)$p['program_id'] === $programId) { $programName = $p['program_name']; break; } }
    $activeFilters[] = ['label' => 'Program: ' . $programName, 'clear' => array_diff_key($_GET, ['program_id' => ''])];
}
if ($status !== '') {
    $activeFilters[] = ['label' => 'Status: ' . $status, 'clear' => array_diff_key($_GET, ['status' => ''])];
}
if ($from !== '') {
    $activeFilters[] = ['label' => 'From: ' . $from, 'clear' => array_diff_key($_GET, ['from' => ''])];
}
if ($to !== '') {
    $activeFilters[] = ['label' => 'To: ' . $to, 'clear' => array_diff_key($_GET, ['to' => ''])];
}

// Attendance participation, scoped by the same site/program/status/date
// filters as the tables above (using attendance_date for the date range
// instead of a health record date). Uses real attendance_records data —
// no chart is drawn if there's nothing recorded yet.
$attWhere = [];
$attParams = [];
if ($siteId > 0) { $attWhere[] = 'b.site_id=?'; $attParams[] = $siteId; }
if ($programId > 0) { $attWhere[] = 'b.program_id=?'; $attParams[] = $programId; }
if ($status !== '') { $attWhere[] = 'b.program_status=?'; $attParams[] = $status; }
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

$ageFilter = trim($_GET['age'] ?? '');

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
    if ($ageFilter !== '' && (string)$age !== $ageFilter) continue;
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

// BMI-category breakdown per growth milestone (Baseline / Midline / Endline).
// Categories are whatever nutritional_status text was recorded at each visit —
// this app does not invent its own BMI cutoffs (see the safeguard notice below).
$milestoneLabels = ['BASELINE' => 'Baseline', 'MIDLINE' => 'Midline', 'ENDLINE' => 'Endline'];
$categoryPalette = ['#e8b93f', '#e2665a', '#1fa896', '#7c3aed', '#64748b'];
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

$pageTitle = 'Reports & Analysis';
$activeNav = 'reports.php';
require __DIR__ . '/partials/header.php';
?>

<section class="panel">
    <span class="filter-label">Filter</span>
    <form class="filter-grid" method="get" data-autosubmit>
        <div class="filter-field <?= $siteId > 0 ? 'is-active' : '' ?>">
            <label>Feeding Site</label>
            <select name="site_id">
                <option value="0">All sites</option>
                <?php foreach ($sites as $s): ?>
                    <option value="<?= $s['site_id'] ?>" <?= $siteId === $s['site_id'] ? 'selected' : '' ?>><?= e($s['site_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-actions">
            <button class="btn primary">Generate</button>
        </div>
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

<section class="panel" id="site-analysis">

    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Feeding Site</th><th class="num">Beneficiaries</th><th class="num">Health Records</th><th class="num">Average BMI</th></tr>
            </thead>
            <tbody>
                <?php foreach ($siteSummary as $site => $r): ?>
                
                <?php endforeach; ?>
                <?php if (!$siteSummary): ?>
                <tr><td colspan="4" class="empty">No records match the selected filters.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel" id="records">
    <h2>Health Monitoring Records</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>ID</th><th>Name</th><th>Grade Level</th><th>Birth Date</th><th>Sex</th><th class="num">Height</th><th class="num">Weight</th><th class="num">BMI</th><th>Weight Status</th><th>Program Status</th></tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): $flag = str_contains(strtolower($r['nutritional_status']), 'under') || str_contains(strtolower($r['nutritional_status']), 'severe'); ?>
                <tr class="<?= $flag ? 'row-flag' : '' ?>">
                    <td><?= $r['beneficiary_code'] ? '<span class="code-tag">' . e($r['beneficiary_code']) . '</span>' : '<span class="muted">—</span>' ?></td>
                    <td><strong><a href="beneficiary.php?id=<?= $r['beneficiary_id'] ?>"><?= e($r['full_name']) ?></a></strong></td>
                    <td><?= e($r['grade_level'] ?: '—') ?></td>
                    <td><?= e($r['birth_date']) ?></td>
                    <td><?= e($r['sex']) ?></td>
                    <td class="num"><?= e($r['height_cm']) ?> cm</td>
                    <td class="num"><?= e($r['weight_kg']) ?> kg</td>
                    <td class="num"><strong><?= e($r['bmi']) ?></strong> kg/m<sup>2</sup></td>
                    <td><?= $r['nutritional_status'] !== 'Pending child reference' ? pcd_badge($r['nutritional_status']) : '<span class="muted">Pending</span>' ?></td>
                    <td><?= pcd_badge($r['program_status'] ?? 'ENROLLED') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$rows): ?>
            <p class="empty">No health data to report yet.</p>
        <?php endif; ?>
    </div>
</section>

<section class="panel" id="bmi-charts">
    <div class="panel-head">
        <h2>Report Analysis</h2>
        <span class="filter-label" style="margin:0;">BMI categories by growth milestone</span>
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
</section>

<section class="panel" id="attendance">
    <div class="panel-head">
        <h2>Attendance Participation</h2>
        <span class="filter-label" style="margin:0;">Same filters as above</span>
    </div>
    <div class="pie-wrap">
        <div class="pie-chart" role="img" aria-label="<?= e('Attendance: ' . implode(', ', array_map(fn($k, $n) => $attLabels[$k] . ' ' . $n, array_keys($attCounts), $attCounts))) ?>" style="background: conic-gradient(<?= $attGradient ?>);"></div>
        <div class="pie-legend">
            <?php foreach ($attCounts as $key => $count): ?>
            <div class="pie-legend-item"><span class="swatch" style="background:<?= $attColors[$key] ?>;"></span><?= e($attLabels[$key]) ?> <strong><?= $count ?></strong></div>
            <?php endforeach; ?>
            <?php if ($attTotal === 0): ?><p class="muted" style="margin:4px 0 0;">No attendance records match this scope.</p><?php endif; ?>
        </div>
    </div>
</section>

<section class="panel" id="age">
    <div class="panel-head">
        <h2>Age Distribution</h2>
    </div>
    <div style="max-width:220px; margin-bottom:10px;">
        <label for="age_filter">Filter by Age</label>
        <form method="get">
            <?php foreach (['site_id' => $siteId ?: '', 'program_id' => $programId ?: '', 'status' => $status, 'from' => $from, 'to' => $to] as $k => $v): if ($v !== '' && $v !== 0): ?>
                <input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>">
            <?php endif; endforeach; ?>
            <input id="age_filter" type="number" name="age" min="0" value="<?= e($ageFilter) ?>" placeholder="e.g. 7" onchange="this.form.submit()">
        </form>
    </div>
    <div class="pie-wrap">
        <div class="pie-chart" role="img" aria-label="<?= e('Age distribution: ' . implode(', ', array_map(fn($l, $n) => $l . ' years ' . $n, array_keys($ageBuckets), $ageBuckets))) ?>" style="background: conic-gradient(<?= $pieGradient ?>);"></div>
        <div class="pie-legend">
            <?php foreach ($ageBuckets as $label => $count): ?>
            <div class="pie-legend-item"><span class="swatch" style="background:<?= $ageColors[$label] ?>;"></span><?= e($label) ?> years <strong><?= $count ?></strong></div>
            <?php endforeach; ?>
            <?php if ($ageTotal === 0): ?><p class="muted" style="margin:4px 0 0;">No beneficiaries match this scope.</p><?php endif; ?>
        </div>
    </div>
</section>

<section class="notice">
    <strong>About weight status:</strong> For children, weight status comes from an age- and sex-specific growth reference, not adult BMI ranges. Records without one show as Pending or Not yet classified.
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
