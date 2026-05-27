<?php

use App\Models\Conversation;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Private channel for a specific conversation
Broadcast::channel('conversations.{conversationId}', function ($user, $conversationId) {
    return Conversation::where('id', $conversationId)
        ->where('user_id', $user->id)
        ->exists();
});

// Private channel for user-level events (memory updates, etc.)
Broadcast::channel('users.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
