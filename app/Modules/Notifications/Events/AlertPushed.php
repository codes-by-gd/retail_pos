<?php

namespace App\Modules\Notifications\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** Pushes a new notification to one user's browser tabs (private channel), over Reverb. */
class AlertPushed implements ShouldBroadcastNow
{
    public function __construct(public int $userId, public array $notification, public int $unread) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'alert';
    }

    public function broadcastWith(): array
    {
        return ['notification' => $this->notification, 'unread' => $this->unread];
    }
}
