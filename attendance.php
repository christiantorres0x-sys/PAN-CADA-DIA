<?php
require_once __DIR__ . '/db.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // Batch action: mark every still-unrecorded child in the current filter
    // as Present for the chosen date (children already marked are untouched).
    if (($_POST['mark_all'] ?? '') === '1') {
        $bDate = $_POST['attendance_date'] ?? date('Y-m-d');
        $bSite = (int)($_POST['return_site_id'] ?? 0);
        $bProg = (int)($_POST['return_program_id'] ?? 0);
        $bQ = trim($_POST['return_q'] ?? '');

        $bWhere = ["b.program_status='ENROLLED'", 'ar.attendance_id IS NULL'];
        $bParams = [$bDate, $_SESSION['user_id'], $bDate];
        if ($bSite > 0) { $bWhere[] = 'b.site_id=?'; $bParams[] = $bSite; }
        if ($bProg > 0) { $bWhere[] = 'b.program_id=?'; $bParams[] = $bProg; }
        if ($bQ !== '') { $bWhere[] = 'b.full_name LIKE ?'; $bParams[] = '%' . $bQ . '%'; }

        $bulk = $pdo->prepare("
            INSERT INTO attendance_records (beneficiary_id, attendance_date, attendance_status, remarks, recorded_by)
            SELECT b.beneficiary_id, ?, 'PRESENT', '', ?
            FROM beneficiaries b
            LEFT JOIN attendance_records ar ON ar.beneficiary_id = b.beneficiary_id AND ar.attendance_date = ?
            WHERE " . implode(' AND ', $bWhere));
        $bulk->execute($bParams);
        $marked = $bulk->rowCount();
        log_action($pdo, 'BULK_ATTENDANCE', 'attendance_records', null, $marked . ' marked present on ' . $bDate);
        flash('success', $marked . ' beneficiar' . ($marked === 1 ? 'y' : 'ies') . ' marked Present.');

        $back = ['date' => $bDate];
        if ($bSite > 0) $back['site_id'] = $bSite;
        if ($bProg > 0) $back['program_id'] = $bProg;
        if ($bQ !== '') $back['q'] = $bQ;
        header('Location: attendance.php?' . http_build_query($back));
        exit;
    }

    $bid = (int)($_POST['beneficiary_id'] ?? 0);
    $date = $_POST['attendance_date'] ?? date('Y-m-d');
    // Quick-action buttons (Present/Absent) submit 'quick_status' and always win.
    // Plain Save (editing remarks only) falls back to the row's hidden
    // 'attendance_status' field, which carries the status already on record.
    $quick = $_POST['quick_status'] ?? null;
    if ($quick !== null && !in_array($quick, ['PRESENT', 'ABSENT'], true)) {
        $quick = null;
    }
    $status = $quick ?? ($_POST['attendance_status'] ?? 'PRESENT');
    $remarks = trim($_POST['remarks'] ?? '');

    if (!in_array($status, ['PRESENT', 'ABSENT'], true)) {
        $status = 'PRESENT';
    }

    $check = $pdo->prepare('SELECT beneficiary_id FROM beneficiaries WHERE beneficiary_id=?');
    $check->execute([$bid]);
    if (!$check->fetch()) {
        $msg = 'Beneficiary not found.';
        flash('danger', $msg);
        if (is_ajax_request()) json_response(['ok' => false, 'message' => $msg]);
        header('Location: attendance.php');
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO attendance_records (beneficiary_id, attendance_date, attendance_status, remarks, recorded_by)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            attendance_status = VALUES(attendance_status),
            remarks = VALUES(remarks),
            recorded_by = VALUES(recorded_by),
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$bid, $date, $status, $remarks, $_SESSION['user_id']]);

    log_action($pdo, 'UPSERT_ATTENDANCE', 'attendance_records', null, 'Beneficiary ID ' . $bid . '; ' . $date . '; ' . $status);
    flash('success', 'Attendance saved.');

    if (is_ajax_request()) {
        $statusLabels = ['PRESENT' => 'Present', 'ABSENT' => 'Absent'];
        $badgeClasses = ['PRESENT' => 'green', 'ABSENT' => 'red'];
        json_response([
            'ok' => true,
            'status' => $status,
            'status_label' => $statusLabels[$status] ?? 'Unrecorded',
            'badge_class' => $badgeClasses[$status] ?? 'gray',
            'remarks' => $remarks,
        ]);
    }

    // Return the user to the exact page/filters they were on, not just the date.
    $returnParams = ['date' => $date];
    if (!empty($_POST['return_page'])) {
        $returnParams['page'] = (int)$_POST['return_page'];
    }
    if (!empty($_POST['return_site_id'])) {
        $returnParams['site_id'] = (int)$_POST['return_site_id'];
    }
    if (!empty($_POST['return_program_id'])) {
        $returnParams['program_id'] = (int)$_POST['return_program_id'];
    }
    if (!empty($_POST['return_q'])) {
        $returnParams['q'] = trim($_POST['return_q']);
    }
    header('Location: attendance.php?' . http_build_query($returnParams));
    exit;
}

