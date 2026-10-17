<?php
require_once __DIR__ . '/db.php';
require_login();

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT b.*, s.site_name, p.program_name
    FROM beneficiaries b
    LEFT JOIN feeding_sites s ON s.site_id=b.site_id
    LEFT JOIN feeding_programs p ON p.program_id=b.program_id
    WHERE b.beneficiary_id=?
");
$stmt->execute([$id]);
$b = $stmt->fetch();
if (!$b) exit('Beneficiary not found.');

$hr = $pdo->prepare("SELECT * FROM health_records WHERE beneficiary_id=? ORDER BY record_date DESC, record_id DESC");
$hr->execute([$id]);
$records = $hr->fetchAll();

$milestoneRecords = get_milestone_records($pdo, $id);
$nextUp = next_milestone($milestoneRecords);

$at = $pdo->prepare("SELECT * FROM attendance_records WHERE beneficiary_id=? ORDER BY attendance_date DESC LIMIT 30");
$at->execute([$id]);
$attendance = $at->fetchAll();

$pageTitle = 'Beneficiary Profile';
$pageEyebrowHtml = '<a class="back-link" href="beneficiaries.php">&larr; Back to Beneficiaries</a>';
$deleteButtonHtml = can_delete_beneficiaries()
    ? ' <a class="btn secondary danger-outline" href="beneficiary_delete.php?id=' . $id . '" data-slideover="beneficiary_delete.php?panel=1&id=' . $id . '" data-slideover-title="Delete Beneficiary">Delete</a>'
    : '';
$recordButtonHtml = $nextUp
    ? '<a class="btn primary" href="health_record.php?beneficiary_id=' . $id . '&milestone=' . $nextUp . '" data-slideover="health_record.php?panel=1&beneficiary_id=' . $id . '&milestone=' . $nextUp . '" data-slideover-title="Record ' . e(milestone_label($nextUp)) . '"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg><span>Record ' . e(milestone_label($nextUp)) . '</span></a>'
    : '';
$pageActionsHtml = '<a class="btn secondary" href="beneficiary_form.php?id=' . $id . '" data-slideover="beneficiary_form.php?panel=1&id=' . $id . '" data-slideover-title="Edit Beneficiary">Edit Profile</a> '
    . $recordButtonHtml . $deleteButtonHtml;
require __DIR__ . '/partials/header.php';
?>
<section class="panel bp">
    <aside class="bp-side">
        <div class="bp-card bp-info">
            <h2>Beneficiary information</h2>
            <dl>
                <div><dt>Beneficiary ID</dt><dd><?= $b['beneficiary_code'] ? '<span class="code-tag">' . e($b['beneficiary_code']) . '</span>' : '—' ?></dd></div>
                <div><dt>Name</dt><dd><?= e($b['full_name']) ?></dd></div>
                <div><dt>Birthdate</dt><dd><?= e($b['birth_date']) ?></dd></div>
                <div><dt>Sex</dt><dd><?= e($b['sex']) ?></dd></div>
                <div><dt>Grade Level</dt><dd><?= e($b['grade_level']) ?></dd></div>
                <div><dt>Status</dt><dd><?= pcd_badge($b['program_status']) ?></dd></div>
                <div><dt>Feeding Site</dt><dd><?= e($b['site_name'] ?? '—') ?></dd></div>
                <div><dt>Date Enlisted</dt><dd><?= e($b['date_enlisted'] ?? '—') ?></dd></div>
            </dl>
        </div>
    </aside>

    <div class="bp-main">
        <h1 class="bp-title"><?= e($b['full_name']) ?></h1>

<section class="bp-section">
    <div class="panel-head">
        <h2>Attendance History</h2>
    </div>
    <div class="table-wrap">
        <table class="history-table">
            <thead>
                <tr><th>Date</th><th>Status</th><th>Remarks</th></tr>
            </thead>
            <tbody>
                <?php foreach ($attendance as $a): ?>
                <tr>
                    <td data-label="Date"><?= e($a['attendance_date']) ?></td>
                    <td data-label="Status"><?= pcd_badge($a['attendance_status']) ?></td>
                    <td data-label="Remarks" class="remarks-cell"><?= e($a['remarks']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$attendance): ?>
            <p class="empty">No attendance records yet.</p>
        <?php endif; ?>
    </div>
</section>

<section class="bp-section" id="health">
    <div class="panel-head">
        <h2>Health History</h2>
        <span class="muted">BMI = kg / m²</span>
    </div>
    <div class="table-wrap">
        <table class="history-table">
            <thead>
                <tr><th>Date</th><th>Milestone</th><th class="num">Height</th><th class="num">Weight</th><th class="num">BMI</th><th>Weight Status</th><th>Remarks</th><?php if (can_edit_health_records()): ?><th>Action</th><?php endif; ?></tr>
            </thead>
            <tbody>
                <?php foreach ($records as $r): ?>
                <tr>
                    <td data-label="Date"><?= e($r['record_date']) ?></td>
                    <td data-label="Milestone"><?= $r['milestone'] ? '<span class="pill green">' . e(milestone_label($r['milestone'])) . '</span>' : '<span class="pill gray">—</span>' ?></td>
                    <td class="num" data-label="Height"><?= e($r['height_cm']) ?> cm</td>
                    <td class="num" data-label="Weight"><?= e($r['weight_kg']) ?> kg</td>
                    <td class="num" data-label="BMI"><strong><?= e($r['bmi']) ?></strong> kg/m<sup>2</sup></td>
                    <td data-label="Weight Status"><?= $r['nutritional_status'] ? pcd_badge($r['nutritional_status']) : '<span class="muted">Pending</span>' ?></td>
                    <td data-label="Remarks" class="remarks-cell"><?= e($r['remarks']) ?></td>
                    <?php if (can_edit_health_records()): ?>
                    <td data-label="Action"><a class="btn secondary sm" href="health_record_edit.php?id=<?= (int)$r['record_id'] ?>" data-slideover="health_record_edit.php?panel=1&id=<?= (int)$r['record_id'] ?>" data-slideover-title="Edit <?= e($r['milestone'] ? milestone_label($r['milestone']) : 'Measurement') ?>" aria-label="Edit measurement of <?= e($r['record_date']) ?>">Edit</a></td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$records): ?>
            <p class="empty">No health records yet.</p>
        <?php endif; ?>
    </div>
</section>
    </div>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
