<?php
/**
 * Printable formal report (long bond paper, 8.5 x 13 in).
 * Included by reports.php when ?generated=1&print=1, so it reuses the
 * already-computed, already-filtered data. Opens the browser print dialog
 * automatically; choose "Save as PDF" to download.
 */
$scopeSite = 'All feeding sites';
foreach ($sites as $s) { if ((int)$s['site_id'] === $siteId) { $scopeSite = $s['site_name']; break; } }
$scopeProgram = 'All programs';
foreach ($programs as $p) { if ((int)$p['program_id'] === $programId) { $scopeProgram = $p['program_name']; break; } }
$fmtD = fn(string $d): string => format_display_date($d);
$period = $implementationPeriod;
$statusScope = $status !== '' ? beneficiary_status_label($status) : 'All statuses';
$preparedBy = $_SESSION['user_name'] ?? '';
$preparedByRole = $_SESSION['role'] ?? '';
$n2 = fn($n): string => rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.');
$pct = fn(int $n, int $t): string => $t > 0 ? number_format($n / $t * 100, 1) . '%' : '—';

// Status counts for the overall summary (latest classification of each record in scope).
$statusTotals = [];
foreach ($rows as $r) {
  if ($r['nutritional_status'] === 'Pending child reference') continue;
  $k = $r['nutritional_status'];
    $statusTotals[$k] = ($statusTotals[$k] ?? 0) + 1;
}
arsort($statusTotals);
$classifiedTotal = array_sum($statusTotals);
$attRate = $attTotal > 0 ? number_format($attCounts['PRESENT'] / $attTotal * 100, 1) . '%' : '—';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Health Monitoring Report | <?= e(APP_NAME) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  @page { size: 8.5in 13in; margin: 0.6in 0.6in 0.7in; }
  * { box-sizing: border-box; }
  html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  body { margin: 0; background: #e9ecf2; color: #111; font: 10.5pt/1.45 "Times New Roman", Georgia, serif; }
  .toolbar { position: sticky; top: 0; z-index: 5; display: flex; gap: 10px; align-items: center; justify-content: center; padding: 10px; background: #14213a; color: #fff; font: 13px Arial, sans-serif; }
  .toolbar button, .toolbar a { font: 600 13px Arial, sans-serif; padding: 8px 16px; border-radius: 6px; border: 0; cursor: pointer; text-decoration: none; background: #4a5fdf; color: #fff; }
  .toolbar a { background: #fff; color: #14213a; }
  .sheet { width: 8.5in; min-height: 13in; margin: 16px auto; padding: 0.6in 0.6in 0.7in; background: #fff; box-shadow: 0 2px 12px rgba(0,0,0,.18); }

  .letterhead { display: grid; grid-template-columns: 0.9in 1fr 0.9in; align-items: center; gap: 12px; text-align: center; padding-bottom: 10px; border-bottom: 3px double #111; }
  .letterhead img { width: 0.85in; height: 0.85in; object-fit: contain; }
  .letterhead .org { font-size: 9.5pt; letter-spacing: .3px; }
  .letterhead .prog { font-size: 15pt; font-weight: 700; letter-spacing: 1px; margin: 2px 0; }
  .letterhead .sys { font-size: 10pt; font-style: italic; }

  h1.title { text-align: center; font-size: 14pt; letter-spacing: 1.5px; text-transform: uppercase; margin: 16px 0 2px; }
  .subtitle { text-align: center; font-size: 10pt; margin-bottom: 12px; }

  table.meta { width: 100%; border-collapse: collapse; margin-bottom: 12px; font-size: 10pt; }
  table.meta td { padding: 3px 6px; vertical-align: top; }
  table.meta td.k { width: 1.5in; font-weight: 700; }

  h2.sec { font-size: 11pt; text-transform: uppercase; letter-spacing: .6px; margin: 16px 0 6px; padding-bottom: 3px; border-bottom: 1.5px solid #111; break-after: avoid; }
  p { margin: 0 0 6px; text-align: justify; }

  table.data { width: 100%; border-collapse: collapse; font-size: 8.5pt; margin: 4px 0 8px; }
  table.data th, table.data td { border: 1px solid #333; padding: 3px 4px; vertical-align: middle; }
  table.data th { background: #e6e9f0; font-weight: 700; text-align: center; }
  table.data thead { display: table-header-group; }
  table.data tr { break-inside: avoid; }
  table.data td.c { text-align: center; }
  table.data td.r { text-align: right; }
  table.data .sub { display: block; font-size: 7.5pt; color: #444; }
  table.health-records-table td.beneficiary-name,
  table.health-records-table td.birth-date-cell,
  table.health-records-table td.age-cell { white-space: nowrap; }
  table.data .flag { font-weight: 700; }
  table.data .pend { color: #666; font-style: italic; text-align: center; }
  table.data tfoot td { font-weight: 700; background: #f1f3f8; }
  table.attendance-register { table-layout: fixed; font-size: 8.5pt; margin-top: 6px; }
  table.attendance-register th, table.attendance-register td { padding: 4px 5px; text-align: center; }
  table.attendance-register .id-col { width: 0.7in; }
  table.attendance-register .name-col { width: 2.3in; }
  table.attendance-register .count-col { width: 0.65in; }
  table.attendance-register .active-days-col { width: 0.9in; }
  table.attendance-register .rate-col { width: 0.8in; }
  table.attendance-register td.name { text-align: left; overflow-wrap: anywhere; }
  .caption { font-size: 8.5pt; font-style: italic; margin: 0 0 8px; }

  .charts { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; break-inside: avoid; }
  .chart { border: 1px solid #333; padding: 8px; text-align: center; break-inside: avoid; }
  .chart h3 { margin: 0 0 6px; font-size: 9.5pt; }
  .pie { width: 1.05in; height: 1.05in; border-radius: 50%; margin: 0 auto 6px; border: 1px solid #333; }
  .bar-chart { display: flex; justify-content: center; align-items: stretch; gap: 4px; min-height: 1.75in; }
  .bar-row { display: grid; flex: 0 1 0.55in; grid-template-rows: 9pt 1.2in auto; justify-items: center; gap: 3px; min-width: 0; font: 6.5pt Arial, sans-serif; }
  .bar-row strong { line-height: 1; }
  .bar-label { text-align: center; white-space: nowrap; font-size: 6pt; }
  .bar-track { display: flex; align-items: flex-end; align-self: end; width: 0.14in; height: 1.2in; overflow: hidden; background: #eef1f6; }
  .bar-fill { display: block; width: 100%; }
  .attendance-chart { display: block; width: 100%; height: auto; margin: 4px auto; }
  .attendance-grid { stroke: #bbb; stroke-width: 1; }
  .attendance-axis { fill: #444; font: 10px Arial, sans-serif; }
  .attendance-line { fill: none; stroke-width: 2.5; stroke-linejoin: round; stroke-linecap: round; }
  .attendance-line.present { stroke: #1a9c53; }
  .attendance-line.absent { stroke: #e53e3e; }
  .attendance-legend { display: flex; justify-content: center; gap: 12px; font: 8pt Arial, sans-serif; }
  .attendance-legend span { display: inline-flex; align-items: center; gap: 4px; }
  .legend { font-size: 8pt; text-align: left; }
  .legend div { display: flex; align-items: center; gap: 5px; margin: 1px 0; }
  .sw { width: 9px; height: 9px; border: 1px solid #333; flex: none; }
  .legend b { margin-left: auto; }
  .pair { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; break-inside: avoid; }
  .pair .chart { display: flex; gap: 10px; align-items: center; text-align: left; }
  .pair .pie { margin: 0; flex: none; }
  .pair .legend { flex: 1; }
  .pair.trio { grid-template-columns: repeat(3, 1fr); }
  .pair.trio .chart { flex-direction: column; text-align: center; }
  .pair.trio .pie { margin: 0 auto; }
  .pair.trio .legend { flex: none; width: 100%; }

  .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 0.6in; margin-top: 0.45in; break-inside: avoid; }
  .sig { text-align: center; font-size: 10pt; }
  .sig .line { border-bottom: 1px solid #111; margin-top: 0.5in; padding-top: 3px; font-weight: 700; }
  .sig .position { display: block; font-size: 9pt; font-weight: 400; }
  .foot { margin-top: 18px; font-size: 8pt; color: #444; text-align: center; border-top: 1px solid #999; padding-top: 4px; }

  @media print {
    body { background: #fff; }
    .toolbar { display: none; }
    .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
  }
</style>
</head>
<body>
<div class="toolbar">
  <span>Choose <strong>Save as PDF</strong> in the print dialog (paper: Legal / 8.5 × 13 in, margins: Default, background graphics: on).</span>
  <button type="button" onclick="window.print()">Download / Print</button>
  <a href="#" onclick="window.close(); return false;">Close</a>
</div>

<div class="sheet">
  <header class="letterhead">
    <img src="assets/images/AdZU-logo.svg" alt="">
    <div>
      <div class="org">Ateneo de Zamboanga University</div>
      <div class="prog"><?= e(APP_NAME) ?></div>
      <div class="sys">Health Monitoring System</div>
    </div>
    <img src="assets/images/CCES-logo.png" alt="">
  </header>

  <h1 class="title">Health Monitoring Report</h1>
  <table class="meta">
    <tr><td class="k">Feeding Site:</td><td><?= e($scopeSite) ?></td><td class="k">Date Generated:</td><td><?= e(format_display_date(date('Y-m-d'))) ?></td></tr>
    <tr><td class="k">Program:</td><td><?= e($scopeProgram) ?></td><td class="k">Period Covered:</td><td><?= e($period) ?></td></tr>
    <tr><td class="k">Program Status:</td><td><?= e($statusScope) ?></td><td class="k">Prepared By:</td><td><?= e($preparedBy ?: '—') ?></td></tr>
  </table>

  <h2 class="sec">I. Summary</h2>
  <p>This report covers <strong><?= (int)$uniqueBeneficiaries ?></strong> beneficiar<?= $uniqueBeneficiaries === 1 ? 'y' : 'ies' ?> with a total of <strong><?= (int)$totalRecords ?></strong> health record<?= $totalRecords === 1 ? '' : 's' ?>
  <?php if ($avgBmi !== null): ?>and an average BMI of <strong><?= e($n2($avgBmi)) ?></strong> kg/m&sup2;<?php endif; ?>.
  Attendance in the selected scope was recorded at <strong><?= e($attRate) ?></strong> participation (<?= (int)$attCounts['PRESENT'] ?> present, <?= (int)$attCounts['ABSENT'] ?> absent out of <?= (int)$attTotal ?> record<?= $attTotal === 1 ? '' : 's' ?>).</p>
  <?php if ($statusTotals): ?>
  <table class="data">
    <thead><tr><th>Weight Status (All Milestones)</th><th style="width:0.8in;">Records</th><th style="width:0.8in;">Share</th></tr></thead>
    <tbody>
      <?php foreach ($statusTotals as $k => $n): ?>
      <tr><td><?= e($k) ?></td><td class="c"><?= (int)$n ?></td><td class="c"><?= e($pct($n, $classifiedTotal)) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td>Total</td><td class="c"><?= (int)$classifiedTotal ?></td><td class="c">100%</td></tr></tfoot>
  </table>
  <?php endif; ?>

  <h2 class="sec">II. Health Monitoring Records</h2>
  <p class="caption">Height (cm), weight (kg) and BMI (kg/m&sup2;) per growth milestone. Weight status follows the Asian BMI cut-offs.</p>
  <table class="data health-records-table">
    <thead>
      <tr>
        <th rowspan="2" style="width:0.48in;">ID</th>
        <th rowspan="2">Beneficiary</th>
        <th rowspan="2">Birth Date</th>
        <th rowspan="2" style="width:0.5in;">Age</th>
        <th rowspan="2">Sex</th>
        <?php foreach (MILESTONES as $m): ?><th colspan="2"><?= e(milestone_label($m)) ?></th><?php endforeach; ?>
      </tr>
      <tr>
        <?php foreach (MILESTONES as $m): ?><th>Ht / Wt &middot; Date</th><th>BMI &middot; Status</th><?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($recordGroups as $g): $info = $g['info']; ?>
      <tr>
        <td class="c"><?= e($info['beneficiary_code'] ?: '—') ?></td>
        <td class="beneficiary-name"><strong><?= e($info['full_name']) ?></strong></td>
        <td class="birth-date-cell"><?= e($fmtD($info['birth_date'])) ?></td>
        <td class="c age-cell"><?= e(age_label($info['birth_date'])) ?></td>
        <td class="c"><?= e($info['sex']) ?></td>
        <?php foreach (MILESTONES as $m): $r = $g['by'][$m]; ?>
          <?php if ($r === null): ?>
            <td colspan="2" class="pend"><?= $dateFilterActive ? 'No record in date range' : 'Pending' ?></td>
          <?php else:
            $lower = strtolower($r['nutritional_status']);
            $flag = str_contains($lower, 'under') || str_contains($lower, 'severe');
            $st = $r['nutritional_status'] === 'Pending child reference' ? 'Pending' : $r['nutritional_status']; ?>
            <td><strong><?= e($fmtNum($r['height_cm'])) ?> cm / <?= e($fmtNum($r['weight_kg'])) ?> kg</strong><span class="sub"><?= e($fmtD($r['record_date'])) ?></span></td>
            <td class="c"><strong><?= e(number_format((float)$r['bmi'], 2, '.', '')) ?></strong><span class="sub<?= $flag ? ' flag' : '' ?>"><?= e($st) ?></span></td>
          <?php endif; ?>
        <?php endforeach; ?>
      </tr>
      <?php endforeach; ?>
      <?php if (!$recordGroups): ?><tr><td colspan="9" class="pend">No health data to report.</td></tr><?php endif; ?>
    </tbody>
  </table>

  <h2 class="sec">III. Report Analysis &mdash; BMI Categories by Growth Milestone</h2>
  <div class="charts">
    <?php foreach ($milestoneCharts as $chart): ?>
    <div class="chart">
      <h3><?= e($chart['label']) ?> (n = <?= (int)$chart['total'] ?>)</h3>
      <div class="bar-chart" role="img" aria-label="<?= e($chart['label'] . ' BMI categories: ' . implode(', ', array_map(fn($cat, $count) => $cat . ' ' . $count, array_keys($chart['counts']), $chart['counts']))) ?>">
        <?php foreach ($chart['counts'] as $cat => $n): ?>
        <div class="bar-row">
          <strong><?= (int)$n ?></strong>
          <span class="bar-track"><span class="bar-fill" style="height: <?= $chart['max'] ? ($n / $chart['max'] * 100) : 0 ?>%; background: <?= e($chart['colors'][$cat]) ?>;"></span></span>
          <span class="bar-label"><?= e($cat) ?></span>
        </div>
        <?php endforeach; ?>
        <?php if ($chart['total'] === 0): ?><div>No records yet.</div><?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <h2 class="sec">IV. Attendance, Age and Gender Profile</h2>
  <div class="charts">
    <div class="chart">
      <h3>Attendance Participation</h3>
      <?php if ($attTotal > 0 && $attendanceChartPoints): ?>
      <svg class="attendance-chart" viewBox="0 0 400 160" role="img" aria-label="<?= e('Daily attendance counts for present and absent records, ' . $attendanceChartPoints[0]['label'] . ' through ' . $attendanceChartPoints[count($attendanceChartPoints) - 1]['label']) ?>">
        <line x1="36" y1="26" x2="364" y2="26" class="attendance-grid" />
        <line x1="36" y1="76" x2="364" y2="76" class="attendance-grid" />
        <line x1="36" y1="126" x2="364" y2="126" class="attendance-grid" />
        <text x="30" y="29" text-anchor="end" class="attendance-axis"><?= (int)$attendanceChartMax ?></text>
        <text x="30" y="79" text-anchor="end" class="attendance-axis"><?= (int)ceil($attendanceChartMax / 2) ?></text>
        <text x="30" y="129" text-anchor="end" class="attendance-axis">0</text>
        <polyline points="<?= e($attendancePresentLine) ?>" class="attendance-line present" />
        <polyline points="<?= e($attendanceAbsentLine) ?>" class="attendance-line absent" />
        <?php if ($attendanceDateCount === 1): ?>
        <circle cx="<?= number_format($attendanceChartPoints[0]['x'], 1, '.', '') ?>" cy="<?= number_format($attendanceChartPoints[0]['presentY'], 1, '.', '') ?>" r="4" fill="#1a9c53" />
        <circle cx="<?= number_format($attendanceChartPoints[0]['x'], 1, '.', '') ?>" cy="<?= number_format($attendanceChartPoints[0]['absentY'], 1, '.', '') ?>" r="4" fill="#e53e3e" />
        <?php endif; ?>
        <?php foreach ($attendanceLabelTicks as $tick): ?>
        <text x="<?= number_format($tick['x'], 1, '.', '') ?>" y="150" text-anchor="middle" class="attendance-axis"><?= e($tick['label']) ?></text>
        <?php endforeach; ?>
      </svg>
      <?php else: ?>
      <div>No attendance records match this scope.</div>
      <?php endif; ?>
      <div class="attendance-legend">
        <?php foreach ($attCounts as $key => $count): ?>
        <span><span class="sw" style="background:<?= $attColors[$key] ?>;"></span><?= e($attLabels[$key]) ?></span>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="chart">
      <h3>Age Distribution</h3>
      <div class="bar-chart" role="img" aria-label="<?= e('Age distribution: ' . implode(', ', array_map(fn($label, $count) => $label . ' years ' . $count, array_keys($ageBuckets), $ageBuckets))) ?>">
        <?php foreach ($ageBuckets as $label => $count): ?>
        <div class="bar-row">
          <strong><?= (int)$count ?></strong>
          <span class="bar-track"><span class="bar-fill" style="height: <?= $ageChartMax ? ($count / $ageChartMax * 100) : 0 ?>%; background: <?= e($ageColors[$label]) ?>;"></span></span>
          <span class="bar-label"><?= e($label) ?> years</span>
        </div>
        <?php endforeach; ?>
        <?php if ($ageTotal === 0): ?><div>No beneficiaries in scope.</div><?php endif; ?>
      </div>
    </div>
    <div class="chart">
      <h3>Gender Distribution</h3>
      <div class="pie" style="background: conic-gradient(<?= $sexGradient ?>);"></div>
      <div class="legend">
        <?php foreach ($sexCounts as $label => $count): ?>
        <div><span class="sw" style="background:<?= $sexColors[$label] ?>;"></span><?= e($label) ?><b><?= (int)$count ?> (<?= e($pct($count, $sexTotal)) ?>)</b></div>
        <?php endforeach; ?>
        <?php if ($sexTotal === 0): ?><div>No beneficiaries in scope.</div><?php endif; ?>
      </div>
    </div>
  </div>

  <h2 class="sec">V. Attendance</h2>
  <?php if (!$attendanceDates): ?>
    <p class="caption">No attendance days match the selected filters.</p>
  <?php elseif (!$attendanceBeneficiaries): ?>
    <p class="caption">No beneficiaries match the selected filters.</p>
  <?php else: ?>
    <table class="data attendance-register">
      <colgroup>
        <col class="id-col">
        <col class="name-col">
        <col class="active-days-col">
        <col class="count-col">
        <col class="count-col">
        <col class="rate-col">
        <col class="rate-col">
      </colgroup>
      <thead>
        <tr>
          <th>ID</th>
          <th>Beneficiary</th>
          <th>Days Active</th>
          <th>Present</th>
          <th>Absent</th>
          <th>Present Rate</th>
          <th>Absent Rate</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($attendanceBeneficiaries as $attendanceBeneficiary): ?>
        <?php $beneficiaryAttendance = $attendanceTotals[(int)$attendanceBeneficiary['beneficiary_id']]; ?>
        <tr>
          <td><?= e($attendanceBeneficiary['beneficiary_code'] ?: '—') ?></td>
          <td class="name single-line-cell"><?= e($attendanceBeneficiary['full_name']) ?></td>
          <td><?= (int)$beneficiaryAttendance['active_days'] ?></td>
          <td><?= (int)$beneficiaryAttendance['present'] ?></td>
          <td><?= (int)$beneficiaryAttendance['absent'] ?></td>
          <td><?= $beneficiaryAttendance['marked_days'] > 0 ? number_format($beneficiaryAttendance['present'] / $beneficiaryAttendance['marked_days'] * 100, 0) . '%' : '—' ?></td>
          <td><?= $beneficiaryAttendance['marked_days'] > 0 ? number_format($beneficiaryAttendance['absent'] / $beneficiaryAttendance['marked_days'] * 100, 0) . '%' : '—' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <div class="signatures">
    <div class="sig"><div class="line"><?= e($preparedBy ?: ' ') ?></div><div><span class="position"><?= e($preparedByRole) ?></span></div>Prepared by</div>
    <div class="sig"><div class="line">Welma L. Omboy</div><div><span class="position">Director</span></div>Witnessed by</div>
  </div>

  <div class="foot"><?= e(APP_NAME) ?> &middot; <?= e(APP_FULL_NAME) ?> &middot; Generated <?= e(date('m-d-Y g:i A')) ?></div>
</div>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });</script>
</body>
</html>