{{-- Organization matrix. Overrides the global settings for every Loop of the
     selected type inside this Organization — never one Loop in particular. --}}
<x-org-admin-layout :title="__('loops.permissions_title')" :organization="$organization">
    <div class="mx-auto max-w-6xl px-4 py-8">

        <header class="mb-6">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">
                {{ __('loops.permissions_title') }} — {{ $organization->name }}
            </h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ __('loops.permissions_intro_organization') }}</p>
        </header>

        @if(session('success'))
            <div class="mb-4 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
                {{ session('success') }}
            </div>
        @endif

        <form method="GET" action="{{ route('organization.admin.loop-permissions', ['organization' => $organization->slug]) }}" class="mb-5 flex flex-wrap items-end gap-3">
            <label class="block">
                <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('loops.type_label') }}</span>
                <select name="type" onchange="this.form.submit()"
                        class="mt-1 rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    @foreach($types as $key => $definition)
                        <option value="{{ $key }}" @selected($type === $key)>{{ __($definition['label_key']) }}</option>
                    @endforeach
                </select>
            </label>
            <p class="pb-2 text-xs text-gray-400">{{ trans_choice('loops.permissions_affected_loops', $affectedLoops, ['count' => $affectedLoops]) }}</p>
        </form>

        <form method="POST" action="{{ route('organization.admin.loop-permissions.update', ['organization' => $organization->slug]) }}">
            @csrf @method('PUT')
            <input type="hidden" name="type" value="{{ $type }}">

            <x-loops.permission-matrix
                :modules="$modules"
                :roles="$roles"
                scope="organization"
                :inherit-label="__('loops.permissions_state_inherited_org')" />

            <div class="sticky bottom-0 mt-5 flex items-center justify-end gap-3 border-t border-gray-200 bg-white/95 py-3 backdrop-blur dark:border-gray-700 dark:bg-gray-900/95">
                <button type="submit" class="inline-flex min-h-[44px] items-center rounded-xl bg-indigo-600 px-5 text-sm font-semibold text-white transition hover:bg-indigo-700">
                    {{ __('loops.permissions_save') }}
                </button>
            </div>
        </form>

        <x-loops.permission-invariants :invariants="$invariants" />
    </div>
</x-org-admin-layout>
