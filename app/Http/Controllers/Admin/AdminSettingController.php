<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminSettingController extends Controller
{
    public function index(): View
    {
        $settings = [
            'platform_name'     => Setting::get('platform_name', 'Entraide'),
            'platform_tagline'  => Setting::get('platform_tagline', 'Échangez vos talents'),
            'maintenance_mode'  => Setting::get('maintenance_mode', '0'),
        ];

        return view('admin.settings.index', compact('settings'));
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'platform_name'    => 'required|string|max:100',
            'platform_tagline' => 'nullable|string|max:255',
            'maintenance_mode' => 'boolean',
        ]);

        Setting::set('platform_name', $data['platform_name']);
        Setting::set('platform_tagline', $data['platform_tagline'] ?? '');
        Setting::set('maintenance_mode', ($data['maintenance_mode'] ?? false) ? '1' : '0');

        return redirect()->route('admin.settings')->with('success', 'Configuration sauvegardée.');
    }
}
