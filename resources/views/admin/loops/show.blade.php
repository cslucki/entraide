{{--
    Read-only overview of a Loop from the admin.

    Everything about a Loop on one page — identity, type, cards, governance,
    Manifesto, invitations — so an administrator can see what a Loop *is*
    without entering its workspace and without any risk of touching it.
--}}
<x-admin-layout :title="$boucle->name">
    <div class="mx-auto max-w-5xl px-4 py-8">

        <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <a href="{{ route('admin.loops') }}" class="text-sm text-gray-500 transition hover:text-indigo-600 dark:text-gray-400">&larr; Boucles</a>
                <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $boucle->name }}</h1>
                @if($boucle->tagline)
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $boucle->tagline }}</p>
                @endif
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.loops.edit', $boucle) }}"
                   class="inline-flex min-h-[44px] items-center rounded-xl bg-indigo-600 px-4 text-sm font-semibold text-white transition hover:bg-indigo-700">Modifier</a>
                {{-- Org-scoped: a Loop of another Organization must not resolve
                     against the admin's own. --}}
                @if($canEnterWorkspace)
                    <a href="{{ $boucle->workspaceUrl() }}"
                       class="inline-flex min-h-[44px] items-center rounded-xl border border-gray-300 px-4 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800">Workspace</a>
                @endif
            </div>
        </div>

        {{-- Identité --}}
        <section class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach([
                ['Organisation', $boucle->organization?->name ?? '—'],
                [__('loops.type_label'), $typeLabel],
                ['Visibilité', $boucle->isPublic() ? 'Publique' : 'Privée'],
                ['Statut', $boucle->status === 'active' ? 'Active' : 'Archivée'],
            ] as [$label, $value])
                <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">{{ $label }}</p>
                    <p class="mt-1 text-sm font-medium text-gray-900 dark:text-gray-100">{{ $value }}</p>
                </div>
            @endforeach
        </section>

        <section class="mb-5 grid gap-3 sm:grid-cols-3">
            @foreach([
                ['Membres actifs', $boucle->active_members_count],
                [__('loops.invitations_sent_count'), $boucle->invitations_count.($boucle->pending_invitations_count ? ' ('.$boucle->pending_invitations_count.' en attente)' : '')],
                [__('loops.cards_linked'), count($activeCards)],
            ] as [$label, $value])
                <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">{{ $label }}</p>
                    <p class="mt-1 text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $value }}</p>
                </div>
            @endforeach
        </section>

        @if($boucle->description)
            <section class="mb-5 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                <h2 class="mb-2 text-sm font-semibold text-gray-800 dark:text-gray-100">Description</h2>
                <p class="whitespace-pre-line text-sm leading-6 text-gray-600 dark:text-gray-300">{{ $boucle->description }}</p>
            </section>
        @endif

        {{-- Manifeste : la raison principale d'ouvrir cette page --}}
        <section class="mb-5 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('loops.manifesto_public_title') }}</h2>
                @if($boucle->manifesto)
                    <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $boucle->manifesto->status === 'published' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' }}">
                        {{ $boucle->manifesto->status === 'published' ? __('loops.manifesto_published') : __('loops.manifesto_draft') }}
                    </span>
                @endif
            </div>

            @if($boucle->manifesto)
                <p class="mb-3 text-xs text-gray-400">
                    {{ $boucle->manifesto->title }}
                    @if($boucle->manifesto->user) · {{ $boucle->manifesto->user->publicDisplayName() }} @endif
                    · {{ $boucle->manifesto->updated_at?->isoFormat('LL') }}
                </p>
                <div class="prose prose-sm max-w-none text-gray-700 dark:prose-invert dark:text-gray-200">
                    {!! $boucle->manifestoHtmlForAdmin() !!}
                </div>

                @if($manifestoSources->isNotEmpty())
                    <div class="mt-4 border-t border-gray-100 pt-3 dark:border-gray-700">
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400">{{ __('loops.manifesto_sources_title') }}</p>
                        <ul class="space-y-1">
                            @foreach($manifestoSources as $source)
                                <li class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                                    <span class="truncate">{{ $source->dossierFile->display_name }}</span>
                                    <span class="shrink-0 text-gray-400">· {{ $source->dossierFile->dossier?->name ?? '—' }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @else
                <p class="text-sm text-gray-400">{{ __('loops.cards.manifesto.empty_title') }}</p>
            @endif
        </section>

        {{-- Gouvernance --}}
        <section class="mb-5 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
            <h2 class="mb-3 text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('loops.governance_title') }}</h2>
            <x-loops.governance-roster
                :members="$boucle->activeMembers"
                :role-route="fn($m) => route('admin.loops.members.role', [$boucle, $m])"
                :remove-route="null"
                :can-manage-owners="false"
                :can-manage-facilitators="false"
                :can-remove="false"
                :creator-id="$boucle->created_by"
                :current-user-id="auth()->id()" />
            <p class="mt-3 text-xs text-gray-400">Lecture seule — les actions sont sur la page Modifier.</p>
        </section>

        {{-- Cards, avec un aperçu de ce qu'elles contiennent --}}
        <section class="mb-5 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
            <h2 class="mb-3 text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('loops.cards_linked') }}</h2>

            @forelse($activeCards as $key)
                @php
                    $definition = $cardCatalogue[$key] ?? null;
                    $preview = $cardPreview[$key] ?? null;
                @endphp
                <div class="mb-2 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-xl border border-gray-100 px-3 py-2 last:mb-0 dark:border-gray-700">
                    <span class="text-sm font-medium text-gray-800 dark:text-gray-100">
                        {{ $definition ? __($definition['label_key']) : $key }}
                    </span>
                    @if(in_array($key, $presetCards, true))
                        <span class="rounded bg-indigo-100 px-1.5 py-0.5 text-[10px] font-semibold text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300">socle</span>
                    @endif
                    <span class="min-w-0 flex-1 truncate text-xs text-gray-500 dark:text-gray-400">
                        @switch($key)
                            @case('core.members') {{ $preview }} membre{{ $preview !== 1 ? 's' : '' }} @break
                            @case('core.roadmap') {{ $preview }} élément{{ $preview !== 1 ? 's' : '' }} @break
                            @case('core.manifesto') {{ $preview ?: 'Aucun Manifeste désigné' }} @break
                            @case('core.ai_summary') {{ $preview }} message{{ $preview !== 1 ? 's' : '' }} dans ChatLoop @break
                            @default {{ $definition ? __($definition['description_key']) : '—' }}
                        @endswitch
                    </span>
                </div>
            @empty
                <p class="text-xs text-gray-400">—</p>
            @endforelse
        </section>

        {{-- Invitations --}}
        @if($invitations->isNotEmpty())
            <section class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                <h2 class="mb-3 text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('loops.invitations_loop_section_title') }}</h2>
                <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($invitations as $invitation)
                        <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2 text-xs">
                            <span class="min-w-0 flex-1 truncate text-gray-700 dark:text-gray-200">{{ $invitation->recipient_email }}</span>
                            <x-loops.invitation-status :invitation="$invitation" />
                            <span class="text-gray-400">{{ $invitation->created_at?->isoFormat('L') }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

    </div>
</x-admin-layout>
