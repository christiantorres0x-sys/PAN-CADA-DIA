<?php
/**
 * Delete a beneficiary - permanently.
 *
 * Removes the beneficiary row AND everything that belongs to them
 * (health measurements and attendance records), all in one transaction so
 * it either fully succeeds or changes nothing. This cannot be undone.
 *
 * Only Admin accounts may delete (can_delete_beneficiaries()). Nothing
 * happens on the profile's Delete button click - the user must confirm here.
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
    $label = $b['full_name'] . ($b['beneficiary_code'] ? ' (' . $b['beneficiary_code'] . ')' : '');
    $healthCount = pcd_count($pdo, 'health_records', $id);
    $attendanceCount = pcd_count($pdo, 'attendance_records', $id);

    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM health_records WHERE beneficiary_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM attendance_records WHERE beneficiary_id = ?")->execute([$id]);
        $del = $pdo->prepare("DELETE FROM beneficiaries WHERE beneficiary_id = ?");
        $del->execute([$id]);
        if ($del->rowCount() === 0) {
            $pdo->rollBack();
            pcd_delete_reply(false, 'That beneficiary no longer exists.', 'beneficiaries.php', $isAjax);
        }
        log_action($pdo, 'DELETE_BENEFICIARY', 'beneficiaries', $id,
            'Permanently deleted ' . $label . ' with ' . $healthCount . ' health record(s) and ' . $attendanceCount . ' attendance record(s)');
        $pdo->commit();
        pcd_delete_reply(true, $label . ' was deleted.', 'beneficiaries.php', $isAjax);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        pcd_delete_reply(false, 'Could not delete this beneficiary, so nothing was changed. Please try again.', $profileUrl, $isAjax);
    }
}

$healthCount = pcd_count($pdo, 'health_records', $id);
$attendanceCount = pcd_count($pdo, 'attendance_records', $id);

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

        <p class="delete-msg">Are you sure you want to delete <strong><?= e($b['full_name']) ?></strong>?</p>
        <p class="delete-sub">
            This permanently deletes the profile<?php if ($healthCount + $attendanceCount > 0): ?>
            along with <?= $healthCount ?> health record<?= $healthCount === 1 ? '' : 's' ?> and
            <?= $attendanceCount ?> attendance record<?= $attendanceCount === 1 ? '' : 's' ?><?php endif; ?>.
            This can't be undone.
        </p>

        <div class="form-actions">
            <a class="btn success" href="<?= e($profileUrl) ?>">Cancel</a>
            <button class="btn danger">Delete</button>
        </div>
    </form>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
