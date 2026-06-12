<?php

namespace App\Http\Controllers;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\MemberAiProfile;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AiAgentLoopController extends Controller
{
    public function __construct(
        private readonly LoopService $loopService,
    ) {}

    public function startConversation(Request $request, User $user): RedirectResponse
    {
        $visitor = $request->user();

        if (! $visitor) {
            return redirect()->guest(route('login'));
        }

        abort_if($visitor->is($user), 403, 'Vous ne pouvez pas démarrer une conversation avec vous-même.');

        $profile = MemberAiProfile::query()
            ->published()
            ->where('user_id', $user->id)
            ->where('organization_id', $user->organization_id)
            ->first();

        abort_unless($profile, 404, "Ce membre n'a pas de profil IA publié.");

        abort_unless($visitor->organization_id === $user->organization_id, 403);

        $existingLoop = Loop::where('member_ai_profile_id', $profile->id)
            ->where('type', 'ai_agent')
            ->whereHas('members', fn ($q) => $q->where('user_id', $visitor->id)->where('status', 'active'))
            ->first();

        if ($existingLoop) {
            return redirect()->route('loops.show', $existingLoop);
        }

        $loop = Loop::create([
            'organization_id' => $visitor->organization_id,
            'name' => 'Agent IA — '.$user->name,
            'slug' => $this->generateUniqueSlug($visitor->organization_id, 'agent-ia-'.$user->name),
            'description' => 'Conversation avec l\'agent IA de '.$user->name,
            'type' => 'ai_agent',
            'status' => 'active',
            'visibility' => 'private',
            'created_by' => $visitor->id,
            'member_ai_profile_id' => $profile->id,
        ]);

        LoopMember::create([
            'loop_id' => $loop->id,
            'user_id' => $visitor->id,
            'role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        LoopMember::firstOrCreate(
            ['loop_id' => $loop->id, 'user_id' => $user->id],
            [
                'role' => 'member',
                'status' => 'active',
                'joined_at' => now(),
            ]
        );

        return redirect()->route('loops.show', $loop);
    }

    private function generateUniqueSlug(string $orgId, string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;

        for ($i = 1; $i <= 20; $i++) {
            $exists = Loop::where('organization_id', $orgId)
                ->where('slug', $slug)
                ->exists();

            if (! $exists) {
                return $slug;
            }

            $slug = $base.'-'.$i;
        }

        return $base.'-'.Str::lower(Str::random(4));
    }
}
