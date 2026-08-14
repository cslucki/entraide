{{-- Global permission matrix. Applies to every Loop of the selected type,
     across every Organization, unless one of them overrides it. --}}
<x-admin-layout :title="__('loops.permissions_title')">
    <div class="mx-auto max-w-6xl px-4 py-8">

        <header class="mb-6">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('loops.permissions_title') }}</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ __('loops.permissions_intro_global') }}</p>
        </header>

        @if(session('success'))
            <div class="mb-4 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
                {{ session('success') }}
            </div>
        @endif

        <form method="GET" action="{{ route('admin.loop-permissions') }}" class="mb-5 flex flex-wrap items-end gap-3">
            {{-- La portee d'abord : c'est elle qui decide de la couche ecrite,
                 et de la liste de types offerte. --}}
            <label class="flex flex-col gap-1">
                <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('loops.types_admin_scope_legend') }}</span>
                <select name="scope" onchange="this.form.submit()"
                        class="min-h-[44px] rounded-xl border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    <option value="platform" @selected(! $scope)>{{ __('loops.types_admin_scope_platform') }}</option>
                    @foreach($organizations as $organization)
                        <option value="{{ $organization->id }}" @selected($scope?->is($organization))>{{ $organization->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('loops.type_label') }}</span>
                <select name="type" onchange="this.form.submit()"
                        class="mt-1 rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    @foreach($types as $key => $definition)
                        <option value="{{ $key }}" @selected($type === $key)>{{ app(\App\Support\Loops\LoopTypeRegistry::class)->label($key, $scope) }}</option>
                    @endforeach
                </select>
            </label>
            <p class="pb-2 text-xs text-gray-400">{{ trans_choice('loops.permissions_affected_loops', $affectedLoops, ['count' => $affectedLoops]) }}</p>
        </form>

        {{-- Honest about the current state: the types are wired but their own
             cards do not exist yet, so they all inherit the same baseline. --}}
        <p class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-800 dark:border-amber-800/60 dark:bg-amber-900/20 dark:text-amber-300">
            {{ __('loops.permissions_types_notice') }}
        </p>

        <form method="POST" action="{{ route('admin.loop-permissions.update') }}">
            @csrf @method('PUT')
            <input type="hidden" name="type" value="{{ $type }}">
            <input type="hidden" name="scope" value="{{ $scope?->id ?? 'platform' }}">

            <x-loops.permission-matrix :modules="$modules" :roles="$roles" :scope="$scope ? 'organization' : 'global'" />

            {{-- Explicit save: nothing is written cell by cell. --}}
            <div class="sticky bottom-0 mt-5 flex items-center justify-end gap-3 border-t border-gray-200 bg-white/95 py-3 backdrop-blur dark:border-gray-700 dark:bg-gray-900/95">
                <button type="submit" form="reset-permissions"
                        class="inline-flex min-h-[44px] items-center rounded-xl border border-gray-300 px-4 text-sm text-gray-600 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">
                    {{ $scope ? __('loops.permissions_reset_to_platform') : __('loops.permissions_reset_to_defaults') }}
                </button>
                <button type="submit" class="inline-flex min-h-[44px] items-center rounded-xl bg-indigo-600 px-5 text-sm font-semibold text-white transition hover:bg-indigo-700">
                    {{ __('loops.permissions_save') }}
                </button>
            </div>
        </form>

        <x-loops.permission-invariants :invariants="$invariants" />
    </div>

        {{-- Hors du formulaire principal : imbriquer deux formulaires est
             invalide en HTML. --}}
        <form id="reset-permissions" method="POST" action="{{ route('admin.loop-permissions.reset') }}" class="hidden">
            @csrf @method('DELETE')
            <input type="hidden" name="type" value="{{ $type }}">
            <input type="hidden" name="scope" value="{{ $scope?->id ?? 'platform' }}">
        </form>

</x-admin-layout>
