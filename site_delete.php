<?php
/**
 * Delete a feeding site (Admin only).
 *
 * A site that still has beneficiaries CAN be deleted, but the confirmation
 * shows a warning first. Those beneficiaries are kept and simply end up with
 * no site assigned (foreign key ON DELETE SET NULL).
 * The site's auto-created "<Site Name> Program" is removed too, but only if
 * no beneficiary is using it. This cannot be undone.
 */
require_once __DIR__ . '/db.php';
require_login();

$id = (int)($_GET['id'] ?? $_POST['site_id'] ?? 0);
$isAjax = is_ajax_request();

function pcd_site_del_reply(bool $ok, string $message, string $redirect, bool $isAjax): void {
    flash($ok ? 'success' : 'danger', $message);
    if ($isAjax) json_response(['ok' => $ok, 'message' => $message, 'redirect' => $redirect]);
    header('Location: ' . $redirect);
    exit;
}

$load = $pdo->prepare("
    SELECT s.*, (SELECT COUNT(*) FROM beneficiaries b WHERE b.site_id = s.site_id) AS beneficiary_count
    FROM feeding_sites s WHERE s.site_id = ?
");
$load->execute([$id]);
$site = $load->fetch();
if (!$site) {
    if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'POST') {
        pcd_site_del_reply(false, 'That site no longer exists.', 'manage.php', true);
    }
    exit('Feeding site not found.');
}

if (!can_delete_beneficiaries()) {
    http_response_code(403);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit('Only an administrator can delete a feeding site.');
    pcd_site_del_reply(false, 'Only an administrator can delete a feeding site.', 'manage.php', $isAjax);
}

$count = (int)$site['beneficiary_count'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $pdo->beginTransaction();
        $del = $pdo->prepare("DELETE FROM feeding_sites WHERE site_id = ?");
        $del->execute([$id]);
        if ($del->rowCount() === 0) {
            $pdo->rollBack();
            pcd_site_del_reply(false, 'That site no longer exists.', 'manage.php', $isAjax);
        }
        // Remove the auto-created program only when nobody is enrolled in it.
        $pdo->prepare("
            DELETE FROM feeding_programs
            WHERE program_name = ?
              AND NOT EXISTS (SELECT 1 FROM beneficiaries b WHERE b.program_id = feeding_programs.program_id)
        ")->execute([$site['site_name'] . ' Program']);
        log_action($pdo, 'DELETE_SITE', 'feeding_sites', $id, 'Deleted feeding site "' . $site['site_name'] . '"' . ($count > 0 ? ' (' . $count . ' beneficiar' . ($count === 1 ? 'y' : 'ies') . ' left without a site)' : ''));
        $pdo->commit();
        pcd_site_del_reply(true, $site['site_name'] . ' was deleted.', 'manage.php', $isAjax);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        pcd_site_del_reply(false, 'Could not delete this site, so nothing was changed. Please try again.', 'manage.php', $isAjax);
    }
}

$pageTitle = 'Delete Feeding Site';
$pageEyebrowHtml = '<a class="back-link" href="manage.php">&larr; Back to Program Setup</a>';
require __DIR__ . '/partials/header.php';
?>
<div class="form-panel">
    <div class="person-banner">
        <strong><?= e($site['site_name']) ?></strong>
        <span><?= e(trim(($site['barangay'] ?? '') . ', ' . ($site['municipality'] ?? ''), ', ')) ?></span>
    </div>

    <form method="post" action="site_delete.php?id=<?= $id ?>">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="site_id" value="<?= $id ?>">

        <?php if ($count > 0): ?>
            <div class="alert danger">
                <strong>Warning:</strong> <?= e($site['site_name']) ?> still has
                <strong><?= $count ?> beneficiar<?= $count === 1 ? 'y' : 'ies' ?></strong>.
                If you delete this site, <?= $count === 1 ? 'that beneficiary' : 'those beneficiaries' ?>
                will stay in the system but will have <strong>no feeding site</strong> until you assign a new one.
            </div>
            <p class="delete-msg">Are you sure you want to delete <strong><?= e($site['site_name']) ?></strong> anyway?</p>
            <p class="delete-sub">This can't be undone. If this is just a typo, use Edit instead.</p>
            <div class="form-actions">
                <a class="btn success cancel-button" href="manage.php">Cancel</a>
                <button class="btn danger">Delete Anyway</button>
            </div>
        <?php else: ?>
            <p class="delete-msg">Are you sure you want to delete <strong><?= e($site['site_name']) ?></strong>?</p>
            <p class="delete-sub">This permanently removes the site. This can't be undone.</p>
            <div class="form-actions">
                <a class="btn success cancel-button" href="manage.php">Cancel</a>
                <button class="btn danger">Delete</button>
            </div>
        <?php endif; ?>
    </form>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
