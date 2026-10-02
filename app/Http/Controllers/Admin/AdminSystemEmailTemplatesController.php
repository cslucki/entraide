<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\SystemEmailTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminSystemEmailTemplatesController extends Controller
{
    public function index(Request $request): View
    {
        $query = SystemEmailTemplate::with('organization')->orderBy('name');

        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->organization_id);
        }

        if ($request->filled('locale')) {
            $query->where('locale', $request->locale);
        }

        // TASK-1659 : permet d'arriver directement sur les gabarits d'UNE
        // fonctionnalite (ex. « Gérer le modèle d'email » depuis
        // /admin/users/bulk-create), plutot que sur la liste entiere.
        if ($request->filled('slug')) {
            $query->where('slug', $request->slug);
        }

        $templates = $query->get();
        $organizations = Organization::orderBy('name')->get();

        return view('admin.system-email-templates.index', compact('templates', 'organizations'));
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

        $redirect = $request->input('redirect', route('admin.system-email-templates'));

        return redirect($redirect)
            ->with('success', __('admin.emailer_updated'));
    }
}
