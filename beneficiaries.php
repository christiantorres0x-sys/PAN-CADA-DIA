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
$pageSubtitle = 'Click on each row to edit beneficiary profile and/or record milestones.';
$pageEyebrowHtml = '<a class="back-link" href="dashboard.php"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>Back to Dashboard</a>';
$pageActionsHtml = '<a class="btn primary" href="beneficiary_form.php" data-slideover="beneficiary_form.php?panel=1" data-slideover-title="Add Beneficiary"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg><span>Add Beneficiary</span></a>';
$activeNav = 'beneficiaries.php';
require __DIR__ . '/partials/header.php';
?>
<div class="beneficiaries-page">
<section class="panel filters-panel beneficiaries-scope">
    <span class="filter-label">Scope</span>
    <form class="scope-row" method="get" data-autosubmit>
        <input type="hidden" name="q" value="<?= e($q) ?>">
        <div class="field filter-field <?= $siteId > 0 ? 'is-active' : '' ?>">
            <label for="site_id">Feeding Site</label>
            <select id="site_id" name="site_id">
                <option value="0">All feeding sites</option>
                <?php foreach ($sites as $s): ?>
                    <option value="<?= $s['site_id'] ?>" <?= $siteId === (int)$s['site_id'] ? 'selected' : '' ?>><?= e($s['site_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn primary" type="submit">Search</button>
    </form>
</section>

<form class="scope-row beneficiary-search-row" method="get">
    <input type="hidden" name="site_id" value="<?= $siteId ?>">
    <div class="field">
        <label class="sr-only" for="beneficiary-search">Search beneficiaries by name or grade level</label>
        <div class="search">
            <input id="beneficiary-search" type="search" name="q" value="<?= e($q) ?>" placeholder="Enter Beneficiary's Name">
            <button class="search-submit" type="submit" aria-label="Search beneficiaries" title="Search beneficiaries">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
            </button>
        </div>
    </div>
</form>

<section class="panel beneficiaries-results">
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Grade Level</th><th>Birth Date</th><th>Name</th><th>Sex</th><th class="num">Height</th><th class="num">Weight</th><th class="num">BMI</th><th>Weight Status</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $b): $rec = $latestByBeneficiary[$b['beneficiary_id']] ?? null; $nutritionStatus = strtolower($rec['nutritional_status'] ?? ''); $flag = str_contains($nutritionStatus, 'under') || str_contains($nutritionStatus, 'severe'); $nutritionAlert = $flag || str_contains($nutritionStatus, 'over'); ?>
                <tr class="clickable-row <?= $flag ? 'row-flag' : '' ?>" data-href="beneficiary.php?id=<?= (int)$b['beneficiary_id'] ?>" onclick="window.location=this.dataset.href">
                    <td><?= e($b['grade_level'] ?: '—') ?></td>
                    <td><?= e($b['birth_date'] ?: '—') ?></td>
                    <td><strong><a href="beneficiary.php?id=<?= (int)$b['beneficiary_id'] ?>"><?= e($b['full_name']) ?></a></strong></td>
                    <td><?= e($b['sex']) ?></td>
                    <td class="num"><?= $rec ? e($rec['height_cm']) . ' cm' : '—' ?></td>
                    <td class="num"><?= $rec ? e($rec['weight_kg']) . ' kg' : '—' ?></td>
                    <td class="num"><?= $rec ? e($rec['bmi']) . ' kg/m<sup>2</sup>' : '—' ?></td>
                    <td><span class="<?= $nutritionAlert ? 'beneficiary-nutrition-alert' : '' ?>"><?= ($rec && $rec['nutritional_status']) ? e($rec['nutritional_status']) : '<span class="muted">Pending</span>' ?></span></td>
                    <td><?= e($b['program_status'] ?: '—') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$rows): ?>
            <p class="empty">No beneficiaries found.</p>
        <?php endif; ?>
    </div>
    <p class="list-count">Total Beneficiaries: <?= count($rows) ?></p>
</section>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
