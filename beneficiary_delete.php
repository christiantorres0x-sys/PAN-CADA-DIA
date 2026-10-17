<?php

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
        <span><?= $b['beneficiary_code'] ? 'ID: ' . e($b['beneficiary_code']) . ' · ' : '' ?><?= e($b['site_name'] ?? 'No site') ?></span>
    </div>

    <form method="post" action="beneficiary_delete.php?id=<?= $id ?>">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="beneficiary_id" value="<?= $id ?>">

        <?php if ($hasHistory): ?>
            <?php /* History on file: a real DELETE would erase it, so this safely deactivates instead. */ ?>
            <input type="hidden" name="action" value="deactivate">
            <p class="delete-msg">Are you sure you want to delete <strong><?= e($b['full_name']) ?></strong>?</p>
            <p class="delete-sub">They will be removed from the attendance list. Their measurements and attendance history are kept, and you can bring them back anytime from Edit Profile (Status).</p>
        <?php else: ?>
            <?php /* No records at all: safe to remove for real. */ ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="confirm_permanent" value="1">
            <p class="delete-msg">Are you sure you want to delete <strong><?= e($b['full_name']) ?></strong>?</p>
            <p class="delete-sub">This profile has no records, so it will be permanently deleted. This can't be undone.</p>
        <?php endif; ?>

        <div class="form-actions">
            <a class="btn success" href="<?= e($profileUrl) ?>">Cancel</a>
            <?php if ($hasHistory && $alreadyInactive): ?>
                <span class="muted">Already removed from the attendance list.</span>
            <?php else: ?>
                <button class="btn danger">Delete</button>
            <?php endif; ?>
        </div>
    </form>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
