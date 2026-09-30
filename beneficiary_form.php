<?php
require_once __DIR__ . '/db.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$isEdit = $id > 0;

$beneficiary = [
    'full_name' => '',
    'first_name' => '',
    'middle_name' => '',
    'last_name' => '',
    'beneficiary_code' => '',
    'birth_date' => '',
    'sex' => 'Male',
    'grade_level' => '',
    'site_id' => '',
    'program_id' => '',
    'program_status' => 'ENROLLED',
    'date_enlisted' => '',
    'remarks' => '',
];
$latestRecord = null;

// "Save & add another" carries the last site/program/date forward.
if (!$isEdit) {
    foreach (['site_id', 'program_id', 'date_enlisted'] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') $beneficiary[$k] = $_GET[$k];
    }
}

if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM beneficiaries WHERE beneficiary_id=?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) exit('Beneficiary not found.');
    $beneficiary = array_merge($beneficiary, $row);

    // Older records may predate the first/middle/last split — fall back to
    // parsing full_name so the fields aren't blank on first edit.
    if (trim((string)$beneficiary['first_name']) === '' && trim($beneficiary['full_name']) !== '') {
        $nameParts = preg_split('/\s+/', trim($beneficiary['full_name']));
        $beneficiary['first_name'] = array_shift($nameParts) ?? '';
        $beneficiary['last_name'] = $nameParts ? implode(' ', $nameParts) : '';
    }

    $recStmt = $pdo->prepare("SELECT * FROM health_records WHERE beneficiary_id=? ORDER BY record_date DESC, record_id DESC LIMIT 1");
    $recStmt->execute([$id]);
    $latestRecord = $recStmt->fetch() ?: null;
}

$sites = $pdo->query("SELECT * FROM feeding_sites WHERE status='ACTIVE' ORDER BY site_name")->fetchAll();
$programs = $pdo->query("SELECT * FROM feeding_programs WHERE status <> 'INACTIVE' ORDER BY start_date DESC, program_name")->fetchAll();

$gradeLevelOptions = ['Day Care', 'Kinder', 'Grade 1', 'Grade 2', 'Grade 3', 'Grade 4', 'Grade 5', 'Grade 6'];
if ($beneficiary['grade_level'] !== '' && !in_array($beneficiary['grade_level'], $gradeLevelOptions, true)) {
    $gradeLevelOptions[] = $beneficiary['grade_level'];
}

