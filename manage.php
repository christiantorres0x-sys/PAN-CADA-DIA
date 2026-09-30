<?php
require_once __DIR__ . '/db.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $type = $_POST['type'] ?? '';

    if ($type === 'site') {
        $name = trim($_POST['site_name'] ?? '');
        if ($name !== '') {
            $s = $pdo->prepare("INSERT INTO feeding_sites (site_name, address, barangay, municipality) VALUES (?, ?, ?, ?)");
            $s->execute([$name, trim($_POST['address'] ?? ''), trim($_POST['barangay'] ?? ''), trim($_POST['municipality'] ?? '')]);
            log_action($pdo, 'CREATE_SITE', 'feeding_sites', (int)$pdo->lastInsertId(), 'Created feeding site');
            flash('success', 'Feeding site added.');
        }
    } elseif ($type === 'program') {
        $name = trim($_POST['program_name'] ?? '');
        if ($name !== '') {
            $s = $pdo->prepare("INSERT INTO feeding_programs (program_name, description, duration_days, start_date, end_date, status) VALUES (?, ?, ?, ?, ?, ?)");
            $s->execute([
                $name,
                trim($_POST['description'] ?? ''),
                ($_POST['duration_days'] ?? '') !== '' ? (int)$_POST['duration_days'] : null,
                ($_POST['start_date'] ?? '') ?: null,
                ($_POST['end_date'] ?? '') ?: null,
                $_POST['status'] ?? 'ACTIVE',
            ]);
            log_action($pdo, 'CREATE_PROGRAM', 'feeding_programs', (int)$pdo->lastInsertId(), 'Created feeding program');
            flash('success', 'Feeding program added.');
        }
    }

    header('Location: manage.php');
    exit;
}

$sites = $pdo->query("SELECT * FROM feeding_sites ORDER BY site_name")->fetchAll();
$programs = $pdo->query("SELECT * FROM feeding_programs ORDER BY program_name")->fetchAll();

$activeSiteCount = (int)$pdo->query("SELECT COUNT(*) FROM feeding_sites WHERE status='ACTIVE'")->fetchColumn();
$activeProgramCount = (int)$pdo->query("SELECT COUNT(*) FROM feeding_programs WHERE status='ACTIVE'")->fetchColumn();
$enrolledCount = (int)$pdo->query("SELECT COUNT(*) FROM beneficiaries WHERE program_status='ENROLLED'")->fetchColumn();
$totalCount = (int)$pdo->query("SELECT COUNT(*) FROM beneficiaries")->fetchColumn();

$durations = array_filter(array_column($programs, 'duration_days'));
$avgDuration = $durations ? (int)round(array_sum($durations) / count($durations)) : null;

$milestoneCounts = ['BASELINE' => 0, 'MIDLINE' => 0, 'ENDLINE' => 0];
foreach ($pdo->query("SELECT milestone, COUNT(DISTINCT beneficiary_id) c FROM health_records WHERE milestone IS NOT NULL GROUP BY milestone")->fetchAll() as $row) {
    $milestoneCounts[$row['milestone']] = (int)$row['c'];
}

$pageTitle = 'Program Setup';
$activeNav = 'manage.php';
require __DIR__ . '/partials/header.php';
?>
<section class="panel">
    <span class="filter-label">Program Information</span>
    <div class="program-info">
        <span class="brand-mark"><img src="assets/images/CCES-logo.png" alt="<?= e(APP_NAME) ?> logo"></span>
        <div>
            <h3><?= e(APP_NAME) ?></h3>
            <p>Nutrition program that aims to improve the health and nutritional status of children in the community through regular meals and health monitoring.</p>
        </div>
    </div>
</section>

<section class="panel">
    <h2>Program Summary</h2>
    <div class="cards" style="margin-top:8px; margin-bottom:0;">
        <div class="stat green"><span>Total Beneficiaries</span><strong><?= $totalCount ?></strong><small class="muted"><?= $enrolledCount ?> enrolled</small></div>
        <div class="stat red"><span>Total Feeding Sites</span><strong><?= count($sites) ?></strong><small class="muted"><?= $activeSiteCount ?> active</small></div>
        <div class="stat orange"><span>Active Programs</span><strong><?= $activeProgramCount ?></strong><small class="muted">of <?= count($programs) ?> configured</small></div>
        <div class="stat gray"><span>Program Duration</span><strong><?= $avgDuration !== null ? $avgDuration . 'd' : '—' ?></strong><small class="muted">Average cycle length</small></div>
    </div>
</section>

<section class="panel">
    <h2>Program Milestones</h2>
    <div class="milestone-panel" style="margin-top:8px;">
        <?php foreach (MILESTONES as $m): ?>
        <div class="milestone-slot">
            <h3><?= e(milestone_label($m)) ?></h3>
            <div class="big-number" style="font-size:20px; margin:4px 0 2px;"><?= $milestoneCounts[$m] ?></div>
            <div class="milestone-meta">beneficiaries recorded</div>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<div class="grid-2">
    <section class="panel">
        <details class="collapsible"><summary><h2>Add Feeding Site</h2></summary>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="type" value="site">
            <label>Site Name *</label>
            <input name="site_name" required>
            <label>Address</label>
            <input name="address">
            <label>Barangay</label>
            <input name="barangay">
            <label>Municipality / City</label>
            <input name="municipality">
            <div class="form-actions"><button class="btn primary">Add Site</button></div>
        </form>
        </details>
    </section>

    <section class="panel">
        <details class="collapsible"><summary><h2>Add Feeding Program</h2></summary>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="type" value="program">
            <label>Program Name *</label>
            <input name="program_name" required>
            <label>Description</label>
            <textarea name="description"></textarea>
            <label>Duration (days)</label>
            <input type="number" name="duration_days" min="1">
            <div class="form-grid">
                <div><label>Start Date</label><input type="date" name="start_date"></div>
                <div><label>End Date</label><input type="date" name="end_date"></div>
            </div>
            <label>Status</label>
            <select name="status">
                <option>ACTIVE</option>
                <option>COMPLETED</option>
                <option>INACTIVE</option>
            </select>
            <div class="form-actions"><button class="btn primary">Add Program</button></div>
        </form>
        </details>
    </section>
</div>

<section class="panel">
    <h2>Configured Feeding Sites</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Name</th><th>Barangay</th><th>Municipality</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php foreach ($sites as $s): ?>
                <tr>
                    <td><?= e($s['site_name']) ?></td>
                    <td><?= e($s['barangay']) ?></td>
                    <td><?= e($s['municipality']) ?></td>
                    <td><?= pcd_badge($s['status']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel">
    <h2>Configured Feeding Programs</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Name</th><th>Duration</th><th>Start</th><th>End</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php foreach ($programs as $p): ?>
                <tr>
                    <td><?= e($p['program_name']) ?></td>
                    <td><?= e($p['duration_days'] ?? '—') ?></td>
                    <td><?= e($p['start_date'] ?? '—') ?></td>
                    <td><?= e($p['end_date'] ?? '—') ?></td>
                    <td><?= pcd_badge($p['status']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
