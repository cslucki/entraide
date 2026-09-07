<x-org-admin-layout :title="__('crm.email_history.reread_title')" :organization="$organization">
    {{-- TASK-1426 — CRM-8 : relire l'email reellement envoye/tente. Le snapshot STOCKE (body_html), jamais
         recalcule depuis le modele, rendu dans un iframe sandbox + CSP default-src 'none' : aucun script,
         aucune ressource distante, aucun formulaire actif. JAMAIS {!! body_html !!} dans ce DOM. --}}
    <div class="mb-4 text-sm">
        <a href="{{ route('organization.admin.crm.contacts.show', ['organization' => $organization->slug, 'contact' => $contact->id]) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">&larr; {{ __('crm.email_history.back') }}</a>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 mb-4" data-crm-email-log-header data-crm-email-status="{{ $log->status }}">
        <h1 class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ $log->subject }}</h1>
        <dl class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1 text-sm">
            <div class="flex gap-2"><dt class="text-gray-500 dark:text-gray-400 w-28 flex-shrink-0">{{ __('crm.email_history.sent_at') }}</dt><dd class="text-gray-900 dark:text-gray-100">{{ $log->created_at->format('d/m/Y H:i') }}</dd></div>
            <div class="flex gap-2"><dt class="text-gray-500 dark:text-gray-400 w-28 flex-shrink-0">{{ __('crm.email_history.status') }}</dt><dd>
                <span class="px-2 py-0.5 rounded text-xs font-semibold {{ match($log->status) { 'sent' => 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-300', 'failed' => 'bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-300', default => 'bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-300' } }}">{{ __('crm.email_history.status_'.$log->status) }}</span>
                @if($log->error_message)<div class="text-xs text-red-600 dark:text-red-400 mt-1" data-crm-email-error>{{ \Illuminate\Support\Str::limit($log->error_message, 200) }}</div>@endif
            </dd></div>
            <div class="flex gap-2"><dt class="text-gray-500 dark:text-gray-400 w-28 flex-shrink-0">{{ __('crm.email_history.to') }}</dt><dd class="text-gray-900 dark:text-gray-100 break-all">{{ $log->to_email }}</dd></div>
            <div class="flex gap-2"><dt class="text-gray-500 dark:text-gray-400 w-28 flex-shrink-0">{{ __('crm.email_history.template') }}</dt><dd class="text-gray-900 dark:text-gray-100">{{ $log->template?->name ?? ($log->data['template_slug'] ?? '—') }}</dd></div>
            <div class="flex gap-2"><dt class="text-gray-500 dark:text-gray-400 w-28 flex-shrink-0">{{ __('crm.email_history.sender') }}</dt><dd class="text-gray-900 dark:text-gray-100" data-crm-email-sender>{{ $sender?->fullName ?? '—' }}</dd></div>
            <div class="flex gap-2"><dt class="text-gray-500 dark:text-gray-400 w-28 flex-shrink-0">{{ __('crm.email_history.integrity') }}</dt><dd data-crm-email-integrity="{{ $integrity }}" class="{{ $integrity === 'divergent' ? 'text-red-600 dark:text-red-400 font-semibold' : 'text-gray-900 dark:text-gray-100' }}">
                {{ __('crm.email_history.integrity_'.$integrity) }}
                @if($log->body_hash)<span class="text-xs text-gray-400 font-mono" title="sha256">· {{ substr($log->body_hash, 0, 12) }}…</span>@endif
            </dd></div>
        </dl>
        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('crm.email_history.status_hint') }}</p>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
        <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-1">{{ __('crm.email_history.snapshot_title') }}</h2>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">{{ __('crm.email_history.snapshot_hint') }}</p>
        @if($srcdoc === null)
            <p class="text-sm text-gray-500 dark:text-gray-400" data-crm-email-no-snapshot>{{ __('crm.email_history.no_snapshot') }}</p>
        @else
            <iframe sandbox="" srcdoc="{{ $srcdoc }}" referrerpolicy="no-referrer" title="{{ __('crm.email_history.snapshot_title') }}" data-crm-email-snapshot class="w-full h-[70vh] bg-white rounded-lg border border-gray-200 dark:border-gray-700"></iframe>
        @endif
    </div>
</x-org-admin-layout>
