<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Community;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AdminCommunityController extends Controller
{
    public function index(): View
    {
        $communities = Community::withCount(['users', 'services'])->with('admin')
            ->latest()
            ->paginate(20);

        return view('admin.communities.index', compact('communities'));
    }

    public function create(): View
    {
        $admins = User::orderBy('name')->get();
        return view('admin.communities.create', compact('admins'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'               => 'required|string|max:100|unique:communities,name',
            'slug'               => 'nullable|string|max:100|unique:communities,slug|regex:/^[a-z0-9\-]+$/',
            'description'        => 'nullable|string|max:500',
            'admin_id'           => 'nullable|uuid|exists:users,id',
            'hero_title'         => 'nullable|string|max:100',
            'hero_description'   => 'nullable|string|max:500',
            'accent_color'       => 'nullable|string|regex:/^#[0-9a-fA-F]{6}$/',
            'welcome_points'     => 'required|integer|min:0|max:10000',
            'is_public'          => 'nullable|boolean',
        ]);

        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        $data['accent_color'] = $data['accent_color'] ?? '#6366f1';
        $data['is_active'] = true;
        $data['is_public'] = isset($data['is_public']);

        Community::create($data);

        return redirect()->route('admin.communities')->with('success', "Communauté « {$data['name']} » créée.");
    }

    public function edit(Community $community): View
    {
        $admins = User::orderBy('name')->get();
        return view('admin.communities.edit', compact('community', 'admins'));
    }

    public function update(Request $request, Community $community): RedirectResponse
    {
        $data = $request->validate([
            'name'               => 'required|string|max:100|unique:communities,name,' . $community->id,
            'slug'               => 'nullable|string|max:100|unique:communities,slug,' . $community->id . '|regex:/^[a-z0-9\-]+$/',
            'description'        => 'nullable|string|max:500',
            'admin_id'           => 'nullable|uuid|exists:users,id',
            'hero_title'         => 'nullable|string|max:100',
            'hero_description'   => 'nullable|string|max:500',
            'accent_color'       => 'nullable|string|regex:/^#[0-9a-fA-F]{6}$/',
            'welcome_points'     => 'required|integer|min:0|max:10000',
            'is_public'          => 'nullable|boolean',
        ]);

        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        $data['is_public'] = isset($data['is_public']);

        $community->update($data);

        return redirect()->route('admin.communities')->with('success', "Communauté « {$community->name} » mise à jour.");
    }

    public function toggleActive(Community $community): RedirectResponse
    {
        if ($community->id === (auth()->user()->organization_id ?? auth()->user()->community_id) && auth()->user()->is_admin) {
            return back()->with('error', 'Vous ne pouvez pas désactiver votre propre communauté.');
        }

        $community->update(['is_active' => !$community->is_active]);
        $status = $community->is_active ? 'activée' : 'désactivée';

        return back()->with('success', "Communauté « {$community->name} » {$status}.");
    }

    public function destroy(Community $community): RedirectResponse
    {
        $community->users()->update(['organization_id' => null]);
        $community->services()->update(['organization_id' => null]);
        $community->serviceRequests()->update(['organization_id' => null]);
        $community->transactions()->update(['organization_id' => null]);

        $community->delete();

        return back()->with('success', "Communauté « {$community->name} » supprimée.");
    }
}
