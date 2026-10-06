<?php
/**
 * Shared page shell for authenticated pages: <head>, sidebar/nav, flash
 * message, and the page-title block.
 *
 * The including page must set, before requiring this file:
 *   $pageTitle       (string) Used for both <title> and the <h1>.
 *
 * Optional variables the including page may set:
 *   $pageSubtitle    (string|null) Raw text shown under the <h1> (escaped here).
 *   $pageActionsHtml (string|null) Raw HTML rendered in the page-actions area.
 *   $activeNav       (string|null) href of the nav item to mark "active"
 *                     (e.g. 'dashboard.php'). Leave unset/null for none.
 *
 * Pair with partials/footer.php to close the markup this file opens.
 */

$pageSubtitle ??= null;
$pageActionsHtml ??= null;
$pageEyebrowHtml ??= null;
$activeNav ??= null;

// Panel mode: this page was fetched by JS to fill a slide-over, not opened
// as a full navigation. Skip the <head>/sidebar chrome entirely and hand
// control straight back so the including page can print just its content
// (flash message first, since panel POSTs normally reply with JSON instead
// and never reach this far — this only matters for the initial GET).
if (($_GET['panel'] ?? '') === '1') {
    $flash = get_flash();
    if ($flash) {
        echo '<div class="alert ' . e($flash['type']) . '">' . e($flash['message']) . '</div>';
    }
    return;
}

$pcd_nav_items = [
    [
        'href' => 'dashboard.php',
        'label' => 'Dashboard',
        'icon' => '<rect x="3.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.5"/>',
    ],
    [
        'href' => 'beneficiaries.php',
        'label' => 'Beneficiaries',
        'icon' => '<circle cx="9" cy="8" r="3.25"/><path d="M3 20c0-3.31 2.69-6 6-6s6 2.69 6 6"/><circle cx="17" cy="8.5" r="2.5"/><path d="M15.5 14.1c2.9.4 5 2.6 5 5.9"/>',
    ],
    [
        'href' => 'attendance.php',
        'label' => 'Attendance',
        'icon' => '<rect x="3.5" y="4.5" width="17" height="16" rx="2"/><path d="M3.5 9.5h17M8 3v3M16 3v3"/><path d="M7.5 13.5l2 2 4-4.2"/>',
    ],
    [
        'href' => 'reports.php',
        'label' => 'Reports & Analysis',
        'icon' => '<path d="M4 20.5V10M10 20.5V4.5M16 20.5v-7M20.5 20.5H4"/>',
    ],
    [
        'href' => 'manage.php',
        'label' => 'Program Setup',
        'icon' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 12a7.4 7.4 0 0 0-.1-1.2l2-1.6-2-3.4-2.4 1a7.5 7.5 0 0 0-2.1-1.2L14.4 3h-4l-.4 2.6a7.5 7.5 0 0 0-2.1 1.2l-2.4-1-2 3.4 2 1.6a7.4 7.4 0 0 0 0 2.4l-2 1.6 2 3.4 2.4-1a7.5 7.5 0 0 0 2.1 1.2L10.4 21h4l.4-2.6a7.5 7.5 0 0 0 2.1-1.2l2.4 1 2-3.4-2-1.6c.07-.4.1-.8.1-1.2Z"/>',
    ],
];

// Append the file's modified time so browsers never keep serving stale CSS.
$pcd_css = static fn(string $f): string => 'assets/css/' . $f . '?v=' . (@filemtime(__DIR__ . '/../assets/css/' . $f) ?: 1);
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($pageTitle) ?> | <?= e(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= $pcd_css('base.css') ?>">
<link rel="stylesheet" href="<?= $pcd_css('layout.css') ?>">
<link rel="stylesheet" href="<?= $pcd_css('components.css') ?>">
<link rel="stylesheet" href="<?= $pcd_css('forms.css') ?>">
<link rel="stylesheet" href="<?= $pcd_css('responsive.css') ?>">
<script defer src="assets/app.js"></script>
</head><body>
<a class="skip-link" href="#main">Skip to content</a>
<div class="layout">
<aside class="sidebar">
    <div class="brand">
        <span class="brand-mark">
            <img src="assets/images/CCES-logo.png" alt="Pan Cada Dia logo" class="brand-image">
        </span>
        <strong><?= e(APP_NAME) ?></strong>
        <small>Health Monitoring System</small>
    </div>
    <nav class="nav" aria-label="Main navigation">
        <?php foreach ($pcd_nav_items as $pcd_item): ?>
        <a href="<?= e($pcd_item['href']) ?>" class="<?= $activeNav === $pcd_item['href'] ? 'active' : '' ?>"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $pcd_item['icon'] ?></svg><span><?= e($pcd_item['label']) ?></span></a>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot">
        <div class="account"><span class="avatar"><?= e(function_exists('mb_substr') ? mb_substr($_SESSION['user_name'] ?? 'A', 0, 1) : substr($_SESSION['user_name'] ?? 'A', 0, 1)) ?></span>
            <div><strong><?= e($_SESSION['user_name'] ?? '') ?></strong><small><?= e(strtolower($_SESSION['role'] ?? 'admin')) ?></small></div>
        </div>
        <a class="signout" href="logout.php"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5.5A1.5 1.5 0 0 1 4 19.5v-15A1.5 1.5 0 0 1 5.5 3H9"/><path d="M16 17l5-5-5-5M21 12H9"/></svg><span>Sign Out</span></a>
    </div>
</aside>
<main class="content" id="main">
<?php $flash = get_flash(); ?>
<?php if ($flash): ?><div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>
<?php if ($pageEyebrowHtml !== null): ?><?= $pageEyebrowHtml ?><?php endif; ?>
<div class="page-title">
    <div><h1><?= e($pageTitle) ?></h1><?php if ($pageSubtitle !== null): ?><p class="muted"><?= e($pageSubtitle) ?></p><?php endif; ?></div>
    <?php if ($pageActionsHtml !== null): ?><div class="page-actions"><?= $pageActionsHtml ?></div><?php endif; ?>
</div>
