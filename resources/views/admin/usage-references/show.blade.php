<x-admin-layout :title="__('admin.usage_reference_show_title', ['version' => $reference->version])">
    {{-- TASK-1480 — LIRE une reference.

         Le geste qui manquait, et la cause reelle de l'incomprehension de cet
         ecran : `edit` etait la seule vue du texte, et elle rend 404 sur une
         version publiee. On ne pouvait donc PAS relire ce que le Shell servait.

         Cette meme vue est la PREVISUALISATION d'un brouillon. Previsualiser,
         ici, c'est lire le texte tel que le Shell le recevra — une seconde mise
         en page « pour l'apercu » serait une deuxieme verite, et c'est
         precisement ce que le produit refuse partout ailleurs. --}}
    <div class="max-w-3xl">
        <a href="{{ route('admin.usage-references') }}" class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200">
            &larr; {{ __('admin.usage_reference_back') }}
        </a>

        <div class="mt-3 flex flex-wrap items-center gap-2" data-usage-reference-show="{{ $reference->id }}" data-usage-reference-state="{{ $reference->state }}">
            <span class="px-2 py-0.5 rounded text-xs font-semibold {{ match($reference->state) { 'published' => 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-300', 'draft' => 'bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-300', default => 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300' } }}">
                {{ __('admin.usage_reference_state_'.$reference->state) }}
            </span>
            <span class="tabular-nums font-mono text-xs text-gray-500">v{{ $reference->version }}</span>
            <span class="font-mono text-xs text-gray-400">{{ $reference->surface_key }}</span>
            <span class="font-mono text-xs text-gray-400">{{ strtoupper($reference->locale) }}</span>
        </div>

        {{-- Un brouillon n'est pas encore servi : on le DIT, plutot que de laisser
             croire que ce texte est en ligne. --}}
        @if($reference->isDraft())
            <p class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-800/50 dark:bg-amber-900/20 dark:text-amber-200" data-usage-reference-not-live>
                {{ __('admin.usage_reference_draft_not_live') }}
            </p>
        @endif

        <h1 class="mt-4 text-2xl font-bold text-gray-900 dark:text-gray-100" data-usage-reference-show-title>{{ $reference->title }}</h1>

        {{-- Le contenu, tel quel. `whitespace-pre-wrap` : le texte est stocke brut
             et servi brut au Shell ; le reformater ici montrerait autre chose que
             ce qui part. --}}
        <div class="mt-4 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
            <p class="whitespace-pre-wrap text-sm leading-6 text-gray-800 dark:text-gray-100" data-usage-reference-show-content>{{ $reference->content }}</p>
        </div>

        <dl class="mt-4 grid grid-cols-1 gap-2 text-xs text-gray-500 dark:text-gray-400 sm:grid-cols-2">
            <div class="flex gap-2">
                <dt class="font-semibold">{{ __('admin.usage_reference_col_author') }}</dt>
                <dd>{{ $reference->author?->name ?? __('admin.usage_reference_author_system') }}</dd>
            </div>
            <div class="flex gap-2">
                <dt class="font-semibold">{{ __('admin.usage_reference_col_published') }}</dt>
                <dd>{{ $reference->published_at?->format('d/m/Y H:i') ?? '—' }}</dd>
            </div>
            @if($reference->publisher)
                <div class="flex gap-2">
                    <dt class="font-semibold">{{ __('admin.usage_reference_publisher') }}</dt>
                    <dd>{{ $reference->publisher->name }}</dd>
                </div>
            @endif
            <div class="flex gap-2">
                <dt class="font-semibold">{{ __('admin.usage_reference_length') }}</dt>
                <dd>{{ mb_strlen($reference->content) }} / {{ $maxChars }}</dd>
            </div>
        </dl>

        <div class="mt-5 flex flex-wrap items-center gap-2">
            @if($reference->isDraft())
                <a href="{{ route('admin.usage-references.edit', $reference) }}" class="px-3 py-2 rounded-lg border border-indigo-300 dark:border-indigo-700 text-sm font-medium text-indigo-700 dark:text-indigo-300 hover:bg-indigo-50 dark:hover:bg-indigo-900/30" data-usage-reference-show-edit>{{ __('admin.usage_reference_edit_draft') }}</a>

                {{-- La publication reste un geste humain explicite, ici comme
                     ailleurs : aucune generation, aucune mise en ligne
                     automatique. --}}
                <form method="POST" action="{{ route('admin.usage-references.publish', $reference) }}">@csrf
                    <button type="submit" class="px-3 py-2 rounded-lg bg-emerald-600 text-white text-sm font-medium hover:bg-emerald-700" data-usage-reference-show-publish>{{ __('admin.usage_reference_publish') }}</button>
                </form>
            @elseif($reference->isPublished())
                <a href="{{ route('admin.usage-references.create', ['surface' => $reference->surface_key, 'locale' => $reference->locale, 'from' => $reference->id]) }}" class="px-3 py-2 rounded-lg border border-indigo-300 dark:border-indigo-700 text-sm font-medium text-indigo-700 dark:text-indigo-300 hover:bg-indigo-50 dark:hover:bg-indigo-900/30" data-usage-reference-show-amend>{{ __('admin.usage_reference_amend') }}</a>
            @endif
        </div>
    </div>
</x-admin-layout>
