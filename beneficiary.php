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
$healthActionLabel = $nextUp ? 'Record ' . milestone_label($nextUp) : 'Record Measurement';
$pageActionsHtml = '<a class="btn secondary" href="beneficiary_form.php?id=' . $id . '" data-slideover="beneficiary_form.php?panel=1&id=' . $id . '" data-slideover-title="Edit Beneficiary">Edit Profile</a> '
    . '<a class="btn primary" href="health_record.php?beneficiary_id=' . $id . '"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg><span>' . e($healthActionLabel) . '</span></a>';
require __DIR__ . '/partials/header.php';
?>
<section class="panel profile-grid">
    <div><span>Beneficiary ID</span><strong><?= $b['beneficiary_code'] ? '<span class="code-tag">' . e($b['beneficiary_code']) . '</span>' : '—' ?></strong></div>
    <div><span>Name</span><strong><?= e($b['full_name']) ?></strong></div>
    <div><span>Birthdate</span><strong><?= e($b['birth_date']) ?></strong></div>
    <div><span>Sex</span><strong><?= e($b['sex']) ?></strong></div>
    <div><span>Grade Level</span><strong><?= e($b['grade_level']) ?></strong></div>
    <div><span>Status</span><strong><?= pcd_badge($b['program_status']) ?></strong></div>
    <div><span>Feeding Site</span><strong><?= e($b['site_name'] ?? '—') ?></strong></div>
    <div><span>Program</span><strong><?= e($b['program_name'] ?? '—') ?></strong></div>
    <div><span>Date Enlisted</span><strong><?= e($b['date_enlisted'] ?? '—') ?></strong></div>
</section>

<section class="panel">
    <div class="panel-head">
        <h2>Attendance History</h2>
        <a class="btn secondary" href="attendance.php">Manage Attendance</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Date</th><th>Status</th><th>Remarks</th></tr>
            </thead>
            <tbody>
                <?php foreach ($attendance as $a): ?>
                <tr>
                    <td><?= e($a['attendance_date']) ?></td>
                    <td><?= pcd_badge($a['attendance_status']) ?></td>
                    <td><?= e($a['remarks']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$attendance): ?>
            <p class="empty">No attendance records yet.</p>
        <?php endif; ?>
    </div>
</section>

<section class="panel" id="health">
    <div class="panel-head">
        <h2>Health History</h2>
        <span class="muted">BMI = kg / m²</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Date</th><th>Milestone</th><th class="num">Height</th><th class="num">Weight</th><th class="num">BMI</th><th>Weight Status</th><th>Remarks</th></tr>
            </thead>
            <tbody>
                <?php foreach ($records as $r): ?>
                <tr>
                    <td><?= e($r['record_date']) ?></td>
                    <td><?= $r['milestone'] ? '<span class="pill green">' . e(milestone_label($r['milestone'])) . '</span>' : '<span class="pill gray">—</span>' ?></td>
                    <td class="num"><?= e($r['height_cm']) ?> cm</td>
                    <td class="num"><?= e($r['weight_kg']) ?> kg</td>
                    <td class="num"><strong><?= e($r['bmi']) ?></strong> kg/m<sup>2</sup></td>
                    <td><?= $r['nutritional_status'] ? pcd_badge($r['nutritional_status']) : '<span class="muted">Pending</span>' ?></td>
                    <td><?= e($r['remarks']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$records): ?>
            <p class="empty">No health records yet.</p>
        <?php endif; ?>
    </div>
    <p class="notice"><strong>About BMI:</strong> BMI is worked out from height and weight. For children, weight status is set from an age- and sex-specific growth reference, not adult BMI ranges, so some records show Pending.</p>
</section>

<section class="panel">
    <div class="panel-head">
        <h2>Growth Milestones</h2>
        <span class="muted">Baseline · Midline · Endline</span>
    </div>
    <div class="milestone-panel">
        <?php foreach (MILESTONES as $m): $rec = $milestoneRecords[$m]; $gap = $rec ? milestone_prerequisite_gap($m, $milestoneRecords) : null; ?>
        <div class="milestone-slot">
            <h3><?= e(milestone_label($m)) ?></h3>
            <?php if ($rec): ?>
                <div><?= e($rec['record_date']) ?></div>
                <div class="milestone-meta"><?= e($rec['height_cm']) ?> cm · <?= e($rec['weight_kg']) ?> kg · BMI <?= e($rec['bmi']) ?></div>
                <?php if ($gap): ?>
                    <div class="milestone-meta" style="color:var(--orange);">⚠ No <?= e(milestone_label($gap)) ?> on file — not comparable yet.</div>
                <?php endif; ?>
            <?php else: ?>
                <div class="muted">Pending</div>
                <a class="btn secondary sm" href="health_record.php?beneficiary_id=<?= $id ?>&milestone=<?= $m ?>">Record <?= e(milestone_label($m)) ?></a>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
