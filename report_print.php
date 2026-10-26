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
$fmtD = fn(string $d): string => ($ts = strtotime($d)) ? date('F j, Y', $ts) : $d;
$period = $implementationPeriod;
$statusScope = $status !== '' ? beneficiary_status_label($status) : 'All statuses';
$preparedBy = $_SESSION['user_name'] ?? '';
$n2 = fn($n): string => rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.');
$pct = fn(int $n, int $t): string => $t > 0 ? number_format($n / $t * 100, 1) . '%' : '—';

// Status counts for the overall summary (latest classification of each record in scope).
$statusTotals = [];
foreach ($rows as $r) {
    $k = $r['nutritional_status'] === 'Pending child reference' ? 'Not yet classified' : $r['nutritional_status'];
    $statusTotals[$k] = ($statusTotals[$k] ?? 0) + 1;
}
arsort($statusTotals);
$attRate = $attTotal > 0 ? number_format($attCounts['PRESENT'] / $attTotal * 100, 1) . '%' : '—';
$rowNo = 0;
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
  table.data .flag { font-weight: 700; }
  table.data .pend { color: #666; font-style: italic; text-align: center; }
  table.data tfoot td { font-weight: 700; background: #f1f3f8; }
  .caption { font-size: 8.5pt; font-style: italic; margin: 0 0 8px; }

  .charts { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; break-inside: avoid; }
  .chart { border: 1px solid #333; padding: 8px; text-align: center; break-inside: avoid; }
  .chart h3 { margin: 0 0 6px; font-size: 9.5pt; }
  .pie { width: 1.05in; height: 1.05in; border-radius: 50%; margin: 0 auto 6px; border: 1px solid #333; }
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
  .sig .line { border-top: 1px solid #111; margin-top: 0.5in; padding-top: 3px; font-weight: 700; }
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
      <div class="sys"><?= e(APP_FULL_NAME) ?></div>
    </div>
    <img src="assets/images/CCES-logo.png" alt="">
  </header>

  <h1 class="title">Health Monitoring Report</h1>
  <div class="subtitle">Nutritional Status and Attendance Analysis</div>

  <table class="meta">
    <tr><td class="k">Feeding Site</td><td>: <?= e($scopeSite) ?></td><td class="k">Date Generated</td><td>: <?= e(date('F j, Y')) ?></td></tr>
    <tr><td class="k">Program</td><td>: <?= e($scopeProgram) ?></td><td class="k">Period Covered</td><td>: <?= e($period) ?></td></tr>
    <tr><td class="k">Program Status</td><td>: <?= e($statusScope) ?></td><td class="k">Prepared By</td><td>: <?= e($preparedBy ?: '—') ?></td></tr>
  </table>

  <h2 class="sec">I. Summary</h2>
  <p>This report covers <strong><?= (int)$uniqueBeneficiaries ?></strong> beneficiar<?= $uniqueBeneficiaries === 1 ? 'y' : 'ies' ?> with a total of <strong><?= (int)$totalRecords ?></strong> health record<?= $totalRecords === 1 ? '' : 's' ?>
  <?php if ($avgBmi !== null): ?>and an average BMI of <strong><?= e($n2($avgBmi)) ?></strong> kg/m&sup2;<?php endif; ?>.
  Attendance in the selected scope was recorded at <strong><?= e($attRate) ?></strong> participation (<?= (int)$attCounts['PRESENT'] ?> present, <?= (int)$attCounts['ABSENT'] ?> absent out of <?= (int)$attTotal ?> record<?= $attTotal === 1 ? '' : 's' ?>).</p>
  <?php if ($statusTotals): ?>
  <table class="data" style="width:60%;">
    <thead><tr><th>Weight Status (all milestones)</th><th style="width:0.8in;">Records</th><th style="width:0.8in;">Share</th></tr></thead>
    <tbody>
      <?php foreach ($statusTotals as $k => $n): ?>
      <tr><td><?= e($k) ?></td><td class="c"><?= (int)$n ?></td><td class="c"><?= e($pct($n, $totalRecords)) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td>Total</td><td class="c"><?= (int)$totalRecords ?></td><td class="c">100%</td></tr></tfoot>
  </table>
  <?php endif; ?>

  <h2 class="sec">II. Health Monitoring Records</h2>
  <p class="caption">Height (cm), weight (kg) and BMI (kg/m&sup2;) per growth milestone. Weight status follows the Asian BMI cut-offs.</p>
  <table class="data">
    <thead>
      <tr>
        <th rowspan="2" style="width:0.28in;">No.</th>
        <th rowspan="2">Beneficiary</th>
        <th rowspan="2" style="width:0.5in;">Grade / Sex</th>
        <?php foreach (MILESTONES as $m): ?><th colspan="2"><?= e(milestone_label($m)) ?></th><?php endforeach; ?>
      </tr>
      <tr>
        <?php foreach (MILESTONES as $m): ?><th>Date &middot; Ht / Wt</th><th>BMI &middot; Status</th><?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($recordGroups as $g): $info = $g['info']; $rowNo++; ?>
      <tr>
        <td class="c"><?= $rowNo ?></td>
        <td><strong><?= e($info['full_name']) ?></strong><span class="sub"><?= e($info['beneficiary_code'] ?: '—') ?> &middot; <?= e($fmtDate($info['birth_date'])) ?></span></td>
        <td class="c"><?= e($info['grade_level'] ?: '—') ?><span class="sub"><?= e($info['sex']) ?></span></td>
        <?php foreach (MILESTONES as $m): $r = $g['by'][$m]; ?>
          <?php if ($r === null): ?>
            <td colspan="2" class="pend"><?= $dateFilterActive ? 'No record in date range' : 'Pending' ?></td>
          <?php else:
            $lower = strtolower($r['nutritional_status']);
            $flag = str_contains($lower, 'under') || str_contains($lower, 'severe');
            $st = $r['nutritional_status'] === 'Pending child reference' ? 'Pending' : $r['nutritional_status']; ?>
            <td><?= e($fmtDate($r['record_date'])) ?><span class="sub"><?= e($fmtNum($r['height_cm'])) ?> cm / <?= e($fmtNum($r['weight_kg'])) ?> kg</span></td>
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
      <div class="pie" style="background: conic-gradient(<?= $chart['gradient'] ?>);"></div>
      <div class="legend">
        <?php foreach ($chart['counts'] as $cat => $n): ?>
        <div><span class="sw" style="background:<?= $chart['colors'][$cat] ?>;"></span><?= e($cat) ?><b><?= (int)$n ?> (<?= e($pct($n, $chart['total'])) ?>)</b></div>
        <?php endforeach; ?>
        <?php if ($chart['total'] === 0): ?><div>No records yet.</div><?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <h2 class="sec">IV. Attendance, Age and Gender Profile</h2>
  <div class="pair trio">
    <div class="chart">
      <div class="pie" style="background: conic-gradient(<?= $attGradient ?>);"></div>
      <div class="legend">
        <strong>Attendance Participation</strong>
        <?php foreach ($attCounts as $key => $count): ?>
        <div><span class="sw" style="background:<?= $attColors[$key] ?>;"></span><?= e($attLabels[$key]) ?><b><?= (int)$count ?> (<?= e($pct($count, $attTotal)) ?>)</b></div>
        <?php endforeach; ?>
        <?php if ($attTotal === 0): ?><div>No attendance records.</div><?php endif; ?>
      </div>
    </div>
    <div class="chart">
      <div class="pie" style="background: conic-gradient(<?= $pieGradient ?>);"></div>
      <div class="legend">
        <strong>Age Distribution</strong>
        <?php foreach ($ageBuckets as $label => $count): ?>
        <div><span class="sw" style="background:<?= $ageColors[$label] ?>;"></span><?= e($label) ?> yrs<b><?= (int)$count ?> (<?= e($pct($count, $ageTotal)) ?>)</b></div>
        <?php endforeach; ?>
        <?php if ($ageTotal === 0): ?><div>No beneficiaries in scope.</div><?php endif; ?>
      </div>
    </div>
    <div class="chart">
      <div class="pie" style="background: conic-gradient(<?= $sexGradient ?>);"></div>
      <div class="legend">
        <strong>Gender Distribution</strong>
        <?php foreach ($sexCounts as $label => $count): ?>
        <div><span class="sw" style="background:<?= $sexColors[$label] ?>;"></span><?= e($label) ?><b><?= (int)$count ?> (<?= e($pct($count, $sexTotal)) ?>)</b></div>
        <?php endforeach; ?>
        <?php if ($sexTotal === 0): ?><div>No beneficiaries in scope.</div><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="signatures">
    <div class="sig"><div class="line"><?= e($preparedBy ?: ' ') ?></div>Prepared by</div>
    <div class="sig"><div class="line">&nbsp;</div>Noted by</div>
  </div>

  <div class="foot"><?= e(APP_NAME) ?> &middot; <?= e(APP_FULL_NAME) ?> &middot; Generated <?= e(date('F j, Y g:i A')) ?></div>
</div>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });</script>
</body>
</html>