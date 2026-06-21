<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminOrganizationController extends Controller
{
    public function index(): View
    {
        $organizations = Organization::withCount(['users', 'services'])->with(['admin', 'primaryLoop'])
            ->latest()
            ->paginate(20);

        return view('admin.organizations.index', ['organizations' => $organizations]);
    }

    public function create(): View
    {
        $admins = User::orderBy('name')->get();

        return view('admin.organizations.create', compact('admins'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100|unique:organizations,name',
            'slug' => 'nullable|string|max:100|unique:organizations,slug|regex:/^[a-z0-9\-]+$/',
            'description' => 'nullable|string|max:500',
            'admin_id' => 'nullable|uuid|exists:users,id',
            'hero_title' => 'nullable|string|max:100',
            'hero_description' => 'nullable|string|max:500',
            'accent_color' => 'nullable|string|regex:/^#[0-9a-fA-F]{6}$/',
            'welcome_points' => 'required|integer|min:0|max:10000',
            'is_public' => 'nullable|boolean',
            'is_default' => 'nullable|boolean',
            'blog_naming' => 'nullable|in:b2b,b2c',
            'transactions_naming' => 'nullable|in:b2b,b2c',
            'locale' => 'nullable|in:fr,en',
        ]);

        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        $data['accent_color'] = $data['accent_color'] ?? '#6366f1';
        $data['is_active'] = true;
        $data['is_public'] = isset($data['is_public']);
        $data['blog_naming'] = $data['blog_naming'] ?? 'b2b';
        $data['transactions_naming'] = $data['transactions_naming'] ?? 'b2c';
        $data['locale'] = $data['locale'] ?? 'fr';

        if (! empty($data['is_default'])) {
            Organization::where('is_default', true)->update(['is_default' => false]);
        }

        Organization::create($data);

        return redirect()->route('admin.organizations')->with('success', "Organisation « {$data['name']} » créée.");
    }

    public function edit(Organization $organization): View
    {
        $admins = User::orderBy('name')->get();
        $loops = $organization->loops()->orderBy('name')->get();

        return view('admin.organizations.edit', compact('organization', 'admins', 'loops'));
    }

    public function update(Request $request, Organization $organization): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100|unique:organizations,name,'.$organization->id,
            'slug' => 'nullable|string|max:100|unique:organizations,slug,'.$organization->id.'|regex:/^[a-z0-9\-]+$/',
            'description' => 'nullable|string|max:500',
            'admin_id' => 'nullable|uuid|exists:users,id',
            'hero_title' => 'nullable|string|max:100',
            'hero_description' => 'nullable|string|max:500',
            'hero_gradient_start' => 'nullable|string|regex:/^#[0-9a-fA-F]{6}$/',
            'accent_color' => 'nullable|string|regex:/^#[0-9a-fA-F]{6}$/',
            'welcome_points' => 'required|integer|min:0|max:10000',
            'service_points_min' => 'nullable|integer|min:0|max:100000',
            'service_points_max' => 'nullable|integer|min:0|max:100000',
            'is_public' => 'nullable|boolean',
            'is_default' => 'nullable|boolean',
            'loops_enabled' => 'nullable|boolean',
            'loop_mode' => 'nullable|in:mono,multi',
            'primary_loop_id' => [
                'nullable',
                'uuid',
                Rule::exists('loops', 'id')->where('organization_id', $organization->id),
            ],
            'maintenance_mode' => 'nullable|boolean',
            'platform_name' => 'sometimes|required|string|max:100',
            'platform_tagline' => 'nullable|string|max:255',
            'global_color_mode' => 'sometimes|required|in:dark,light',
            'header_javascript_enabled' => 'nullable|boolean',
            'header_javascript' => 'nullable|string',
            'blog_naming' => 'nullable|in:b2b,b2c',
            'transactions_naming' => 'nullable|in:b2b,b2c',
            'feed_post_publish_mode' => 'nullable|in:admin,members',
            'theme_id' => 'nullable|exists:themes,id',
            'locale' => 'nullable|in:fr,en',
        ]);

        $min = $data['service_points_min'] ?? null;
        $max = $data['service_points_max'] ?? null;
        if ($min !== null && $max !== null && $max < $min) {
            return back()->withErrors(['service_points_max' => 'Le maximum doit être supérieur ou égal au minimum.'])->withInput();
        }

        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        $data['is_public'] = isset($data['is_public']);
        $data['loops_enabled'] = ($data['loops_enabled'] ?? '0') === '1';
        $data['loop_mode'] = $data['loop_mode'] ?? 'multi';
        $data['primary_loop_id'] = ($data['primary_loop_id'] ?? null) ?: null;
        $data['header_javascript_enabled'] = ($data['header_javascript_enabled'] ?? '0') === '1';
        $data['maintenance_mode'] = ($data['maintenance_mode'] ?? '0') === '1';
        $data['platform_name'] = $data['platform_name'] ?? $organization->platform_name;
        $data['platform_tagline'] = $data['platform_tagline'] ?? $organization->platform_tagline;
        $data['global_color_mode'] = $data['global_color_mode'] ?? $organization->global_color_mode;
        $data['is_default'] = ($data['is_default'] ?? '0') === '1';
        $data['blog_naming'] = $data['blog_naming'] ?? $organization->blog_naming ?? 'b2b';
        $data['transactions_naming'] = $data['transactions_naming'] ?? $organization->transactions_naming ?? 'b2c';
        $data['feed_post_publish_mode'] = $data['feed_post_publish_mode'] ?? $organization->feed_post_publish_mode ?? 'admin';
        $data['locale'] = $data['locale'] ?? $organization->locale ?? 'fr';

        if ($data['is_default']) {
            Organization::where('is_default', true)
                ->where('id', '!=', $organization->id)
                ->update(['is_default' => false]);
        }

        $organization->update($data);

        return redirect()->route('admin.organizations')->with('success', "Organisation « {$organization->name} » mise à jour.");
    }

    public function toggleActive(Organization $organization): RedirectResponse
    {
        if ($organization->id === auth()->user()->organization_id && auth()->user()->is_admin) {
            return back()->with('error', 'Vous ne pouvez pas désactiver votre propre organisation.');
        }

        $organization->update(['is_active' => ! $organization->is_active]);
        $status = $organization->is_active ? 'activée' : 'désactivée';

        return back()->with('success', "Organisation « {$organization->name} » {$status}.");
    }

    public function destroy(Organization $organization): RedirectResponse
    {
        $organization->users()->update(['organization_id' => null]);
        $organization->services()->update(['organization_id' => null]);
        $organization->serviceRequests()->update(['organization_id' => null]);
        $organization->transactions()->update(['organization_id' => null]);

        $organization->forceDelete();

        return back()->with('success', "Organisation « {$organization->name} » supprimée définitivement.");
    }
}
