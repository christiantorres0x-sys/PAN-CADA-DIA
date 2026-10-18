<?php
require_once __DIR__ . '/db.php';
require_login();

$q = trim($_GET['q'] ?? '');
$siteId = (int)($_GET['site_id'] ?? 0);
$total = (int)$pdo->query("SELECT COUNT(*) FROM beneficiaries")->fetchColumn();
$enrolled = (int)$pdo->query("SELECT COUNT(*) FROM beneficiaries WHERE program_status='ENROLLED'")->fetchColumn();
$sites = (int)$pdo->query("SELECT COUNT(*) FROM feeding_sites WHERE status='ACTIVE'")->fetchColumn();
$records = (int)$pdo->query("SELECT COUNT(*) FROM health_records")->fetchColumn();
$baselineDone = (int)$pdo->query("SELECT COUNT(*) FROM health_records WHERE milestone='BASELINE'")->fetchColumn();
$pendingEnrollment = max(0, $total - $enrolled);

$pageTitle = 'Dashboard';
$activeNav = 'dashboard.php';
require __DIR__ . '/partials/header.php';
?>
<div class="dashboard-page">
    <div class="dash-top-grid">
        <div class="dash-top-left">
            <a class="stat pending-card" href="beneficiaries.php" title="Go to Beneficiaries">
                <span class="pending-card-label">Pending Enrollments</span>
                <strong class="pending-count-<?= $pendingEnrollment === 0 ? 'zero' : 'positive' ?>"><?= $pendingEnrollment ?></strong>
                <span class="pending-card-review">
                    Manage
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </span>
            </a>
        </div>

        <section class="dash-top-right">
            <span class="filter-label">Quick Actions</span>
            <div class="quick-actions-body">
                <div class="quick-actions-grid">
                    <a class="quick-tile quick-tile-add" href="beneficiary_form.php" data-slideover="beneficiary_form.php?panel=1" data-slideover-title="Add Beneficiary"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg><span>Add Beneficiary</span></a>
                    <a class="btn quick-primary" href="attendance.php?date=<?= date('Y-m-d') ?>">Take Attendance</a>                </div>
            </div>
        </section>
    </div>

    <form class="scope-row beneficiary-search-row" method="get" action="beneficiaries.php">
        <input type="hidden" name="site_id" value="<?= $siteId ?>">
        <div class="field">
            <label class="sr-only" for="beneficiary-search">Search beneficiaries by name</label>
            <div class="search">
                <input id="beneficiary-search" type="search" name="q" value="<?= e($q) ?>" placeholder="Enter Beneficiary Name">
                <button class="search-submit" type="submit" aria-label="Search beneficiaries" title="Search beneficiaries">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
                </button>
            </div>
        </div>
    </form>
</div>

<section class="panel">
    <div class="panel-head">
        <div>
            <h2 style="font-size:16px; margin: 2px 0 2px 2px">Recently Added Beneficiaries</h2>
            <span class="muted" style="font-size:12.5px; margin: 2px 0 2px 2px;">Latest beneficiaries across all sites</span>
        </div>
        <a href="beneficiaries.php" style="font-size:12.5px; margin: 2px 2px 0 0; font-weight:800; text-transform:uppercase;">View All Beneficiaries</a>
    </div>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
