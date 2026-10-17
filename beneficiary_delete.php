<?php
/**
 * Delete a beneficiary - safely.
 *
 * Why this is not a plain DELETE: attendance_records and health_records are
 * defined with ON DELETE CASCADE, so removing a beneficiary row would silently
 * erase their whole attendance and measurement history and change every
 * report total. So:
 *
 *   1. DEACTIVATE (default, always available): sets program_status to
 *      INACTIVE. The child leaves the daily attendance list, but their
 *      profile, measurements, attendance history and past report figures
 *      are all kept. It can be undone from Edit Profile (Status).
 *   2. PERMANENT DELETE: only allowed when the profile has NO health
 *      measurements and NO attendance records (e.g. a duplicate or a
 *      mistaken entry), so nothing can be lost. The rule is enforced in the
 *      DELETE statement itself, not just in the form.
 *
 * Only Admin accounts may use either action (can_delete_beneficiaries()).
 * Nothing happens on the Delete button click - the user must confirm here.
 */
require_once __DIR__ . '/db.php';
require_login();

$id = (int)($_GET['id'] ?? $_POST['beneficiary_id'] ?? 0);
$isAjax = is_ajax_request();

function pcd_delete_reply(bool $ok, string $message, string $redirect, bool $isAjax): void {
    flash($ok ? 'success' : 'danger', $message);
    if ($isAjax) json_response(['ok' => $ok, 'message' => $message, 'redirect' => $redirect]);
    header('Location: ' . $redirect);
    exit;
}

$load = $pdo->prepare("
    SELECT b.*, s.site_name, p.program_name
    FROM beneficiaries b
    LEFT JOIN feeding_sites s ON s.site_id = b.site_id
    LEFT JOIN feeding_programs p ON p.program_id = b.program_id
    WHERE b.beneficiary_id = ?
");
$load->execute([$id]);
$b = $load->fetch();
if (!$b) {
    if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'POST') {
        pcd_delete_reply(false, 'That beneficiary no longer exists.', 'beneficiaries.php', true);
    }
    exit('Beneficiary not found.');
}
$profileUrl = 'beneficiary.php?id=' . $id;

if (!can_delete_beneficiaries()) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(403);
        exit('Only an administrator can delete or deactivate a beneficiary.');
    }
    http_response_code(403);
    pcd_delete_reply(false, 'Only an administrator can delete or deactivate a beneficiary.', $profileUrl, $isAjax);
}

