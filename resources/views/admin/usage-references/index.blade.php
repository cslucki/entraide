<x-admin-layout :title="__('admin.usage_reference_title')">
    {{-- TASK-1439 — UsageReference V1 : « a quoi sert cette surface ? » — curee, versionnee, publiee par un humain. Plateforme-only.

         TASK-1480 — l'ecran repond desormais a la question qu'on lui pose vraiment :
         « qu'est-ce qui est en ligne sur cette surface, dans cette langue ? »

         Il listait des VERSIONS. Un SuperAdmin devait reconstituer de tete, pour
         chaque couple (surface, locale), laquelle etait publiee et laquelle etait un
         brouillon en attente. La donnee etait la ; la reponse, non.

         Et surtout : AUCUNE vue ne montrait le contenu d'une version PUBLIEE.
         `edit` etait la seule vue du texte, et elle rend 404 sur du publie. On ne
         pouvait donc pas relire ce qu'on servait. C'est cela qui rendait l'ecran
         incomprehensible — pas le mot « brouillon », qui reste. --}}
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('admin.usage_reference_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('admin.usage_reference_hint', ['locale' => $platformLocale]) }}</p>
        </div>
        <a href="{{ route('admin.usage-references.create') }}" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 text-sm font-medium" data-usage-reference-new>{{ __('admin.usage_reference_new') }}</a>
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 px-4 py-3 text-sm text-emerald-800 dark:text-emerald-200">{{ session('success') }}</div>
    @endif

    @foreach($surfaces as $surface)
    <section class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 mb-6" data-usage-reference-surface="{{ $surface }}">
        <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-700">
            <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ __('admin.usage_reference_surface_'.$surface) }} <code class="text-xs text-gray-400">{{ $surface }}</code></h2>
        </div>

        <div class="divide-y divide-gray-100 dark:divide-gray-700">
            @foreach($locales as $loc)
                @php
                    $cell = $cells[$surface][$loc];
                    $published = $cell['published'];
                    $draft = $cell['draft'];
                @endphp
                <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
                     data-usage-reference-cell="{{ $surface }}:{{ $loc }}"
                     data-usage-reference-draft-version="{{ $draft?->version ?? '' }}">

                    {{-- L'ETAT, en une ligne : ce qui est en ligne, et ce qui attend. --}}
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <span class="font-mono text-xs text-gray-400">{{ strtoupper($loc) }}</span>

                        @if($published)
                            {{-- La PAIRE reste adjacente : TASK-1439 mesure
                                 `data-usage-reference-live` suivi de
                                 `data-usage-reference-live-version`, et ce
                                 contrat n'a aucune raison de changer. --}}
                            <span class="px-2 py-0.5 rounded text-xs font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300" data-usage-reference-live="{{ $surface }}:{{ $loc }}" data-usage-reference-live-version="{{ $published->version }}">
                                {{ __('admin.usage_reference_live_version', ['version' => $published->version]) }}
                            </span>
                            <span class="text-gray-700 dark:text-gray-200">{{ $published->title }}</span>
                        @else
                            <span class="text-gray-500 dark:text-gray-400" data-usage-reference-none="{{ $surface }}:{{ $loc }}">{{ __('admin.usage_reference_none') }}</span>
                        @endif

                        @if($draft)
                            <span class="px-2 py-0.5 rounded text-xs font-semibold bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300" data-usage-reference-draft="{{ $surface }}:{{ $loc }}">
                                {{ __('admin.usage_reference_draft_version', ['version' => $draft->version]) }}
                            </span>
                        @endif
                    </div>

                    {{-- LES GESTES. Chaque bouton mene quelque part ; aucun n'est propose
                         quand l'objet qu'il vise n'existe pas. --}}
                    <div class="flex flex-wrap items-center gap-2">
                        @if($published)
                            <a href="{{ route('admin.usage-references.show', $published) }}"
                               class="px-2.5 py-1 rounded border border-gray-300 dark:border-gray-600 text-xs font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700"
                               data-usage-reference-view="{{ $published->id }}">{{ __('admin.usage_reference_view') }}</a>
                        @endif

                        @if($draft)
                            {{-- Un brouillon existe : on l'edite, on le relit, on le publie.
                                 Proposer en plus « Modifier » sur la version publiee creerait
                                 un SECOND brouillon concurrent pour le meme couple. --}}
                            <a href="{{ route('admin.usage-references.edit', $draft) }}"
                               class="px-2.5 py-1 rounded border border-indigo-300 dark:border-indigo-700 text-xs font-medium text-indigo-700 dark:text-indigo-300 hover:bg-indigo-50 dark:hover:bg-indigo-900/30"
                               data-usage-reference-edit-draft="{{ $draft->id }}">{{ __('admin.usage_reference_edit_draft') }}</a>

                            <a href="{{ route('admin.usage-references.show', $draft) }}"
                               class="px-2.5 py-1 rounded border border-gray-300 dark:border-gray-600 text-xs font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700"
                               data-usage-reference-preview="{{ $draft->id }}">{{ __('admin.usage_reference_preview') }}</a>

                            <form method="POST" action="{{ route('admin.usage-references.publish', $draft) }}">@csrf
                                <button type="submit" class="px-2.5 py-1 rounded bg-emerald-600 text-white text-xs font-medium hover:bg-emerald-700" data-usage-reference-publish="{{ $draft->id }}">{{ __('admin.usage_reference_publish') }}</button>
                            </form>
                        @elseif($published)
                            {{-- Aucun brouillon : « Modifier » ouvre un brouillon v+1
                                 PRE-REMPLI. Une version publiee est immuable — mais on ne
                                 doit pas avoir a retaper son texte pour la faire evoluer. --}}
                            <a href="{{ route('admin.usage-references.create', ['surface' => $surface, 'locale' => $loc, 'from' => $published->id]) }}"
                               class="px-2.5 py-1 rounded border border-indigo-300 dark:border-indigo-700 text-xs font-medium text-indigo-700 dark:text-indigo-300 hover:bg-indigo-50 dark:hover:bg-indigo-900/30"
                               data-usage-reference-amend="{{ $published->id }}">{{ __('admin.usage_reference_amend') }}</a>
                        @else
                            <a href="{{ route('admin.usage-references.create', ['surface' => $surface, 'locale' => $loc]) }}"
                               class="px-2.5 py-1 rounded bg-indigo-600 text-white text-xs font-medium hover:bg-indigo-700"
                               data-usage-reference-create="{{ $surface }}:{{ $loc }}">{{ __('admin.usage_reference_create_for') }}</a>
                        @endif

                        @if($published)
                            <form method="POST" action="{{ route('admin.usage-references.retire', $published) }}" onsubmit="return confirm(@js(__('admin.usage_reference_retire_confirm')))">@csrf @method('DELETE')
                                <button type="submit" class="px-2.5 py-1 rounded border border-gray-300 dark:border-gray-600 text-xs text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700" data-usage-reference-retire="{{ $published->id }}">{{ __('admin.usage_reference_retire') }}</button>
                            </form>
                        @endif
                    </div>
                </div>

                {{-- L'HISTORIQUE. Il existait deja, melange au reste dans une table :
                     rien ne distinguait ce qui est en ligne de ce qui ne l'est plus.
                     Il se replie, sur le meme patron `<details>` que TASK-1474 —
                     un element HTML, pas de JavaScript, et l'etat ouvert survit a
                     l'impression. --}}
                @if($cell['versions']->count() > 0)
                    <details class="px-4 pb-3" data-usage-reference-history="{{ $surface }}:{{ $loc }}">
                        <summary class="cursor-pointer text-xs text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">
                            {{ trans_choice('admin.usage_reference_history', $cell['versions']->count(), ['count' => $cell['versions']->count()]) }}
                        </summary>
                        <ul class="mt-2 space-y-1">
                            @foreach($cell['versions'] as $version)
                                <li class="flex flex-wrap items-center gap-2 text-xs" data-usage-reference-row="{{ $version->id }}" data-usage-reference-state="{{ $version->state }}" data-usage-reference-version="{{ $version->version }}">
                                    <span class="tabular-nums font-mono text-gray-500">v{{ $version->version }}</span>
                                    <span class="px-1.5 py-0.5 rounded font-semibold {{ match($version->state) { 'published' => 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-300', 'draft' => 'bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-300', default => 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300' } }}">{{ __('admin.usage_reference_state_'.$version->state) }}</span>
                                    <span class="text-gray-700 dark:text-gray-200">{{ $version->title }}</span>
                                    <span class="text-gray-400">{{ $version->author?->name ?? __('admin.usage_reference_author_system') }}</span>
                                    <span class="text-gray-400">{{ $version->published_at?->format('d/m/Y H:i') ?? '—' }}</span>
                                    <a href="{{ route('admin.usage-references.show', $version) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">{{ __('admin.usage_reference_view') }}</a>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            @endforeach
        </div>
    </section>
    @endforeach

    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('admin.usage_reference_footer', ['max' => $maxChars]) }}</p>
</x-admin-layout>
