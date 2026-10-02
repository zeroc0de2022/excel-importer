<?php

declare(strict_types=1);

namespace App\Import;

use Generator;
use Illuminate\Support\Facades\Redis;

/**
 * Import progress and errors stored in Redis.
 *
 * Keys per import:
 *   import:{id}:processed  rows processed so far (INCRBY)
 *   import:{id}:failed     rows that failed validation or were duplicates
 *   import:{id}:chunks     set of chunk numbers already counted (makes retries idempotent)
 *   import:{id}:errors     sorted set of report lines, scored by row number
 */
final class ImportProgress
{
    /**
     * Counts a chunk exactly once. Redis runs a Lua script atomically, so either all
     * of these changes happen or none, and a chunk seen before is ignored.
     */
    private const RECORD_CHUNK = <<<'LUA'
        if redis.call('SADD', KEYS[1], ARGV[1]) == 0 then
            return 0
        end
        redis.call('INCRBY', KEYS[2], ARGV[2])
        redis.call('INCRBY', KEYS[3], ARGV[3])
        for i = 4, #ARGV, 2 do
            redis.call('ZADD', KEYS[4], ARGV[i], ARGV[i + 1])
        end
        return 1
        LUA;

    public function isChunkRecorded(string $importId, int $chunk): bool
    {
        return (bool) Redis::sismember($this->key($importId, 'chunks'), (string) $chunk);
    }

    /**
     * @param  array<int, string>  $errors  row number => report line
     * @return bool false if this chunk was already recorded earlier
     */
    public function recordChunk(string $importId, int $chunk, int $processed, int $failed, array $errors): bool
    {
        $args = [
            $this->key($importId, 'chunks'),
            $this->key($importId, 'processed'),
            $this->key($importId, 'failed'),
            $this->key($importId, 'errors'),
            $chunk,
            $processed,
            $failed,
        ];

        foreach ($errors as $line => $message) {
            $args[] = $line;
            $args[] = $message;
        }

        // command() hands the call to phpredis as-is: eval(script, [keys..., args...], number of keys).
        // phpredis adds the configured key prefix to the first 4 (the keys).
        $recorded = Redis::command('eval', [self::RECORD_CHUNK, $args, 4]);

        return (int) $recorded === 1;
    }

    public function processed(string $importId): int
    {
        return (int) Redis::get($this->key($importId, 'processed'));
    }

    public function failed(string $importId): int
    {
        return (int) Redis::get($this->key($importId, 'failed'));
    }

    /**
     * Report lines ordered by row number, read in pages so a big report never sits in memory.
     *
     * @return Generator<int, string>
     */
    public function errorLines(string $importId, int $pageSize = 1000): Generator
    {
        $key = $this->key($importId, 'errors');

        for ($start = 0; ; $start += $pageSize) {
            $lines = Redis::zrange($key, $start, $start + $pageSize - 1);

            yield from $lines;

            if (count($lines) < $pageSize) {
                return;
            }
        }
    }

    /**
     * Keep the counters for a while after the import finished, then let Redis drop them.
     */
    public function expire(string $importId, int $seconds): void
    {
        foreach (['processed', 'failed', 'chunks', 'errors'] as $name) {
            Redis::expire($this->key($importId, $name), $seconds);
        }
    }

    public function forget(string $importId): void
    {
        Redis::del(array_map(
            fn (string $name): string => $this->key($importId, $name),
            ['processed', 'failed', 'chunks', 'errors'],
        ));
    }

    private function key(string $importId, string $name): string
    {
        return "import:{$importId}:{$name}";
    }
}
