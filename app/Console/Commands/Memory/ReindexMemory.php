<?php

namespace App\Console\Commands\Memory;

use App\Models\User;
use App\Services\Memory\MemoryService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('memory:reindex {--user= : User ID (all users if omitted)}')]
#[Description('Reindex all memory nodes into Qdrant vector database')]
class ReindexMemory extends Command
{
    public function handle(MemoryService $memory): void
    {
        $userId = $this->option('user');
        $users  = $userId ? User::where('id', $userId)->get() : User::all();

        if ($users->isEmpty()) {
            $this->warn('No users found.');
            return;
        }

        foreach ($users as $user) {
            $count = $memory->reindexAll($user);
            $this->info("User [{$user->id}] {$user->name}: {$count} nodes indexed.");
        }

        $this->info('Done.');
    }
}
