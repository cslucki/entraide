<?php

use App\Models\Loop;
use App\Models\LoopMember;
use Illuminate\Support\Facades\Broadcast;

Broadcast::routes();

Broadcast::channel('loop.{loopId}', function ($user, string $loopId) {
    $loop = Loop::find($loopId);

    if (! $loop) {
        return false;
    }

    $isActiveMember = LoopMember::where('loop_id', $loopId)
        ->where('user_id', $user->id)
        ->where('status', 'active')
        ->exists();

    if (! $isActiveMember) {
        return false;
    }

    if ($loop->community_id !== $user->community_id) {
        return false;
    }

    return ['id' => $user->id];
});
