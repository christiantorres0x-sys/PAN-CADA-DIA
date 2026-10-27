<?php
/**
 * Edit one saved health measurement (weight, height, remarks, weight status
 * and - for Admin accounts only - the measurement date).
 *
 * Safety rules:
 *  - The UPDATE targets exactly one row (record_id AND beneficiary_id); the
 *    milestone and every other record are never touched.
 *  - Values are typed in the selected unit and converted once to kg / cm
 *    (parse_weight_kg / parse_height_cm); BMI is recomputed from the stored
 *    values, so it always matches the saved height and weight.
 *  - The original date is kept unless the signed-in user is allowed to change
 *    it (can_change_measurement_date()).
 *  - A fingerprint of the record (orig_token) is posted back; if the record
 *    changed while the form was open the save is refused instead of
 *    overwriting someone else's edit.
 *  - Every change is written to the audit log with old -> new values.
 * Works as a slide-over (?panel=1, JSON replies) or as a normal page.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/bmi_status.php';
require_login();

$recordId = (int)($_GET['id'] ?? $_POST['record_id'] ?? 0);
$isAjax = is_ajax_request();

function pcd_edit_fail(string $message, ?string $redirect, bool $isAjax): void {
    flash('danger', $message);
    if ($isAjax) json_response(['ok' => false, 'message' => $message]);
    if ($redirect) { header('Location: ' . $redirect); exit; }
}

$load = $pdo->prepare("
    SELECT hr.*, b.full_name, b.birth_date, b.program_status
    FROM health_records hr
    JOIN beneficiaries b ON b.beneficiary_id = hr.beneficiary_id
    WHERE hr.record_id = ?
");
$load->execute([$recordId]);
$rec = $load->fetch();
if (!$rec) {
    if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'POST') json_response(['ok' => false, 'message' => 'That measurement no longer exists.']);
    exit('Measurement not found.');
}
$beneficiaryId = (int)$rec['beneficiary_id'];
$profileUrl = 'beneficiary.php?id=' . $beneficiaryId . '#health';

if (!can_edit_health_records()) {
    pcd_edit_fail('You are not allowed to edit health measurements.', $profileUrl, $isAjax);
    exit;
}
$canDate = can_change_measurement_date();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $self = 'health_record_edit.php?id=' . $recordId;

    [$height, $heightErr] = parse_height_cm($_POST);
    [$weight, $weightErr] = parse_weight_kg($_POST);
    $remarks = capitalize_sentences(trim($_POST['remarks'] ?? ''));
    // Status AT THIS MILESTONE (stored on the record, so other milestones keep theirs).
    $oldRecStatus = $rec['record_status'] ?: $rec['program_status'];
    $newStatus = normalize_beneficiary_status($_POST['program_status'] ?? '', $oldRecStatus);

    // Date: keep the original unless this user may change it.
    $newDate = $rec['record_date'];
    $dateErr = null;
    if ($canDate) {
        $postedDate = trim($_POST['record_date'] ?? '');
        if ($postedDate !== '' && $postedDate !== $rec['record_date']) {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $postedDate);
            if (!$d || $d->format('Y-m-d') !== $postedDate) {
                $dateErr = 'Please enter a valid measurement date.';
            } elseif ($postedDate > date('Y-m-d')) {
                $dateErr = 'The measurement date cannot be in the future.';
            } elseif ($rec['birth_date'] && $postedDate < $rec['birth_date']) {
                $dateErr = 'The measurement date cannot be before the child\'s birthdate.';
            } else {
                $newDate = $postedDate;
            }
        }
    }

    $error = $heightErr ?? $weightErr ?? $dateErr;
    if ($error === null && ($height === null || $weight === null)) {
        $error = 'Please enter both height and weight.';
    }
    if ($error === null && ($_POST['orig_token'] ?? '') !== health_record_token($rec)) {
        $error = 'This measurement was changed by someone else while you were editing. Close this form and open it again to see the latest values.';
    }

    if ($error !== null) {
        flash('danger', $error);
        if ($isAjax) json_response(['ok' => false, 'message' => $error]);
    } else {
        $bmi = calculate_bmi($weight, $height);
        $status = pcd_weight_status($bmi);   // Asian BMI cut-offs, recalculated on every save
        $changed = [];
        if ($newDate !== $rec['record_date']) $changed[] = 'date ' . $rec['record_date'] . ' -> ' . $newDate;
        if ((float)$rec['height_cm'] !== $height) $changed[] = 'height ' . $rec['height_cm'] . ' -> ' . $height . ' cm';
        if ((float)$rec['weight_kg'] !== $weight) $changed[] = 'weight ' . $rec['weight_kg'] . ' -> ' . $weight . ' kg';
        if ((float)$rec['bmi'] !== $bmi) $changed[] = 'BMI ' . $rec['bmi'] . ' -> ' . $bmi;
        if ((string)$rec['nutritional_status'] !== (string)$status) $changed[] = 'weight status ' . ($rec['nutritional_status'] ?? 'Pending') . ' -> ' . ($status ?? 'Pending');
        if ((string)$rec['remarks'] !== $remarks) $changed[] = 'remarks edited';

        $statusChanged = $newStatus !== $oldRecStatus;
        if (!$changed && !$statusChanged) {
            flash('success', 'No changes to save.');
        } else {
            if ($changed) {
                // One row only: this record, and only if it still belongs to this child.
                $upd = $pdo->prepare("UPDATE health_records SET record_date=?, height_cm=?, weight_kg=?, bmi=?, nutritional_status=?, remarks=? WHERE record_id=? AND beneficiary_id=?");
                $upd->execute([$newDate, $height, $weight, $bmi, $status, $remarks, $recordId, $beneficiaryId]);
                if ($newDate !== $rec['record_date']) sync_beneficiary_status($pdo, $beneficiaryId);
                log_action($pdo, 'UPDATE_HEALTH_RECORD', 'health_records', $recordId,
                    ($rec['milestone'] ? milestone_label($rec['milestone']) . ' ' : '') . 'record edited for beneficiary ID ' . $beneficiaryId . ': ' . implode('; ', $changed));
            }
            // The Status field belongs to THIS milestone only. The child's overall
            // status then follows the most recent milestone, and the date they
            // became inactive is tracked (beneficiaries.inactive_since).
            if ($statusChanged) {
                $pdo->prepare("UPDATE health_records SET record_status = ? WHERE record_id = ? AND beneficiary_id = ?")->execute([$newStatus, $recordId, $beneficiaryId]);
                sync_beneficiary_status($pdo, $beneficiaryId);
                log_action($pdo, 'UPDATE_BENEFICIARY_STATUS', 'health_records', $recordId,
                    ($rec['milestone'] ? milestone_label($rec['milestone']) . ' ' : '') . 'status ' . $oldRecStatus . ' -> ' . $newStatus . ' for beneficiary ID ' . $beneficiaryId . ' (measurement dated ' . $newDate . ')');
            }
            flash('success', $changed
                ? 'Measurement updated. Weight ' . $weight . ' kg, height ' . $height . ' cm, BMI ' . $bmi . '.' . ($statusChanged ? ' Status is now ' . beneficiary_status_label($newStatus) . '.' : '')
                : 'Status updated to ' . beneficiary_status_label($newStatus) . '.');
        }
        if ($isAjax) json_response(['ok' => true, 'redirect' => 'beneficiary.php?id=' . $beneficiaryId]);
        header('Location: ' . $profileUrl);
        exit;
    }
    // Non-AJAX validation failure: fall through and redraw the form with the saved values.
}

$title = $rec['milestone'] ? milestone_label($rec['milestone']) . ' Measurement' : 'Measurement';
$pageTitle = 'Edit ' . $title;
$pageSubtitle = 'Correct a previously recorded measurement';
$pageEyebrowHtml = '<a class="back-link" href="beneficiary.php?id=' . $beneficiaryId . '">&larr; Back to Beneficiary Profile</a>';
require __DIR__ . '/partials/header.php';
?>
<div class="form-panel">
    <div class="person-banner">
        <strong><?= e($rec['full_name']) ?></strong>
        <span><?= e($title) ?> · recorded <?= e(format_display_date($rec['record_date'])) ?></span>
    </div>

    <form method="post" action="health_record_edit.php?id=<?= $recordId ?>">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="record_id" value="<?= $recordId ?>">
        <input type="hidden" name="orig_token" value="<?= e(health_record_token($rec)) ?>">
        <div class="form-grid">
            <div>
                <label>Milestone</label>
                <input value="<?= e($rec['milestone'] ? milestone_label($rec['milestone']) : 'None') ?>" readonly tabindex="-1">
            </div>
            <div>
                <label>Measurement Date</label>
                <?php if ($canDate): ?>
                    <input type="date" name="record_date" value="<?= e($rec['record_date']) ?>" min="<?= e($rec['birth_date']) ?>" max="<?= date('Y-m-d') ?>">
                <?php else: ?>
                    <input value="<?= e(format_display_date($rec['record_date'])) ?>" readonly tabindex="-1">
                    <span class="form-hint">Only an administrator can change the measurement date.</span>
                <?php endif; ?>
            </div>
            <?php $mPrefix = ''; $mRequired = true; $mHeightCm = (float)$rec['height_cm']; $mWeightKg = (float)$rec['weight_kg']; require __DIR__ . '/partials/measurement_fields.php'; ?>
            <div>
                <label>Calculated BMI</label>
                <input id="bmi_preview" readonly value="<?= e($rec['bmi']) ?>">
            </div>
            <div>
                <label>Weight Status</label>
                <input id="status_preview" readonly value="<?= e(pcd_weight_status((float)$rec['bmi'])) ?>">
            </div>
            <div>
                <label for="beneficiary_status">Status</label>
                <select name="program_status" id="beneficiary_status">
                    <?php foreach (BENEFICIARY_STATUSES as $st): ?>
                        <option value="<?= $st ?>" <?= ($rec['record_status'] ?: $rec['program_status']) === $st ? 'selected' : '' ?>><?= e(beneficiary_status_label($st)) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="form-hint">Status at this milestone. Other milestones keep their own status; the date a child became inactive is tracked.</span>
            </div>
            <div class="full-col">
                <label>Remarks</label>
                <textarea name="remarks" class="remarks-input" data-autogrow rows="2"><?= e($rec['remarks']) ?></textarea>
            </div>
        </div>
        <div class="notice">
            Saving recalculates BMI and the weight status (Asian BMI cut-offs) from the new height and weight. Other measurements for this child are not affected.
        </div>
        <div class="form-actions">
            <a class="btn secondary cancel-button" href="beneficiary.php?id=<?= $beneficiaryId ?>#health">Cancel</a>
            <button class="btn primary">Save Changes</button>
        </div>
    </form>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
