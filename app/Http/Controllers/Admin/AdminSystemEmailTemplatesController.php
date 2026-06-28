<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemEmailTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminSystemEmailTemplatesController extends Controller
{
    public function index(): View
    {
        $templates = SystemEmailTemplate::orderBy('name')->get();

        return view('admin.system-email-templates.index', compact('templates'));
    }

    public function edit(SystemEmailTemplate $systemEmailTemplate): View
    {
        return view('admin.system-email-templates.edit', compact('systemEmailTemplate'));
    }

    public function update(Request $request, SystemEmailTemplate $systemEmailTemplate): RedirectResponse
    {
        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'content_html' => 'required|string',
            'enabled' => 'boolean',
        ]);

        $validated['enabled'] = $request->boolean('enabled');

        $systemEmailTemplate->update($validated);

        return redirect()->route('admin.system-email-templates')
            ->with('success', __('admin.emailer_updated'));
    }
}
