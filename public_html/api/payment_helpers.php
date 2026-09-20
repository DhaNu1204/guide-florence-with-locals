<?php
/**
 * payment_helpers.php - step 3.8: the rules every payment write must pass.
 * Functions only - no output, no routing, safe to require_once (same pattern as group_helpers.php).
 */

if (!function_exists('paymentAmountError')) {
    /**
     * The single gate on an amount written to `payments`.
     * Returns null when the value is acceptable, or the message to send with HTTP 400.
     *
     * The checks this replaced let a non-numeric amount through: `empty()` is false for the
     * string "abc", and in PHP 8 `"abc" <= 0` compares two strings and is false, so the value
     * reached the prepared statement and was bound as 0.00. On the PUT path
     * `isset($x) && $x > 0` simply skipped the field, so a bad amount was silently ignored
     * instead of rejected.
     *
     * This changes no payment semantics: not the model, not 1 group = 1 payment, not the 409.
     */
    function paymentAmountError($value) {
        if ($value === null || is_array($value) || is_bool($value)) {
            return 'Amount is required and must be a number greater than 0';
        }
        if (is_string($value) && trim($value) === '') {
            return 'Amount is required and must be a number greater than 0';
        }
        if (!is_numeric($value)) {
            return 'Amount must be a number greater than 0';
        }
        $amount = (float) $value;
        if (!is_finite($amount) || $amount <= 0) {
            return 'Amount must be a number greater than 0';
        }
        if ($amount > 100000) {
            return 'Amount looks wrong (over 100000)';
        }
        return null;
    }
}
