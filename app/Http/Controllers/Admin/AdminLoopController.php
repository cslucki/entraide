<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Loop;
use Illuminate\View\View;

class AdminLoopController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();

        $orgId = $user->organization_id ?? $user->community_id;

        $loops = Loop::where('organization_id', $orgId)
            ->with('creator:id,name,email')
            ->withCount('activeMembers')
            ->latest()
            ->paginate(25);

        $loops->load(['messages' => fn ($q) => $q->latest()->limit(1)]);

        return view('admin.loops.index', compact('loops'));
    }
}
