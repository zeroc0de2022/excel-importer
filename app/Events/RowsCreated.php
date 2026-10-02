<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired once per processed chunk (not per row): 1 event per 1000 rows keeps
 * the WebSocket traffic and the browser's work small, and the UI only needs counts.
 *
 * ShouldBroadcastNow sends it straight from the worker instead of queueing
 * one more job for every chunk.
 */
class RowsCreated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public readonly string $importId,
        public readonly int $created,
        public readonly int $processed,
        public readonly int $failed,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('imports');
    }

    public function broadcastAs(): string
    {
        return 'rows.created';
    }
}
