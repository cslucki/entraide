<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Services\EmailerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminEmailTemplatesController extends Controller
{
    public function index(Request $request): View
    {
        $search = $request->input('search');
        $templates = EmailTemplate::when($search, fn ($q) => $q->where('name', 'like', "%{$search}%")
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
        $emailTemplate->load(['logs' => fn ($q) => $q->latest()->limit(10)]);

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
            'slug' => 'required|string|max:100|unique:email_templates,slug,'.$emailTemplate->id,
            'name' => 'required|string|max:255',
            'subject' => 'required|string|max:255',
            'content_html' => 'required|string',
            'variables' => 'nullable|array',
        ]);

        $emailTemplate->update($validated);

        return redirect()->route('admin.email-templates')
            ->with('success', __('admin.emailer_updated'));
    }

    public function destroy(EmailTemplate $emailTemplate): RedirectResponse
    {
        $emailTemplate->delete();

        return redirect()->route('admin.email-templates')
            ->with('success', __('admin.emailer_deleted'));
    }

    public function preview(Request $request): string
    {
        $validated = $request->validate([
            'content' => 'required|string',
        ]);

        return $validated['content'];
    }

    public function sendForm(EmailTemplate $emailTemplate): View
    {
        $users = User::with('organization')
            ->orderBy('name')
            ->paginate(50);

        $service = app(EmailerService::class);

        return view('admin.email-templates.send', [
            'template' => $emailTemplate,
            'users' => $users,
            'service' => $service,
        ]);
    }

    public function sendExecute(Request $request, EmailTemplate $emailTemplate): RedirectResponse
    {
        $validated = $request->validate([
            'user_ids' => 'required|array',
            'user_ids.*' => 'exists:users,id',
            'confirmed' => 'nullable|string',
        ]);

        $userIds = array_unique($validated['user_ids']);

        if (count($userIds) > 50) {
            return back()->withErrors(['user_ids' => __('admin.emailer_max_50')])->withInput();
        }

        $users = User::whereIn('id', $userIds)->get();

        if ($users->isEmpty()) {
            return back()->withErrors(['user_ids' => __('admin.emailer_no_recipients')])->withInput();
        }

        if (! $request->has('confirmed') && count($users) > 1) {
            return redirect()->route('admin.email-templates.send.confirm', [
                'emailTemplate' => $emailTemplate,
                'user_ids' => $userIds,
            ]);
        }

        $service = app(EmailerService::class);
        $sender = $request->user();

        $sent = 0;
        $failed = 0;

        foreach ($users as $user) {
            $log = $service->sendFromTemplate($emailTemplate, $user, $sender);
            if ($log->status === 'sent') {
                $sent++;
            } else {
                $failed++;
            }
        }

        $total = $sent + $failed;

        return redirect()->route('admin.email-templates.show', $emailTemplate)
            ->with('success', __('admin.emailer_sent_count', ['sent' => $sent, 'failed' => $failed, 'total' => $total]));
    }

    public function sendConfirm(Request $request, EmailTemplate $emailTemplate): View
    {
        $userIds = $request->query('user_ids', []);

        if (empty($userIds)) {
            return redirect()->route('admin.email-templates.send', $emailTemplate);
        }

        $users = User::whereIn('id', $userIds)->get();
        $service = app(EmailerService::class);
        $previewUser = $users->first();

        return view('admin.email-templates.send', [
            'template' => $emailTemplate,
            'users' => $users,
            'previewUser' => $previewUser,
            'userIds' => $userIds,
            'service' => $service,
            'confirm' => true,
        ]);
    }

    private function parseVariables(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value ?: null;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return array_values(array_filter(array_map('trim', explode("\n", $value))));
    }
}
