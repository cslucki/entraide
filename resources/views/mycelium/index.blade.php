{{--
    TASK-1349 — le hub public de la gouvernance IA.

    UN SEUL ECRAN. L'arbre n'est pas une table des matieres qui renvoie
    ailleurs : c'est un SELECTEUR. Cliquer un noeud remplace le document
    affiche dans le panneau — sans changer de page, et sans faire defiler.

    Tous les documents sont rendus par le serveur ; Alpine ne fait que
    basculer lequel est visible. Sans JavaScript, la racine reste affichee
    (les panneaux d'organisation portent `x-cloak`), donc la page ne perd
    jamais son contenu principal. Pas de `x-transition` : il gele quand
    l'onglet est en arriere-plan (precedent TASK-1244).

    Les degrades sont poses en `style` inline : une valeur arbitraire Tailwind
    absente du build casserait la page en silence, un style inline jamais.

    Ce n'est PAS l'Arbre des connaissances : aucune competence, aucune
    personne, aucun appariement. Uniquement la gouvernance.
--}}
<x-app-layout :title="__('mycelium.title')">
    {{-- Le fond « rings » de la page /about : c'est deja le langage visuel des
         pages publiques du produit, et le reutiliser evite d'en inventer un
         second. Pose en CSS reel plutot qu'en valeur arbitraire Tailwind — une
         classe absente du build casserait en silence. --}}
    @push('head')
        <style>
            .mycelium-rings{position:fixed;inset:0;z-index:0;pointer-events:none;
                background-image:url('{{ asset('img/boucle-rings.svg') }}');
                background-size:cover;background-position:center;background-repeat:no-repeat;
                opacity:.62}
            /* En sombre, le degrade pastel ecraserait le texte : il devient une
               presence, pas un fond. */
            .dark .mycelium-rings{opacity:.14}
            .mycelium-contenu{position:relative;z-index:1}
        </style>
    @endpush

    <div class="mycelium-rings" aria-hidden="true" data-mycelium-rings></div>

    <x-page-container>
        <div class="mycelium-contenu mx-auto max-w-3xl pb-12 pt-16 sm:pb-16 sm:pt-24"
             x-data="{ noeud: 'root' }"
             data-mycelium-hub>

            {{-- Le titre du document existe toujours, meme quand la carte qui
                 le porte visuellement est repliee : sans lui, la page perdrait
                 son `h1` des qu'une organisation est selectionnee. --}}
            <h1 class="sr-only">{{ __('mycelium.title') }}</h1>

            {{-- L'ARBRE — un selecteur, pas une navigation. Il ouvre la page :
                 ce qu'est le Mycelium s'explique plus bas, une fois qu'on l'a
                 vu. --}}
            <section aria-label="{{ __('mycelium.tree_label') }}" data-mycelium-tree>
                <div class="relative flex justify-center">
                    <div class="pointer-events-none absolute inset-x-0 -top-14 h-40"
                         style="background: radial-gradient(closest-side, color-mix(in srgb, var(--bp-primary) 20%, transparent), transparent 78%)"
                         aria-hidden="true"></div>

                    <button type="button"
                            @click="noeud = 'root'"
                            :aria-pressed="noeud === 'root'"
                            class="relative inline-flex items-center gap-2.5 rounded-full px-5 py-2.5 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2"
                            :class="noeud === 'root' ? 'text-white' : 'text-[var(--bp-text)]'"
                            :style="noeud === 'root'
                                ? 'background: var(--bp-primary); box-shadow: 0 10px 30px -12px color-mix(in srgb, var(--bp-primary) 70%, transparent)'
                                : 'background: var(--bp-panel); border: 1px solid var(--bp-border)'"
                            style="background: var(--bp-primary); color: #fff; box-shadow: 0 10px 30px -12px color-mix(in srgb, var(--bp-primary) 70%, transparent)"
                            data-mycelium-node="root">
                        <span class="h-1.5 w-1.5 rounded-full bg-current opacity-70" aria-hidden="true"></span>
                        {{ __('mycelium.tree_root') }}
                    </button>
                </div>

                @if($organizations->isNotEmpty())
                    {{-- LE RESEAU HYPHAL.

                         Une tige par organisation, et des filaments plus fins
                         qui s'en detachent — la signature visuelle d'un
                         mycelium, obtenue en geometrie pure : aucun JS, aucune
                         librairie de graphe, aucun calcul au chargement.

                         La geometrie derive du `slug` (`crc32`) : deux visites
                         de la meme page dessinent EXACTEMENT le meme reseau.
                         Un trace aleatoire a chaque requete serait joli une
                         fois et desorientant la seconde.

                         `userSpaceOnUse` est obligatoire : avec une seule
                         organisation la tige est verticale, sa bounding box a
                         une largeur NULLE, et un degrade en objectBoundingBox
                         y devient degenere — le trace disparait sans erreur. --}}
                    @php
                        $noeuds = $organizations->take(6);
                        $total = max($noeuds->count(), 1);
                        $hauteur = 96;
                        $traces = [];

                        foreach ($noeuds as $i => $node) {
                            $x = $total === 1 ? 360.0 : 110 + ($i * (500 / max($total - 1, 1)));
                            $graine = crc32((string) $node['slug']);

                            $traces[] = [
                                'd' => sprintf('M360 0 C360 %.1f, %.1f %.1f, %.1f %d',
                                    $hauteur * 0.45, $x, $hauteur * 0.35, $x, $hauteur),
                                'w' => 1.4,
                                'o' => 1.0,
                            ];

                            // Deux hyphes par tige : elles naissent dessus,
                            // s'en ecartent, et ne menent nulle part. C'est
                            // exactement ce que fait un mycelium.
                            for ($h = 0; $h < 2; $h++) {
                                $t = 0.38 + 0.20 * $h;
                                $sens = (($graine >> $h) & 1) ? 1 : -1;
                                $ampleur = 16 + ((($graine >> (2 + $h * 3)) & 7) * 5);

                                $bx = 360 + ($x - 360) * $t;
                                $by = $hauteur * $t;
                                $ex = $bx + $sens * $ampleur;
                                $ey = $by + $hauteur * (0.30 + 0.08 * $h);

                                $traces[] = [
                                    'd' => sprintf('M%.1f %.1f C%.1f %.1f, %.1f %.1f, %.1f %.1f',
                                        $bx, $by,
                                        $bx + $sens * $ampleur * 0.35, $by + 7,
                                        $ex, $ey - 11,
                                        $ex, $ey),
                                    'w' => 0.7,
                                    'o' => 0.5,
                                ];
                            }
                        }
                    @endphp
                    <div class="hidden sm:block" aria-hidden="true">
                        <svg viewBox="0 0 720 {{ $hauteur }}" class="mx-auto w-full max-w-2xl" role="presentation">
                            <defs>
                                <linearGradient id="mycelium-hyphe" gradientUnits="userSpaceOnUse" x1="0" y1="0" x2="0" y2="{{ $hauteur }}">
                                    <stop offset="0%" stop-color="var(--bp-primary)" stop-opacity="0.55"/>
                                    <stop offset="100%" stop-color="var(--bp-primary)" stop-opacity="0.10"/>
                                </linearGradient>
                            </defs>
                            @foreach($traces as $trace)
                                <path d="{{ $trace['d'] }}" fill="none"
                                      stroke="url(#mycelium-hyphe)"
                                      stroke-width="{{ $trace['w'] }}"
                                      stroke-opacity="{{ $trace['o'] }}"
                                      stroke-linecap="round"/>
                            @endforeach
                        </svg>
                    </div>

                    <ul class="mt-5 flex flex-wrap items-center justify-center gap-2.5 sm:mt-0" data-mycelium-nodes>
                        @foreach($organizations as $node)
                            <li>
                                <button type="button"
                                        @click="noeud = '{{ $node['slug'] }}'"
                                        :aria-pressed="noeud === '{{ $node['slug'] }}'"
                                        class="inline-flex items-center gap-2 rounded-full border px-4 py-2 text-sm font-medium shadow-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--bp-primary)]"
                                        :class="noeud === '{{ $node['slug'] }}'
                                            ? 'border-[var(--bp-primary)] text-[var(--bp-text)]'
                                            : 'border-[var(--bp-border)] text-[var(--bp-text)] hover:border-[var(--bp-primary)]/50'"
                                        style="background: var(--bp-panel)"
                                        data-mycelium-node="organization" data-mycelium-node-slug="{{ $node['slug'] }}">
                                    <span class="h-1.5 w-1.5 rounded-full" style="background: var(--bp-primary); opacity: .55" aria-hidden="true"></span>
                                    {{ $node['name'] }}
                                    <span class="text-xs font-normal text-[var(--bp-muted)]">v{{ $node['version'] }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- LE PANNEAU — un seul, et il change de contenu --}}
            <section class="relative mt-8 overflow-hidden rounded-3xl border border-[var(--bp-border)] bg-[var(--bp-panel)] shadow-sm"
                     aria-live="polite"
                     data-mycelium-panel>
                <div class="h-1 w-full"
                     style="background: linear-gradient(90deg, var(--bp-primary), color-mix(in srgb, var(--bp-primary) 25%, transparent))"
                     aria-hidden="true"></div>

                {{-- La racine --}}
                <div x-show="noeud === 'root'" id="mycelium-racine" class="p-7 sm:p-9" data-mycelium-root>
                    <h2 class="border-b border-[var(--bp-border)] pb-5 text-lg font-semibold tracking-tight text-[var(--bp-text)]">{{ __('mycelium.title') }}</h2>

                    <div class="mt-6" data-mycelium-root-text>
                        @include('mycelium.partials.document', ['text' => $platformText])
                    </div>

                    {{-- La version en bas : c'est une mention legale, pas le
                         sujet. Le texte passe d'abord. --}}
                    <p class="mt-6 border-t border-[var(--bp-border)] pt-4 text-xs text-[var(--bp-muted)]" data-mycelium-root-version>
                        @if($platformVersion)
                            {{ __('mycelium.root_version', ['version' => $platformVersion]) }}
                            @if($platformActivatedAt)
                                · {{ __('mycelium.root_activated_at', ['date' => $platformActivatedAt->isoFormat('LL')]) }}
                            @endif
                        @else
                            {{ __('mycelium.root_version_seed') }}
                        @endif
                    </p>
                </div>

                {{-- Chaque organisation publique. `x-cloak` : sans JavaScript
                     elles restent fermees et la racine seule s'affiche. --}}
                @foreach($organizations as $node)
                    <div x-show="noeud === '{{ $node['slug'] }}'" x-cloak
                         class="p-7 sm:p-9"
                         data-mycelium-organization="{{ $node['slug'] }}">
                        {{-- Le nom, et tout de suite ce qu'on peut en faire :
                             aller chez elle, ou lire sa Constitution en propre.
                             Les deux destinations sont toujours proposees —
                             l'accueil gere lui-meme son acces, public ou
                             connexion requise, comme partout ailleurs. --}}
                        <div class="flex flex-wrap items-center justify-between gap-x-5 gap-y-2 border-b border-[var(--bp-border)] pb-5">
                            <h2 class="text-lg font-semibold tracking-tight text-[var(--bp-text)]">{{ $node['name'] }}</h2>

                            <div class="flex flex-wrap items-center gap-x-5 gap-y-2">
                                <a href="{{ route('organization.home', ['organization' => $node['slug']]) }}"
                                   class="inline-flex items-center gap-1 text-xs font-semibold hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--bp-primary)]"
                                   style="color: var(--bp-primary)"
                                   data-mycelium-organization-site="{{ $node['slug'] }}">
                                    {{ __('mycelium.org_visit_site') }}
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/>
                                    </svg>
                                </a>

                                <a href="{{ route('organization.constitution', ['organization' => $node['slug']]) }}"
                                   class="inline-flex items-center gap-1 text-xs font-semibold hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--bp-primary)]"
                                   style="color: var(--bp-primary)"
                                   data-mycelium-organization-link="{{ $node['slug'] }}">
                                    {{ __('mycelium.org_open_page') }}
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                                    </svg>
                                </a>
                            </div>
                        </div>

                        <div class="mt-6" data-mycelium-organization-body="{{ $node['slug'] }}">
                            @include('mycelium.partials.document', ['text' => $node['body']])
                        </div>

                        {{-- Heritage et version en bas : deux mentions, pas le
                             sujet. Le texte de l'organisation passe d'abord. --}}
                        <div class="mt-6 flex flex-wrap items-baseline justify-between gap-x-5 gap-y-1 border-t border-[var(--bp-border)] pt-4 text-xs text-[var(--bp-muted)]">
                            <p>{{ __('mycelium.org_inherits') }}</p>
                            <p>
                                {{ __('mycelium.org_version', ['version' => $node['version']]) }}
                                @if($node['activated_at'])
                                    · {{ __('mycelium.root_activated_at', ['date' => $node['activated_at']->isoFormat('LL')]) }}
                                @endif
                            </p>
                        </div>
                    </div>
                @endforeach
            </section>

            {{-- CE QU'EST LE MYCELIUM. Sous l'arbre, et non au-dessus : on
                 montre d'abord, on explique ensuite. Rattachee a la RACINE,
                 comme la carte d'heritage qui la suit. --}}
            <section x-show="noeud === 'root'"
                     class="mt-6 rounded-2xl border border-[var(--bp-border)] bg-[var(--bp-panel)] p-6 sm:p-7"
                     data-mycelium-about>
                <p class="text-[11px] font-semibold uppercase tracking-[0.25em] text-[var(--bp-primary)]">{{ __('mycelium.subtitle') }}</p>
                <h2 class="mt-2 text-2xl font-semibold leading-tight tracking-tight text-[var(--bp-text)]">{{ __('mycelium.title') }}</h2>
                <p class="mt-3 max-w-2xl text-[15px] leading-7 text-[var(--bp-muted)]">{{ __('mycelium.intro') }}</p>
            </section>

            {{-- Rattachee a la RACINE : quand une organisation est selectionnee,
                 son propre panneau porte deja sa ligne d'heritage, et repeter
                 l'explication generale n'apprend plus rien. Pas de `x-cloak` :
                 sans JavaScript la carte reste visible sous la racine. --}}
            <section x-show="noeud === 'root'"
                     class="mt-6 rounded-2xl p-5 sm:p-6"
                     style="background: color-mix(in srgb, var(--bp-primary) 6%, var(--bp-surface)); border: 1px solid color-mix(in srgb, var(--bp-primary) 16%, transparent)"
                     data-mycelium-inheritance>
                <div class="flex gap-3.5">
                    <span class="mt-0.5 flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg"
                          style="background: color-mix(in srgb, var(--bp-primary) 14%, transparent)" aria-hidden="true">
                        <svg class="h-4 w-4" style="color: var(--bp-primary)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 100 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.769-.283 1.093m0-2.186l9.566-5.314m-9.566 7.5l9.566 5.314m0 0a2.25 2.25 0 103.935 2.186 2.25 2.25 0 00-3.935-2.186zm0-12.814a2.25 2.25 0 103.933-2.185 2.25 2.25 0 00-3.933 2.185z"/>
                        </svg>
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-sm font-semibold text-[var(--bp-text)]">{{ __('mycelium.inheritance_title') }}</h2>
                        <p class="mt-1.5 text-sm leading-6 text-[var(--bp-muted)]">{{ __('mycelium.inheritance_body') }}</p>

                        <p class="mt-3 text-xs text-[var(--bp-muted)]" data-mycelium-organizations>
                            @if($organizations->isEmpty())
                                <span data-mycelium-organizations-empty>{{ __('mycelium.organizations_empty') }}</span>
                            @else
                                {{ __('mycelium.organizations_note') }}
                            @endif
                        </p>
                    </div>
                </div>
            </section>

        </div>
    </x-page-container>
</x-app-layout>
