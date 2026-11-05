<?php
/**
 * Edit a feeding site (fix typos in name / address / barangay / city).
 * If the site has its auto-created "<Site Name> Program", that program is
 * renamed along with it so the two stay matched.
 */
require_once __DIR__ . '/db.php';
require_login();

$id = (int)($_GET['id'] ?? $_POST['site_id'] ?? 0);
$isAjax = is_ajax_request();

function pcd_site_reply(bool $ok, string $message, string $redirect, bool $isAjax): void {
    if ($isAjax) {
        if ($ok) flash('success', $message);
        json_response(['ok' => $ok, 'message' => $message, 'redirect' => $redirect]);
    }
    flash($ok ? 'success' : 'danger', $message);
    header('Location: ' . $redirect);
    exit;
}

$load = $pdo->prepare("SELECT * FROM feeding_sites WHERE site_id = ?");
$load->execute([$id]);
$site = $load->fetch();
if (!$site) {
    if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'POST') {
        pcd_site_reply(false, 'That site no longer exists.', 'manage.php', true);
    }
    exit('Feeding site not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = capitalize_words(trim($_POST['site_name'] ?? ''));
    $address = capitalize_words(trim($_POST['address'] ?? ''));
    $barangay = capitalize_words(trim($_POST['barangay'] ?? ''));
    $city = capitalize_words(trim($_POST['municipality'] ?? ''));

    if ($name === '') {
        if ($isAjax) json_response(['ok' => false, 'message' => 'Site name is required.']);
        flash('danger', 'Site name is required.');
        header('Location: site_edit.php?id=' . $id);
        exit;
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE feeding_sites SET site_name = ?, address = ?, barangay = ?, municipality = ? WHERE site_id = ?")
            ->execute([$name, $address, $barangay, $city, $id]);

        // Keep the auto-named program ("<Site> Program") in step with a renamed site.
        if ($name !== $site['site_name']) {
            $pdo->prepare("UPDATE feeding_programs SET program_name = ? WHERE program_name = ?")
                ->execute([$name . ' Program', $site['site_name'] . ' Program']);
        }

        log_action($pdo, 'UPDATE_SITE', 'feeding_sites', $id,
            'Updated feeding site "' . $site['site_name'] . '"' . ($name !== $site['site_name'] ? ' (renamed to "' . $name . '")' : ''));
        $pdo->commit();
        pcd_site_reply(true, 'Feeding site updated.', 'manage.php', $isAjax);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        pcd_site_reply(false, 'Could not update the site, so nothing was changed. Please try again.', 'manage.php', $isAjax);
    }
}

$pageTitle = 'Edit Feeding Site';
$pageEyebrowHtml = '<a class="back-link" href="manage.php">&larr; Back to Program Setup</a>';
require __DIR__ . '/partials/header.php';
?>
<div class="form-panel">
    <form method="post" action="site_edit.php?id=<?= $id ?>">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="site_id" value="<?= $id ?>">
        <div class="form-grid">
            <div class="full-col">
                <label>Site Name *</label>
                <input name="site_name" value="<?= e($site['site_name']) ?>" required>
            </div>
            <div class="full-col">
                <label>Address</label>
                <input name="address" value="<?= e($site['address']) ?>">
            </div>
            <div>
                <label>Barangay</label>
                <input name="barangay" value="<?= e($site['barangay']) ?>">
            </div>
            <div>
                <label>City</label>
                <input name="municipality" value="<?= e($site['municipality']) ?>">
            </div>
        </div>
        <div class="form-actions">
            <a class="btn danger cancel-button" href="manage.php">Cancel</a>
            <button class="btn primary">Save Changes</button>
        </div>
    </form>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
