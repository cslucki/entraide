<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrganizationSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminMetaOrganizationController extends Controller
{
    public function index(): View
    {
        $orgId = OrganizationSetting::getDefaultOrgId(); // Uses default config scope

        $settings = [
            'global_color_mode' => OrganizationSetting::get($orgId, 'global_color_mode', 'dark'),
        ];

        return view('admin.meta-organization.index', compact('settings'));
    }

    public function update(Request $request): RedirectResponse
    {
        $orgId = OrganizationSetting::getDefaultOrgId(); // Uses default config scope

        $data = $request->validate([
            'global_color_mode' => 'required|in:dark,light',
        ]);

        OrganizationSetting::set($orgId, 'global_color_mode', $data['global_color_mode']);

        return redirect()->route('admin.meta-organization')->with('success', 'Paramètres enregistrés.');
    }
}
