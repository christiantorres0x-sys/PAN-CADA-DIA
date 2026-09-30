<?php
// PAN CADA DIA configuration
// For XAMPP: MySQL host=127.0.0.1, database=pan_cada_dia, user=root, password=''
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'pan_cada_dia');
define('DB_USER', 'root');
define('DB_PASS', '');

define('APP_NAME', 'PAN CADA DIA');
define('APP_FULL_NAME', 'Feeding Program Health Monitoring System');

session_start();

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function require_login(): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: index.php');
        exit;
    }
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verify_csrf(): void {
    if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])) {
        http_response_code(419);
        exit('Invalid request token.');
    }
}

function flash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/**
 * True when the current request came from our own JS (slide-over panels,
 * in-place attendance row saves) rather than a normal browser navigation —
 * either an explicit ajax=1/panel=1 flag or the X-Requested-With header
 * our fetch() calls send. Used to decide whether to reply with JSON
 * instead of a header() redirect / full HTML page.
 */
function is_ajax_request(): bool {
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
        return true;
    }
    return ($_POST['ajax'] ?? $_GET['ajax'] ?? $_GET['panel'] ?? '') === '1';
}

/** Sends a JSON reply and stops execution. Used by AJAX-aware endpoints in place of header('Location: ...'). */
function json_response(array $data): void {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Builds a compact list of page numbers for pagination controls, with
 * `null` entries marking where an ellipsis ("…") should be rendered.
 * Always includes the first and last page, plus a window of pages
 * around the current one (e.g. 1, null, 6, 7, 8, null, 20).
 */
function pagination_pages(int $current, int $total, int $around = 1): array {
    if ($total <= 1) {
        return [1];
    }
    $current = max(1, min($current, $total));
    $keep = [];
    for ($i = 1; $i <= $total; $i++) {
        if ($i === 1 || $i === $total || ($i >= $current - $around && $i <= $current + $around)) {
            $keep[$i] = true;
        }
    }
    $pages = [];
    $prev = 0;
    foreach (array_keys($keep) as $p) {
        if ($p - $prev > 1) {
            $pages[] = null;
        }
        $pages[] = $p;
        $prev = $p;
    }
    return $pages;
}

function get_flash(): ?array {
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

/** Maps a stored status/enum value to a plain colored status label (no pill/background). */
function pcd_badge(string $raw): string {
    $map = [
        'ENROLLED'  => ['ENROLLED',  'dark'],
        'COMPLETED' => ['COMPLETED', 'dark'],
        'INACTIVE'  => ['INACTIVE',  'gray'],
        'PRESENT'   => ['PRESENT',   'green'],
        'ABSENT'    => ['ABSENT',    'red'],
        'ACTIVE'    => ['ACTIVE',    'green'],
    ];
    if ($raw === '') return '<span class="status-text gray">—</span>';
    [$label, $tone] = $map[$raw] ?? [$raw, status_tone_for($raw)];
    return '<span class="status-text ' . e($tone) . '">' . e($label) . '</span>';
}

/** Best-effort color tone for free-text nutritional/weight status values. */
function status_tone_for(string $raw): string {
    $v = strtolower($raw);
    if (str_contains($v, 'normal')) return 'green';
    if (str_contains($v, 'under') || str_contains($v, 'severe')) return 'red';
    if (str_contains($v, 'over') || str_contains($v, 'obese')) return 'orange';
    return 'gray';
}

// ---------------------------------------------------------------------
// Beneficiary ID generation & duplicate detection
//
// ID format: first 3 letters of the first name + '-' + middle-name
// initial (e.g. "Christian" + "Carlo" -> "CHR-C"). If no middle name
// is on file, the ID is just the first 3 letters. This is a display/
// lookup aid, not a database surrogate key — beneficiary_id stays the
// real primary key.
// ---------------------------------------------------------------------

function generate_beneficiary_code(string $firstName, string $middleName = ''): string {
    $letters = strtoupper(preg_replace('/[^A-Za-z]/', '', $firstName));
    $first3 = substr($letters, 0, 3);
    if ($first3 === '') return '';
    $mLetters = strtoupper(preg_replace('/[^A-Za-z]/', '', $middleName));
    $mInitial = $mLetters !== '' ? substr($mLetters, 0, 1) : '';
    return $mInitial !== '' ? $first3 . '-' . $mInitial : $first3;
}

/** Finds an existing beneficiary sharing this code (excluding $excludeId, e.g. the record being edited). */
function find_beneficiary_by_code(PDO $pdo, string $code, ?int $excludeId = null): ?array {
    if ($code === '') return null;
    $sql = "SELECT beneficiary_id, full_name, birth_date, program_status FROM beneficiaries WHERE beneficiary_code = ?";
    $params = [$code];
    if ($excludeId !== null) {
        $sql .= " AND beneficiary_id <> ?";
        $params[] = $excludeId;
    }
    $stmt = $pdo->prepare($sql . " LIMIT 1");
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** "Dela Cruz, C.C." style compact label for tables where space is tight. */
function surname_initials_label(string $firstName, string $middleName, string $lastName): string {
    if ($lastName === '' && $firstName === '') return '';
    $initials = '';
    if ($firstName !== '') $initials .= strtoupper($firstName[0]) . '.';
    if ($middleName !== '') $initials .= strtoupper($middleName[0]) . '.';
    return $lastName !== '' ? $lastName . ', ' . $initials : trim($firstName . ' ' . $initials);
}

function calculate_bmi(float $weightKg, float $heightCm): float {
    $heightM = $heightCm / 100;
    if ($heightM <= 0) throw new InvalidArgumentException('Height must be greater than zero.');
    return round($weightKg / ($heightM * $heightM), 2);
}

function age_in_years(string $birthDate, ?string $asOf = null): int {
    $birth = new DateTime($birthDate);
    $today = new DateTime($asOf ?: 'today');
    return (int)$birth->diff($today)->y;
}

function log_action(PDO $pdo, string $action, ?string $entity = null, ?int $entityId = null, ?string $details = null): void {
    $stmt = $pdo->prepare("INSERT INTO audit_log (user_id, action, entity, entity_id, details) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$_SESSION['user_id'] ?? null, $action, $entity, $entityId, $details]);
}

// ---------------------------------------------------------------------
// Growth milestone tracking (Baseline / Midline / Endline)
//
// Each beneficiary progresses through up to three measurement
// milestones over a 197-day feeding cycle. This is intentionally a
// soft workflow: a beneficiary can be registered without a baseline,
// and a later milestone can be recorded even if an earlier one is
// missing — the UI warns about the gap instead of blocking entry,
// since field data collection doesn't always happen in a clean order.
// ---------------------------------------------------------------------

const MILESTONES = ['BASELINE', 'MIDLINE', 'ENDLINE'];

function milestone_label(string $milestone): string {
    $map = ['BASELINE' => 'Baseline', 'MIDLINE' => 'Midline', 'ENDLINE' => 'Endline'];
    return $map[$milestone] ?? ucfirst(strtolower($milestone));
}

/**
 * Fetches this beneficiary's milestone health records.
 * Returns ['BASELINE' => row|null, 'MIDLINE' => row|null, 'ENDLINE' => row|null].
 */
function get_milestone_records(PDO $pdo, int $beneficiaryId): array {
    $out = ['BASELINE' => null, 'MIDLINE' => null, 'ENDLINE' => null];
    $stmt = $pdo->prepare("SELECT * FROM health_records WHERE beneficiary_id = ? AND milestone IS NOT NULL");
    $stmt->execute([$beneficiaryId]);
    foreach ($stmt->fetchAll() as $row) {
        $out[$row['milestone']] = $row;
    }
    return $out;
}

/** First milestone not yet recorded, in Baseline -> Midline -> Endline order. Null if all three are done. */
function next_milestone(array $milestoneRecords): ?string {
    foreach (MILESTONES as $m) {
        if (!$milestoneRecords[$m]) return $m;
    }
    return null;
}

/**
 * Whether recording $milestone right now would skip over a missing
 * earlier milestone (used to show a soft, non-blocking warning).
 */
function milestone_prerequisite_gap(string $milestone, array $milestoneRecords): ?string {
    if ($milestone === 'MIDLINE' && !$milestoneRecords['BASELINE']) return 'BASELINE';
    if ($milestone === 'ENDLINE' && !$milestoneRecords['BASELINE']) return 'BASELINE';
    if ($milestone === 'ENDLINE' && !$milestoneRecords['MIDLINE']) return 'MIDLINE';
    return null;
}

/** Compact status pill for a milestone slot: recorded / recorded-with-gap / pending. */
function milestone_pill(string $milestone, array $milestoneRecords): string {
    $label = e(milestone_label($milestone));
    $record = $milestoneRecords[$milestone];

    if (!$record) {
        return '<span class="pill gray">' . $label . ' · Pending</span>';
    }

    $gap = milestone_prerequisite_gap($milestone, $milestoneRecords);
    if ($gap) {
        return '<span class="pill orange" title="Recorded without a prior ' . e(milestone_label($gap)) . ' on file">'
            . $label . ' ⚠</span>';
    }

    return '<span class="pill green">' . $label . ' ✓</span>';
}
