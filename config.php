<?php
// Database settings (used by db.php, which creates $pdo)
define('DB_HOST', 'localhost');
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
// ID format: 2-digit year + 4-digit running order within that year, e.g.
// the first beneficiary added in 2026 is 260001, the second 260002. The
// count restarts at 0001 each January. The ID is assigned once when the
// beneficiary is added and never changes afterwards (editing the name does
// not touch it). It is a display/lookup aid, not a database surrogate key -
// beneficiary_id stays the real primary key.
// ---------------------------------------------------------------------
 
/** Next free ID for the given year (default: current year), e.g. "260001". */
function next_beneficiary_code(PDO $pdo, ?int $year = null): string {
    $yy = substr((string)($year ?? (int)date('Y')), -2);
    $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(beneficiary_code, 3) AS UNSIGNED)) FROM beneficiaries WHERE beneficiary_code REGEXP ?");
    $stmt->execute(['^' . $yy . '[0-9]{4,}$']);
    $last = (int)$stmt->fetchColumn();
    return $yy . str_pad((string)($last + 1), 4, '0', STR_PAD_LEFT);
}
 
/**
 * Finds an existing beneficiary with the same name and birthdate (excluding
 * $excludeId, e.g. the record being edited). IDs are now unique by design, so
 * this is what catches the same child being entered twice.
 */
