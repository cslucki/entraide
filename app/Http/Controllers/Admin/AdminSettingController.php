<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrganizationSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminSettingController extends Controller
{
    public function index(): View
    {
        $orgId = auth()->user()->organization_id;

        $settings = [
            'platform_name'     => OrganizationSetting::get($orgId, 'platform_name', 'Entraide'),
            'platform_tagline'  => OrganizationSetting::get($orgId, 'platform_tagline', 'Échangez vos talents'),
            'maintenance_mode'  => OrganizationSetting::get($orgId, 'maintenance_mode', '0'),
        ];

        return view('admin.settings.index', compact('settings'));
    }

    public function update(Request $request): RedirectResponse
    {
        $orgId = auth()->user()->organization_id;

        $data = $request->validate([
            'platform_name'    => 'required|string|max:100',
            'platform_tagline' => 'nullable|string|max:255',
            'maintenance_mode' => 'boolean',
        ]);

        OrganizationSetting::set($orgId, 'platform_name', $data['platform_name']);
        OrganizationSetting::set($orgId, 'platform_tagline', $data['platform_tagline'] ?? '');
        OrganizationSetting::set($orgId, 'maintenance_mode', ($data['maintenance_mode'] ?? false) ? '1' : '0');

        return redirect()->route('admin.settings')->with('success', 'Configuration sauvegardée.');
    }
}
