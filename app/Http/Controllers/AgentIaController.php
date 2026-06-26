<?php

namespace App\Http\Controllers;

use App\Models\MemberAiProfile;
use Illuminate\View\View;

class AgentIaController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();
        $organization = currentOrganization();

        $profile = MemberAiProfile::forUser($user)
            ->forOrganization($organization)
            ->first();

        return view('agent-ia.show', compact('profile'));
    }

    public function wizard(): View
    {
        return view('agent-ia.wizard');
    }

    public function test(): View
    {
        return view('agent-ia.test');
    }
}