function find_possible_duplicate(PDO $pdo, string $firstName, string $lastName, string $birthDate, ?int $excludeId = null): ?array {
    if (trim($firstName) === '' || trim($lastName) === '' || $birthDate === '') return null;
    $sql = "SELECT beneficiary_id, full_name, beneficiary_code, birth_date, program_status FROM beneficiaries WHERE LOWER(TRIM(first_name)) = LOWER(?) AND LOWER(TRIM(last_name)) = LOWER(?) AND birth_date = ?";
    $params = [trim($firstName), trim($lastName), $birthDate];
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
 
// ---------------------------------------------------------------------
// Measurement units. The database ALWAYS stores height in centimetres and
// weight in kilograms (health_records.height_cm / weight_kg), and BMI is
// always computed from those stored, rounded values. The forms let the user
// type height as cm or ft + in, and weight as kg or lb; the conversion
// happens exactly once, here on the server, from the unit that was
// selected when the form was submitted.
// ---------------------------------------------------------------------
const CM_PER_INCH = 2.54;
const KG_PER_LB = 0.45359237;
const HEIGHT_MIN_CM = 40.0;
const HEIGHT_MAX_CM = 220.0;
const WEIGHT_MIN_KG = 3.0;
const WEIGHT_MAX_KG = 200.0;
 
/** cm -> "3 ft 9.3 in" (display helper). */
function cm_to_ft_in_label(float $cm): string {
    $totalIn = round($cm / CM_PER_INCH, 1);
    $ft = (int)floor($totalIn / 12);
    $in = round($totalIn - $ft * 12, 1);
    if ($in >= 12) { $ft++; $in = 0.0; }
    return $ft . ' ft ' . rtrim(rtrim(number_format($in, 1, '.', ''), '0'), '.') . ' in';
}
 
/** kg -> "45.2 lb" (display helper). */
function kg_to_lb_label(float $kg): string {
    return number_format($kg / KG_PER_LB, 1, '.', '') . ' lb';
}
 
/**
 * Read and validate a height from a form submission and return it in cm.
 * Fields read: {prefix}height_unit ('cm' | 'ftin'), {prefix}height_cm,
 * {prefix}height_ft, {prefix}height_in.
 * Returns [?float $cm, ?string $error]. [null, null] means "left blank".
 */
function parse_height_cm(array $in, string $prefix = ''): array {
    $unit = (string)($in[$prefix . 'height_unit'] ?? 'cm');
    $rangeMsg = 'Height must be between ' . (int)HEIGHT_MIN_CM . ' and ' . (int)HEIGHT_MAX_CM . ' cm ('
        . cm_to_ft_in_label(HEIGHT_MIN_CM) . ' to ' . cm_to_ft_in_label(HEIGHT_MAX_CM) . ').';
 
    if ($unit === 'cm') {
        $raw = trim((string)($in[$prefix . 'height_cm'] ?? ''));
        if ($raw === '') return [null, null];
        if (!is_numeric($raw)) return [null, 'Height must be a number.'];
        $cm = (float)$raw;
    } elseif ($unit === 'ftin') {
        $ftRaw = trim((string)($in[$prefix . 'height_ft'] ?? ''));
        $inRaw = trim((string)($in[$prefix . 'height_in'] ?? ''));
        if ($ftRaw === '' && $inRaw === '') return [null, null];
        if ($ftRaw === '') $ftRaw = '0';
        if ($inRaw === '') $inRaw = '0';
        if (!ctype_digit($ftRaw)) return [null, 'Feet must be a whole number.'];
        if (!is_numeric($inRaw) || (float)$inRaw < 0 || (float)$inRaw >= 12) {
            return [null, 'Inches must be a number from 0 up to (but not including) 12.'];
        }
        $cm = ((int)$ftRaw * 12 + (float)$inRaw) * CM_PER_INCH;
    } else {
        return [null, 'Unknown height unit.'];
    }
 
    $cm = round($cm, 2);
    if ($cm <= 0) return [null, 'Height must be greater than zero.'];
    if ($cm < HEIGHT_MIN_CM || $cm > HEIGHT_MAX_CM) return [null, $rangeMsg];
    return [$cm, null];
}
 
/**
 * Read and validate a weight from a form submission and return it in kg.
 * Fields read: {prefix}weight_unit ('kg' | 'lb'), {prefix}weight_value.
 * Returns [?float $kg, ?string $error]. [null, null] means "left blank".
 */
function parse_weight_kg(array $in, string $prefix = ''): array {
    $unit = (string)($in[$prefix . 'weight_unit'] ?? 'kg');
    if ($unit !== 'kg' && $unit !== 'lb') return [null, 'Unknown weight unit.'];
 
    $raw = trim((string)($in[$prefix . 'weight_value'] ?? ''));
    if ($raw === '') return [null, null];
    if (!is_numeric($raw)) return [null, 'Weight must be a number.'];
 
    $kg = round($unit === 'lb' ? (float)$raw * KG_PER_LB : (float)$raw, 2);
    if ($kg <= 0) return [null, 'Weight must be greater than zero.'];
    if ($kg < WEIGHT_MIN_KG || $kg > WEIGHT_MAX_KG) {
        return [null, 'Weight must be between ' . (int)WEIGHT_MIN_KG . ' and ' . (int)WEIGHT_MAX_KG . ' kg ('
            . kg_to_lb_label(WEIGHT_MIN_KG) . ' to ' . kg_to_lb_label(WEIGHT_MAX_KG) . ').'];
    }
    return [$kg, null];
}
 
// ---------------------------------------------------------------------
// Who may edit saved health measurements.
//   can_edit_health_records()      - any signed-in user may correct a weight /
//                                    height / remarks. Tighten here (e.g. by
//                                    role) if only some accounts should.
//   can_change_measurement_date()  - only Admin accounts may move the date a
//                                    measurement was taken; everyone else
//                                    keeps the original date.
// ---------------------------------------------------------------------
function can_edit_health_records(): bool {
    return !empty($_SESSION['user_id']);
}
 
function can_change_measurement_date(): bool {
    return strcasecmp((string)($_SESSION['role'] ?? ''), 'Admin') === 0;
}
 
/** Fingerprint of an editable record, used to detect that someone else changed it while a form was open. */
function health_record_token(array $r): string {
    return md5(implode('|', [
        $r['record_date'], $r['height_cm'], $r['weight_kg'], $r['bmi'],
        (string)$r['nutritional_status'], (string)$r['remarks'],
    ]));
}
 
/** Only Admin accounts may deactivate or permanently delete a beneficiary. */
function can_delete_beneficiaries(): bool {
    return strcasecmp((string)($_SESSION['role'] ?? ''), 'Admin') === 0;
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
 
// A beneficiary's overall status (Enrollment Info): Active or Inactive.
const BENEFICIARY_STATUSES = ['ACTIVE', 'INACTIVE'];
 
function beneficiary_status_label(string $status): string {
    return $status === 'INACTIVE' ? 'Inactive' : 'Active';
}
 
/** A posted status if it is Active / Inactive, otherwise the fallback (usually the current value). */
function normalize_beneficiary_status($raw, string $fallback): string {
    $v = strtoupper(trim((string)$raw));
    return in_array($v, BENEFICIARY_STATUSES, true) ? $v : $fallback;
}
 
/**
 * Converts beneficiaries.program_status from the old Enrolled / Completed / Inactive
 * values to Active / Inactive only (Enrolled -> Active, Completed -> Inactive).
 * Safe to run repeatedly; returns true once the column is already Active / Inactive.
 */
function beneficiaries_ensure_active_inactive(PDO $pdo): bool {
    try {
        $type = (string)$pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'beneficiaries' AND COLUMN_NAME = 'program_status'")->fetchColumn();
        if ($type === '') return false;                       // table not created yet
        if (stripos($type, 'ENROLLED') === false && stripos($type, 'COMPLETED') === false) return true;
        // Widen first so both old and new values are legal, move the rows, then narrow.
        $pdo->exec("ALTER TABLE beneficiaries MODIFY program_status ENUM('ENROLLED','COMPLETED','ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE'");
        $pdo->exec("UPDATE beneficiaries SET program_status = 'ACTIVE' WHERE program_status = 'ENROLLED'");
        $pdo->exec("UPDATE beneficiaries SET program_status = 'INACTIVE' WHERE program_status = 'COMPLETED'");
        $pdo->exec("ALTER TABLE beneficiaries MODIFY program_status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE'");
        return true;
    } catch (PDOException $e) {
        return false;
    }
}
 
/**
 * Status tracking per measurement. Each health record keeps the status the
 * child had at that milestone (record_status), and the beneficiary keeps the
 * date they became inactive (inactive_since). Adds the two columns on first
 * use and backfills existing data. Safe to run repeatedly.
 */
function status_tracking_ensure(PDO $pdo): bool {
    try {
        $has = function (string $table, string $col) use ($pdo): bool {
            $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $q->execute([$table, $col]);
            return (int)$q->fetchColumn() > 0;
        };
        $tableOk = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('health_records','beneficiaries')")->fetchColumn();
        if ($tableOk < 2) return false;                       // tables not created yet
        // Weight-status wording: six fixed labels. Relabel older records from their BMI
        // (only rows that still carry an old/different label are touched).
        $pdo->exec("UPDATE health_records SET nutritional_status = CASE
                WHEN bmi < 15 THEN 'Severely Wasted' WHEN bmi < 16 THEN 'Severely Underweight'
                WHEN bmi < 18.5 THEN 'Moderately Underweight' WHEN bmi < 23 THEN 'Normal'
                WHEN bmi < 25 THEN 'Overweight' ELSE 'Obese' END
            WHERE nutritional_status IS NOT NULL AND nutritional_status <> CASE
                WHEN bmi < 15 THEN 'Severely Wasted' WHEN bmi < 16 THEN 'Severely Underweight'
                WHEN bmi < 18.5 THEN 'Moderately Underweight' WHEN bmi < 23 THEN 'Normal'
                WHEN bmi < 25 THEN 'Overweight' ELSE 'Obese' END");
        if (!$has('beneficiaries', 'inactive_since')) {
            $pdo->exec("ALTER TABLE beneficiaries ADD COLUMN inactive_since DATE NULL AFTER program_status");
        }
        if (!$has('health_records', 'record_status')) {
            $pdo->exec("ALTER TABLE health_records ADD COLUMN record_status ENUM('ACTIVE','INACTIVE') NULL AFTER nutritional_status");
            // Existing data: every record was Active, except the latest record of a child who is Inactive now.
            $pdo->exec("UPDATE health_records SET record_status = 'ACTIVE'");
            $inactive = $pdo->query("SELECT beneficiary_id FROM beneficiaries WHERE program_status = 'INACTIVE'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($inactive as $bid) {
                $pdo->prepare("UPDATE health_records SET record_status = 'INACTIVE' WHERE record_id = (SELECT record_id FROM (SELECT record_id FROM health_records WHERE beneficiary_id = ? ORDER BY record_date DESC, record_id DESC LIMIT 1) t)")->execute([$bid]);
                sync_beneficiary_status($pdo, (int)$bid);
            }
        }
        return true;
    } catch (PDOException $e) {
        return false;
    }
}
 
/**
 * Makes the beneficiary's overall status follow their most recent measurement
 * and records WHEN they became inactive (the date of the first measurement in
 * the current run of Inactive records). Earlier milestones are never changed.
 */
function sync_beneficiary_status(PDO $pdo, int $beneficiaryId): void {
    $q = $pdo->prepare("SELECT record_date, COALESCE(record_status, 'ACTIVE') st FROM health_records WHERE beneficiary_id = ? ORDER BY record_date DESC, record_id DESC");
    $q->execute([$beneficiaryId]);
    $recs = $q->fetchAll();
    if (!$recs) return;
    if ($recs[0]['st'] === 'INACTIVE') {
        $since = $recs[0]['record_date'];
        foreach ($recs as $r) {
            if ($r['st'] !== 'INACTIVE') break;
            $since = $r['record_date'];
        }
        $pdo->prepare("UPDATE beneficiaries SET program_status = 'INACTIVE', inactive_since = ? WHERE beneficiary_id = ?")->execute([$since, $beneficiaryId]);
    } else {
        $pdo->prepare("UPDATE beneficiaries SET program_status = 'ACTIVE', inactive_since = NULL WHERE beneficiary_id = ?")->execute([$beneficiaryId]);
    }
}
 
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
 
// UTF-8 string helpers that use mbstring when it's enabled and fall back to
// plain PHP/PCRE when it isn't (same defensive approach as partials/header.php).
function str_upper_utf8(string $s): string {
    return function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
}
 
function str_lower_utf8(string $s): string {
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}
 
/** First $max characters (not bytes) of $s. */
function str_limit_utf8(string $s, int $max): string {
    if (function_exists('mb_substr')) return mb_substr($s, 0, $max, 'UTF-8');
    return preg_match('/^.{0,' . max(0, $max) . '}/su', $s, $m) ? $m[0] : substr($s, 0, $max);
}
 
// ---------------------------------------------------------------------
// Login brute-force protection
//
// Failed logins are counted per username AND per client IP in the
// login_throttle table (kept in the database, not the session, so
// clearing cookies or opening a private window doesn't reset the count).
// After LOGIN_MAX_ATTEMPTS wrong passwords for one username, that
// username is locked for LOGIN_LOCK_SECONDS — even a correct password is
// refused until the lock expires. A higher per-IP limit stops one
// machine from guessing across many usernames. Failures older than
// LOGIN_WINDOW_SECONDS stop counting, and a successful login clears the
// username's counter. Unknown usernames are tracked exactly like real
// ones, so the lock never reveals which accounts exist.
// ---------------------------------------------------------------------
 
const LOGIN_MAX_ATTEMPTS    = 5;    // wrong passwords per username before it locks
const LOGIN_IP_MAX_ATTEMPTS = 20;   // wrong passwords from one IP (any username) before it locks
const LOGIN_LOCK_SECONDS    = 300;  // how long a lock lasts (5 minutes)
const LOGIN_WINDOW_SECONDS  = 900;  // failures older than this (15 minutes) are forgotten
 
/** Creates the throttle table on first use, so existing databases need no manual migration. */
function login_throttle_ensure_table(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_throttle (
            throttle_key VARCHAR(190) NOT NULL PRIMARY KEY,
            fail_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_failed_at INT UNSIGNED NOT NULL DEFAULT 0,
            locked_until INT UNSIGNED NOT NULL DEFAULT 0
        ) ENGINE=InnoDB
    ");
}
 
/** REMOTE_ADDR only — forwarded-for headers are client-controlled and would let an attacker dodge the IP limit. */
function login_client_ip(): string {
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
}
 
function login_throttle_key_username(string $username): string {
    return 'u:' . str_limit_utf8(str_lower_utf8(trim($username)), 150);
}
 
function login_throttle_key_ip(string $ip): string {
    return 'ip:' . $ip;
}
 
/** Seconds left on an active lock for this key (0 = not locked). Also forgets expired locks and stale failures. */
function login_throttle_remaining(PDO $pdo, string $key): int {
    $stmt = $pdo->prepare("SELECT fail_count, last_failed_at, locked_until FROM login_throttle WHERE throttle_key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    if (!$row) return 0;
 
    $now = time();
    if ((int)$row['locked_until'] > $now) {
        return (int)$row['locked_until'] - $now;
    }
    // Lock has run its course, or the failures are old enough to forget: start fresh.
    if ((int)$row['locked_until'] > 0 || (int)$row['last_failed_at'] < $now - LOGIN_WINDOW_SECONDS) {
        login_throttle_clear($pdo, $key);
    }
    return 0;
}
 
/**
 * Records one failed attempt and locks the key once $max is reached.
 * Returns ['count' => failures so far, 'locked_for' => seconds locked (0 if not locked)].
 */
function login_throttle_record_failure(PDO $pdo, string $key, int $max): array {
    $now = time();
    // fail_count is assigned before last_failed_at so the IF() still sees the previous timestamp.
    $pdo->prepare("
        INSERT INTO login_throttle (throttle_key, fail_count, last_failed_at, locked_until)
        VALUES (?, 1, ?, 0)
        ON DUPLICATE KEY UPDATE
            fail_count = IF(last_failed_at < ?, 1, fail_count + 1),
            last_failed_at = ?
    ")->execute([$key, $now, $now - LOGIN_WINDOW_SECONDS, $now]);
 
    $stmt = $pdo->prepare("SELECT fail_count FROM login_throttle WHERE throttle_key = ?");
    $stmt->execute([$key]);
    $count = (int)$stmt->fetchColumn();
 
    $lockedFor = 0;
    if ($count >= $max) {
        $lockedFor = LOGIN_LOCK_SECONDS;
        $pdo->prepare("UPDATE login_throttle SET locked_until = ? WHERE throttle_key = ?")
            ->execute([$now + $lockedFor, $key]);
    }
 
    // Housekeeping: drop rows nobody can still be affected by.
    $pdo->prepare("DELETE FROM login_throttle WHERE last_failed_at < ? AND locked_until < ?")
        ->execute([$now - LOGIN_WINDOW_SECONDS, $now]);
 
    return ['count' => $count, 'locked_for' => $lockedFor];
}
 
function login_throttle_clear(PDO $pdo, string $key): void {
    $pdo->prepare("DELETE FROM login_throttle WHERE throttle_key = ?")->execute([$key]);
}
 
/** "5 minutes", "1 minute", "45 seconds" — for the lockout message. */
function format_lock_duration(int $seconds): string {
    if ($seconds >= 60) {
        $m = (int)ceil($seconds / 60);
        return $m . ' minute' . ($m === 1 ? '' : 's');
    }
    return $seconds . ' second' . ($seconds === 1 ? '' : 's');
}
 
// ---------------------------------------------------------------------
// Automatic capitalization
//
// assets/app.js capitalizes as the user types; these do the same on the
// server so the saved value is right even if JS is off or bypassed.
// Only first letters are touched — the rest of the text is left exactly
// as typed (so "McDonald" or "dela Cruz" aren't mangled further).
// Keep the regexes in sync with pcdCapitalizeWords / pcdCapitalizeSentences.
// ---------------------------------------------------------------------
 
/** Names, places, titles: "juan dela cruz" -> "Juan Dela Cruz". */
function capitalize_words(string $s): string {
    $out = preg_replace_callback(
        '/(^|[\s\-\/(])(\p{Ll})/u',
        fn($m) => $m[1] . str_upper_utf8($m[2]),
        $s
    );
    return $out ?? $s;
}
 
/** Remarks, descriptions, notes: "ate all. went home" -> "Ate all. Went home". */
function capitalize_sentences(string $s): string {
    $out = preg_replace_callback(
        '/(^\s*|[.!?]\s+|[\r\n]+\s*)(\p{Ll})/u',
        fn($m) => $m[1] . str_upper_utf8($m[2]),
        $s
    );
    return $out ?? $s;
}
 
// ---------------------------------------------------------------------
// Beneficiary grade level / learner category
//
// Not every beneficiary is in a graded class: some are toddlers, some are
// in Alternative Learning System or SPED, some are out of school. The
// list is grouped (<optgroup>) so it stays scannable, and "Other" lets
// staff type a category that isn't listed instead of forcing a wrong one.
// Stored value is the option text itself (or the typed text for "Other").
// ---------------------------------------------------------------------
 
const GRADE_LEVEL_OTHER = 'Other';
const GRADE_LEVEL_MAX_LENGTH = 80; // matches beneficiaries.grade_level VARCHAR(80)
 
function grade_level_groups(): array {
    return [
        'Not yet in school (by age)' => ['1 year old', '2 years old', '3 years old', '4 years old'],
        'Early childhood'            => ['Day Care', 'Kinder'],
        'Elementary'                 => ['Grade 1', 'Grade 2', 'Grade 3', 'Grade 4', 'Grade 5', 'Grade 6'],
        'Junior High School'         => ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10'],
        'Senior High School'         => ['Grade 11', 'Grade 12'],
        'Other learners'             => ['Alternative Learning System (ALS)', 'Special Education (SPED)', 'Out of School'],
    ];
}
 
/** Flat list of every predefined grade/category value. */
function grade_level_known_values(): array {
    return array_merge(...array_values(grade_level_groups()));
}
 

