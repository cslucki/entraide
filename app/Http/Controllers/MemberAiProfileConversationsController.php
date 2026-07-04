<?php

namespace App\Http\Controllers;

use App\Models\MemberAiProfile;
use App\Models\ProfileAgentConversation;
use App\Models\ProfileAgentMessage;
use App\Support\Tenancy\DefaultOrganizationResolver;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MemberAiProfileConversationsController extends Controller
{
    public function index(Request $request): View
    {
        $organization = currentOrganization()
            ?? $request->user()?->organization
            ?? DefaultOrganizationResolver::resolve();

        $profile = MemberAiProfile::query()
            ->where('user_id', $request->user()->id)
            ->where('organization_id', $organization?->id)
            ->first();

        $conversations = ProfileAgentConversation::query()
            ->with(['visitor', 'owner'])
            ->withCount('messages')
            ->when($organization, fn ($query) => $query->where('organization_id', $organization->id))
            ->where('profile_owner_user_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        return view('agent-ia.conversations', [
            'profile' => $profile,
            'conversations' => $conversations,
        ]);
    }

    public function show(Request $request, ProfileAgentConversation $conversation): View
    {
        $this->authorize('view', $conversation);

        $messages = ProfileAgentMessage::where('conversation_id', $conversation->id)
            ->orderBy('created_at')
            ->get();

        return view('agent-ia.conversation-show', [
            'conversation' => $conversation->load(['visitor', 'owner']),
            'messages' => $messages,
        ]);
    }
}
