{{--
    Loop governance: owners, facilitators and members, with named actions.

    Shared by the global admin, the Organization admin and the Members card, so
    the three cannot drift on what an action is called or when it is offered.

    Actions are named after what they do — "Make owner", "Back to member" — not
    a technical role field auto-submitted on change. Availability comes from the
    caller's resolved permissions, never from reading a role label in Blade.

    Densite : une personne tient sur une ligne. Les trois listes separees, leurs
    titres et leur tiret quand elles etaient vides coutaient une demi-hauteur
    d'ecran ; le role se lit maintenant sur la ligne, et les actions — rarement
    utilisees, jamais urgentes — attendent dans un menu au lieu d'etaler deux
    boutons larges en face de chaque nom.
--}}
@props([
    'members',            // Collection<LoopMember>, active
    'roleRoute',          // fn(LoopMember): string
    'removeRoute',        // fn(LoopMember): string|null
    'canManageOwners' => false,
    'canManageFacilitators' => false,
    'canRemove' => false,
    'creatorId' => null,
    'currentUserId' => null,
    // Les personnes qui viennent d'arriver : leur ligne se signale un instant,
    // pour qu'on voie ou elles se sont rangees dans une liste triee.
    'highlightIds' => [],
    // Les intitules de role au-dessus de chaque groupe. Vrais par defaut : les
    // deux ecrans d'administration ont la largeur pour eux et s'en servent. La
    // Card Membres, elle, tient dans un panneau etroit et prefere un flux unique
    // ou le role se lit sur la ligne.
    'sections' => true,
    // TASK-1693 bug N — sur les ecrans d'ADMINISTRATION, les actions de role
    // se posent EN FACE du nom plutot que dans un menu « ... » sans libelle.
    //
    // On y vient precisement pour agir, et le menu rendait le changement de
    // role introuvable : « il n'est pas commode de gerer qui est proprietaire,
    // animateur ou membre » (recette Cyril, 21/10). La Card Membres, elle,
    // tient dans un panneau etroit et garde son menu — c'est le meme arbitrage
    // de largeur que `sections` juste au-dessus.
    //
    // Aucune regle ne change : memes gardes, memes routes, meme formulaire.
    'inlineActions' => false,
    // TASK-1693 bug O — fn(LoopMember): string, ou null. Quand elle est
    // fournie, la ligne du DERNIER proprietaire offre un transfert de
    // propriete plutot qu'une simple note expliquant qu'il est bloque.
    'transferRoute' => null,
])

@php
    $roles = app(\App\Support\Loops\LoopRoleRegistry::class);

    // TASK-1693 bug O — qui peut reprendre la Boucle : tout membre ACTIF qui
    // n'est pas deja proprietaire. Calcule UNE fois, pas par ligne.
    $transferables = collect($members)
        ->filter(fn ($m) => $roles->canonical($m->role) !== \App\Support\Loops\LoopRoleRegistry::OWNER)
        ->values();

    $OWNER = \App\Support\Loops\LoopRoleRegistry::OWNER;
    $FACILITATOR = \App\Support\Loops\LoopRoleRegistry::FACILITATOR;
    $MEMBER = \App\Support\Loops\LoopRoleRegistry::MEMBER;

    $grouped = $members->groupBy(fn ($m) => $roles->canonical($m->role));
    $ownerCount = $grouped->get($OWNER, collect())->count();

    // Gouvernance d'abord : on lit qui decide avant qui participe.
    $rank = [$OWNER => 0, $FACILITATOR => 1, $MEMBER => 2];
    $byName = fn ($m) => mb_strtolower($m->user?->publicDisplayName() ?? '');

    // Un seul parcours, que les groupes soient titres ou non : le balisage
    // d'une ligne n'existe qu'en un exemplaire.
    $blocks = $sections
        ? collect([
            [__('loops.governance_owners'), $grouped->get($OWNER, collect())->sortBy($byName)->values()],
            [__('loops.governance_facilitators'), $grouped->get($FACILITATOR, collect())->sortBy($byName)->values()],
            [__('loops.governance_members'), $grouped->get($MEMBER, collect())->sortBy($byName)->values()],
        ])
        : collect([[null, $members->sortBy(fn ($m) => [$rank[$roles->canonical($m->role)] ?? 3, $byName($m)])->values()]]);

    $chips = [
        $OWNER => ['label' => __('loops.members_role_owner'), 'class' => 'bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-200'],
        $FACILITATOR => ['label' => __('loops.members_role_facilitator'), 'class' => 'bg-sky-100 text-sky-700 dark:bg-sky-500/20 dark:text-sky-200'],
    ];
@endphp

