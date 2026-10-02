<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class ImportFinished implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public readonly string $importId,
        public readonly string $status,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('imports');
    }

    public function broadcastAs(): string
    {
        return 'import.finished';
    }
}
