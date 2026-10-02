<?php

declare(strict_types=1);

namespace Tests\Concerns;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

trait CreatesSpreadsheets
{
    /**
     * Writes rows (header included) to a temporary xlsx file and returns its path.
     *
     * @param  list<list<mixed>|null>  $rows  null writes an empty row
     */
    protected function makeXlsx(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';

        $writer = new Writer;
        $writer->openToFile($path);

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row ?? []));
        }

        $writer->close();

        return $path;
    }
}
