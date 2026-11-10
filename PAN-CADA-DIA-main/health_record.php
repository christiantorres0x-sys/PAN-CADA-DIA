<?php
/**
 * Record a growth milestone (Baseline / Midline / Endline) or a plain
 * measurement for one beneficiary.
 *
 *  - ?beneficiary_id=ID            -> milestone preselected to the next one not yet recorded
 *  - ?beneficiary_id=ID&milestone= -> that milestone preselected (from the profile's milestone slots)
 *  - Only Baseline, Midline and Endline can be recorded - once each per child.
 *    There are no extra/"no milestone" measurements, and once all three are
 *    on file nothing more can be added (enforced here on the server too).
 *    To fix a saved one, use the Edit button (health_record_edit.php).
 *  - Soft workflow: recording Midline/Endline without an earlier milestone is
 *    allowed, the form just warns about the gap (see milestone_prerequisite_gap()).
 *  - BMI is always recalculated server-side from the converted cm / kg values.
 * Opens as a slide-over popup (?panel=1, JSON replies) from the profile, or as a normal page.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/bmi_status.php';
require_login();

$beneficiaryId = (int)($_GET['beneficiary_id'] ?? $_POST['beneficiary_id'] ?? 0);
$isAjax = is_ajax_request();

$stmt = $pdo->prepare("SELECT beneficiary_id, full_name, birth_date, program_status FROM beneficiaries WHERE beneficiary_id = ?");
$stmt->execute([$beneficiaryId]);
$b = $stmt->fetch();
if (!$b) {
    if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'POST') json_response(['ok' => false, 'message' => 'That beneficiary no longer exists.']);
    exit('Beneficiary not found.');
}

$profileUrl = 'beneficiary.php?id=' . $beneficiaryId . '#health';
$milestoneRecords = get_milestone_records($pdo, $beneficiaryId);

// Which milestone is preselected: the one in the link, else the next one due.
$requested = strtoupper(trim((string)($_GET['milestone'] ?? $_POST['milestone'] ?? '')));
$nextDue = next_milestone($milestoneRecords);   // null once all three are recorded
$selected = (in_array($requested, MILESTONES, true) && empty($milestoneRecords[$requested])) ? $requested : ($nextDue ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $milestone = strtoupper(trim((string)($_POST['milestone'] ?? '')));
    $milestone = $milestone === '' ? null : $milestone;
    [$height, $heightErr] = parse_height_cm($_POST);
    [$weight, $weightErr] = parse_weight_kg($_POST);
    $remarks = capitalize_sentences(trim($_POST['remarks'] ?? ''));
    $newStatus = normalize_beneficiary_status($_POST['program_status'] ?? '', $b['program_status']);

    $date = trim((string)($_POST['record_date'] ?? ''));
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    $error = null;
    if ($nextDue === null) {
        $error = 'Baseline, Midline and Endline are all recorded for this child. No further measurements can be added.';
    } elseif ($milestone === null || !in_array($milestone, MILESTONES, true)) {
        $error = 'Please choose a milestone (Baseline, Midline or Endline).';
    } elseif (!empty($milestoneRecords[$milestone])) {
        $error = milestone_label($milestone) . ' is already recorded for this child. Use Edit on the existing record to correct it.';
    } elseif (!$d || $d->format('Y-m-d') !== $date) {
        $error = 'Please enter a valid measurement date.';
    } elseif ($date > date('Y-m-d')) {
        $error = 'The measurement date cannot be in the future.';
    } elseif ($b['birth_date'] && $date < $b['birth_date']) {
        $error = 'The measurement date cannot be before the child\'s birthdate.';
    } elseif ($heightErr !== null || $weightErr !== null) {
        $error = $heightErr ?? $weightErr;
    } elseif ($height === null || $weight === null) {
        $error = 'Please enter both height and weight.';
    }

    if ($error === null) {
        $bmi = calculate_bmi($weight, $height);
        $status = pcd_weight_status($bmi);   // Asian BMI cut-offs, set automatically
        try {
            $pdo->beginTransaction();
            $ins = $pdo->prepare("
                INSERT INTO health_records
                (beneficiary_id, record_date, milestone, height_cm, weight_kg, bmi, nutritional_status, record_status, remarks, recorded_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $ins->execute([$beneficiaryId, $date, $milestone, $height, $weight, $bmi, $status, $newStatus, $remarks, $_SESSION['user_id'] ?? null]);
            $newId = (int)$pdo->lastInsertId();
            log_action($pdo, 'CREATE_HEALTH_RECORD', 'health_records', $newId,
                ($milestone ? milestone_label($milestone) . ' ' : '') . 'measurement recorded for beneficiary ID ' . $beneficiaryId
                . ': ' . $height . ' cm, ' . $weight . ' kg, BMI ' . $bmi);
            // Status is stored on this milestone; the overall status follows the latest one.
            sync_beneficiary_status($pdo, $beneficiaryId);
            if ($newStatus !== $b['program_status']) {
                log_action($pdo, 'UPDATE_BENEFICIARY_STATUS', 'beneficiaries', $beneficiaryId,
                    'Status ' . $b['program_status'] . ' -> ' . $newStatus . ' (set while recording ' . ($milestone ? milestone_label($milestone) : 'a measurement') . ', dated ' . $date . ')');
            }
            $pdo->commit();
            $label = $milestone ? milestone_label($milestone) : 'Measurement';
            flash('success', $label . ' recorded. Weight ' . $weight . ' kg, height ' . $height . ' cm, BMI ' . $bmi . '.');
            if ($isAjax) json_response(['ok' => true, 'redirect' => 'beneficiary.php?id=' . $beneficiaryId]);
            header('Location: ' . $profileUrl);
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            // 23000 = duplicate (beneficiary, milestone), e.g. two people saving at once.
            $error = $e->getCode() === '23000'
                ? 'That milestone was just recorded by someone else. Reload the profile to see it.'
                : 'Could not save the measurement. Please try again.';
        }
    }

    flash('danger', $error);
    if ($isAjax) json_response(['ok' => false, 'message' => $error]);
    $selected = $nextDue === null ? '' : ($milestone ?? $nextDue);   // redraw the form on the same milestone
}

$pageTitle = $selected ? 'Record ' . milestone_label($selected) : 'Milestones Complete';
$pageSubtitle = 'Record a milestone height and weight measurement';
$pageEyebrowHtml = '<a class="back-link" href="beneficiary.php?id=' . $beneficiaryId . '">&larr; Back to Beneficiary Profile</a>';
require __DIR__ . '/partials/header.php';

$keep = fn(string $k, string $default = '') => $_SERVER['REQUEST_METHOD'] === 'POST' ? (string)($_POST[$k] ?? $default) : $default;
?>
<div class="form-panel">
    <div class="person-banner">
        <strong><?= e($b['full_name']) ?></strong>
        <span><?= $selected ? e(milestone_label($selected)) . ' measurement' : 'Milestone measurements' ?></span>
    </div>

    <?php if ($selected === ''): ?>
        <div class="notice">Baseline, Midline and Endline are all recorded for this child, so no more measurements can be added. To correct a saved one, use Edit in the Health History table.</div>
        <div class="form-actions">
            <a class="btn secondary" href="<?= e($profileUrl) ?>">Back to Profile</a>
        </div>
    <?php else: ?>
    <form method="post" action="health_record.php?beneficiary_id=<?= $beneficiaryId ?><?= ($_GET['panel'] ?? '') === '1' ? '&amp;panel=1' : '' ?>">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="beneficiary_id" value="<?= $beneficiaryId ?>">
        <div class="form-grid">
            <div>
                <label>Milestone</label>
                <select name="milestone" id="milestone_select">
                    <?php foreach (MILESTONES as $m):
                        $done = !empty($milestoneRecords[$m]);
                        $gap = $done ? null : milestone_prerequisite_gap($m, $milestoneRecords); ?>
                        <option value="<?= $m ?>" <?= $selected === $m ? 'selected' : '' ?> <?= $done ? 'disabled' : '' ?>
                            data-gap="<?= $gap ? e(milestone_label($gap)) : '' ?>"><?= e(milestone_label($m)) ?><?= $done ? ' (already recorded)' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Date Measured *</label>
                <input type="date" name="record_date" value="<?= e($keep('record_date', date('Y-m-d'))) ?>" min="<?= e($b['birth_date']) ?>" max="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="full-col">
                <div id="milestone_warning" class="notice" style="display:none;"></div>
            </div>
            <?php $mPrefix = ''; $mRequired = true; require __DIR__ . '/partials/measurement_fields.php'; ?>
            <div>
                <label>Calculated BMI</label>
                <input id="bmi_preview" readonly placeholder="Enter height and weight">
            </div>
            <div>
                <label>Weight Status</label>
                <input id="status_preview" readonly placeholder="Calculated from BMI">
                <span class="form-hint">Set automatically from BMI using the Asian BMI cut-offs.</span>
            </div>
            <div>
                <label for="beneficiary_status">Status</label>
                <?php $curStatus = normalize_beneficiary_status($keep('program_status', $b['program_status']), $b['program_status']); ?>
                <select name="program_status" id="beneficiary_status">
                    <?php foreach (BENEFICIARY_STATUSES as $st): ?>
                        <option value="<?= $st ?>" <?= $curStatus === $st ? 'selected' : '' ?>><?= e(beneficiary_status_label($st)) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="form-hint">Overall status of this beneficiary.</span>
            </div>
            <div class="full-col">
                <label>Remarks</label>
                <textarea name="remarks" class="remarks-input" data-autogrow rows="2"><?= e($keep('remarks')) ?></textarea>
            </div>
        </div>
        <div class="form-actions">
            <a class="btn secondary cancel-button" href="beneficiary.php?id=<?= $beneficiaryId ?>#health">Cancel</a>
            <button class="btn primary">Save Measurement</button>
        </div>
    </form>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
