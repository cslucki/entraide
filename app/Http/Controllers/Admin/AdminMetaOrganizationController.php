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
        $orgId = auth()->user()->organization_id;

        $settings = [
            'global_color_mode' => OrganizationSetting::get($orgId, 'global_color_mode', 'dark'),
        ];

        return view('admin.meta-organization.index', compact('settings'));
    }

    public function update(Request $request): RedirectResponse
    {
        $orgId = auth()->user()->organization_id;

        $data = $request->validate([
            'global_color_mode' => 'required|in:dark,light',
        ]);

        OrganizationSetting::set($orgId, 'global_color_mode', $data['global_color_mode']);

        return redirect()->route('admin.meta-organization')->with('success', 'Paramètres enregistrés.');
    }
}