$duplicateMatch = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $firstName = trim($_POST['first_name'] ?? '');
    $middleName = trim($_POST['middle_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $fullName = trim($firstName . ' ' . $lastName);
    $code = generate_beneficiary_code($firstName, $middleName);

    // Keep whatever the user typed on screen if we have to re-show the form
    // (validation error or duplicate warning) instead of resetting it.
    $beneficiary = array_merge($beneficiary, [
        'first_name' => $firstName,
        'middle_name' => $middleName,
        'last_name' => $lastName,
        'full_name' => $fullName,
        'beneficiary_code' => $code,
        'birth_date' => $_POST['birth_date'] ?? '',
        'sex' => $_POST['sex'] ?? 'Male',
        'grade_level' => trim($_POST['grade_level'] ?? ''),
        'site_id' => $_POST['site_id'] ?? '',
        'program_id' => $_POST['program_id'] ?? '',
        'program_status' => $_POST['program_status'] ?? 'ENROLLED',
        'date_enlisted' => $_POST['date_enlisted'] ?? '',
        'remarks' => trim($_POST['remarks'] ?? ''),
    ]);

    $siteId = ($_POST['site_id'] ?? '') !== '' ? (int)$_POST['site_id'] : null;
    $programId = ($_POST['program_id'] ?? '') !== '' ? (int)$_POST['program_id'] : null;
    $dateEnlisted = ($_POST['date_enlisted'] ?? '') !== '' ? $_POST['date_enlisted'] : null;

    if ($fullName === '' || $beneficiary['birth_date'] === '') {
        $msg = 'First name, last name, and birthdate are required.';
        flash('danger', $msg);
        if (is_ajax_request()) json_response(['ok' => false, 'message' => $msg]);
    } else {
        // Duplicate check: does another beneficiary already generate this
        // same ID? Skip the check (rather than block) if the name is too
        // short to produce a code at all. Confirming once
        // (confirm_duplicate=1) lets the officer proceed anyway — e.g.
        // genuine siblings/same-name cases.
        if ($code !== '') {
            $duplicateMatch = find_beneficiary_by_code($pdo, $code, $isEdit ? $id : null);
        }

        if ($duplicateMatch && ($_POST['confirm_duplicate'] ?? '') !== '1') {
            $msg = 'A beneficiary with the same generated ID (' . $code . ') already exists.';
            flash('danger', $msg);
            if (is_ajax_request()) json_response(['ok' => false, 'duplicate' => true, 'message' => $msg]);
        } elseif ($isEdit) {
            $sql = "UPDATE beneficiaries SET full_name=?, first_name=?, middle_name=?, last_name=?, beneficiary_code=?, birth_date=?, sex=?, grade_level=?, site_id=?, program_id=?, program_status=?, date_enlisted=?, remarks=? WHERE beneficiary_id=?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $fullName, $firstName, $middleName, $lastName, $code !== '' ? $code : null,
                $beneficiary['birth_date'], $beneficiary['sex'], $beneficiary['grade_level'],
                $siteId, $programId, $beneficiary['program_status'], $dateEnlisted, $beneficiary['remarks'], $id,
            ]);
            log_action($pdo, 'UPDATE_BENEFICIARY', 'beneficiaries', $id, 'Updated beneficiary profile');
            flash('success', 'Beneficiary updated.');
            if (is_ajax_request()) json_response(['ok' => true, 'redirect' => 'beneficiary.php?id=' . $id]);
            header('Location: beneficiary.php?id=' . $id);
            exit;
        } else {
            // Optional baseline capture, shown directly on the Add form.
            // This is a soft step: the beneficiary is created either way.
            // Both height and weight must be given together for a baseline
            // to be recorded; a lone value is treated as "not enough to
            // record yet" rather than an error, since the officer may just
            // be filling the form out ahead of the actual weigh-in.
            $baselineHeight = (float)($_POST['baseline_height_cm'] ?? 0);
            $baselineWeight = (float)($_POST['baseline_weight_kg'] ?? 0);
            $baselineDate = ($_POST['baseline_date'] ?? '') !== '' ? $_POST['baseline_date'] : date('Y-m-d');
            $weightStatus = trim($_POST['weight_status'] ?? '');
            $baselineRemarks = trim($_POST['baseline_remarks'] ?? '');
            $hasBaselineMeasurement = $baselineHeight > 0 && $baselineWeight > 0;

            $pdo->beginTransaction();
            try {
                $sql = "INSERT INTO beneficiaries (full_name, first_name, middle_name, last_name, beneficiary_code, birth_date, sex, grade_level, site_id, program_id, program_status, date_enlisted, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $fullName, $firstName, $middleName, $lastName, $code !== '' ? $code : null,
                    $beneficiary['birth_date'], $beneficiary['sex'], $beneficiary['grade_level'],
                    $siteId, $programId, $beneficiary['program_status'], $dateEnlisted, $beneficiary['remarks'],
                ]);
                $newId = (int)$pdo->lastInsertId();
                log_action($pdo, 'CREATE_BENEFICIARY', 'beneficiaries', $newId, 'Created beneficiary profile');

                if ($hasBaselineMeasurement) {
                    $bmi = calculate_bmi($baselineWeight, $baselineHeight);
                    $stmt = $pdo->prepare("
                        INSERT INTO health_records
                        (beneficiary_id, record_date, milestone, height_cm, weight_kg, bmi, nutritional_status, remarks, recorded_by)
                        VALUES (?, ?, 'BASELINE', ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$newId, $baselineDate, $baselineHeight, $baselineWeight, $bmi, $weightStatus !== '' ? $weightStatus : null, $baselineRemarks, $_SESSION['user_id']]);
                    log_action($pdo, 'CREATE_HEALTH_RECORD', 'health_records', (int)$pdo->lastInsertId(), 'Baseline captured during enrollment; BMI ' . $bmi);
                }

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                $msg = 'Could not save beneficiary. Please try again.';
                flash('danger', $msg);
                if (is_ajax_request()) json_response(['ok' => false, 'message' => $msg]);
                header('Location: beneficiary_form.php');
                exit;
            }

            if ($hasBaselineMeasurement) {
                flash('success', 'Beneficiary added and baseline recorded.');
            } else {
                flash('success', 'Beneficiary added. Baseline is still pending — add it from the profile page once the child is weighed.');
            }
            if (($_POST['add_another'] ?? '') === '1') {
                $qs = http_build_query(['site_id' => $siteId, 'program_id' => $programId, 'date_enlisted' => $dateEnlisted]);
                if (is_ajax_request()) json_response(['ok' => true, 'redirect' => 'beneficiaries.php', 'reopen' => 'beneficiary_form.php?panel=1&' . $qs]);
                header('Location: beneficiary_form.php?' . $qs);
                exit;
            }
            if (is_ajax_request()) json_response(['ok' => true, 'redirect' => 'beneficiary.php?id=' . $newId]);
            header('Location: beneficiary.php?id=' . $newId);
            exit;
        }
    }
}

