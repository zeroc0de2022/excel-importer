<?php
namespace App\Jobs;

use App\Models\Row;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use App\Events\RowCreated;

class ProcessExcelChunk implements \Illuminate\Contracts\Queue\ShouldQueue
{
    protected $chunk;
    protected $hash;

    public function __construct(array $chunk, string $hash)
    {
        $this->chunk = $chunk;
        $this->hash = $hash;
    }

    public function handle()
    {
        $errors = [];
        $processed = Redis::get("import_progress:{$this->hash}") ?? 0;

        foreach ($this->chunk as $index => $row) {
            $line = $processed + $index + 2; // +2 потому что пропущен заголовок и индексация с 0
            [$id, $name, $date] = $row;

            $lineErrors = [];

            if (!is_numeric($id) || $id < 0) {
                $lineErrors[] = 'Неверный ID';
            }

            if (!preg_match('/^[A-Za-z ]+$/', $name)) {
                $lineErrors[] = 'Неверное имя';
            }

            $dt = \DateTime::createFromFormat('d.m.Y', $date);
            if (!$dt || $dt->format('d.m.Y') !== $date) {
                $lineErrors[] = 'Неверная дата';
            }

            if (!empty($lineErrors)) {
                $errors[] = "$line - " . implode(', ', $lineErrors);
                continue;
            }

            if (!Row::where('external_id', $id)->exists()) {
                Row::create([
                    'external_id' => $id,
                    'name' => $name,
                    'date' => $dt->format('Y-m-d')
                ]);

                event(new RowCreated($id));
            } else {
                $errors[] = "$line - Дубликат ID";
            }
        }

        Redis::incrby("import_progress:{$this->hash}", count($this->chunk));

        if (!empty($errors)) {
            Storage::append('result.txt', implode("\n", $errors) . "\n");
        }
    }
}
