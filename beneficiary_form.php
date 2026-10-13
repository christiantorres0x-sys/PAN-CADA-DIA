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

$duplicateMatch = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $firstName = capitalize_words(trim($_POST['first_name'] ?? ''));
    $middleName = capitalize_words(trim($_POST['middle_name'] ?? ''));
    $lastName = capitalize_words(trim($_POST['last_name'] ?? ''));
    $fullName = trim($firstName . ' ' . $lastName);
    $code = generate_beneficiary_code($firstName, $middleName);

    // Grade level: either one of the listed options, or "Other" + typed text.
    $gradeChoice = trim($_POST['grade_level'] ?? '');
    $gradeOtherText = capitalize_words(trim($_POST['grade_level_other'] ?? ''));
    $gradeValue = $gradeChoice === GRADE_LEVEL_OTHER
        ? ($gradeOtherText !== '' ? str_limit_utf8($gradeOtherText, GRADE_LEVEL_MAX_LENGTH) : GRADE_LEVEL_OTHER)
        : $gradeChoice;

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
        'grade_level' => $gradeValue,
        'site_id' => $_POST['site_id'] ?? '',
        'program_id' => $_POST['program_id'] ?? '',
        'program_status' => $_POST['program_status'] ?? 'ENROLLED',
        'date_enlisted' => $_POST['date_enlisted'] ?? '',
        'remarks' => capitalize_sentences(trim($_POST['remarks'] ?? '')),
    ]);

    $siteId = ($_POST['site_id'] ?? '') !== '' ? (int)$_POST['site_id'] : null;
    $programId = ($_POST['program_id'] ?? '') !== '' ? (int)$_POST['program_id'] : null;
    $dateEnlisted = ($_POST['date_enlisted'] ?? '') !== '' ? $_POST['date_enlisted'] : null;

    // Baseline measurement (add mode only; the edit form has none). A new
    // enrollment must always record a real baseline (date + height + weight,
    // typed in the selected unit and converted once to cm / kg). A blank
    // baseline is never saved as a record.
    $baselineMode = '';
    $baselineHeight = null; $baselineWeight = null; $baselineError = null;
    $baselineDate = null;
    if (!$isEdit) {
        $baselineMode = 'record'; // baseline is always required when adding a beneficiary
        if ($baselineMode === 'record') {
            [$baselineHeight, $hErr] = parse_height_cm($_POST, 'baseline_');
            [$baselineWeight, $wErr] = parse_weight_kg($_POST, 'baseline_');
            $baselineDate = trim((string)($_POST['baseline_date'] ?? ''));
            $bd = $baselineDate !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d', $baselineDate) : null;
            if ($hErr !== null) {
                $baselineError = $hErr;
            } elseif ($baselineHeight === null) {
                $baselineError = 'Baseline height is required.';
            } elseif ($wErr !== null) {
                $baselineError = $wErr;
            } elseif ($baselineWeight === null) {
                $baselineError = 'Baseline weight is required.';
            } elseif ($baselineDate === '') {
                $baselineError = 'Baseline measurement date is required.';
            } elseif (!$bd || $bd->format('Y-m-d') !== $baselineDate) {
                $baselineError = 'Please enter a valid baseline measurement date.';
            } elseif ($baselineDate > date('Y-m-d')) {
                $baselineError = 'The baseline measurement date cannot be in the future.';
            } elseif ($beneficiary['birth_date'] !== '' && $baselineDate < $beneficiary['birth_date']) {
                $baselineError = 'The baseline measurement date cannot be before the birthdate.';
            }
        }
    }

    if ($fullName === '' || $beneficiary['birth_date'] === '') {
        $msg = 'First name, last name, and birthdate are required.';
        flash('danger', $msg);
        if (is_ajax_request()) json_response(['ok' => false, 'message' => $msg]);
    } elseif ($baselineError !== null) {
        $msg = preg_match('/^(Baseline|The baseline|Please)/', $baselineError) ? $baselineError : 'Baseline measurement: ' . $baselineError;
        flash('danger', $msg);
        if (is_ajax_request()) json_response(['ok' => false, 'message' => $msg]);
    } elseif ($gradeChoice === GRADE_LEVEL_OTHER && $gradeOtherText === '') {
        $msg = 'Please type the grade level or category for "Other".';
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
            // Baseline (already validated above): either a complete, real
            // measurement - never a blank.
            $weightStatus = trim($_POST['weight_status'] ?? '');
            $baselineRemarks = capitalize_sentences(trim($_POST['baseline_remarks'] ?? ''));
            $hasBaselineMeasurement = $baselineMode === 'record' && $baselineHeight !== null && $baselineWeight !== null && $baselineDate !== null;

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

                $bmi = calculate_bmi($baselineWeight, $baselineHeight);
                $stmt = $pdo->prepare("
                    INSERT INTO health_records
                    (beneficiary_id, record_date, milestone, height_cm, weight_kg, bmi, nutritional_status, remarks, recorded_by)
                    VALUES (?, ?, 'BASELINE', ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$newId, $baselineDate, $baselineHeight, $baselineWeight, $bmi, $weightStatus !== '' ? $weightStatus : null, $baselineRemarks, $_SESSION['user_id']]);
                log_action($pdo, 'CREATE_HEALTH_RECORD', 'health_records', (int)$pdo->lastInsertId(), 'Baseline captured during enrollment; BMI ' . $bmi);

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                $msg = 'Could not save beneficiary. Please try again.';
                flash('danger', $msg);
                if (is_ajax_request()) json_response(['ok' => false, 'message' => $msg]);
                header('Location: beneficiary_form.php');
                exit;
            }

            flash('success', 'Beneficiary added and baseline recorded.');
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

