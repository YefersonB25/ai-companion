<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Memory\MemoryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExtractMemoryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 60;

    public function __construct(
        private readonly int $userId,
        private readonly string $text,
        private readonly ?int $conversationId = null,
    ) {}

    public function handle(MemoryService $memory): void
    {
        $user = User::find($this->userId);

        if (! $user) {
            return;
        }

        $memory->extractWithAI($user, $this->text, $this->conversationId);
    }
}
