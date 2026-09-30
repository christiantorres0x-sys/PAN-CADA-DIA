<?php
require_once __DIR__ . '/db.php';
require_login();

$q = trim($_GET['q'] ?? '');
$siteId = (int)($_GET['site_id'] ?? 0);

$sites = $pdo->query("SELECT * FROM feeding_sites WHERE status='ACTIVE' ORDER BY site_name")->fetchAll();

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(b.full_name LIKE ? OR b.grade_level LIKE ?)';
    $like = "%$q%";
    $params[] = $like;
    $params[] = $like;
}
if ($siteId > 0) {
    $where[] = 'b.site_id = ?';
    $params[] = $siteId;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$activeFilters = [];
if ($siteId > 0) {
    $siteName = '';
    foreach ($sites as $s) { if ((int)$s['site_id'] === $siteId) { $siteName = $s['site_name']; break; } }
    $activeFilters[] = ['label' => 'Site: ' . $siteName, 'clear' => array_diff_key($_GET, ['site_id' => ''])];
}
if ($q !== '') {
    $activeFilters[] = ['label' => 'Search: ' . $q, 'clear' => array_diff_key($_GET, ['q' => ''])];
}

$stmt = $pdo->prepare("
    SELECT b.*, s.site_name, p.program_name
    FROM beneficiaries b
    LEFT JOIN feeding_sites s ON s.site_id=b.site_id
    LEFT JOIN feeding_programs p ON p.program_id=b.program_id
    $whereSql
    ORDER BY b.full_name
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Latest health record per beneficiary, fetched once rather than N+1 per row.
$latestByBeneficiary = [];
$hrStmt = $pdo->query("SELECT * FROM health_records ORDER BY record_date DESC, record_id DESC");
foreach ($hrStmt->fetchAll() as $row) {
    $bid = $row['beneficiary_id'];
    if (!isset($latestByBeneficiary[$bid])) {
        $latestByBeneficiary[$bid] = $row;
    }
}

$pageTitle = 'Beneficiaries';
$pageSubtitle = "Click on each row to open a beneficiary's profile and record measurements.";
$pageActionsHtml = '<a class="btn primary" href="beneficiary_form.php" data-slideover="beneficiary_form.php?panel=1" data-slideover-title="Add Beneficiary"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg><span>Add Beneficiary</span></a>';
$activeNav = 'beneficiaries.php';
require __DIR__ . '/partials/header.php';
?>
<section class="panel filters-panel">
    <form class="scope-row" method="get" data-autosubmit>
        <div class="field">
            <label for="q">Search</label>
            <input id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="Name or grade level">
        </div>
        <div class="field filter-field <?= $siteId > 0 ? 'is-active' : '' ?>">
            <label for="site_id">Feeding Site</label>
            <select id="site_id" name="site_id">
                <option value="0">All feeding sites</option>
                <?php foreach ($sites as $s): ?>
                    <option value="<?= $s['site_id'] ?>" <?= $siteId === (int)$s['site_id'] ? 'selected' : '' ?>><?= e($s['site_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn primary">Search</button>
        <?php if ($activeFilters): ?><a class="btn secondary" href="beneficiaries.php">Reset</a><?php endif; ?>
    </form>
    <?php if ($activeFilters): ?>
    <div class="active-filters">
        <span class="label">Active filters:</span>
        <?php foreach ($activeFilters as $af): ?>
            <span class="filter-chip"><?= e($af['label']) ?> <a href="beneficiaries.php?<?= e(http_build_query($af['clear'])) ?>" title="Remove this filter" aria-label="Remove filter: <?= e($af['label']) ?>">&times;</a></span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<section class="panel">
    <p class="list-count">Total Beneficiaries: <?= count($rows) ?></p>
    <p class="hint-line">A red marker on the left means the latest weight status is underweight or severely underweight.</p>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>ID</th><th>Beneficiary</th><th>Enroll Date</th><th>Age</th><th>Sex</th><th class="num">Height</th><th class="num">Weight</th><th class="num">BMI</th><th>Weight Status</th><th>Program Status</th></tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $b): $rec = $latestByBeneficiary[$b['beneficiary_id']] ?? null; $flag = $rec && $rec['nutritional_status'] && (str_contains(strtolower($rec['nutritional_status']), 'under') || str_contains(strtolower($rec['nutritional_status']), 'severe')); ?>
                <tr class="clickable-row <?= $flag ? 'row-flag' : '' ?>" data-href="beneficiary.php?id=<?= (int)$b['beneficiary_id'] ?>" onclick="window.location=this.dataset.href">
                    <td><?= $b['beneficiary_code'] ? '<span class="code-tag">' . e($b['beneficiary_code']) . '</span>' : '<span class="muted">—</span>' ?></td>
                    <td><strong><a href="beneficiary.php?id=<?= (int)$b['beneficiary_id'] ?>"><?= e($b['full_name']) ?></a></strong></td>
                    <td><?= e($b['date_enlisted'] ?: '—') ?></td>
                    <td><?= age_in_years($b['birth_date']) ?></td>
                    <td><?= e($b['sex']) ?></td>
                    <td class="num"><?= $rec ? e($rec['height_cm']) . ' cm' : '—' ?></td>
                    <td class="num"><?= $rec ? e($rec['weight_kg']) . ' kg' : '—' ?></td>
                    <td class="num"><?= $rec ? e($rec['bmi']) . ' kg/m<sup>2</sup>' : '—' ?></td>
                    <td><?= ($rec && $rec['nutritional_status']) ? pcd_badge($rec['nutritional_status']) : '<span class="muted">Pending</span>' ?></td>
                    <td><?= pcd_badge($b['program_status']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$rows): ?>
            <p class="empty">No beneficiaries found.</p>
        <?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
