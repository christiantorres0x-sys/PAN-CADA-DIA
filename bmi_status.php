<?php
/**
 * Weight status from BMI, using the Asian (WHO Asia-Pacific) BMI cut-offs.
 *
 *   BMI below 16.0         Severely Underweight
 *   BMI 16.0  - 18.4       Underweight
 *   BMI 18.5  - 22.9       Normal
 *   BMI 23.0  - 24.9       Overweight
 *   BMI 25.0 and above     Obese
 *
 * Status is now calculated automatically on save (record, edit, and the
 * baseline taken during enrollment), so everyone gets the same result.
 * The same cut-offs are mirrored in assets/app.js for the live preview -
 * if you change them here, change them there too.
 */
function pcd_weight_status(float $bmi): string {
    if ($bmi < 16.0) return 'Severely Underweight';
    if ($bmi < 18.5) return 'Underweight';
    if ($bmi < 23.0) return 'Normal';
    if ($bmi < 25.0) return 'Overweight';
    return 'Obese';
}
