<?php

namespace App\Policies;

use App\Models\MemoryNode;
use App\Models\User;

class MemoryNodePolicy
{
    public function viewAny(User $user): bool { return true; }
    public function view(User $user, MemoryNode $memoryNode): bool { return $user->id === $memoryNode->user_id; }
    public function create(User $user): bool { return true; }
    public function update(User $user, MemoryNode $memoryNode): bool { return $user->id === $memoryNode->user_id; }
    public function delete(User $user, MemoryNode $memoryNode): bool { return $user->id === $memoryNode->user_id; }
    public function restore(User $user, MemoryNode $memoryNode): bool { return $user->id === $memoryNode->user_id; }
    public function forceDelete(User $user, MemoryNode $memoryNode): bool { return $user->id === $memoryNode->user_id; }
}