<div {{ $attributes->merge(['class' => 'space-y-3']) }}>

    @if($members->isEmpty())
        <p class="text-xs text-gray-400">—</p>
    @else
        @foreach($blocks as [$heading, $bucket])
        @if($heading !== null)
            <h3 class="mb-1 mt-3 text-xs font-semibold uppercase tracking-wide text-gray-500 first:mt-0 dark:text-gray-400">
                {{ $heading }}
                <span class="font-normal text-gray-400">({{ $bucket->count() }})</span>
            </h3>
        @endif

        @if($bucket->isEmpty())
            <p class="text-xs text-gray-400">—</p>
        @else
        <ul class="divide-y divide-gray-100 dark:divide-gray-800">
            @foreach($bucket as $member)
                @php
                    $role = $roles->canonical($member->role);
                    $isLastOwner = $role === $OWNER && $ownerCount === 1;
                    $isSelf = $currentUserId !== null && $member->user_id === $currentUserId;

                    // Ce qui serait propose : si rien ne l'est, pas de menu.
                    $canPromoteOwner = $role !== $OWNER && $canManageOwners;
                    $canPromoteFacilitator = $role === $MEMBER && $canManageFacilitators;
                    $canDemoteOwner = $role === $OWNER && $canManageOwners && ! $isLastOwner;
                    $canDemoteFacilitator = $role === $FACILITATOR && $canManageFacilitators;
                    $canDrop = $canRemove && $removeRoute && ! $isLastOwner;
                    $hasActions = $canPromoteOwner || $canPromoteFacilitator || $canDemoteOwner || $canDemoteFacilitator || $canDrop;
                @endphp

                <li class="flex items-center gap-2.5 rounded-xl px-1.5 py-1.5 transition-colors duration-700 {{ in_array($member->user_id, $highlightIds, true) ? 'bg-emerald-50 dark:bg-emerald-900/25' : '' }}">
                    <x-user-avatar :user="$member->user" size="sm" />

                    <span class="min-w-0 flex-1">
                        <span class="flex items-center gap-1.5">
                            <span class="truncate text-sm font-medium text-gray-800 dark:text-gray-100">
                                {{ $member->user?->publicDisplayName() ?? '—' }}
                            </span>
                            @isset($chips[$role])
                                <span class="shrink-0 rounded-full px-1.5 py-px text-[9px] font-bold uppercase tracking-wide {{ $chips[$role]['class'] }}">
                                    {{ $chips[$role]['label'] }}
                                </span>
                            @endisset
                        </span>

                        @php
                            $notes = [];
                            if ($creatorId && $member->user_id === $creatorId) {
                                $notes[] = __('loops.governance_creator');
                            }
                            if ($roles->isLegacyAlias($member->role)) {
                                $notes[] = $member->role;
                            }
                            if ($isLastOwner) {
                                $notes[] = __('loops.governance_last_owner_short');
                            }
                        @endphp
                        @if($notes !== [])
                            <span class="block truncate text-[10px] leading-tight text-gray-400">{{ implode(' · ', $notes) }}</span>
                        @endif
                    </span>

                    @if($isLastOwner && $inlineActions && $transferRoute && $transferables->isNotEmpty())
                        {{-- TASK-1693 bug O — le dernier proprietaire n'a aucune
                             action, et c'est juste : une Boucle sans
                             proprietaire est l'etat qui bloque tout le reste.

                             Mais la NOTE seule laissait l'administrateur se
                             debrouiller en deux gestes, dans deux endroits de
                             l'ecran : promouvoir quelqu'un, puis revenir
                             retrograder celui-ci. On nomme donc la succession
                             ici meme — c'est la question reelle : « qui reprend
                             la Boucle ? », pas « comment contourner la
                             garde ». --}}
                        <form method="POST" action="{{ $transferRoute($member) }}"
                              class="flex shrink-0 items-center gap-1"
                              data-governance-transfer="{{ $member->user_id }}"
                              onsubmit="return confirm('{{ __('loops.governance_transfer_confirm') }}')">
                            @csrf @method('PUT')
                            <label class="sr-only" for="successeur-{{ $member->id }}">{{ __('loops.governance_transfer_label') }}</label>
                            <select name="successor_id" id="successeur-{{ $member->id }}" required
                                    class="max-w-[10rem] rounded-md border border-gray-300 px-1.5 py-1 text-[11px] text-gray-700 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200">
                                <option value="">{{ __('loops.governance_transfer_placeholder') }}</option>
                                @foreach($transferables as $candidat)
                                <option value="{{ $candidat->id }}">{{ $candidat->user?->publicDisplayName() ?? '—' }}</option>
                                @endforeach
                            </select>
                            <button type="submit"
                                    class="rounded-md border border-indigo-300 px-2 py-1 text-[11px] font-medium text-indigo-700 transition hover:bg-indigo-50 dark:border-indigo-800 dark:text-indigo-300 dark:hover:bg-indigo-900/25">
                                {{ __('loops.governance_transfer_submit') }}
                            </button>
                        </form>
                    @elseif($hasActions && $inlineActions)
                        <span class="flex shrink-0 flex-wrap items-center justify-end gap-1"
                              data-governance-inline-actions="{{ $member->user_id }}">
                            @if($canPromoteOwner)
                                <x-loops.governance-action :action="$roleRoute($member)" role="owner"
                                    :label="__('loops.governance_promote_owner')" variant="inline" />
                            @endif
                            @if($canPromoteFacilitator)
                                <x-loops.governance-action :action="$roleRoute($member)" role="facilitator"
                                    :label="__('loops.governance_promote_facilitator')" variant="inline" />
                            @endif
                            @if($canDemoteOwner)
                                <x-loops.governance-action :action="$roleRoute($member)" role="facilitator"
                                    :label="__('loops.governance_demote_facilitator')" variant="inline"
                                    :confirm="$isSelf ? __('loops.governance_self_demote_confirm') : null" />
                                <x-loops.governance-action :action="$roleRoute($member)" role="member"
                                    :label="__('loops.governance_demote_member')" variant="inline"
                                    :confirm="$isSelf ? __('loops.governance_self_demote_confirm') : null" />
                            @endif
                            @if($canDemoteFacilitator)
                                <x-loops.governance-action :action="$roleRoute($member)" role="member"
                                    :label="__('loops.governance_demote_member')" variant="inline" />
                            @endif
                            @if($canDrop)
                                <form method="POST" action="{{ $removeRoute($member) }}"
                                      onsubmit="return confirm('{{ __('loops.governance_remove') }} ?')" class="inline">
                                    @csrf @method('DELETE')
                                    <button type="submit" data-governance-inline="remove"
                                            class="rounded-md border border-red-200 px-2 py-1 text-[11px] font-medium text-red-600 transition hover:bg-red-50 dark:border-red-900/60 dark:text-red-400 dark:hover:bg-red-900/20">
                                        {{ __('loops.governance_remove') }}
                                    </button>
                                </form>
                            @endif
                        </span>
                    @elseif($hasActions)
                        {{-- Les actions de gouvernance se prennent rarement et
                             jamais dans l'urgence : elles n'ont pas a occuper
                             une ligne en face de chaque nom. --}}
                        <div x-data="{ open: false }" class="relative shrink-0"
                             x-on:keydown.escape.window="open = false">
                            <button type="button"
                                    x-on:click="open = ! open"
                                    x-bind:aria-expanded="open ? 'true' : 'false'"
                                    class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 focus-visible:ring-2 focus-visible:ring-indigo-500 dark:hover:bg-gray-800 dark:hover:text-gray-200"
                                    aria-label="{{ __('loops.governance_actions_for', ['name' => $member->user?->publicDisplayName() ?? '—']) }}">
                                <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                                    <path d="M10 6a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm0 5.5a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm0 5.5a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Z"/>
                                </svg>
                            </button>

                            <div x-show="open" x-cloak
                                 x-on:click.outside="open = false"
                                 x-transition.opacity.duration.120ms
                                 class="absolute right-0 top-9 z-30 w-56 overflow-hidden rounded-xl border border-gray-200 bg-white p-1 shadow-lg dark:border-gray-700 dark:bg-gray-900">

                                @if($canPromoteOwner)
                                    <x-loops.governance-action :action="$roleRoute($member)" role="owner"
                                        :label="__('loops.governance_promote_owner')" />
                                @endif

                                @if($canPromoteFacilitator)
                                    <x-loops.governance-action :action="$roleRoute($member)" role="facilitator"
                                        :label="__('loops.governance_promote_facilitator')" />
                                @endif

                                @if($canDemoteOwner)
                                    <x-loops.governance-action :action="$roleRoute($member)" role="facilitator"
                                        :label="__('loops.governance_demote_facilitator')"
                                        :confirm="$isSelf ? __('loops.governance_self_demote_confirm') : null" />
                                    <x-loops.governance-action :action="$roleRoute($member)" role="member"
                                        :label="__('loops.governance_demote_member')"
                                        :confirm="$isSelf ? __('loops.governance_self_demote_confirm') : null" />
                                @endif

                                @if($canDemoteFacilitator)
                                    <x-loops.governance-action :action="$roleRoute($member)" role="member"
                                        :label="__('loops.governance_demote_member')" />
                                @endif

                                @if($canDrop)
                                    <form method="POST" action="{{ $removeRoute($member) }}"
                                          onsubmit="return confirm('{{ __('loops.governance_remove') }} ?')"
                                          class="mt-1 border-t border-gray-100 pt-1 dark:border-gray-800">
                                        @csrf @method('DELETE')
                                        <button type="submit"
                                                class="w-full rounded-lg px-2.5 py-2 text-left text-xs font-medium text-red-600 transition hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20">
                                            {{ __('loops.governance_remove') }}
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
        @endif
        @endforeach
    @endif

    {{-- Said once for the whole roster, not repeated on every row. --}}
    @if($ownerCount === 1)
        <p class="rounded-xl bg-amber-50 px-3 py-2 text-[11px] leading-snug text-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
            {{ __('loops.governance_last_owner') }}
        </p>
    @endif
</div>
