<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminMetaOrganizationController extends Controller
{
    public function index(): View
    {
        $settings = [
            'global_color_mode' => Setting::get('global_color_mode', 'dark'),
        ];

        return view('admin.meta-community.index', compact('settings'));
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'global_color_mode' => 'required|in:dark,light',
        ]);

        Setting::set('global_color_mode', $data['global_color_mode']);

        return redirect()->route('admin.meta-organization')->with('success', 'Paramètres enregistrés.');
    }
}
