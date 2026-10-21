<?php
require_once __DIR__ . '/db.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $type = $_POST['type'] ?? '';

    if ($type === 'site') {
        $name = capitalize_words(trim($_POST['site_name'] ?? ''));
        $startRaw = trim($_POST['start_date'] ?? '');
        $endRaw = trim($_POST['end_date'] ?? '');
        $durRaw = trim($_POST['duration_days'] ?? '');
        $hasProgram = ($startRaw !== '' || $endRaw !== '' || $durRaw !== '');
        $error = null;

        $parseDate = function (string $v): ?DateTimeImmutable {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
            return ($d && $d->format('Y-m-d') === $v) ? $d : null;
        };
        $start = $startRaw !== '' ? $parseDate($startRaw) : null;
        $end = $endRaw !== '' ? $parseDate($endRaw) : null;
        $duration = null;

        if ($name === '') {
            $error = 'Site name is required.';
        } elseif (($startRaw !== '' && !$start) || ($endRaw !== '' && !$end)) {
            $error = 'Please enter valid dates.';
        } elseif ($start && $end && $end < $start) {
            $error = 'End Date cannot be earlier than Start Date.';
        } elseif ($durRaw !== '' && (!ctype_digit($durRaw) || (int)$durRaw < 1 || (int)$durRaw > 3650)) {
            $error = 'Duration must be a whole number of days between 1 and 3650.';
        } elseif ($durRaw !== '') {
            $duration = (int)$durRaw;   // user-entered (or user-edited) value is kept as is
        } elseif ($start && $end) {
            $duration = (int)$start->diff($end)->days + 1;   // inclusive of both dates
        }

        if ($error !== null) {
            flash('danger', $error);
        } else {
            $s = $pdo->prepare("INSERT INTO feeding_sites (site_name, address, barangay, municipality) VALUES (?, ?, ?, ?)");
            $s->execute([
                $name,
                capitalize_words(trim($_POST['address'] ?? '')),
                capitalize_words(trim($_POST['barangay'] ?? '')),
                capitalize_words(trim($_POST['municipality'] ?? '')),
            ]);
            log_action($pdo, 'CREATE_SITE', 'feeding_sites', (int)$pdo->lastInsertId(), 'Created feeding site');

            if ($hasProgram) {
                $s = $pdo->prepare("INSERT INTO feeding_programs (program_name, description, duration_days, start_date, end_date, status) VALUES (?, ?, ?, ?, ?, 'ACTIVE')");
                $s->execute([
                    $name . ' Program',   // named after the site
                    capitalize_sentences(trim($_POST['description'] ?? '')),
                    $duration,
                    $start ? $start->format('Y-m-d') : null,
                    $end ? $end->format('Y-m-d') : null,
                ]);
                log_action($pdo, 'CREATE_PROGRAM', 'feeding_programs', (int)$pdo->lastInsertId(), 'Created feeding program');
                flash('success', 'Feeding site and program added.');
            } else {
                flash('success', 'Feeding site added.');
            }
        }
    }

    header('Location: manage.php');
    exit;
}

$sites = $pdo->query("SELECT s.*, (SELECT COUNT(*) FROM beneficiaries b WHERE b.site_id = s.site_id) AS beneficiary_count FROM feeding_sites s ORDER BY s.site_name")->fetchAll();
$programs = $pdo->query("SELECT * FROM feeding_programs ORDER BY program_name")->fetchAll();

$activeSiteCount = (int)$pdo->query("SELECT COUNT(*) FROM feeding_sites WHERE status='ACTIVE'")->fetchColumn();
$activeProgramCount = (int)$pdo->query("SELECT COUNT(*) FROM feeding_programs WHERE status='ACTIVE'")->fetchColumn();
$enrolledCount = (int)$pdo->query("SELECT COUNT(*) FROM beneficiaries WHERE program_status='ACTIVE'")->fetchColumn();
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

<section class="panel program-setup-panel">
    <h2>Program Summary</h2>
    <div class="cards program-summary-cards" style="margin-top:8px; margin-bottom:0;">
        <div class="stat red"><span>Total Feeding Sites</span><strong><?= count($sites) ?></strong></div>
        <div class="stat green"><span>Total Beneficiaries</span><strong><?= $totalCount ?></strong></div>
    </div>
</section>

<div>
    <section class="panel program-setup-panel program-setup-add-panel">
        <details class="collapsible"><summary><h2>Add Feeding Site</h2></summary>
        <form method="post" id="program-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="type" value="site">
            <div class="form-grid">
                <div class="full-col">
                    <label>Site Name *</label>
                    <input name="site_name" required>
                </div>
                <div class="full-col">
                    <label>Address</label>
                    <input name="address">
                </div>
                <div>
                    <label>Barangay</label>
                    <input name="barangay">
                </div>
                <div>
                    <label>City</label>
                    <input name="municipality">
                </div>
            </div>
            <hr class="program-period-divider">
            <h3 class="form-section-title program-period-title">Program Period (optional)</h3>
            <div class="form-grid">
                <div>
                    <label>Start Date</label>
                    <input type="date" name="start_date" id="program_start_date">
                </div>
                <div>
                    <label>End Date</label>
                    <input type="date" name="end_date" id="program_end_date">
                </div>
                <div class="full-col">
                    <label>Duration (Days)</label>
                    <input type="number" name="duration_days" id="program_duration_days" min="1" max="3650" step="1" inputmode="numeric">
                    <span class="form-hint" id="program_duration_hint">Derived from start and end date. This is editable.</span>
                </div>
            </div>
            <div class="form-actions"><button class="btn primary">Add Site</button></div>
        </form>
        </details>
    </section>

</div>

<section class="panel program-setup-panel">
    <h2>Configured Feeding Sites</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Name</th><th>Barangay</th><th>City</th><th>Beneficiary Count</th></tr>
            </thead>
            <tbody>
                <?php foreach ($sites as $s): ?>
                <tr>
                    <td><?= e($s['site_name']) ?></td>
                    <td><?= e($s['barangay']) ?></td>
                    <td><?= e($s['municipality']) ?></td>
                    <td><?= (int)$s['beneficiary_count'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>