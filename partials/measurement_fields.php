<?php
/**
 * Height + weight inputs with unit selectors (shared by the Record
 * Measurement form and the baseline section of the Add Beneficiary form).
 * Renders two cells for a .form-grid. The server converts whatever is typed
 * into cm / kg (see parse_height_cm() / parse_weight_kg() in config.php);
 * assets/app.js only drives the unit toggle and the live BMI preview.
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
    <label>Height<?= $mStar ?></label>
    <div style="<?= $rowStyle ?>">
        <input type="number" name="<?= $mPrefix ?>height_cm" data-m="cm" min="40" max="220" step="any" inputmode="decimal" placeholder="e.g. 115" value="<?= e($mFmt($mHeightCm)) ?>" aria-label="Height in centimeters" style="flex:1; min-width:0;" <?= $mRequired ? 'required' : '' ?>>
        <span data-m="ftin" style="display:none; flex:1; min-width:0; gap:6px;">
            <input type="number" name="<?= $mPrefix ?>height_ft" data-m="ft" min="1" max="7" step="1" inputmode="numeric" placeholder="ft" aria-label="Height, feet" style="flex:1; min-width:0;">
            <input type="number" name="<?= $mPrefix ?>height_in" data-m="in" min="0" max="11.9" step="0.1" inputmode="decimal" placeholder="in" aria-label="Height, inches" style="flex:1; min-width:0;">
        </span>
        <select name="<?= $mPrefix ?>height_unit" data-m="height-unit" aria-label="Height unit" style="width:auto; flex:none;">
            <option value="cm">cm</option>
            <option value="ftin">ft / in</option>
        </select>
    </div>
</div>
<div data-measure="weight">
    <label>Weight<?= $mStar ?></label>
    <div style="<?= $rowStyle ?>">
        <input type="number" name="<?= $mPrefix ?>weight_value" data-m="weight" min="3" max="200" step="any" inputmode="decimal" placeholder="e.g. 20.5" value="<?= e($mFmt($mWeightKg)) ?>" aria-label="Weight" style="flex:1; min-width:0;" <?= $mRequired ? 'required' : '' ?>>
        <select name="<?= $mPrefix ?>weight_unit" data-m="weight-unit" aria-label="Weight unit" style="width:auto; flex:none;">
            <option value="kg">kg</option>
            <option value="lb">lb</option>
        </select>
    </div>
</div>