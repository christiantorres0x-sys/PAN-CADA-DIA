<?php
require_once __DIR__ . '/db.php';
require_login();

$total = (int)$pdo->query("SELECT COUNT(*) FROM beneficiaries")->fetchColumn();
$enrolled = (int)$pdo->query("SELECT COUNT(*) FROM beneficiaries WHERE program_status='ENROLLED'")->fetchColumn();
$sites = (int)$pdo->query("SELECT COUNT(*) FROM feeding_sites WHERE status='ACTIVE'")->fetchColumn();
$records = (int)$pdo->query("SELECT COUNT(*) FROM health_records")->fetchColumn();
$baselineDone = (int)$pdo->query("SELECT COUNT(*) FROM health_records WHERE milestone='BASELINE'")->fetchColumn();
$pendingEnrollment = max(0, $total - $enrolled);

$latest = $pdo->query("
    SELECT hr.*, b.full_name, b.birth_date, b.sex, b.grade_level, b.program_status, s.site_name
    FROM health_records hr
    JOIN beneficiaries b ON b.beneficiary_id = hr.beneficiary_id
    LEFT JOIN feeding_sites s ON s.site_id = b.site_id
    ORDER BY hr.record_date DESC, hr.record_id DESC
    LIMIT 8
")->fetchAll();

$pageTitle = 'Dashboard';
$activeNav = 'dashboard.php';
require __DIR__ . '/partials/header.php';
?>
<div class="dash-top-grid">
    <div class="dash-top-left">
        <a class="stat pending-card" href="beneficiaries.php" title="Go to Beneficiaries">
            <span class="pending-card-label">Pending Enrollments</span>
            <strong class="pending-count-<?= $pendingEnrollment === 0 ? 'zero' : 'positive' ?>"><?= $pendingEnrollment ?></strong>
            <span class="pending-card-review">
                Manage
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </span>
        </a>
        <a class="btn primary dash-add-beneficiary" href="beneficiary_form.php" data-slideover="beneficiary_form.php?panel=1" data-slideover-title="Add Beneficiary"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg><span>Add Beneficiary</span></a>
    </div>

    <section class="dash-top-right">
        <span class="filter-label">Quick Actions</span>
        <div class="quick-actions-body">
            <div class="quick-actions-grid">
                <a class="btn quick-primary" href="attendance.php?date=<?= date('Y-m-d') ?>">Take Today&rsquo;s Attendance</a>
                <a class="btn quick-primary" href="beneficiaries.php">Find a Beneficiary</a>
                <a class="btn quick-primary" href="reports.php">View Reports &amp; Analysis</a>
            </div>
        </div>
    </section>
</div>



<section class="panel">
    <div class="panel-head">
        <div>
            <h2>Recent Records</h2>
            <span class="muted" style="font-size:12.5px;">Latest measurements recorded across all sites</span>
        </div>
        <a href="reports.php" style="font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:.4px;">View Reports &amp; Analysis</a>
    </div>
    <?php if (!$latest): ?>
        <p class="empty">No health records yet.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Name</th><th>Site</th><th>Grade Level</th><th>Birth Date</th><th>Sex</th><th class="num">Height</th><th class="num">Weight</th><th class="num">BMI</th><th>Weight Status</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php foreach ($latest as $r): ?>
                <tr>
                    <td><strong><?= e($r['full_name']) ?></strong></td>
                    <td><?= e($r['site_name'] ?? '—') ?></td>
                    <td><?= e($r['grade_level'] ?: '—') ?></td>
                    <td><?= e($r['birth_date']) ?></td>
                    <td><?= e($r['sex']) ?></td>
                    <td class="num"><?= e($r['height_cm']) ?> cm</td>
                    <td class="num"><?= e($r['weight_kg']) ?> kg</td>
                    <td class="num"><?= e($r['bmi']) ?> kg/m<sup>2</sup></td>
                    <td><?= $r['nutritional_status'] ? pcd_badge($r['nutritional_status']) : '<span class="muted">Pending</span>' ?></td>
                    <td><?= pcd_badge($r['program_status']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
