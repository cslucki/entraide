{{--
    TASK-1349 — page DEDIEE a la Constitution de l'organisation.

    Trois blocs, trois natures :
      1. le Mycelium HERITE — lecture seule, ce qui s'applique deja ;
      2. la Constitution propre — edition, versions, historique ;
      3. la publication — opt-in explicite, prive par defaut.

    Elle ne duplique AUCUNE logique de versionnement : les formulaires postent
    vers les memes routes que le cockpit « Comportement IA », qui reste le
    tableau de bord du systeme nerveux.
--}}
<x-org-admin-layout :title="__('mycelium.admin_org_title')" :organization="$organization">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100" data-org-constitution-title>{{ __('mycelium.admin_org_title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ __('mycelium.admin_org_subtitle') }}</p>
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-900/20 dark:text-emerald-200">{{ session('success') }}</div>
    @endif
    @if(session('info'))
        <div class="mb-4 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">{{ session('info') }}</div>
    @endif

    <div class="max-w-4xl space-y-6">

        {{-- 1. LE MYCELIUM HERITE --}}
        <section class="rounded-xl border border-gray-200 bg-gray-50 p-6 dark:border-gray-600 dark:bg-gray-900/40" data-org-constitution-inherited>
            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ __('mycelium.admin_org_inherited_title') }}</h2>
                <span class="rounded-full bg-gray-200 px-2.5 py-1 text-xs font-medium text-gray-700 dark:bg-gray-700 dark:text-gray-200">{{ $platformVersion }}</span>
            </div>
            <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">{{ __('mycelium.admin_org_inherited_help') }}</p>
            <pre class="whitespace-pre-wrap rounded-lg border border-gray-100 bg-white p-4 font-sans text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" data-org-constitution-inherited-text>{{ $platformText }}</pre>
        </section>

        {{-- 2. LA CONSTITUTION PROPRE --}}
        <section class="rounded-xl border-2 border-indigo-200 bg-white p-6 dark:border-indigo-800/60 dark:bg-gray-800" data-org-constitution-editor>
            <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('ai.behavior_org_constitution_title') }}</h2>
                @if($constitution)
                    <span class="rounded-full bg-indigo-100 px-2.5 py-1 text-xs font-medium text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300" data-org-constitution-status="active" data-org-constitution-version="{{ $constitution->version }}">{{ __('ai.behavior_org_constitution_active', ['version' => $constitution->version]) }}</span>
                @else
                    <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-300" data-org-constitution-status="none">{{ __('ai.behavior_org_constitution_badge') }}</span>
                @endif
            </div>
            <p class="mb-1 text-xs text-gray-500 dark:text-gray-400">{{ __('ai.behavior_org_constitution_help') }}</p>
            <p class="mb-4 text-xs text-gray-500 dark:text-gray-400">{{ __('ai.behavior_org_constitution_inherit_note') }}</p>

            <form method="POST" action="{{ route('organization.admin.ai-behavior.constitution.update', ['organization' => $organization->slug]) }}">
                @csrf
                @method('PUT')
                <textarea name="constitution_body" rows="9" maxlength="{{ $constitutionMaxChars }}"
                          placeholder="{{ __('ai.behavior_org_constitution_placeholder') }}"
                          class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                          data-org-constitution-input>{{ old('constitution_body', $constitution->body ?? '') }}</textarea>
                @error('constitution_body')
                    <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700" data-org-constitution-save>{{ __('ai.behavior_org_constitution_save') }}</button>
                    <span class="text-xs text-gray-400">{{ $constitutionMaxChars }}</span>
                </div>
            </form>

            @if($constitution)
                <form method="POST" action="{{ route('organization.admin.ai-behavior.constitution.withdraw', ['organization' => $organization->slug]) }}" class="mt-3">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="text-xs text-gray-500 underline hover:text-red-600 dark:text-gray-400" data-org-constitution-withdraw>{{ __('ai.behavior_org_constitution_withdraw') }}</button>
                </form>
            @endif

            @if($historyTotal > 0)
                <div class="mt-5 border-t border-gray-100 pt-4 dark:border-gray-700">
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ai.behavior_org_constitution_history') }}</h3>
                    <ul class="space-y-1 text-xs text-gray-500 dark:text-gray-400" data-org-constitution-history data-org-constitution-history-total="{{ $historyTotal }}">
                        @foreach($history as $version)
                            <li>{{ __('ai.constitution_admin_version_by', [
                                'version' => $version->version,
                                'author' => $version->author?->publicDisplayName() ?? '—',
                                'date' => optional($version->activated_at ?? $version->created_at)->isoFormat('LLL'),
                            ]) }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </section>

        {{-- 3. LA PUBLICATION — opt-in explicite, prive par defaut --}}
        <section class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800" data-org-constitution-publication>
            <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ __('mycelium.publication_title') }}</h2>
                <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $isPublic ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' }}"
                      data-org-constitution-publication-state="{{ $isPublic ? 'public' : 'private' }}">
                    {{ $isPublic ? __('mycelium.publication_state_public') : __('mycelium.publication_state_private') }}
                </span>
            </div>
            <p class="mb-4 text-xs text-gray-500 dark:text-gray-400">{{ __('mycelium.publication_help') }}</p>

            @if(! $constitution && ! $isPublic)
                <p class="mb-3 text-xs text-amber-700 dark:text-amber-300" data-org-constitution-publication-blocked>{{ __('mycelium.publication_needs_text') }}</p>
            @endif

            <form method="POST" action="{{ route('organization.admin.constitution.publication', ['organization' => $organization->slug]) }}">
                @csrf
                @method('PUT')
                <input type="hidden" name="ai_constitution_public" value="{{ $isPublic ? 0 : 1 }}">
                <button type="submit"
                        class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition hover:border-indigo-400 dark:border-gray-600 dark:text-gray-200"
                        data-org-constitution-publication-toggle>
                    {{ $isPublic ? __('mycelium.publication_private') : __('mycelium.publication_public') }}
                </button>
            </form>

            @if($isPublic && $constitution)
                <a href="{{ route('organization.constitution', ['organization' => $organization->slug]) }}"
                   class="mt-3 inline-flex text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-400"
                   data-org-constitution-public-link>{{ route('organization.constitution', ['organization' => $organization->slug]) }}</a>
            @endif
        </section>

        {{-- La Doctrine reste ailleurs : le dire evite de la chercher ici. --}}
        <p class="text-xs text-gray-500 dark:text-gray-400" data-org-constitution-doctrine-note>
            {{ __('mycelium.admin_org_doctrine_note') }}
            <a href="{{ route('organization.admin.ai-behavior', ['organization' => $organization->slug]) }}" class="font-semibold text-indigo-600 hover:underline dark:text-indigo-400">{{ __('mycelium.admin_org_doctrine_link') }}</a>
        </p>

    </div>
</x-org-admin-layout>
