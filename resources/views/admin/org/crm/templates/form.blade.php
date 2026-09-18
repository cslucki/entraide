<x-org-admin-layout :title="$template ? $template->name : __('crm.templates.new')" :organization="$organization">
    {{-- TASK-1420 — CRM-7a : creer / editer un modele d'email de l'Organization. --}}
    <div class="mb-4">
        <a href="{{ route('organization.admin.crm.templates', ['organization' => $organization->slug]) }}" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">&larr; {{ __('crm.templates.title') }}</a>
    </div>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <form method="POST" data-crm-template-form
              action="{{ $template ? route('organization.admin.crm.templates.update', ['organization' => $organization->slug, 'template' => $template->id]) : route('organization.admin.crm.templates.store', ['organization' => $organization->slug]) }}"
              class="lg:col-span-2 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 flex flex-col gap-3">
            @csrf
            @if($template) @method('PUT') @endif
            <h1 class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ $template ? $template->name : __('crm.templates.new') }}</h1>
            @if($template)<p class="text-xs text-gray-400 font-mono" data-crm-template-slug>{{ $template->slug }}</p>@endif
            <label class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('crm.templates.name') }}</label>
            <input type="text" name="name" required maxlength="120" value="{{ old('name', $template?->name) }}" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
            <label class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('crm.templates.subject') }}</label>
            <input type="text" name="subject" required maxlength="200" value="{{ old('subject', $template?->subject) }}" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
            <label class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('crm.templates.content') }}</label>
            <textarea name="content_html" required rows="14" maxlength="20000" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm font-mono">{{ old('content_html', $template?->content_html) }}</textarea>
            @if($errors->any())<ul class="text-xs text-red-600 dark:text-red-400" data-crm-template-errors>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
            <div class="flex flex-wrap justify-end gap-2">
                @if($template)
                <a href="{{ route('organization.admin.crm.templates.preview', ['organization' => $organization->slug, 'template' => $template->id]) }}" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-700 dark:text-gray-300">{{ __('crm.templates.preview') }}</a>
                @endif
                <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">{{ __('crm.templates.save') }}</button>
            </div>
        </form>
        <aside class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5" data-crm-template-variables>
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-2">{{ __('crm.templates.variables') }}</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">{{ __('crm.templates.variables_hint') }}</p>
            <ul class="space-y-1 text-xs font-mono text-gray-700 dark:text-gray-300">
                @foreach($variables as $variable)
                <li><code>{{ '{'.'{ '.$variable.' }'.'}' }}</code> <span class="font-sans text-gray-500 dark:text-gray-400">— {{ __('crm.templates.variable.'.$variable) }}</span></li>
                @endforeach
            </ul>
        </aside>
    </div>
</x-org-admin-layout>
