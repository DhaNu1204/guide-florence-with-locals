<?php
/**
 * Validator.php - Input Validation Helper Class
 *
 * Provides reusable validation methods for API inputs.
 * All methods return true on success or a string error message on failure.
 */

class Validator {

    /**
     * Validate that a value is not empty
     */
    public static function required($value, $fieldName = 'Field') {
        if ($value === null || $value === '' || (is_array($value) && empty($value))) {
            return "$fieldName is required";
        }
        return true;
    }

    /**
     * Validate email format
     */
    public static function email($value, $fieldName = 'Email') {
        if (empty($value)) {
            return true; // Empty is allowed, use required() to enforce
        }
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return "$fieldName must be a valid email address";
        }
        return true;
    }

    /**
     * Validate string length
     */
    public static function maxLength($value, $max, $fieldName = 'Field') {
        if (strlen($value) > $max) {
            return "$fieldName must not exceed $max characters";
        }
        return true;
    }

    /**
     * Validate minimum string length
     */
    public static function minLength($value, $min, $fieldName = 'Field') {
        if (strlen($value) < $min) {
            return "$fieldName must be at least $min characters";
        }
        return true;
    }

    /**
     * Validate integer
     */
    public static function integer($value, $fieldName = 'Field') {
        if (!is_numeric($value) || intval($value) != $value) {
            return "$fieldName must be an integer";
        }
        return true;
    }

    /**
     * Validate positive integer (greater than 0)
     */
    public static function positiveInt($value, $fieldName = 'Field') {
        $intCheck = self::integer($value, $fieldName);
        if ($intCheck !== true) {
            return $intCheck;
        }
        if (intval($value) <= 0) {
            return "$fieldName must be a positive integer";
        }
        return true;
    }

    /**
     * Validate date format (Y-m-d)
     */
    public static function date($value, $fieldName = 'Date') {
        if (empty($value)) {
            return true; // Empty is allowed, use required() to enforce
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return "$fieldName must be in YYYY-MM-DD format";
        }
        // Validate actual date
        $parts = explode('-', $value);
        if (!checkdate($parts[1], $parts[2], $parts[0])) {
            return "$fieldName is not a valid date";
        }
        return true;
    }

    /**
     * Validate time format (H:i or H:i:s)
     */
    public static function time($value, $fieldName = 'Time') {
        if (empty($value)) {
            return true;
        }
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value)) {
            return "$fieldName must be in HH:MM or HH:MM:SS format";
        }
        return true;
    }

    /**
     * Validate phone number (basic validation)
     */
    public static function phone($value, $fieldName = 'Phone') {
        if (empty($value)) {
            return true;
        }
        // Allow digits, spaces, dashes, parentheses, and plus sign
        if (!preg_match('/^[\d\s\-\(\)\+]+$/', $value)) {
            return "$fieldName contains invalid characters";
        }
        // Minimum 7 digits
        $digits = preg_replace('/\D/', '', $value);
        if (strlen($digits) < 7) {
            return "$fieldName must have at least 7 digits";
        }
        return true;
    }

    /**
     * Validate numeric value (including decimals)
     */
    public static function numeric($value, $fieldName = 'Field') {
        if (!is_numeric($value)) {
            return "$fieldName must be a number";
        }
        return true;
    }

    /**
     * Validate value is in allowed list
     */
    public static function inArray($value, $allowedValues, $fieldName = 'Field') {
        if (!in_array($value, $allowedValues, true)) {
            $allowed = implode(', ', $allowedValues);
            return "$fieldName must be one of: $allowed";
        }
        return true;
    }

    /**
     * Validate boolean
     */
    public static function boolean($value, $fieldName = 'Field') {
        if (!is_bool($value) && $value !== 0 && $value !== 1 && $value !== '0' && $value !== '1') {
            return "$fieldName must be a boolean value";
        }
        return true;
    }

    /**
     * Validate URL format
     */
    public static function url($value, $fieldName = 'URL') {
        if (empty($value)) {
            return true;
        }
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            return "$fieldName must be a valid URL";
        }
        return true;
    }

    /**
     * Sanitize string - remove dangerous characters
     */
    public static function sanitizeString($value) {
        return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Sanitize for SQL (use prepared statements instead when possible)
     */
    public static function sanitizeForDb($conn, $value) {
        return $conn->real_escape_string(trim($value));
    }

    /**
     * Validate multiple fields at once
     *
     * @param array $rules Array of [field => [value, [validations]]]
     * @return array ['valid' => bool, 'errors' => array]
     *
     * Example:
     * $result = Validator::validateAll([
     *     'name' => [$name, ['required', ['maxLength', 255]]],
     *     'email' => [$email, ['email']],
     *     'age' => [$age, [['positiveInt']]]
     * ]);
     */
    public static function validateAll($rules) {
        $errors = [];

        foreach ($rules as $fieldName => $config) {
            $value = $config[0];
            $validations = $config[1];

            foreach ($validations as $validation) {
                // Handle validation with parameters
                if (is_array($validation)) {
                    $method = array_shift($validation);
                    $params = array_merge([$value], $validation, [$fieldName]);
                    $result = call_user_func_array([self::class, $method], $params);
                } else {
                    // Simple validation (no extra parameters)
                    $result = self::$validation($value, $fieldName);
                }

                if ($result !== true) {
                    $errors[$fieldName] = $result;
                    break; // Stop at first error for this field
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Quick validation check - throws exception on failure
     */
    public static function check($value, $validation, $fieldName = 'Field', ...$params) {
        $args = array_merge([$value], $params, [$fieldName]);
        $result = call_user_func_array([self::class, $validation], $args);

        if ($result !== true) {
            throw new ValidationException($result);
        }

        return true;
    }
}

/**
 * Custom exception for validation errors
 */
class ValidationException extends Exception {
    public function __construct($message) {
        parent::__construct($message);
    }
}
