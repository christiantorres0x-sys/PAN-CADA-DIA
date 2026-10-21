<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/bmi_status.php';
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
    'program_status' => 'ACTIVE',
    'date_enlisted' => '',
    'remarks' => '',
];
$latestRecord = null;

// "Save & add another" carries the last site/program/date forward.
if (!$isEdit) {
    foreach (['site_id', 'date_enlisted'] as $k) {
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

$duplicateMatch = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $firstName = capitalize_words(trim($_POST['first_name'] ?? ''));
    $middleName = capitalize_words(trim($_POST['middle_name'] ?? ''));
    $lastName = capitalize_words(trim($_POST['last_name'] ?? ''));
    $fullName = trim($firstName . ' ' . $lastName);

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
        'birth_date' => $_POST['birth_date'] ?? '',
        'sex' => $_POST['sex'] ?? 'Male',
        'grade_level' => $gradeValue,
        'site_id' => $_POST['site_id'] ?? '',
        // New beneficiaries are always Active; only an edit can switch to Inactive.
        'program_status' => ($isEdit && strtoupper(trim((string)($_POST['program_status'] ?? ''))) === 'INACTIVE') ? 'INACTIVE' : 'ACTIVE',
        'date_enlisted' => $_POST['date_enlisted'] ?? '',
        'remarks' => capitalize_sentences(trim($_POST['remarks'] ?? '')),
    ]);

    $siteId = ($_POST['site_id'] ?? '') !== '' ? (int)$_POST['site_id'] : null;
    // Program is no longer picked in the form (PAN CADA DIA is the only program).
    $programId = null;
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
        // Duplicate check: is there already a beneficiary with the same name
        // and birthdate? Confirming once (confirm_duplicate=1) lets the officer
        // proceed anyway — e.g. genuine same-name cases.
        $duplicateMatch = find_possible_duplicate($pdo, $firstName, $lastName, $beneficiary['birth_date'], $isEdit ? $id : null);

        if ($duplicateMatch && ($_POST['confirm_duplicate'] ?? '') !== '1') {
            $msg = 'A beneficiary with the same name and birthdate already exists (ID ' . ($duplicateMatch['beneficiary_code'] ?: 'none') . ').';
            flash('danger', $msg);
            if (is_ajax_request()) json_response(['ok' => false, 'duplicate' => true, 'message' => $msg]);
        } elseif ($isEdit) {
            $prevStatus = (string)$pdo->query("SELECT program_status FROM beneficiaries WHERE beneficiary_id=" . (int)$id)->fetchColumn();
            $sql = "UPDATE beneficiaries SET full_name=?, first_name=?, middle_name=?, last_name=?, birth_date=?, sex=?, grade_level=?, site_id=?, program_status=?, date_enlisted=?, remarks=? WHERE beneficiary_id=?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $fullName, $firstName, $middleName, $lastName,
                $beneficiary['birth_date'], $beneficiary['sex'], $beneficiary['grade_level'],
                $siteId, $beneficiary['program_status'], $dateEnlisted, $beneficiary['remarks'], $id,
            ]);
            if ($prevStatus !== $beneficiary['program_status']) {
                // Track when the child became inactive (cleared again when reactivated).
                $pdo->prepare("UPDATE beneficiaries SET inactive_since = " . ($beneficiary['program_status'] === 'INACTIVE' ? 'CURDATE()' : 'NULL') . " WHERE beneficiary_id = ?")->execute([$id]);
                log_action($pdo, 'UPDATE_BENEFICIARY_STATUS', 'beneficiaries', $id, 'Status ' . $prevStatus . ' -> ' . $beneficiary['program_status'] . ' (profile edit)');
            }
            log_action($pdo, 'UPDATE_BENEFICIARY', 'beneficiaries', $id, 'Updated beneficiary profile');
            flash('success', 'Beneficiary updated.');
            if (is_ajax_request()) json_response(['ok' => true, 'redirect' => 'beneficiary.php?id=' . $id]);
            header('Location: beneficiary.php?id=' . $id);
            exit;
        } else {
            // Baseline (already validated above): either a complete, real
            // measurement - never a blank.
            $baselineRemarks = capitalize_sentences(trim($_POST['baseline_remarks'] ?? ''));
            $hasBaselineMeasurement = $baselineMode === 'record' && $baselineHeight !== null && $baselineWeight !== null && $baselineDate !== null;

            $pdo->beginTransaction();
            try {
                $sql = "INSERT INTO beneficiaries (full_name, first_name, middle_name, last_name, beneficiary_code, birth_date, sex, grade_level, site_id, program_id, program_status, date_enlisted, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                // The ID (year + running order, e.g. 260001) is generated here. If two
                // officers save at the same instant the unique key rejects the
                // second one, so just take the next number and try again.
                for ($attempt = 0; ; $attempt++) {
                    $code = next_beneficiary_code($pdo);
                    try {
                        $stmt->execute([
                            $fullName, $firstName, $middleName, $lastName, $code,
                            $beneficiary['birth_date'], $beneficiary['sex'], $beneficiary['grade_level'],
                            $siteId, $programId, $beneficiary['program_status'], $dateEnlisted, $beneficiary['remarks'],
                        ]);
                        break;
                    } catch (PDOException $dupErr) {
                        if (($dupErr->errorInfo[1] ?? 0) !== 1062 || $attempt >= 4) throw $dupErr;
                    }
                }
                $newId = (int)$pdo->lastInsertId();
                log_action($pdo, 'CREATE_BENEFICIARY', 'beneficiaries', $newId, 'Created beneficiary profile');

                $bmi = calculate_bmi($baselineWeight, $baselineHeight);
                $stmt = $pdo->prepare("
                    INSERT INTO health_records
                    (beneficiary_id, record_date, milestone, height_cm, weight_kg, bmi, nutritional_status, remarks, recorded_by)
                    VALUES (?, ?, 'BASELINE', ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$newId, $baselineDate, $baselineHeight, $baselineWeight, $bmi, pcd_weight_status($bmi), $baselineRemarks, $_SESSION['user_id']]);
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
                $qs = http_build_query(['site_id' => $siteId, 'date_enlisted' => $dateEnlisted]);
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
        (<?= $duplicateMatch['beneficiary_code'] ? 'ID ' . e($duplicateMatch['beneficiary_code']) . ', ' : '' ?>born <?= e($duplicateMatch['birth_date']) ?>, <?= e(strtolower($duplicateMatch['program_status'])) ?>)
        is already on file with the same name and birthdate.
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
                            <?php if ($isEdit): ?>
                            <select name="program_status">
                                <option value="ACTIVE" <?= $beneficiary['program_status'] === 'ACTIVE' ? 'selected' : '' ?>>Active</option>
                                <option value="INACTIVE" <?= $beneficiary['program_status'] === 'INACTIVE' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                            <?php else: ?>
                            <input class="auto-field" value="Active" readonly>
                            <span class="form-hint">New beneficiaries are set to Active automatically.</span>
                            <?php endif; ?>
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
                            <input name="middle_name" value="<?= e($beneficiary['middle_name']) ?>"<?= $isEdit ? ' placeholder="Used for the ID\'s initial"' : '' ?>>
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
                            <input id="bmi_preview" class="auto-field" readonly tabindex="-1" placeholder="Enter height and weight">
                        </div>
                        <div class="full-col">
                            <label>Weight Status</label>
                            <input class="auto-field" value="Pending" readonly tabindex="-1" aria-readonly="true">
                            <span class="form-hint">Calculated automatically once the child growth reference is confirmed.</span>
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
    <?php $nextMilestone = next_milestone(get_milestone_records($pdo, $id)); ?>
    <?php if ($nextMilestone): ?>
    <div class="form-actions" style="margin-top:10px;">
        <a class="btn primary sm" href="health_record.php?beneficiary_id=<?= $id ?>&milestone=<?= e($nextMilestone) ?>" data-slideover="health_record.php?panel=1&beneficiary_id=<?= $id ?>&milestone=<?= e($nextMilestone) ?>" data-slideover-title="Record <?= e(milestone_label($nextMilestone)) ?>">Record <?= e(milestone_label($nextMilestone)) ?></a>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
