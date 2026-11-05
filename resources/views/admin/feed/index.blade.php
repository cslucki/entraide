<x-admin-layout :title="__('admin.feed_title')">
    {{-- TASK-1752 — tous les contenus du Flux, toutes Organizations.

         Ecrit sur le gabarit de `admin/roadmap/index` : voir, filtrer,
         corriger, et rien de plus. Aucun second systeme.

         LA distinction que cet ecran doit faire : un evenement automatique n'a
         pas de texte a modifier — il est CALCULE. Lui offrir un champ de texte
         afficherait un formulaire qui ne change rien. Pour lui, « modifier »
         ne porte que sur l'audience et le statut. --}}

    @if(session('success'))
        <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-800 dark:bg-emerald-900/20 dark:text-emerald-300">
            {{ session('success') }}
        </div>
    @endif

    <form method="GET" class="mb-5 flex flex-wrap items-end gap-3">
        <div>
            <label for="organization_id" class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">{{ __('admin.feed_filter_organization') }}</label>
            <select name="organization_id" id="organization_id" data-marqueur="filtre-organisation"
                    class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
                <option value="">{{ __('admin.feed_all_organizations') }}</option>
                @foreach($organizations as $org)
                    <option value="{{ $org->id }}" {{ $organizationId === $org->id ? 'selected' : '' }}>{{ $org->name }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="type" class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">{{ __('admin.feed_filter_type') }}</label>
            <select name="type" id="type" data-marqueur="filtre-type"
                    class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
                <option value="">{{ __('admin.feed_all_types') }}</option>
                <option value="annonces" {{ $type === 'annonces' ? 'selected' : '' }}>{{ __('admin.feed_type_announcements') }}</option>
                <option value="evenements" {{ $type === 'evenements' ? 'selected' : '' }}>{{ __('admin.feed_type_events') }}</option>
            </select>
        </div>

        <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">{{ __('admin.feed_filter') }}</button>
        <a href="{{ route('admin.feed') }}" class="px-4 py-2 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">{{ __('admin.feed_clear') }}</a>
    </form>

    {{-- TASK-1752 (§8.4, demande Cyril) — l'outil de reprise d'historique.

         Par ORGANISATION, jamais « toutes » : trois tenants, trois decisions.
         Le panneau n'apparait donc que lorsqu'une Organization est choisie
         dans le filtre ci-dessus — et le compte a blanc, qui parcourt cinq
         tables, n'est calcule qu'a ce moment-la. --}}
    @if($reprise === null)
        <div class="mb-6 rounded-xl border border-dashed border-gray-300 px-4 py-3 text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400" data-marqueur="reprise-invite">
            {{ __('admin.feed_replay_pick_organization') }}
        </div>
    @else
        @php($_aEcrire = array_sum($reprise['comptes']))
        @php($_enPlace = array_sum($reprise['parType']))

        <section class="mb-6 rounded-xl border border-gray-200 bg-gray-50 p-5 dark:border-gray-700 dark:bg-gray-800/40" data-marqueur="reprise-historique">
            <h2 class="text-sm font-bold text-gray-900 dark:text-gray-100">{{ __('admin.feed_replay_title', ['name' => $reprise['organization']->name]) }}</h2>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('admin.feed_replay_help') }}</p>

            {{-- TASK-1758 — l'interrupteur des evenements AUTOMATIQUES.
                 Il manquait : la page savait RETIRER ce qui etait deja ecrit,
                 rien n'arretait les suivants. Les deux gestes restent distincts
                 — on peut vouloir arreter sans effacer l'historique. --}}
            @php($_evenementsActifs = (bool) ($reprise['organization']->feed_auto_events_enabled ?? true))
            <form method="POST" action="{{ route('admin.feed.auto-events') }}"
                  class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border px-4 py-3
                         {{ $_evenementsActifs
                            ? 'border-green-200 bg-green-50 dark:border-green-800/60 dark:bg-green-900/20'
                            : 'border-amber-200 bg-amber-50 dark:border-amber-800/60 dark:bg-amber-900/20' }}">
                @csrf
                <input type="hidden" name="organization_id" value="{{ $reprise['organization']->id }}">
                <input type="hidden" name="actif" value="{{ $_evenementsActifs ? 0 : 1 }}">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-gray-900 dark:text-gray-100" data-marqueur="evenements-auto-etat">
                        {{ $_evenementsActifs ? __('admin.feed_auto_events_active') : __('admin.feed_auto_events_paused') }}
                    </p>
                    <p class="mt-0.5 text-xs text-gray-600 dark:text-gray-400">{{ __('admin.feed_auto_events_help') }}</p>
                </div>
                <button type="submit" data-marqueur="evenements-auto-bascule"
                        class="shrink-0 rounded-lg px-4 py-2 text-sm font-semibold text-white transition
                            {{ $_evenementsActifs ? 'bg-amber-600 hover:bg-amber-700' : 'bg-green-600 hover:bg-green-700' }}">
                    {{ $_evenementsActifs ? __('admin.feed_auto_events_pause') : __('admin.feed_auto_events_resume') }}
                </button>
            </form>

            <div class="mt-4 grid gap-5 md:grid-cols-2">
                {{-- Generer --}}
                <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    <h3 class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.feed_replay_generate') }}</h3>

                    <ul class="mt-3 space-y-1 text-sm" data-marqueur="reprise-comptes">
                        @foreach($reprise['comptes'] as $clef => $n)
                            <li class="flex items-center justify-between {{ $n > 0 ? 'text-gray-700 dark:text-gray-200' : 'text-gray-400 dark:text-gray-600' }}">
                                <span>{{ __('admin.feed_replay_source_'.$clef) }}</span>
                                <span class="font-semibold tabular-nums">{{ $n }}</span>
                            </li>
                        @endforeach
                    </ul>

                    <p class="mt-3 border-t border-gray-100 pt-3 text-sm font-semibold text-gray-900 dark:border-gray-800 dark:text-gray-100">
                        {{ __('admin.feed_replay_total', ['count' => $_aEcrire]) }}
                    </p>

                    <form method="POST" action="{{ route('admin.feed.replay') }}" class="mt-3">
                        @csrf
                        <input type="hidden" name="organization_id" value="{{ $reprise['organization']->id }}">
                        <input type="hidden" name="action" value="reprendre">
                        <button type="submit" @disabled($_aEcrire === 0)
                                class="w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-40"
                                data-marqueur="reprise-generer">
                            {{ $_aEcrire === 0 ? __('admin.feed_replay_nothing') : __('admin.feed_replay_run', ['count' => $_aEcrire]) }}
                        </button>
                    </form>
                </div>

                {{-- Supprimer, par TYPE --}}
                <form method="POST" action="{{ route('admin.feed.replay') }}"
                      class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900"
                      onsubmit="return confirm('{{ __('admin.feed_replay_remove_confirm') }}')">
                    @csrf
                    <input type="hidden" name="organization_id" value="{{ $reprise['organization']->id }}">
                    <input type="hidden" name="action" value="retirer">

                    <h3 class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.feed_replay_remove') }}</h3>

                    <div class="mt-3 space-y-1" data-marqueur="reprise-types">
                        @foreach($reprise['parType'] as $clef => $n)
                            <label class="flex items-center justify-between gap-3 rounded px-1 py-0.5 text-sm {{ $n > 0 ? 'text-gray-700 dark:text-gray-200' : 'text-gray-400 dark:text-gray-600' }}">
                                <span class="flex items-center gap-2">
                                    <input type="checkbox" name="types[]" value="{{ $clef }}" @checked($n > 0) @disabled($n === 0)
                                           class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-40 dark:border-gray-600 dark:bg-gray-800">
                                    {{ \App\Support\Feed\FeedEventCatalogue::libelleCourt($clef) }}
                                </span>
                                <span class="font-semibold tabular-nums">{{ $n }}</span>
                            </label>
                        @endforeach
                    </div>

                    <p class="mt-3 border-t border-gray-100 pt-3 text-xs text-amber-700 dark:border-gray-800 dark:text-amber-400">
                        {{ __('admin.feed_replay_remove_warning') }}
                    </p>

                    <button type="submit" @disabled($_enPlace === 0)
                            class="mt-3 w-full rounded-lg border border-red-300 px-4 py-2 text-sm font-semibold text-red-600 transition hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-red-800 dark:hover:bg-red-900/30"
                            data-marqueur="reprise-supprimer">
                        {{ __('admin.feed_replay_remove_action') }}
                    </button>
                </form>
            </div>
        </section>
    @endif

    <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm" data-marqueur="table-flux">
            <thead class="bg-gray-50 dark:bg-gray-800/60">
                <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <th class="px-4 py-3">{{ __('admin.feed_col_content') }}</th>
                    <th class="px-4 py-3">{{ __('admin.feed_col_organization') }}</th>
                    <th class="px-4 py-3">{{ __('admin.feed_col_author') }}</th>
                    <th class="px-4 py-3">{{ __('admin.feed_col_audience') }}</th>
                    <th class="px-4 py-3">{{ __('admin.feed_col_engagement') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('admin.feed_col_actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse($posts as $post)
                    @php($estEvenement = in_array($post->type, $typesEvenement, true))
                    <tr data-marqueur="ligne-flux" data-type="{{ $post->type }}">
                        <td class="px-4 py-3 align-top max-w-md">
                            @if($estEvenement)
                                <span class="inline-flex items-center rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-semibold text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200">{{ __('feed.events.badge') }}</span>
                                <p class="mt-1 text-gray-800 dark:text-gray-100">{{ \App\Support\Feed\FeedEventRenderer::libelle($post) }}</p>
                            @else
                                @if($post->title)
                                    <p class="font-semibold text-gray-900 dark:text-gray-100">{{ $post->title }}</p>
                                @endif
                                <p class="mt-1 line-clamp-2 text-gray-600 dark:text-gray-300">{{ \Illuminate\Support\Str::limit(strip_tags((string) $post->content), 160) }}</p>
                            @endif
                            <p class="mt-1 text-xs text-gray-400">{{ $post->published_at?->isoFormat('lll') ?? $post->created_at->isoFormat('lll') }}</p>
                        </td>
                        <td class="px-4 py-3 align-top text-gray-600 dark:text-gray-300">{{ $post->organization?->name ?? '—' }}</td>
                        <td class="px-4 py-3 align-top text-gray-600 dark:text-gray-300">{{ $post->user?->publicDisplayName() ?? '—' }}</td>
                        <td class="px-4 py-3 align-top">
                            <span class="text-xs text-gray-600 dark:text-gray-300">{{ $post->visibility === 'loop' ? __('admin.feed_audience_loop') : __('admin.feed_audience_organization') }}</span>
                        </td>
                        <td class="px-4 py-3 align-top text-xs text-gray-500 dark:text-gray-400">
                            {{ __('admin.feed_engagement', ['comments' => $post->comments_count, 'reactions' => $post->reactions_count]) }}
                        </td>
                        <td class="px-4 py-3 align-top text-right">
                            <form method="POST" action="{{ route('admin.feed.update', $post->id) }}" class="inline-flex flex-wrap items-center justify-end gap-2">
                                @csrf
                                @method('PUT')

                                {{-- Le texte n'est propose QUE pour une annonce humaine. --}}
                                @unless($estEvenement)
                                    <input type="text" name="title" value="{{ $post->title }}" placeholder="{{ __('admin.feed_col_title') }}"
                                           class="w-40 rounded-lg border border-gray-300 bg-white px-2 py-1 text-xs dark:border-gray-600 dark:bg-gray-800">
                                    <input type="text" name="content" value="{{ $post->content }}"
                                           class="w-48 rounded-lg border border-gray-300 bg-white px-2 py-1 text-xs dark:border-gray-600 dark:bg-gray-800">
                                @endunless

                                <select name="status" class="rounded-lg border border-gray-300 bg-white px-2 py-1 text-xs dark:border-gray-600 dark:bg-gray-800">
                                    @foreach(['published', 'draft', 'scheduled'] as $statut)
                                        <option value="{{ $statut }}" {{ $post->status === $statut ? 'selected' : '' }}>{{ $statut }}</option>
                                    @endforeach
                                </select>

                                <select name="visibility" class="rounded-lg border border-gray-300 bg-white px-2 py-1 text-xs dark:border-gray-600 dark:bg-gray-800">
                                    <option value="organization" {{ $post->visibility !== 'loop' ? 'selected' : '' }}>{{ __('admin.feed_audience_organization') }}</option>
                                    <option value="loop" {{ $post->visibility === 'loop' ? 'selected' : '' }}>{{ __('admin.feed_audience_loop') }}</option>
                                </select>

                                <button type="submit" class="rounded-lg bg-indigo-600 px-3 py-1 text-xs font-semibold text-white hover:bg-indigo-700">{{ __('admin.feed_save') }}</button>
                            </form>

                            {{-- « definitivement » est dit a l'ecran : le geste
                                 est irreversible et emporte les commentaires. --}}
                            <form method="POST" action="{{ route('admin.feed.destroy', $post->id) }}" class="mt-2 inline-block"
                                  onsubmit="return confirm('{{ __('admin.feed_delete_confirm') }}')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="rounded-lg border border-red-300 px-3 py-1 text-xs font-semibold text-red-600 hover:bg-red-50 dark:border-red-800 dark:hover:bg-red-900/30">{{ __('admin.feed_delete') }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">{{ __('admin.feed_empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-5">{{ $posts->links() }}</div>
</x-admin-layout>