$pageTitle = $isEdit ? 'Edit Beneficiary' : 'Add Beneficiary';
$pageEyebrowHtml = '<a class="back-link" href="' . ($isEdit ? 'beneficiary.php?id=' . $id : 'beneficiaries.php') . '">&larr; Back to ' . ($isEdit ? 'Beneficiary Profile' : 'Dashboard') . '</a>';
require __DIR__ . '/partials/header.php';
?>
<div class="form-panel <?= $isEdit ? '' : 'form-panel-wide' ?>">
    <?php if ($duplicateMatch): ?>
    <div class="notice danger">
        <strong>Possible duplicate:</strong> a beneficiary named
        <strong><?= e($duplicateMatch['full_name']) ?></strong>
        (born <?= e($duplicateMatch['birth_date']) ?>, <?= e(strtolower($duplicateMatch['program_status'])) ?>)
        already generates the ID <strong><?= e($beneficiary['beneficiary_code']) ?></strong>.
        <a href="beneficiary.php?id=<?= (int)$duplicateMatch['beneficiary_id'] ?>" target="_blank" rel="noopener">Review that record &rarr;</a>
        <br>If this is genuinely a different person, you can save anyway.
    </div>
    <?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <?php if ($duplicateMatch): ?><input type="hidden" name="confirm_duplicate" value="1"><?php endif; ?>

        <div class="beneficiary-form-grid <?= $isEdit ? 'single' : '' ?>">
            <section class="panel form-subpanel">
                <div class="form-section">
                    <h3 class="form-section-title">Enrollment Info</h3>
                    <div class="form-grid">
                        <div>
                            <label>Status *</label>
                            <select name="program_status">
                                <option value="ENROLLED" <?= $beneficiary['program_status'] === 'ENROLLED' ? 'selected' : '' ?>>Enrolled</option>
                                <option value="COMPLETED" <?= $beneficiary['program_status'] === 'COMPLETED' ? 'selected' : '' ?>>Completed</option>
                                <option value="INACTIVE" <?= $beneficiary['program_status'] === 'INACTIVE' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                        <div>
                            <label>Feeding Site *</label>
                            <select name="site_id" required>
                                <option value="">Select site</option>
                                <?php foreach ($sites as $s): ?>
                                    <option value="<?= $s['site_id'] ?>" <?= (string)$beneficiary['site_id'] === (string)$s['site_id'] ? 'selected' : '' ?>><?= e($s['site_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Program</label>
                            <select name="program_id">
                                <option value="">Select program</option>
                                <?php foreach ($programs as $p): ?>
                                    <option value="<?= $p['program_id'] ?>" <?= (string)$beneficiary['program_id'] === (string)$p['program_id'] ? 'selected' : '' ?>><?= e($p['program_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Date Enlisted</label>
                            <input type="date" name="date_enlisted" value="<?= e($beneficiary['date_enlisted']) ?>">
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-title-row">
                        <h3 class="form-section-title">Personal Info</h3>
                        <?php if ($beneficiary['beneficiary_code']): ?><span class="code-tag" title="Generated beneficiary ID">ID: <?= e($beneficiary['beneficiary_code']) ?></span><?php endif; ?>
                    </div>
                    <div class="form-grid form-grid-3">
                        <div>
                            <label>First Name *</label>
                            <input name="first_name" value="<?= e($beneficiary['first_name']) ?>" required>
                        </div>
                        <div>
                            <label>Middle Name</label>
                            <input name="middle_name" value="<?= e($beneficiary['middle_name']) ?>" placeholder="Used for the ID's initial">
                        </div>
                        <div>
                            <label>Last Name *</label>
                            <input name="last_name" value="<?= e($beneficiary['last_name']) ?>" required>
                        </div>
                        <div>
                            <label>Birthdate *</label>
                            <input type="date" name="birth_date" value="<?= e($beneficiary['birth_date']) ?>" required>
                        </div>
                        <div>
                            <label>Sex *</label>
                            <select name="sex">
                                <option <?= $beneficiary['sex'] === 'Male' ? 'selected' : '' ?>>Male</option>
                                <option <?= $beneficiary['sex'] === 'Female' ? 'selected' : '' ?>>Female</option>
                            </select>
                        </div>
                        <div>
                            <label>Grade Level *</label>
                            <select name="grade_level" required>
                                <option value="">Select grade level</option>
                                <?php foreach ($gradeLevelOptions as $g): ?>
                                    <option <?= $beneficiary['grade_level'] === $g ? 'selected' : '' ?>><?= e($g) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </section>

            <?php if (!$isEdit): ?>
            <section class="panel form-subpanel">
                <div class="form-section">
                    <h3 class="form-section-title">Baseline Measurement <span class="muted" style="font-weight:600; text-transform:none; letter-spacing:0;">(optional — can be added later)</span></h3>
                    <label class="checkbox-label"><input type="checkbox" id="capture_baseline_toggle"> Record baseline measurement now</label>
                    <div class="form-grid" id="baseline_fields" style="display:none; margin-top:8px;">
                        <div>
                            <label>Date of Measurement</label>
                            <input type="date" name="baseline_date" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div>
                            <label>Height (cm)</label>
                            <input id="height_cm" type="number" name="baseline_height_cm" min="40" max="220" step="0.1" inputmode="decimal" placeholder="e.g. 115">
                        </div>
                        <div>
                            <label>Weight (kgs)</label>
                            <input id="weight_kg" type="number" name="baseline_weight_kg" min="3" max="200" step="0.1" inputmode="decimal" placeholder="e.g. 20.5">
                        </div>
                        <div>
                            <label>Calculated BMI</label>
                            <input id="bmi_preview" readonly placeholder="Enter height and weight">
                        </div>
                        <div class="full-col">
                            <label>Weight Status</label>
                            <select name="weight_status">
                                <option value="">Pending</option>
                                <option>Severely Underweight</option>
                                <option>Underweight</option>
                                <option>Normal</option>
                                <option>Overweight</option>
                                <option>Obese</option>
                            </select>
                            <span class="form-hint">Leave as Pending until the child growth reference is confirmed.</span>
                        </div>
                        <div class="full-col">
                            <label>Remarks</label>
                            <input name="baseline_remarks">
                        </div>
                    </div>
                </div>
            </section>
            <?php endif; ?>
        </div>

        <div class="form-actions">
            <a class="btn secondary" href="<?= $isEdit ? 'beneficiary.php?id=' . $id : 'beneficiaries.php' ?>">Cancel</a>
            <?php if (!$isEdit && !$duplicateMatch): ?><button class="btn secondary" name="add_another" value="1">Save &amp; Add Another</button><?php endif; ?>
            <button class="btn primary"><?= $duplicateMatch ? 'Save Anyway' : ($isEdit ? 'Update Beneficiary' : 'Add Beneficiary') ?></button>
        </div>
    </form>
</div>

<?php if ($isEdit): ?>
<section class="panel bmi-snapshot">
    <div class="panel-head">
        <h2>BMI / Health Snapshot</h2>
        <a class="btn secondary sm" href="beneficiary.php?id=<?= $id ?>#health">View Full Health History</a>
    </div>
    <?php if ($latestRecord): ?>
    <div class="bmi-snapshot-grid">
        <div><span>Last Recorded</span><strong><?= e($latestRecord['record_date']) ?></strong></div>
        <div><span>Milestone</span><strong><?= $latestRecord['milestone'] ? e(milestone_label($latestRecord['milestone'])) : '—' ?></strong></div>
        <div><span>Height</span><strong><?= e($latestRecord['height_cm']) ?> cm</strong></div>
        <div><span>Weight</span><strong><?= e($latestRecord['weight_kg']) ?> kg</strong></div>
        <div><span>BMI</span><strong><?= e($latestRecord['bmi']) ?> kg/m<sup>2</sup></strong></div>
        <div><span>Status</span><strong><?= $latestRecord['nutritional_status'] ? pcd_badge($latestRecord['nutritional_status']) : '<span class="muted">Pending</span>' ?></strong></div>
    </div>
    <?php else: ?>
        <p class="empty">No height/weight measurements recorded yet for this child.</p>
    <?php endif; ?>
    <div class="form-actions" style="margin-top:10px;">
        <a class="btn primary sm" href="health_record.php?beneficiary_id=<?= $id ?>">Record Measurement</a>
    </div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
