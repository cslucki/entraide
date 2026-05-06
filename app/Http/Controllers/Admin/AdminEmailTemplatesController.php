<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminEmailTemplatesController extends Controller
{
    public function index(Request $request): View
    {
        $search = $request->input('search');
        $templates = EmailTemplate::when($search, fn($q) => $q->where('name', 'like', "%{$search}%")
            ->orWhere('slug', 'like', "%{$search}%"))
            ->withCount('logs')
            ->orderBy('name')
            ->paginate(20);

        return view('admin.email-templates.index', compact('templates', 'search'));
    }

    public function create(): View
    {
        return view('admin.email-templates.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['variables' => $this->parseVariables($request->input('variables'))]);

        $validated = $request->validate([
            'slug' => 'required|string|max:100|unique:email_templates,slug',
            'name' => 'required|string|max:255',
            'subject' => 'required|string|max:255',
            'content_html' => 'required|string',
            'variables' => 'nullable|array',
        ]);

        EmailTemplate::create($validated);

        return redirect()->route('admin.email-templates')
            ->with('success', 'Template d\'email créé avec succès.');
    }

    public function show(EmailTemplate $emailTemplate): View
    {
        $emailTemplate->load(['logs' => fn($q) => $q->latest()->limit(10)]);

        return view('admin.email-templates.show', compact('emailTemplate'));
    }

    public function edit(EmailTemplate $emailTemplate): View
    {
        return view('admin.email-templates.edit', compact('emailTemplate'));
    }

    public function update(Request $request, EmailTemplate $emailTemplate): RedirectResponse
    {
        $request->merge(['variables' => $this->parseVariables($request->input('variables'))]);

        $validated = $request->validate([
            'slug' => 'required|string|max:100|unique:email_templates,slug,' . $emailTemplate->id,
            'name' => 'required|string|max:255',
            'subject' => 'required|string|max:255',
            'content_html' => 'required|string',
            'variables' => 'nullable|array',
        ]);

        $emailTemplate->update($validated);

        return redirect()->route('admin.email-templates')
            ->with('success', 'Template d\'email mis à jour avec succès.');
    }

    public function destroy(EmailTemplate $emailTemplate): RedirectResponse
    {
        $emailTemplate->delete();

        return redirect()->route('admin.email-templates')
            ->with('success', 'Template d\'email supprimé avec succès.');
    }

    public function preview(Request $request): string
    {
        $validated = $request->validate([
            'content' => 'required|string',
        ]);

        return $validated['content'];
    }

    private function parseVariables(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value ?: null;
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return array_values(array_filter(array_map('trim', explode("\n", $value))));
    }
}
