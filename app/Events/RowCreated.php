<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class RowCreated implements ShouldBroadcast
{
    public $id;

    public function __construct($id)
    {
        $this->id = $id;
    }

    public function broadcastOn()
    {
        return ['rows'];
    }

    public function broadcastAs()
    {
        return 'row.created';
    }
}
