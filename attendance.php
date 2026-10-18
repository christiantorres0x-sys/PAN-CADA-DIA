<?php
require_once __DIR__ . '/db.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (($_POST['save_attendance'] ?? '') === '1') {
        $saveDate = $_POST['attendance_date'] ?? date('Y-m-d');
        $saveSite = (int)($_POST['return_site_id'] ?? 0);
        $saveQ = trim($_POST['return_q'] ?? '');
        $savePage = max(1, (int)($_POST['return_page'] ?? 1));
        $attendance = is_array($_POST['attendance'] ?? null) ? $_POST['attendance'] : [];
        $parsedSaveDate = DateTimeImmutable::createFromFormat('!Y-m-d', $saveDate);

        if (!$parsedSaveDate || $parsedSaveDate->format('Y-m-d') !== $saveDate || $saveDate > date('Y-m-d')) {
            flash('danger', 'Choose a valid attendance date that is not in the future.');
            header('Location: attendance.php');
            exit;
        }

        $eligibleWhere = ["program_status='ENROLLED'"];
        $eligibleParams = [];
        if ($saveSite > 0) {
            $eligibleWhere[] = 'site_id=?';
            $eligibleParams[] = $saveSite;
        }
        if ($saveQ !== '') {
            $saveEsc = addcslashes($saveQ, '\\%_');
            $eligibleWhere[] = '(full_name LIKE ? OR full_name LIKE ?)';
            $eligibleParams[] = $saveEsc . '%';
            $eligibleParams[] = '% ' . $saveEsc . '%';
        }
        $eligibleStmt = $pdo->prepare('SELECT beneficiary_id FROM beneficiaries WHERE ' . implode(' AND ', $eligibleWhere));
        $eligibleStmt->execute($eligibleParams);
        $eligibleIds = array_fill_keys(array_map('strval', $eligibleStmt->fetchAll(PDO::FETCH_COLUMN)), true);

        $pageOffset = ($savePage - 1) * 20;
        $pageStmt = $pdo->prepare('SELECT beneficiary_id FROM beneficiaries WHERE ' . implode(' AND ', $eligibleWhere) . ' ORDER BY full_name LIMIT 20 OFFSET ' . $pageOffset);
        $pageStmt->execute($eligibleParams);
        $pageIds = array_map('strval', $pageStmt->fetchAll(PDO::FETCH_COLUMN));
        foreach ($pageIds as $beneficiaryId) {
            if (!in_array($attendance[$beneficiaryId] ?? '', ['PRESENT', 'ABSENT'], true)) {
                flash('danger', 'Choose Present or Absent for every beneficiary before saving.');
                $returnParams = ['date' => $saveDate, 'site_id' => $saveSite, 'purpose' => 'mark', 'applied' => '1', 'page' => $savePage];
                if ($saveQ !== '') $returnParams['q'] = $saveQ;
                header('Location: attendance.php?' . http_build_query($returnParams));
                exit;
            }
        }

        $saveStmt = $pdo->prepare("
            INSERT INTO attendance_records (beneficiary_id, attendance_date, attendance_status, remarks, recorded_by)
            VALUES (?, ?, ?, '', ?)
            ON DUPLICATE KEY UPDATE
                attendance_status = VALUES(attendance_status),
                recorded_by = VALUES(recorded_by),
                updated_at = CURRENT_TIMESTAMP
        ");
        $savedCount = 0;
        $pdo->beginTransaction();
        try {
            foreach ($pageIds as $beneficiaryId) {
                $attendanceStatus = $attendance[$beneficiaryId];
                if (!isset($eligibleIds[$beneficiaryId])) {
                    continue;
                }
                $saveStmt->execute([(int)$beneficiaryId, $saveDate, $attendanceStatus, $_SESSION['user_id']]);
                $savedCount++;
            }
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }

        log_action($pdo, 'SAVE_ATTENDANCE', 'attendance_records', null, $savedCount . ' records saved for ' . $saveDate);
        $savedSiteName = 'All Sites';
        if ($saveSite > 0) {
            $savedSiteStmt = $pdo->prepare('SELECT site_name FROM feeding_sites WHERE site_id = ?');
            $savedSiteStmt->execute([$saveSite]);
            $savedSiteName = $savedSiteStmt->fetchColumn() ?: 'Selected Site';
        }
        flash('success', 'Attendance for ' . $savedSiteName . ' on ' . $saveDate . ' has been saved.');
        header('Location: attendance.php');
        exit;
    }

    // Batch action: mark every still-unrecorded child in the current filter
    // as Present for the chosen date (children already marked are untouched).
    if (($_POST['mark_all'] ?? '') === '1') {
        $bDate = $_POST['attendance_date'] ?? date('Y-m-d');
        $bSite = (int)($_POST['return_site_id'] ?? 0);
        $bQ = trim($_POST['return_q'] ?? '');

        $bWhere = ["b.program_status='ENROLLED'", 'ar.attendance_id IS NULL'];
        $bParams = [$bDate, $_SESSION['user_id'], $bDate];
        if ($bSite > 0) { $bWhere[] = 'b.site_id=?'; $bParams[] = $bSite; }
        if ($bQ !== '') { $bEsc = addcslashes($bQ, '\\%_'); $bWhere[] = '(b.full_name LIKE ? OR b.full_name LIKE ?)'; $bParams[] = $bEsc . '%'; $bParams[] = '% ' . $bEsc . '%'; }

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

        $back = ['date' => $bDate, 'applied' => '1'];
        if ($bSite > 0) $back['site_id'] = $bSite;
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
    $remarks = capitalize_sentences(trim($_POST['remarks'] ?? ''));

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
    $returnParams = ['date' => $date, 'applied' => '1'];
    if (!empty($_POST['return_page'])) {
        $returnParams['page'] = (int)$_POST['return_page'];
    }
    if (!empty($_POST['return_site_id'])) {
        $returnParams['site_id'] = (int)$_POST['return_site_id'];
    }
    if (!empty($_POST['return_q'])) {
        $returnParams['q'] = trim($_POST['return_q']);
    }
    header('Location: attendance.php?' . http_build_query($returnParams));
    exit;
}

