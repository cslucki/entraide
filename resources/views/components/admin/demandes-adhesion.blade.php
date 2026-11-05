@props(['demandes', 'organizationId' => null, 'titre' => null])

{{--
    TASK-1761 points 2 et 3 — la carte « Demandes à rejoindre une Boucle ».

    ## Un composant, parce qu'elle vit à DEUX endroits

    Cyril l'a demandé explicitement : la même carte sur `/admin/dashboard` et
    sur `/admin/loops`. Écrite deux fois, elle divergerait — on corrigerait
    celle qu'on voit.

    ## Elle n'invente AUCUNE chorégraphie

    Les deux boutons pointent sur `admin.loops.join-requests.accept|reject`,
    les routes qui servent déjà la fiche d'une Boucle. Elles appellent
    `LoopService::acceptJoinRequest()`, donc la personne acceptée reçoit
    exactement ce qu'elle recevrait si la décision venait de la Boucle :
    notification, email, et le reste.

    **C'était le danger principal de cette TASK** : construire un second chemin
    d'acceptation qui finirait par diverger du premier, en silence.

    ## Pourquoi `back()` et non un lien de retour

    Le contrôleur redirige désormais vers `back()`. Depuis la fiche d'une
    Boucle, c'est la fiche — comportement inchangé. Depuis le tableau de bord,
    c'est le tableau de bord : le SuperAdmin ne quitte pas sa page pour traiter
    une demande, ce qui est tout l'objet du point 2.
--}}
<div {{ $attributes->merge(['class' => 'bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5']) }}
     data-marqueur="demandes-adhesion">
    <div class="flex items-center justify-between gap-3 mb-4">
        <h2 class="text-sm font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">
            {{ $titre ?? __('admin.join_requests_card_title') }}
        </h2>
        @if($demandes->isNotEmpty())
            <span class="inline-flex items-center justify-center min-w-[1.5rem] px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 text-xs font-bold dark:bg-amber-900/40 dark:text-amber-200"
                  data-marqueur="demandes-adhesion-compteur">
                {{ $demandes->count() }}
            </span>
        @endif
    </div>

    @if($demandes->isEmpty())
        <p class="text-sm text-gray-400 dark:text-gray-500" data-marqueur="demandes-adhesion-vide">
            {{ __('admin.join_requests_card_empty') }}
        </p>
    @else
        <ul class="divide-y divide-gray-100 dark:divide-gray-700">
            @foreach($demandes as $demande)
                <li class="py-3 flex flex-wrap items-start justify-between gap-3"
                    data-marqueur="demande-adhesion" data-demande="{{ $demande->id }}">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100">
                            {{ $demande->user?->publicDisplayName() ?? __('profile.deactivated_user') }}
                        </p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ __('admin.join_requests_card_wants_to_join') }}
                            <a href="{{ route('admin.loops.edit', $demande->loop) }}"
                               class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ $demande->loop?->name }}</a>
                            {{-- L'Organization n'est montrée que sur la vue GLOBALE : sous une
                                 page déjà filtrée, elle serait la même partout et ferait du bruit. --}}
                            @unless($organizationId)
                                · <span class="text-gray-400">{{ $demande->loop?->organization?->name }}</span>
                            @endunless
                            · {{ $demande->created_at?->diffForHumans() }}
                        </p>
                        @if(filled($demande->message))
                            <p class="mt-1 text-xs italic text-gray-500 dark:text-gray-400">« {{ Str::limit($demande->message, 120) }} »</p>
                        @endif
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <form method="POST" action="{{ route('admin.loops.join-requests.accept', [$demande->loop, $demande]) }}">
                            @csrf
                            <button type="submit" data-marqueur="demande-accepter"
                                    class="rounded-lg bg-green-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-green-700">
                                {{ __('admin.join_requests_card_accept') }}
                            </button>
                        </form>
                        <form method="POST" action="{{ route('admin.loops.join-requests.reject', [$demande->loop, $demande]) }}"
                              onsubmit="return confirm('{{ __('admin.join_requests_card_reject_confirm') }}')">
                            @csrf
                            <button type="submit" data-marqueur="demande-refuser"
                                    class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 transition hover:bg-gray-100 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">
                                {{ __('admin.join_requests_card_reject') }}
                            </button>
                        </form>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