$date = $_GET['date'] ?? date('Y-m-d');
$siteId = (int)($_GET['site_id'] ?? 0);
$programId = (int)($_GET['program_id'] ?? 0);
$q = trim($_GET['q'] ?? '');

$sites = $pdo->query("SELECT * FROM feeding_sites WHERE status='ACTIVE' ORDER BY site_name")->fetchAll();
$programs = $pdo->query("SELECT * FROM feeding_programs WHERE status<>'INACTIVE' ORDER BY program_name")->fetchAll();

$where = ["b.program_status='ENROLLED'"];
$params = [];
if ($siteId > 0) {
    $where[] = 'b.site_id=?';
    $params[] = $siteId;
}
if ($programId > 0) {
    $where[] = 'b.program_id=?';
    $params[] = $programId;
}
if ($q !== '') {
    $where[] = 'b.full_name LIKE ?';
    $params[] = '%' . $q . '%';
}
$ws = 'WHERE ' . implode(' AND ', $where);

// Counts (Present/Absent) reflect the full filtered set, not just
// the current page, so they're computed with a separate query.
$countsStmt = $pdo->prepare("
    SELECT ar.attendance_status, COUNT(*) AS cnt
    FROM beneficiaries b
    LEFT JOIN attendance_records ar ON ar.beneficiary_id = b.beneficiary_id AND ar.attendance_date = ?
    $ws
    GROUP BY ar.attendance_status
");
$countsStmt->execute(array_merge([$date], $params));
$counts = ['PRESENT' => 0, 'ABSENT' => 0, 'UNRECORDED' => 0];
foreach ($countsStmt->fetchAll() as $row) {
    $counts[$row['attendance_status'] ?: 'UNRECORDED'] = (int)$row['cnt'];
}

// Pagination
$perPage = 20;
$page = max(1, (int)($_GET['page'] ?? 1));

$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM beneficiaries b $ws");
$totalStmt->execute($params);
$totalBeneficiaries = (int)$totalStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalBeneficiaries / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT b.beneficiary_id, b.full_name, b.beneficiary_code, b.grade_level, s.site_name, p.program_name,
           ar.attendance_status, ar.remarks
    FROM beneficiaries b
    LEFT JOIN feeding_sites s ON s.site_id = b.site_id
    LEFT JOIN feeding_programs p ON p.program_id = b.program_id
    LEFT JOIN attendance_records ar ON ar.beneficiary_id = b.beneficiary_id AND ar.attendance_date = ?
    $ws
    ORDER BY b.full_name
    LIMIT $perPage OFFSET $offset
");
$stmt->execute(array_merge([$date], $params));
$rows = $stmt->fetchAll();

// Base query string (current filters, minus page) reused by every pagination link.
$paginationBase = $_GET;
unset($paginationBase['page']);

$pageTitle = 'Attendance';
$pageActionsHtml = '<a class="btn primary" href="beneficiary_form.php" data-slideover="beneficiary_form.php?panel=1" data-slideover-title="Add Beneficiary"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg><span>Add Beneficiary</span></a>';
$activeNav = 'attendance.php';
require __DIR__ . '/partials/header.php';
?>
<section class="panel">
    <form method="get" data-autosubmit>
        <div class="scope-row">
            <div class="field filter-field is-active">
                <label for="att_date">Date</label>
                <input id="att_date" type="date" name="date" value="<?= e($date) ?>">
            </div>
            <div class="field filter-field <?= $siteId > 0 ? 'is-active' : '' ?>">
                <label for="att_site">Feeding Site</label>
                <select id="att_site" name="site_id">
                    <option value="0">All sites</option>
                    <?php foreach ($sites as $s): ?>
                        <option value="<?= $s['site_id'] ?>" <?= $siteId === $s['site_id'] ? 'selected' : '' ?>><?= e($s['site_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field filter-field <?= $programId > 0 ? 'is-active' : '' ?>">
                <label for="att_program">Program</label>
                <select id="att_program" name="program_id">
                    <option value="0">All programs</option>
                    <?php foreach ($programs as $p): ?>
                        <option value="<?= $p['program_id'] ?>" <?= $programId === $p['program_id'] ? 'selected' : '' ?>><?= e($p['program_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field <?= $q !== '' ? 'filter-field is-active' : '' ?>">
                <label for="att_q">Find a child</label>
                <input id="att_q" type="search" name="q" value="<?= e($q) ?>" placeholder="Type a name">
            </div>
            <button class="btn primary">Apply</button>
            <?php if ($siteId > 0 || $programId > 0 || $q !== ''): ?><a class="btn secondary" href="attendance.php?date=<?= e($date) ?>">Reset</a><?php endif; ?>
        </div>
    </form>
    <?php if ($siteId > 0 || $programId > 0 || $q !== ''): ?>
    <div class="active-filters">
        <span class="label">Active filters:</span>
        <?php if ($programId > 0): $pn = ''; foreach ($programs as $p) { if ((int)$p['program_id'] === $programId) { $pn = $p['program_name']; break; } } ?>
            <span class="filter-chip">Program: <?= e($pn) ?> <a href="attendance.php?<?= e(http_build_query(array_diff_key($_GET, ['program_id' => '']))) ?>" title="Remove this filter" aria-label="Remove filter: Program">&times;</a></span>
        <?php endif; ?>
        <?php if ($siteId > 0): $sn = ''; foreach ($sites as $s) { if ((int)$s['site_id'] === $siteId) { $sn = $s['site_name']; break; } } ?>
            <span class="filter-chip">Site: <?= e($sn) ?> <a href="attendance.php?<?= e(http_build_query(array_diff_key($_GET, ['site_id' => '']))) ?>" title="Remove this filter" aria-label="Remove filter: Site">&times;</a></span>
        <?php endif; ?>
        <?php if ($q !== ''): ?>
            <span class="filter-chip">Name: <?= e($q) ?> <a href="attendance.php?<?= e(http_build_query(array_diff_key($_GET, ['q' => '']))) ?>" title="Remove this filter" aria-label="Remove filter: Name">&times;</a></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</section>

<div class="cards cards-3">
    <div class="stat green"><span>Present</span><strong><?= $counts['PRESENT'] ?></strong></div>
    <div class="stat red"><span>Absent</span><strong><?= $counts['ABSENT'] ?></strong></div>
    <div class="stat gray"><span>Not yet recorded</span><strong><?= $counts['UNRECORDED'] ?></strong></div>
</div>

<section class="panel">
    <div class="panel-head">
        <h2><?= e($date) ?> — Enrolled Beneficiaries</h2>
        <?php if ($counts['UNRECORDED'] > 0): ?>
        <form method="post" class="batch-form" data-confirm="Mark all <?= (int)$counts['UNRECORDED'] ?> unrecorded beneficiaries as Present for <?= e($date) ?>?">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="mark_all" value="1">
            <input type="hidden" name="attendance_date" value="<?= e($date) ?>">
            <input type="hidden" name="return_site_id" value="<?= $siteId ?>">
            <input type="hidden" name="return_program_id" value="<?= $programId ?>">
            <input type="hidden" name="return_q" value="<?= e($q) ?>">
            <button class="btn secondary sm">Mark all unrecorded as Present (<?= (int)$counts['UNRECORDED'] ?>)</button>
        </form>
        <?php endif; ?>
    </div>
    <div class="attendance-guide" role="note">
        <svg class="guide-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9.5"/><path d="M12 11v5.5M12 7.6v.01"/></svg>
        <div class="guide-body">
            <strong>How to record attendance</strong>
            <ul>
                <li><span class="guide-key present"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg></span> Tap to mark <b>Present</b></li>
                <li><span class="guide-key absent"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg></span> Tap to mark <b>Absent</b></li>
                <li>It <b>saves right away</b>. Use <b>Save note</b> only after typing a remark.</li>
            </ul>
        </div>
    </div>
    <div class="table-wrap">
        <table class="attendance-table">
            <thead>
                <tr><th>Beneficiary</th><th class="hide-sm">Site</th><th class="hide-sm">Program</th><th>Status</th><th>Remarks</th><th><span class="sr-only">Action</span></th></tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <form method="post" class="attendance-row-form">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="beneficiary_id" value="<?= $r['beneficiary_id'] ?>">
                        <input type="hidden" name="attendance_date" value="<?= e($date) ?>">
                        <input type="hidden" name="return_page" value="<?= $page ?>">
                        <input type="hidden" name="return_site_id" value="<?= $siteId ?>">
                        <input type="hidden" name="return_program_id" value="<?= $programId ?>">
                        <input type="hidden" name="return_q" value="<?= e($q) ?>">
                    <td><a href="beneficiary.php?id=<?= $r['beneficiary_id'] ?>"><?= e($r['full_name']) ?></a></td>
                    <td class="hide-sm"><?= e($r['site_name'] ?? '—') ?></td>
                    <td class="hide-sm"><?= e($r['program_name'] ?? '—') ?></td>
                    <?php
                        $curStatus = $r['attendance_status'] ?? '';
                        $statusLabel = ['PRESENT' => 'Present', 'ABSENT' => 'Absent'][$curStatus] ?? 'Unrecorded';
                        $statusBadgeClass = ['PRESENT' => 'green', 'ABSENT' => 'red'][$curStatus] ?? 'gray';
                    ?>
                    <td>
                        <div class="status-cell">
                            <span class="badge <?= $statusBadgeClass ?>"><?= $statusLabel ?></span>
                            <div class="status-quick">
                                <button type="submit" name="quick_status" value="PRESENT" class="status-btn present <?= $curStatus === 'PRESENT' ? 'active' : '' ?>" title="Mark Present" aria-label="Mark Present">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>
                                </button>
                                <button type="submit" name="quick_status" value="ABSENT" class="status-btn absent <?= $curStatus === 'ABSENT' ? 'active' : '' ?>" title="Mark Absent" aria-label="Mark Absent">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg>
                                </button>
                            </div>
                            <input type="hidden" name="attendance_status" value="<?= e($curStatus ?: 'PRESENT') ?>">
                        </div>
                    </td>
                    <td><input name="remarks" value="<?= e($r['remarks'] ?? '') ?>" placeholder="Add a note (optional)" aria-label="Remarks for <?= e($r['full_name']) ?>"></td>
                    <td><button class="btn secondary sm">Save note</button></td>
                    </form>
                </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                <tr><td colspan="6" class="empty">No enrolled beneficiaries match the selected filters.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <nav class="pagination" aria-label="Attendance pagination">
        <?php if ($page > 1): ?>
            <a class="page-nav" href="attendance.php?<?= e(http_build_query(array_merge($paginationBase, ['page' => $page - 1]))) ?>">&larr; Previous</a>
        <?php else: ?>
            <span class="page-nav disabled">&larr; Previous</span>
        <?php endif; ?>

        <?php foreach (pagination_pages($page, $totalPages) as $pn): ?>
            <?php if ($pn === null): ?>
                <span class="page-ellipsis">&hellip;</span>
            <?php elseif ($pn === $page): ?>
                <span class="page-num active"><?= $pn ?></span>
            <?php else: ?>
                <a class="page-num" href="attendance.php?<?= e(http_build_query(array_merge($paginationBase, ['page' => $pn]))) ?>"><?= $pn ?></a>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($page < $totalPages): ?>
            <a class="page-nav" href="attendance.php?<?= e(http_build_query(array_merge($paginationBase, ['page' => $page + 1]))) ?>">Next &rarr;</a>
        <?php else: ?>
            <span class="page-nav disabled">Next &rarr;</span>
        <?php endif; ?>
    </nav>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
