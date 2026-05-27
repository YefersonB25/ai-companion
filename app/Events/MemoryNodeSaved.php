<?php

namespace App\Events;

use App\Models\MemoryNode;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MemoryNodeSaved implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public MemoryNode $node) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("users.{$this->node->user_id}"),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'id'         => $this->node->id,
            'type'       => $this->node->type,
            'label'      => $this->node->label,
            'content'    => $this->node->content,
            'importance' => $this->node->importance,
            'parent_id'  => $this->node->parent_id,
            'attributes' => $this->node->attributes,
        ];
    }

    public function broadcastAs(): string
    {
        return 'memory.saved';
    }
}