// Grade level dropdown state. A stored value that isn't one of the listed
// options (older records, or a category typed under "Other") is shown as
// "Other" with its text filled in, so editing never silently drops it.
$gradeGroups = grade_level_groups();
$gradeSelected = (string)$beneficiary['grade_level'];
$gradeOtherValue = '';
if ($gradeSelected !== '' && $gradeSelected !== GRADE_LEVEL_OTHER && !in_array($gradeSelected, grade_level_known_values(), true)) {
    $gradeOtherValue = $gradeSelected;
    $gradeSelected = GRADE_LEVEL_OTHER;
} elseif ($gradeSelected === GRADE_LEVEL_OTHER) {
    $gradeOtherValue = (string)($_POST['grade_level_other'] ?? '');
}

$pageTitle = $isEdit ? 'Edit Beneficiary' : 'Add Beneficiary';
$pageEyebrowHtml = '<a class="back-link" href="' . ($isEdit ? 'beneficiary.php?id=' . $id : 'beneficiaries.php') . '">&larr; Back to ' . ($isEdit ? 'Beneficiary Profile' : 'Dashboard') . '</a>';
require __DIR__ . '/partials/header.php';
?>
<div class="form-panel">
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

        <div class="beneficiary-form-grid single">
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
                        <div class="full-col">
                            <label>Remarks</label>
                            <textarea name="remarks" class="remarks-input" data-autogrow rows="3" placeholder="Optional notes about this beneficiary"><?= e($beneficiary['remarks']) ?></textarea>
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
                            <label>Grade Level / Category *</label>
                            <select name="grade_level" id="grade_level_select" required>
                                <option value="">Select grade level</option>
                                <?php foreach ($gradeGroups as $groupLabel => $groupOptions): ?>
                                    <optgroup label="<?= e($groupLabel) ?>">
                                        <?php foreach ($groupOptions as $g): ?>
                                            <option value="<?= e($g) ?>" <?= $gradeSelected === $g ? 'selected' : '' ?>><?= e($g) ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                                <option value="<?= e(GRADE_LEVEL_OTHER) ?>" <?= $gradeSelected === GRADE_LEVEL_OTHER ? 'selected' : '' ?>>Other (specify)</option>
                            </select>
                        </div>
                        <div class="full-col" id="grade_other_wrap" style="<?= $gradeSelected === GRADE_LEVEL_OTHER ? '' : 'display:none;' ?>">
                            <label>Specify Grade Level / Category *</label>
                            <input name="grade_level_other" id="grade_level_other" maxlength="<?= GRADE_LEVEL_MAX_LENGTH ?>" value="<?= e($gradeOtherValue) ?>" placeholder="e.g. Madrasah, Home-schooled" <?= $gradeSelected === GRADE_LEVEL_OTHER ? 'required' : '' ?>>
                        </div>
                    </div>
                </div>
                <?php if (!$isEdit): ?>
                <div class="form-section">
                    <h3 class="form-section-title">Baseline Measurement <span class="muted" style="font-weight:600; text-transform:none; letter-spacing:0;">(required)</span></h3>
                    <div class="form-grid" id="baseline_fields" style="margin-top:8px;">
                        <div>
                            <label>Date of Measurement *</label>
                            <input type="date" name="baseline_date" value="<?= e($_POST['baseline_date'] ?? date('Y-m-d')) ?>" max="<?= date('Y-m-d') ?>" required>
                        </div>
                        <?php $mPrefix = 'baseline_'; $mRequired = true; require __DIR__ . '/partials/measurement_fields.php'; ?>
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
                            <label>Baseline Remarks</label>
                            <textarea name="baseline_remarks" class="remarks-input" data-autogrow rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </section>
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