$today = date('Y-m-d');
$date = $_GET['date'] ?? $today;
if ($date > $today) {
    $date = $today;
}
$siteId = (int)($_GET['site_id'] ?? 0);
$q = trim($_GET['q'] ?? '');
$purpose = ($_GET['purpose'] ?? 'mark') === 'history' ? 'history' : 'mark';
$applied = ($_GET['applied'] ?? '') === '1';

$sites = $pdo->query("SELECT * FROM feeding_sites WHERE status='ACTIVE' ORDER BY site_name")->fetchAll();
$attendanceSiteName = 'All Sites';
foreach ($sites as $site) {
    if ((int)$site['site_id'] === $siteId) {
        $attendanceSiteName = $site['site_name'];
        break;
    }
}
$where = ["b.program_status='ENROLLED'"];
$params = [];
if ($siteId > 0) {
    $where[] = 'b.site_id=?';
    $params[] = $siteId;
}
if ($q !== '') {
    $esc = addcslashes($q, '\\%_');
    $where[] = '(b.full_name LIKE ? OR b.full_name LIKE ?)';
    $params[] = $esc . '%';
    $params[] = '% ' . $esc . '%';
}
$ws = 'WHERE ' . implode(' AND ', $where);

// Counts (Present/Absent) reflect the full filtered set, not just
// the current page, so they're computed with a separate query.
$countsStmt = $pdo->prepare("
    SELECT ar.attendance_status, COUNT(*) AS cnt
    FROM beneficiaries b
    " . ($purpose === 'history' ? 'JOIN' : 'LEFT JOIN') . " attendance_records ar ON ar.beneficiary_id = b.beneficiary_id AND ar.attendance_date = ?
    $ws
    GROUP BY ar.attendance_status
");
$countsStmt->execute(array_merge([$date], $params));
$counts = ['PRESENT' => 0, 'ABSENT' => 0, 'UNRECORDED' => 0];
foreach ($countsStmt->fetchAll() as $row) {
    $counts[$row['attendance_status'] ?: 'UNRECORDED'] = (int)$row['cnt'];
}
$savedAttendanceCount = $counts['PRESENT'] + $counts['ABSENT'];

// Pagination
$perPage = 20;
$page = max(1, (int)($_GET['page'] ?? 1));

$historyJoin = $purpose === 'history' ? 'JOIN attendance_records history_ar ON history_ar.beneficiary_id = b.beneficiary_id AND history_ar.attendance_date = ?' : '';
$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM beneficiaries b $historyJoin $ws");
$totalStmt->execute($purpose === 'history' ? array_merge([$date], $params) : $params);
$totalBeneficiaries = (int)$totalStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalBeneficiaries / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
            SELECT b.beneficiary_id, b.full_name, b.beneficiary_code, b.grade_level, s.site_name,
           ar.attendance_status, ar.remarks
    FROM beneficiaries b
    LEFT JOIN feeding_sites s ON s.site_id = b.site_id
            " . ($purpose === 'history' ? 'JOIN' : 'LEFT JOIN') . " attendance_records ar ON ar.beneficiary_id = b.beneficiary_id AND ar.attendance_date = ?
    $ws
    ORDER BY b.full_name
    LIMIT $perPage OFFSET $offset
");
$stmt->execute(array_merge([$date], $params));
$rows = $stmt->fetchAll();

// Base query string (current filters, minus page) reused by every pagination link.
$paginationBase = $_GET;
unset($paginationBase['page'], $paginationBase['program_id']);

$pageTitle = 'Attendance';
$pageActionsHtml = $applied
    ? '<a class="btn secondary attendance-change-filters" href="attendance.php">Change Filters</a>'
    : null;
$activeNav = 'attendance.php';
require __DIR__ . '/partials/header.php';
?>
<?php if (!$applied): ?>
<section class="panel filters-panel attendance-filter-panel">
    <span class="filter-label">Filter</span>
    <form class="scope-row" method="get">
        <input type="hidden" name="applied" value="1">
            <div class="field filter-field">
                <label for="att_purpose">Purpose</label>
                <select id="att_purpose" name="purpose">
                    <option value="mark" <?= $purpose === 'mark' ? 'selected' : '' ?>>Mark attendance</option>
                    <option value="history" <?= $purpose === 'history' ? 'selected' : '' ?>>View previous attendance</option>
                </select>
            </div>
            <div class="field filter-field <?= $siteId > 0 ? 'is-active' : '' ?>">
                <label for="att_site">Feeding Site</label>
                <select id="att_site" name="site_id">
                    <option value="0">All sites</option>
                    <?php foreach ($sites as $s): ?>
                        <option value="<?= $s['site_id'] ?>" <?= $siteId === (int)$s['site_id'] ? 'selected' : '' ?>><?= e($s['site_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field filter-field is-active">
                <label for="att_date">Date</label>
                <input id="att_date" type="date" name="date" value="<?= e($date) ?>" max="<?= e($today) ?>">
            </div>
            <button class="btn primary">Apply</button>
    </form>
</section>
<?php else: ?>
<?php if ($purpose === 'mark' && $savedAttendanceCount > 0): ?>
<div class="alert success attendance-alert">Attendance for <?= e($date) ?> has already been marked. You are editing the saved attendance.</div>
<?php endif; ?>
<div class="cards cards-2 attendance-summary-cards">
    <div class="stat green"><span>Present</span><strong data-attendance-count="PRESENT"><?= $counts['PRESENT'] ?></strong></div>
    <div class="stat red"><span>Absent</span><strong data-attendance-count="ABSENT"><?= $counts['ABSENT'] ?></strong></div>
</div>

<section class="panel attendance-panel">
    <div class="panel-head">
        <h2><?= e($attendanceSiteName) ?> - <?= e($date) ?></h2>
    </div>
    <div class="table-wrap">
        <table class="attendance-table">
            <thead>
                <tr><th>ID</th><th>Beneficiary</th><th>Attendance Status</th></tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= $r['beneficiary_code'] ? '<span class="code-tag">' . e($r['beneficiary_code']) . '</span>' : '<span class="muted">—</span>' ?></td>
                    <td><a href="beneficiary.php?id=<?= $r['beneficiary_id'] ?>"><?= e($r['full_name']) ?></a></td>
                    <?php
                        $curStatus = $r['attendance_status'] ?? '';
                        $selectedStatus = $purpose === 'history' || $savedAttendanceCount > 0 ? $curStatus : '';
                    ?>
                    <?php if ($purpose === 'history'): ?>
                    <td><span class="badge <?= $curStatus === 'PRESENT' ? 'green' : 'red' ?>"><?= $curStatus === 'PRESENT' ? 'Present' : 'Absent' ?></span></td>
                    <?php else: ?>
                    <td>
                        <div class="status-cell">
                            <button type="button" data-attendance-status="PRESENT" aria-pressed="<?= $selectedStatus === 'PRESENT' ? 'true' : 'false' ?>" class="status-btn present <?= $selectedStatus === 'PRESENT' ? 'active' : '' ?>" title="Mark Present" aria-label="Mark Present">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>
                            </button>
                            <button type="button" data-attendance-status="ABSENT" aria-pressed="<?= $selectedStatus === 'ABSENT' ? 'true' : 'false' ?>" class="status-btn absent <?= $selectedStatus === 'ABSENT' ? 'active' : '' ?>" title="Mark Absent" aria-label="Mark Absent">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg>
                            </button>
                        </div>
                        <input type="hidden" form="attendance-save-form" name="attendance[<?= (int)$r['beneficiary_id'] ?>]" value="<?= e($selectedStatus) ?>" data-initial-status="<?= e($curStatus) ?>">
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                <tr><td colspan="3" class="empty"><?= $purpose === 'history' ? 'No saved attendance matches the selected filters.' : 'No enrolled beneficiaries match the selected filters.' ?></td></tr>
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
    <?php if ($purpose === 'mark'): ?>
    <div class="attendance-save-actions">
        <form method="post" id="attendance-save-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="save_attendance" value="1">
            <input type="hidden" name="attendance_date" value="<?= e($date) ?>">
            <input type="hidden" name="return_site_id" value="<?= $siteId ?>">
            <input type="hidden" name="return_q" value="<?= e($q) ?>">
            <input type="hidden" name="return_page" value="<?= $page ?>">
            <button class="btn primary" type="submit">Save Attendance</button>
        </form>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