function pcd_count(PDO $pdo, string $table, int $id): int {
    $s = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE beneficiary_id = ?");
    $s->execute([$id]);
    return (int)$s->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $label = $b['full_name'] . ($b['beneficiary_code'] ? ' (' . $b['beneficiary_code'] . ')' : '');

    try {
        if ($action === 'deactivate') {
            if ($b['program_status'] === 'INACTIVE') {
                pcd_delete_reply(true, $label . ' is already inactive.', $profileUrl, $isAjax);
            }
            $upd = $pdo->prepare("UPDATE beneficiaries SET program_status='INACTIVE' WHERE beneficiary_id=? AND program_status<>'INACTIVE'");
            $upd->execute([$id]);
            log_action($pdo, 'DEACTIVATE_BENEFICIARY', 'beneficiaries', $id,
                'Deactivated ' . $label . ' (was ' . $b['program_status'] . '); health and attendance history kept');
            pcd_delete_reply(true, $label . ' was deactivated. Their records and history were kept.', $profileUrl, $isAjax);

        } elseif ($action === 'delete') {
            if (($_POST['confirm_permanent'] ?? '') !== '1') {
                pcd_delete_reply(false, 'Please tick the box to confirm the permanent deletion.', $profileUrl, $isAjax);
            }
            // The "no records" rule lives in the statement itself, so it holds
            // even if a record is added between opening this form and saving.
            $del = $pdo->prepare("
                DELETE FROM beneficiaries
                WHERE beneficiary_id = ?
                  AND NOT EXISTS (SELECT 1 FROM health_records WHERE beneficiary_id = ?)
                  AND NOT EXISTS (SELECT 1 FROM attendance_records WHERE beneficiary_id = ?)
            ");
            $del->execute([$id, $id, $id]);
            if ($del->rowCount() === 0) {
                pcd_delete_reply(false, 'This beneficiary has health or attendance records, so it cannot be permanently deleted. Deactivate it instead.', $profileUrl, $isAjax);
            }
            log_action($pdo, 'DELETE_BENEFICIARY', 'beneficiaries', $id,
                'Permanently deleted ' . $label . ' (no health or attendance records existed)');
            pcd_delete_reply(true, $label . ' was permanently deleted.', 'beneficiaries.php', $isAjax);

        } else {
            pcd_delete_reply(false, 'Please choose what to do with this beneficiary.', $profileUrl, $isAjax);
        }
    } catch (PDOException $e) {
        // Most likely a foreign-key rule refusing the delete: nothing was changed.
        pcd_delete_reply(false, 'The database refused this change, so nothing was deleted. Deactivate the beneficiary instead.', $profileUrl, $isAjax);
    }
}

$healthCount = pcd_count($pdo, 'health_records', $id);
$attendanceCount = pcd_count($pdo, 'attendance_records', $id);
$hasHistory = ($healthCount + $attendanceCount) > 0;
$alreadyInactive = $b['program_status'] === 'INACTIVE';

$pageTitle = 'Delete Beneficiary';
$pageEyebrowHtml = '<a class="back-link" href="' . e($profileUrl) . '">&larr; Back to Beneficiary Profile</a>';
require __DIR__ . '/partials/header.php';
?>
<div class="form-panel">
    <div class="person-banner">
        <strong><?= e($b['full_name']) ?></strong>
        <span><?= $b['beneficiary_code'] ? 'ID: ' . e($b['beneficiary_code']) . ' · ' : '' ?><?= e($b['site_name'] ?? 'No site') ?> · <?= e($b['program_name'] ?? 'No program') ?></span>
    </div>

    <div class="notice danger">
        <strong>Before you continue:</strong> this beneficiary has
        <strong><?= $healthCount ?></strong> health measurement<?= $healthCount === 1 ? '' : 's' ?> and
        <strong><?= $attendanceCount ?></strong> attendance record<?= $attendanceCount === 1 ? '' : 's' ?>.
        Permanently deleting a beneficiary would also erase those records and change report totals.
    </div>

    <form method="post" action="beneficiary_delete.php?id=<?= $id ?>">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="beneficiary_id" value="<?= $id ?>">

        <?php if ($hasHistory): ?>
            <p>Because there is history on file, this profile can't be permanently deleted. It will be <strong>deactivated</strong> instead:</p>
            <ul class="delete-facts">
                <li>Status changes to <strong>Inactive</strong>.</li>
                <li>They no longer appear on the daily attendance list.</li>
                <li>Their profile, measurements, attendance history and past report figures are <strong>kept</strong>.</li>
                <li>The Enrolled count on the dashboard goes down by one; the total stays the same.</li>
                <li>You can reactivate them any time from Edit Profile (Status).</li>
            </ul>
            <input type="hidden" name="action" value="deactivate">
            <div class="form-actions">
                <a class="btn secondary cancel-button" href="<?= e($profileUrl) ?>">Cancel</a>
                <?php if ($alreadyInactive): ?>
                    <span class="muted">Already inactive.</span>
                <?php else: ?>
                    <button class="btn danger">Deactivate <?= e($b['first_name'] ?: 'Beneficiary') ?></button>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <p>This profile has no measurements or attendance, so you can choose:</p>
            <label class="delete-choice">
                <input type="radio" name="action" value="deactivate" <?= $alreadyInactive ? 'disabled' : 'checked' ?>>
                <strong>Deactivate</strong> (recommended) &mdash; status becomes Inactive and they leave the attendance list. Can be undone from Edit Profile.
            </label>
            <label class="delete-choice">
                <input type="radio" name="action" value="delete" <?= $alreadyInactive ? 'checked' : '' ?>>
                <strong>Delete permanently</strong> &mdash; removes the profile completely. This cannot be undone.
            </label>
            <label class="checkbox-label" style="margin:10px 0;">
                <input type="checkbox" name="confirm_permanent" value="1"> I understand permanent deletion cannot be undone.
            </label>
            <div class="form-actions">
                <a class="btn secondary cancel-button" href="<?= e($profileUrl) ?>">Cancel</a>
                <button class="btn danger">Confirm</button>
            </div>
        <?php endif; ?>
    </form>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
