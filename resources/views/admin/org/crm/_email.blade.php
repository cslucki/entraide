    {{-- TASK-1421 — CRM-7b : la page de confirmation. Rien n'est envoye tant que l'humain ne clique pas. --}}
    <div class="mb-4">
        <a href="{{ $r('contacts.show', ['contact' => $contact->id]) }}" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline" data-crm-email-back>&larr; {{ $contact->fullName }}</a>
    </div>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5" data-crm-email-preview>
            <dl class="text-sm space-y-1 mb-4">
                <div class="flex gap-3"><dt class="w-24 text-gray-500 dark:text-gray-400">{{ __('crm.email.to') }}</dt><dd class="text-gray-900 dark:text-gray-100 break-all" data-crm-email-to>{{ $rendered['to'] }}</dd></div>
                <div class="flex gap-3"><dt class="w-24 text-gray-500 dark:text-gray-400">{{ __('crm.email.template') }}</dt><dd class="text-gray-900 dark:text-gray-100">{{ $template->name }}</dd></div>
                <div class="flex gap-3"><dt class="w-24 text-gray-500 dark:text-gray-400">{{ __('crm.templates.subject') }}</dt><dd class="text-gray-900 dark:text-gray-100 font-semibold" data-crm-email-subject>{{ $rendered['subject'] }}</dd></div>
            </dl>
            <div class="prose prose-sm dark:prose-invert max-w-none border-t border-gray-200 dark:border-gray-700 pt-4" data-crm-email-body>{!! $rendered['html'] !!}</div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-2">{{ __('crm.email.confirm_title') }}</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">{{ __('crm.email.confirm_hint') }}</p>
            <form method="POST" action="{{ $r('contacts.email.send', ['contact' => $contact->id, 'template' => $template->id]) }}" data-crm-email-form>
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <button type="submit" data-crm-email-send class="w-full px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">{{ __('crm.email.send') }}</button>
            </form>
            <a href="{{ route('organization.admin.crm.templates.edit', ['organization' => $organization->slug, 'template' => $template->id]) }}" class="block mt-3 text-xs text-indigo-600 dark:text-indigo-400 hover:underline">{{ __('crm.email.edit_template') }}</a>
        </div>
    </div>
