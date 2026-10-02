<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Result of validating one spreadsheet row: normalized values, or the list of errors.
 */
final readonly class ValidatedRow
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        public int $line,
        public ?string $id,
        public ?string $name,
        public ?string $date,
        public array $errors,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
