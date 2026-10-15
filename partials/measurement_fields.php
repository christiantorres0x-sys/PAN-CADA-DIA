<?php
/**
 * Height (cm) + weight (kg) inputs (shared by the Record
 * Measurement form and the baseline section of the Add Beneficiary form).
 * Renders two cells for a .form-grid. Units are fixed to cm and kg; the hidden
 * unit fields only keep parse_height_cm() / parse_weight_kg() and assets/app.js
 * working unchanged (live BMI preview).
 *
 * Expects: $mPrefix (field-name prefix, e.g. '' or 'baseline_'),
 *          $mRequired (bool).
 */
$mPrefix = $mPrefix ?? '';
$mRequired = !empty($mRequired);
$mHeightCm = $mHeightCm ?? null;   // optional prefill, always in cm
$mWeightKg = $mWeightKg ?? null;   // optional prefill, always in kg
$mFmt = fn($v) => $v === null ? '' : rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
$mStar = $mRequired ? ' *' : '';
$rowStyle = 'display:flex; gap:6px; align-items:center;';
?>
<div data-measure="height">
    <label>Height (cm)<?= $mStar ?></label>
    <div style="<?= $rowStyle ?>">
        <input type="number" name="<?= $mPrefix ?>height_cm" data-m="cm" min="40" max="220" step="any" inputmode="decimal" placeholder="e.g. 115" value="<?= e($mFmt($mHeightCm)) ?>" aria-label="Height in centimeters" style="flex:1; min-width:0;" <?= $mRequired ? 'required' : '' ?>>
        <span style="color:var(--muted, #667); font-weight:600;">cm</span>
        <span data-m="ftin" style="display:none;">
            <input type="hidden" name="<?= $mPrefix ?>height_ft" data-m="ft">
            <input type="hidden" name="<?= $mPrefix ?>height_in" data-m="in">
        </span>
        <select name="<?= $mPrefix ?>height_unit" data-m="height-unit" style="display:none;"><option value="cm" selected>cm</option></select>
    </div>
</div>
<div data-measure="weight">
    <label>Weight (kg)<?= $mStar ?></label>
    <div style="<?= $rowStyle ?>">
        <input type="number" name="<?= $mPrefix ?>weight_value" data-m="weight" min="3" max="200" step="any" inputmode="decimal" placeholder="e.g. 20.5" value="<?= e($mFmt($mWeightKg)) ?>" aria-label="Weight in kilograms" style="flex:1; min-width:0;" <?= $mRequired ? 'required' : '' ?>>
        <span style="color:var(--muted, #667); font-weight:600;">kg</span>
        <select name="<?= $mPrefix ?>weight_unit" data-m="weight-unit" style="display:none;"><option value="kg" selected>kg</option></select>
    </div>
</div>
