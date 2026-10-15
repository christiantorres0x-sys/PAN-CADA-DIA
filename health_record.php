<?php
/**
 * Record a growth milestone (Baseline / Midline / Endline) or a plain
 * measurement for one beneficiary.
 *
 *  - ?beneficiary_id=ID            -> milestone preselected to the next one not yet recorded
 *  - ?beneficiary_id=ID&milestone= -> that milestone preselected (from the profile's milestone slots)
 *  - Each milestone can only be recorded once per child (unique key in the DB);
 *    to fix a saved one, use the Edit button (health_record_edit.php).
 *  - Soft workflow: recording Midline/Endline without an earlier milestone is
 *    allowed, the form just warns about the gap (see milestone_prerequisite_gap()).
 *  - BMI is always recalculated server-side from the converted cm / kg values.
 * Works as a slide-over (?panel=1, JSON replies) or as a normal page.
 */
require_once __DIR__ . '/db.php';
require_login();

$beneficiaryId = (int)($_GET['beneficiary_id'] ?? $_POST['beneficiary_id'] ?? 0);
$isAjax = is_ajax_request();

$stmt = $pdo->prepare("SELECT beneficiary_id, full_name, birth_date FROM beneficiaries WHERE beneficiary_id = ?");
$stmt->execute([$beneficiaryId]);
$b = $stmt->fetch();
if (!$b) {
    if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'POST') json_response(['ok' => false, 'message' => 'That beneficiary no longer exists.']);
    exit('Beneficiary not found.');
}

$profileUrl = 'beneficiary.php?id=' . $beneficiaryId . '#health';
$milestoneRecords = get_milestone_records($pdo, $beneficiaryId);
$statusOptions = ['Severely Underweight', 'Underweight', 'Normal', 'Overweight', 'Obese'];

// Which milestone is preselected: the one in the link, else the next one due.
$requested = strtoupper(trim((string)($_GET['milestone'] ?? $_POST['milestone'] ?? '')));
$selected = in_array($requested, MILESTONES, true) ? $requested : (next_milestone($milestoneRecords) ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $milestone = strtoupper(trim((string)($_POST['milestone'] ?? '')));
    $milestone = $milestone === '' ? null : $milestone;
    [$height, $heightErr] = parse_height_cm($_POST);
    [$weight, $weightErr] = parse_weight_kg($_POST);
    $remarks = capitalize_sentences(trim($_POST['remarks'] ?? ''));
    $status = trim($_POST['weight_status'] ?? '');
    $status = in_array($status, $statusOptions, true) ? $status : null;

    $date = trim((string)($_POST['record_date'] ?? ''));
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    $error = null;
    if ($milestone !== null && !in_array($milestone, MILESTONES, true)) {
        $error = 'Please choose a valid milestone.';
    } elseif ($milestone !== null && !empty($milestoneRecords[$milestone])) {
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
        try {
            $ins = $pdo->prepare("
                INSERT INTO health_records
                (beneficiary_id, record_date, milestone, height_cm, weight_kg, bmi, nutritional_status, remarks, recorded_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $ins->execute([$beneficiaryId, $date, $milestone, $height, $weight, $bmi, $status, $remarks, $_SESSION['user_id'] ?? null]);
            $newId = (int)$pdo->lastInsertId();
            log_action($pdo, 'CREATE_HEALTH_RECORD', 'health_records', $newId,
                ($milestone ? milestone_label($milestone) . ' ' : '') . 'measurement recorded for beneficiary ID ' . $beneficiaryId
                . ': ' . $height . ' cm, ' . $weight . ' kg, BMI ' . $bmi);
            $label = $milestone ? milestone_label($milestone) : 'Measurement';
            flash('success', $label . ' recorded. Weight ' . $weight . ' kg, height ' . $height . ' cm, BMI ' . $bmi . '.');
            if ($isAjax) json_response(['ok' => true, 'redirect' => 'beneficiary.php?id=' . $beneficiaryId]);
            header('Location: ' . $profileUrl);
            exit;
        } catch (PDOException $e) {
            // 23000 = duplicate (beneficiary, milestone), e.g. two people saving at once.
            $error = $e->getCode() === '23000'
                ? 'That milestone was just recorded by someone else. Reload the profile to see it.'
                : 'Could not save the measurement. Please try again.';
        }
    }

    flash('danger', $error);
    if ($isAjax) json_response(['ok' => false, 'message' => $error]);
    $selected = $milestone ?? '';   // redraw the form on the same milestone
}

$pageTitle = $selected ? 'Record ' . milestone_label($selected) : 'Record Measurement';
$pageSubtitle = 'Add a height and weight measurement';
$pageEyebrowHtml = '<a class="back-link" href="beneficiary.php?id=' . $beneficiaryId . '">&larr; Back to Beneficiary Profile</a>';
require __DIR__ . '/partials/header.php';

$keep = fn(string $k, string $default = '') => $_SERVER['REQUEST_METHOD'] === 'POST' ? (string)($_POST[$k] ?? $default) : $default;
?>
<div class="form-panel">
    <div class="person-banner">
        <strong><?= e($b['full_name']) ?></strong>
        <span>New measurement</span>
    </div>

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
                    <option value="" <?= $selected === '' ? 'selected' : '' ?>>No milestone (extra measurement)</option>
                </select>
            </div>
            <div>
                <label>Date of Measurement *</label>
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
                <select name="weight_status">
                    <option value="">Pending</option>
                    <?php foreach ($statusOptions as $o): ?>
                        <option <?= $keep('weight_status') === $o ? 'selected' : '' ?>><?= e($o) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="form-hint">Leave as Pending until the child growth reference is confirmed.</span>
            </div>
            <div class="full-col">
                <label>Remarks</label>
                <textarea name="remarks" class="remarks-input" data-autogrow rows="2"><?= e($keep('remarks')) ?></textarea>
            </div>
        </div>
        <div class="form-actions">
            <a class="btn secondary" href="beneficiary.php?id=<?= $beneficiaryId ?>#health">Cancel</a>
            <button class="btn primary">Save Measurement</button>
        </div>
    </form>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
