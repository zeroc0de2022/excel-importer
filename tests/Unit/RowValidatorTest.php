<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Import\RowValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RowValidatorTest extends TestCase
{
    private RowValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new RowValidator;
    }

    public function test_valid_row_is_normalized(): void
    {
        $row = $this->validator->validate(2, ['1015295', 'Juliana Schulze', '18.12.1990']);

        $this->assertTrue($row->isValid());
        $this->assertSame(2, $row->line);
        $this->assertSame('1015295', $row->id);
        $this->assertSame('Juliana Schulze', $row->name);
        $this->assertSame('1990-12-18', $row->date);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function validIds(): array
    {
        return [
            'zero' => ['0', '0'],
            'plain string' => ['42', '42'],
            'leading zeros' => ['007', '7'],
            'surrounding spaces' => [' 12 ', '12'],
            'int cell' => [42, '42'],
            'whole float cell' => [42.0, '42'],
            'unsigned bigint max' => ['18446744073709551615', '18446744073709551615'],
            'above signed bigint max' => ['9223372036854775808', '9223372036854775808'],
        ];
    }

    #[DataProvider('validIds')]
    public function test_valid_ids(mixed $input, string $expected): void
    {
        $row = $this->validator->validate(2, [$input, 'John', '01.01.2000']);

        $this->assertTrue($row->isValid(), implode(', ', $row->errors));
        $this->assertSame($expected, $row->id);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidIds(): array
    {
        return [
            'negative' => ['-1'],
            'negative int' => [-1],
            'decimal' => ['1.5'],
            'fractional float' => [1.5],
            'exponent' => ['1e5'],
            'inner spaces' => ['2 003 377'],
            'letters' => ['12a'],
            'empty' => [''],
            'null' => [null],
            'above unsigned bigint max' => ['18446744073709551616'],
            'too many digits' => ['100000000000000000000'],
            'float beyond exact precision' => [2.0 ** 53],
        ];
    }

    #[DataProvider('invalidIds')]
    public function test_invalid_ids(mixed $input): void
    {
        $row = $this->validator->validate(2, [$input, 'John', '01.01.2000']);

        $this->assertSame([RowValidator::ERROR_ID], $row->errors);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function validNames(): array
    {
        return [
            'single word' => ['John', 'John'],
            'with spaces' => ['Mary Ann Smith', 'Mary Ann Smith'],
            'lower case' => ['john', 'john'],
            'trimmed' => ['  John Smith ', 'John Smith'],
        ];
    }

    #[DataProvider('validNames')]
    public function test_valid_names(string $input, string $expected): void
    {
        $row = $this->validator->validate(2, ['1', $input, '01.01.2000']);

        $this->assertTrue($row->isValid());
        $this->assertSame($expected, $row->name);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidNames(): array
    {
        return [
            'digit' => ['John3'],
            'cyrillic' => ['Иван'],
            'apostrophe' => ["O'Brien"],
            'hyphen' => ['Mary-Jane'],
            'non-breaking space' => ["John\u{00A0}Smith"],
            'only spaces' => ['   '],
            'empty' => [''],
            'null' => [null],
            'number cell' => [123],
        ];
    }

    #[DataProvider('invalidNames')]
    public function test_invalid_names(mixed $input): void
    {
        $row = $this->validator->validate(2, ['1', $input, '01.01.2000']);

        $this->assertSame([RowValidator::ERROR_NAME], $row->errors);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function validDates(): array
    {
        return [
            'regular' => ['18.12.1990', '1990-12-18'],
            'leap day' => ['29.02.2024', '2024-02-29'],
            'leap day in 2000' => ['29.02.2000', '2000-02-29'],
            'trimmed' => [' 01.01.2020 ', '2020-01-01'],
        ];
    }

    #[DataProvider('validDates')]
    public function test_valid_dates(string $input, string $expected): void
    {
        $row = $this->validator->validate(2, ['1', 'John', $input]);

        $this->assertTrue($row->isValid());
        $this->assertSame($expected, $row->date);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidDates(): array
    {
        return [
            'leap day in a common year' => ['29.02.2023'],
            'leap day in 1900' => ['29.02.1900'],
            'day 31 in a 30-day month' => ['31.04.2020'],
            'month 13' => ['11.13.1991'],
            'day zero' => ['00.01.2020'],
            'no zero padding' => ['1.2.2020'],
            'ISO format' => ['2020-01-01'],
            'slashes' => ['08/11/1999'],
            'two-digit year' => ['01.01.20'],
            'empty' => [''],
            'null' => [null],
            'number cell' => [44000],
        ];
    }

    #[DataProvider('invalidDates')]
    public function test_invalid_dates(mixed $input): void
    {
        $row = $this->validator->validate(2, ['1', 'John', $input]);

        $this->assertSame([RowValidator::ERROR_DATE], $row->errors);
    }

    public function test_all_errors_are_collected_in_column_order(): void
    {
        $row = $this->validator->validate(7, ['-1', 'John3', '31.02.2020']);

        $this->assertFalse($row->isValid());
        $this->assertSame([RowValidator::ERROR_ID, RowValidator::ERROR_NAME, RowValidator::ERROR_DATE], $row->errors);
    }

    public function test_missing_cells_are_invalid(): void
    {
        $row = $this->validator->validate(3, []);

        $this->assertCount(3, $row->errors);
    }
}
