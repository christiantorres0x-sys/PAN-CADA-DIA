<?php
require_once __DIR__ . '/db.php';
require_login();

$beneficiaryId = (int)($_GET['beneficiary_id'] ?? $_POST['beneficiary_id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM beneficiaries WHERE beneficiary_id=?");
$stmt->execute([$beneficiaryId]);
$b = $stmt->fetch();
if (!$b) exit('Beneficiary not found.');

$milestoneRecords = get_milestone_records($pdo, $beneficiaryId);
$suggested = next_milestone($milestoneRecords);
$allMilestonesDone = $suggested === null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $milestone = strtoupper(trim($_POST['milestone'] ?? ''));
    $date = $_POST['record_date'] ?? date('Y-m-d');
    $height = (float)($_POST['height_cm'] ?? 0);
    $weight = (float)($_POST['weight_kg'] ?? 0);
    $remarks = trim($_POST['remarks'] ?? '');

    if (!in_array($milestone, MILESTONES, true)) {
        flash('danger', 'Please select which milestone this measurement is for.');
        header('Location: health_record.php?beneficiary_id=' . $beneficiaryId);
        exit;
    }

    if ($milestoneRecords[$milestone]) {
        flash('danger', milestone_label($milestone) . ' has already been recorded for this child.');
        header('Location: health_record.php?beneficiary_id=' . $beneficiaryId);
        exit;
    }

    if ($height <= 0 || $weight <= 0) {
        flash('danger', 'Height and weight must be greater than zero.');
        header('Location: health_record.php?beneficiary_id=' . $beneficiaryId . '&milestone=' . $milestone);
        exit;
    }

    $bmi = calculate_bmi($weight, $height);

    // Deliberately no adult BMI classification.
    // Replace this with the final CCES/WHO child reference after age-range confirmation.
    $status = null;

    $stmt = $pdo->prepare("
        INSERT INTO health_records
        (beneficiary_id, record_date, milestone, height_cm, weight_kg, bmi, nutritional_status, remarks, recorded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$beneficiaryId, $date, $milestone, $height, $weight, $bmi, $status, $remarks, $_SESSION['user_id']]);

    $recordId = (int)$pdo->lastInsertId();
    log_action($pdo, 'CREATE_HEALTH_RECORD', 'health_records', $recordId, milestone_label($milestone) . ' for beneficiary ID ' . $beneficiaryId . '; BMI ' . $bmi);

    $gap = milestone_prerequisite_gap($milestone, $milestoneRecords);
    $msg = milestone_label($milestone) . ' recorded. BMI: ' . $bmi . '. Child classification is pending the confirmed age/sex reference.';
    if ($gap) {
        $msg .= ' Note: no ' . milestone_label($gap) . ' is on file for this child, so this can\'t be compared against it yet.';
    }
    flash($gap ? 'danger' : 'success', $msg);
    header('Location: beneficiary.php?id=' . $beneficiaryId);
    exit;
}

$pageTitle = 'Record Measurement';
$pageSubtitle = 'Record a new height/weight measurement';
require __DIR__ . '/partials/header.php';
?>
<section class="panel form-panel">
    <div class="person-banner">
        <strong><?= e($b['full_name']) ?></strong>
        <span><?= e($b['birth_date']) ?> · <?= e($b['sex']) ?></span>
    </div>

    <div class="pill-group" style="margin-bottom:10px;">
        <?php foreach (MILESTONES as $m): ?>
            <?= milestone_pill($m, $milestoneRecords) ?>
        <?php endforeach; ?>
    </div>

    <?php if ($allMilestonesDone): ?>
        <p class="empty">Baseline, Midline, and Endline have all been recorded for this child.</p>
        <div class="form-actions">
            <a class="btn secondary" href="beneficiary.php?id=<?= $beneficiaryId ?>">Back to Profile</a>
        </div>
    <?php else:
        $requested = strtoupper($_GET['milestone'] ?? '');
        $selectedMilestone = (in_array($requested, MILESTONES, true) && !$milestoneRecords[$requested]) ? $requested : $suggested;
    ?>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="beneficiary_id" value="<?= $beneficiaryId ?>">
        <div class="form-grid">
            <div>
                <label>Milestone *</label>
                <select id="milestone_select" name="milestone" required>
                    <?php foreach (MILESTONES as $m):
                        if ($milestoneRecords[$m]) continue; // already recorded, not selectable
                        $gap = milestone_prerequisite_gap($m, $milestoneRecords);
                    ?>
                        <option value="<?= $m ?>" data-gap="<?= $gap ? e(milestone_label($gap)) : '' ?>" <?= $m === $selectedMilestone ? 'selected' : '' ?>>
                            <?= e(milestone_label($m)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Measurement Date *</label>
                <input type="date" name="record_date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div>
                <label>Height (cm) *</label>
                <input id="height_cm" type="number" name="height_cm" min="40" max="220" step="0.1" inputmode="decimal" placeholder="e.g. 115" required>
            </div>
            <div>
                <label>Weight (kg) *</label>
                <input id="weight_kg" type="number" name="weight_kg" min="3" max="200" step="0.1" inputmode="decimal" placeholder="e.g. 20.5" required>
            </div>
            <div>
                <label>Calculated BMI</label>
                <input id="bmi_preview" readonly placeholder="Enter height and weight">
            </div>
            <div class="full-col">
                <label>Remarks</label>
                <textarea name="remarks" placeholder="Optional notes"></textarea>
            </div>
        </div>
        <div id="milestone_warning" class="notice" style="display:none;"></div>
        <div class="notice">
            <strong>About BMI:</strong> BMI = weight (kg) ÷ height² (m²). For children, the weight status is set from an age- and sex-specific reference, not adult BMI ranges, so it shows as Pending for now.
        </div>
        <div class="form-actions">
            <a class="btn secondary" href="beneficiary.php?id=<?= $beneficiaryId ?>">Cancel</a>
            <button class="btn primary">Save Measurement</button>
        </div>
    </form>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
