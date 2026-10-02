<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Validates a spreadsheet row against the task's rules:
 *  - id:   unsigned big integer (0 .. 18446744073709551615)
 *  - name: English letters and spaces only
 *  - date: an existing date in d.m.Y format
 */
final class RowValidator
{
    public const ID_MAX = '18446744073709551615';

    public const ERROR_ID = 'id must be an unsigned big integer';

    public const ERROR_NAME = 'name must contain only English letters and spaces';

    public const ERROR_DATE = 'date must be a valid date in d.m.Y format';

    /**
     * @param  array<int, mixed>  $cells  [id, name, date] as read from the file
     */
    public function validate(int $line, array $cells): ValidatedRow
    {
        $id = $this->normalizeId($cells[0] ?? null);
        $name = $this->normalizeName($cells[1] ?? null);
        $date = $this->normalizeDate($cells[2] ?? null);

        $errors = [];

        if ($id === null) {
            $errors[] = self::ERROR_ID;
        }

        if ($name === null) {
            $errors[] = self::ERROR_NAME;
        }

        if ($date === null) {
            $errors[] = self::ERROR_DATE;
        }

        return new ValidatedRow($line, $id, $name, $date, $errors);
    }

    /**
     * Returns the id as a digit string without leading zeros, or null if invalid.
     * A string is used because the unsigned bigint range doesn't fit in a PHP int.
     */
    private function normalizeId(mixed $value): ?string
    {
        if (is_int($value)) {
            return $value >= 0 ? (string) $value : null;
        }

        // Numeric Excel cells arrive as floats; only whole numbers that a float
        // represents exactly (below 2^53) are safe to accept.
        if (is_float($value)) {
            return $value >= 0 && $value < 2 ** 53 && floor($value) === $value
                ? (string) (int) $value
                : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (! ctype_digit($value)) {
            return null;
        }

        $value = ltrim($value, '0') ?: '0';

        // Compare as strings: a longer number is bigger; equal length compares lexicographically
        if (strlen($value) > strlen(self::ID_MAX)
            || (strlen($value) === strlen(self::ID_MAX) && strcmp($value, self::ID_MAX) > 0)) {
            return null;
        }

        return $value;
    }

    private function normalizeName(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return preg_match('/^[A-Za-z ]+$/', $value) === 1 ? $value : null;
    }

    /**
     * Returns the date as Y-m-d (database format), or null if invalid.
     */
    private function normalizeDate(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', trim($value), $m) !== 1) {
            return null;
        }

        [, $day, $month, $year] = $m;

        // checkdate() rejects dates that don't exist, like 31.04 or 29.02 in a non-leap year
        if (! checkdate((int) $month, (int) $day, (int) $year)) {
            return null;
        }

        return "{$year}-{$month}-{$day}";
    }
}
