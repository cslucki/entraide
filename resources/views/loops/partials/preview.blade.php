{{--
    TASK-1865 — APERCU d'une Boucle : fragment HTML SANS layout, servi par
    `LoopController::preview()` (`loops.preview` / `organization.loops.preview`).
    Un seul gabarit pour ses deux futurs consommateurs : le tiroir de la grille
    (TASK D) et les volets de la liste (TASK C).

    Recoit : $item (Loop annotee par VisibleLoops), $canRead (viewWorkspace),
    $messages et $members (VIDES sans $canRead — jamais charges), $memberCount
    (perimetre des membres a montrer, le meme dans les deux etats).

    Deux etats :
    - lecteur (`$canRead`) : description, 5 derniers messages humains, 12 membres
      au plus, « Ouvrir la Boucle » ;
    - decouverte : ce que la carte du catalogue montre deja (accroche,
      description, proprietaires, nombre de membres, badge d'acces) + l'action
      d'adhesion de `catalog-cta`. Aucun mot de la conversation.

    L'espace d'une Boucle n'a pas d'ancre de section membres (les Cards s'y
    ouvrent par etat Alpine) : le lien « voir les autres » mene a la Boucle,
    sans promettre d'ouvrir la liste.

    TASK-1867 — quatre attributs STRUCTURELS, sans effet visuel ici :
    `data-loop-preview-cover`, `-body`, `-messages`, `-members`. Ils servent
    UNIQUEMENT au placement CSS du volet de la liste G (`list-pane`, regle
    `@container`) : le contenu reste celui de ce seul gabarit.
--}}
@php
    $organizationRouteParam = request()->route('organization');
    $isOrgScoped = $organizationRouteParam && request()->routeIs('organization.*');

    // Memes trois liens que `loops/index.blade.php`, exiges par `catalog-cta`.
    $loopShowHref = function ($loop, array $query = []) use ($organizationRouteParam, $isOrgScoped) {
        if ($isOrgScoped) {
            return route('organization.loops.show', ['organization' => $organizationRouteParam, 'loop' => $loop->slug ?: $loop->getKey()] + $query);
        }

        return route('loops.show', ['loop' => $loop] + $query);
    };
    $loopJoinAction = function ($loop) use ($organizationRouteParam, $isOrgScoped) {
        if ($isOrgScoped) {
            return route('organization.loops.join', ['organization' => $organizationRouteParam, 'loop' => $loop]);
        }

        return route('loops.join', $loop);
    };
    $loopEditHref = function ($loop) use ($organizationRouteParam, $isOrgScoped) {
        if ($isOrgScoped) {
            return route('organization.loops.edit', ['organization' => $organizationRouteParam, 'loop' => $loop]);
        }

        return route('loops.edit', $loop);
    };

    $tagline = filled($item->tagline) ? $item->tagline : null;
    $description = filled($item->description) ? $item->description : null;
    if ($tagline && $description && mb_strtolower(trim($tagline)) === mb_strtolower(trim($description))) {
        $description = null;
    }

    $remainingMembers = max(0, $memberCount - $members->count());
@endphp
<section data-loop-preview="{{ $item->id }}"
         data-loop-preview-state="{{ $canRead ? 'reader' : 'discover' }}"
         class="flex flex-col text-gray-900 dark:text-gray-100">
    <div class="relative" data-loop-preview-cover>
        <x-loops.cover :loop="$item" ratio="16 / 7" rounded="rounded-none" />
        <div class="absolute inset-0 flex items-end bg-gradient-to-t from-black/75 via-black/10 to-transparent p-5">
            <h2 class="text-xl font-bold leading-tight text-white drop-shadow-sm">{{ $item->name }}</h2>
        </div>
    </div>

    <div class="flex flex-col gap-5 p-5" data-loop-preview-body>
        <div class="flex flex-col gap-2">
            @if($tagline)
                <p class="font-medium text-gray-800 dark:text-gray-200">{{ $tagline }}</p>
            @endif
            @if($description)
                <p class="whitespace-pre-line text-sm leading-relaxed text-gray-600 dark:text-gray-300">{{ $description }}</p>
            @endif
            @if(! $tagline && ! $description)
                <p class="text-sm italic text-gray-400 dark:text-gray-500">{{ __('loops.card_no_pitch') }}</p>
            @endif

            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                @if($item->owners->isNotEmpty())
                    <span class="min-w-0"><x-loops.owner-names :owners="$item->owners" /></span>
                    <span aria-hidden="true">·</span>
                @endif
                <span data-loop-preview-member-count>{{ trans_choice('loops.presentation_members_count', $memberCount, ['count' => $memberCount]) }}</span>
                @unless($item->is_member)
                    <x-loops.access-badge :loop="$item" />
                @endunless
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @include('loops.partials.catalog-cta', ['item' => $item, 'ctaWidth' => ''])
            @can('update', $item)
                <a href="{{ $loopEditHref($item) }}"
                   class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 transition hover:border-indigo-400 hover:text-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-gray-600 dark:text-gray-200 dark:hover:text-indigo-300">
                    {{ __('loops.edit') }}
                </a>
            @endcan
        </div>

        @if($canRead)
            <div data-loop-preview-messages>
                <h3 class="mb-2 text-sm font-semibold text-gray-500 dark:text-gray-400">{{ __('loops.latest_messages_title') }}</h3>
                @if($messages->isEmpty())
                    <p class="text-sm italic text-gray-400 dark:text-gray-500">{{ __('loops.catalog_row_no_message') }}</p>
                @else
                    <ul class="flex flex-col gap-1">
                        @foreach($messages as $message)
                            @php
                                $body = trim(preg_replace('/\s+/u', ' ', (string) $message->body));
                                $imageUrl = $message->imageUrl();
                            @endphp
                            <li>
                                <a href="{{ $loopShowHref($item, ['message' => $message->id]) }}"
                                   data-loop-preview-message="{{ $message->id }}"
                                   class="flex gap-3 rounded-lg p-2 transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:hover:bg-gray-700/40">
                                    @if($message->sender)
                                        <img src="{{ $message->sender->avatar_url }}" alt=""
                                             class="h-8 w-8 shrink-0 rounded-full object-cover ring-1 ring-gray-200 dark:ring-gray-600">
                                    @endif
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-baseline gap-x-2">
                                            @if($message->sender)
                                                <span class="text-sm font-semibold">{{ $message->sender->publicDisplayName() }}</span>
                                            @endif
                                            <time datetime="{{ $message->created_at->toIso8601String() }}" class="text-xs text-gray-400 dark:text-gray-500">{{ $message->created_at->diffForHumans() }}</time>
                                        </div>
                                        @if($body !== '')
                                            <p class="mt-0.5 line-clamp-3 break-words text-sm text-gray-600 dark:text-gray-300">{{ \Illuminate\Support\Str::limit($body, 280) }}</p>
                                        @elseif($imageUrl)
                                            <p class="mt-0.5 text-sm italic text-gray-400 dark:text-gray-500">{{ __('loops.latest_messages_image_only') }}</p>
                                        @endif
                                    </div>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div data-loop-preview-members>
                <h3 class="mb-2 text-sm font-semibold text-gray-500 dark:text-gray-400">{{ __('loops.preview_members_title') }}</h3>
                <ul class="flex flex-col gap-2">
                    @foreach($members as $member)
                        @continue(! $member->user)
                        <li class="flex items-center gap-3 text-sm" data-loop-preview-member="{{ $member->user_id }}">
                            <img src="{{ $member->user->avatar_url }}" alt=""
                                 class="h-7 w-7 shrink-0 rounded-full object-cover ring-1 ring-gray-200 dark:ring-gray-600">
                            <span class="min-w-0 flex-1 truncate">{{ $member->user->publicDisplayName() }}</span>
                            @if($member->role === 'owner')
                                <span class="shrink-0 text-xs text-gray-400 dark:text-gray-500">{{ __('loops.role_owner') }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if($remainingMembers > 0)
                    <p class="mt-3 text-sm text-gray-500 dark:text-gray-400" data-loop-preview-members-more>
                        {{ trans_choice('loops.preview_members_more', $remainingMembers, ['count' => $remainingMembers]) }}
                        <a href="{{ $loopShowHref($item) }}" class="font-semibold text-indigo-600 hover:underline dark:text-indigo-400">{{ __('loops.preview_members_more_link') }}</a>
                    </p>
                @endif
            </div>
        @else
            <p class="rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:bg-gray-900/40 dark:text-gray-300" data-loop-preview-locked>
                {{ __('loops.preview_locked') }}
            </p>
        @endif
    </div>
</section>
