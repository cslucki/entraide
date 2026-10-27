{{-- TASK-1683 BUG-06 — arrive filtre sur une Organization (lien « Membres »
     de /admin/organizations), l'ecran doit DIRE laquelle : « Utilisateurs »
     seul laissait croire a la population entiere, alors que la liste ET les
     compteurs sont bornes a ce tenant. --}}
<x-admin-layout :title="$scopedOrganization ? __('admin.users_title_scoped', ['organization' => $scopedOrganization->name]) : 'Utilisateurs'">

{{-- TASK-1640 — racine Alpine de la page.
     Une directive Alpine sans racine `x-data` sur un ancetre est inerte ET
     silencieuse : rien ne casse, le clic ne fait simplement rien. La racine est
     donc portee ici, autour de la table ET de la modal, et pas sur la modal
     seule — sinon une directive Alpine appelee depuis une ligne ne trouverait pas
     l'etat. --}}
{{-- TASK-1668 — plus de composant Alpine de suppression ici : la page
     `admin/users/delete.blade.php` a pris le relais. Cette racine reste
     parce que des directives Alpine subsistent plus bas (menus replies)
     et qu'une directive sans racine est inerte ET silencieuse. --}}
<div x-data="{}">
    {{-- TASK-1640 — bandeau de compteurs.
         Ils portent sur la POPULATION ENTIERE, pas sur le filtre courant : un
         compteur qui bougerait avec les filtres repondrait « combien en vois-je »
         au lieu de « combien y en a-t-il ». Le total filtre est affiche a cote du
         tableau, la ou il a du sens. Une SEULE requete agregee les produit tous. --}}
    {{-- TASK-1693 bug C — le compteur « Non vérifiés » est CLIQUABLE et mène au
         filtre correspondant.

         Il préserve `organization_id` : arrivé depuis « Membres » d'une
         Organization, le compteur est borné à ce tenant (exception TASK-1683
         BUG-06), et un lien qui laisserait tomber la portée montrerait d'autres
         tenants que ceux qu'il vient de compter — un chiffre et une liste qui
         se contrediraient.

         Il est le SEUL cliquable, et ce n'est pas une incohérence : « Nouveaux
         ce mois » n'a aucun filtre correspondant, et en inventer un pour
         uniformiser l'apparence dépasserait la demande. --}}
    @php
        $unverifiedUrl = route('admin.users', array_filter([
            'status' => 'unverified',
            'organization_id' => request('organization_id'),
        ]));
    @endphp
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-5">
        @foreach([
            ['label' => __('admin.users_stat_total'), 'value' => $stats['total'], 'accent' => 'text-gray-900 dark:text-gray-100', 'dot' => 'bg-gray-300 dark:bg-gray-600', 'url' => null],
            ['label' => __('admin.users_stat_available'), 'value' => $stats['disponibles'], 'accent' => 'text-emerald-600 dark:text-emerald-400', 'dot' => 'bg-emerald-500', 'url' => null],
            ['label' => __('admin.users_stat_admins'), 'value' => $stats['admins'], 'accent' => 'text-purple-600 dark:text-purple-400', 'dot' => 'bg-purple-500', 'url' => null],
            ['label' => __('admin.users_stat_banned'), 'value' => $stats['bannis'], 'accent' => 'text-red-600 dark:text-red-400', 'dot' => 'bg-red-500', 'url' => null],
            ['label' => __('admin.users_stat_new'), 'value' => $stats['nouveaux'], 'accent' => 'text-indigo-600 dark:text-indigo-400', 'dot' => 'bg-indigo-500', 'url' => null],
            ['label' => __('admin.users_stat_unverified'), 'value' => $stats['non_verifies'], 'accent' => 'text-amber-600 dark:text-amber-400', 'dot' => 'bg-amber-500', 'url' => $unverifiedUrl],
        ] as $stat)
        @if($stat['url'])
        <a href="{{ $stat['url'] }}"
           title="{{ __('admin.users_stat_unverified_hint') }}"
           data-users-stat-unverified
           class="relative overflow-hidden rounded-xl border border-amber-200 dark:border-amber-800/60 bg-white dark:bg-gray-800 px-4 py-3 shadow-sm transition hover:border-amber-400 hover:shadow focus:outline-none focus:ring-2 focus:ring-amber-500">
            <div class="flex items-center gap-2 mb-1">
                <span class="w-1.5 h-1.5 rounded-full {{ $stat['dot'] }}"></span>
                <span class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 truncate">{{ $stat['label'] }}</span>
            </div>
            <p class="text-2xl font-bold tabular-nums {{ $stat['accent'] }}">{{ number_format($stat['value'], 0, ',', ' ') }}</p>
        </a>
        @else
        <div class="relative overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-4 py-3 shadow-sm">
            <div class="flex items-center gap-2 mb-1">
                <span class="w-1.5 h-1.5 rounded-full {{ $stat['dot'] }}"></span>
                <span class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 truncate">{{ $stat['label'] }}</span>
            </div>
            <p class="text-2xl font-bold tabular-nums {{ $stat['accent'] }}">{{ number_format($stat['value'], 0, ',', ' ') }}</p>
        </div>
        @endif
        @endforeach
    </div>

    @if($scopedOrganization)
    {{-- TASK-1683 BUG-07 — la ligne de portee offre les DEUX sorties : elargir
         a toutes les organisations, et revenir a l'Organization d'ou l'on
         vient. Le NOM porte ce retour — une seule poignee, pas un second
         lien qui ferait doublon (lecon BUG-04). Le nom reste interpole par
         Blade, donc echappe : il vient d'une saisie d'administration. --}}
    <p class="mb-4 text-xs text-gray-500 dark:text-gray-400">
        {{ __('admin.users_stats_scoped_prefix') }}
        <a href="{{ route('admin.organizations.edit', $scopedOrganization) }}"
           class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">
            {{ $scopedOrganization->name }}</a>.
        <a href="{{ route('admin.users') }}" class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">
            {{ __('admin.users_stats_scope_all') }}
        </a>
    </p>
    @endif

    <div class="flex justify-end mb-4">
        <a href="{{ route('admin.users.create') }}"
           class="w-full sm:w-auto text-center px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition">
            + Créer un utilisateur
        </a>
    </div>

    <!-- Filters -->
    <form method="GET" class="mb-5 flex flex-col sm:flex-row sm:flex-wrap gap-3">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="Nom ou email..."
            class="w-full sm:flex-1 min-w-48 px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
        <select name="status" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
            <option value="">Tous</option>
            <option value="available" {{ request('status') === 'available' ? 'selected' : '' }}>Disponibles</option>
            <option value="banned" {{ request('status') === 'banned' ? 'selected' : '' }}>Bannis</option>
            <option value="admin" {{ request('status') === 'admin' ? 'selected' : '' }}>Admins</option>
            {{-- TASK-1693 bug B — retrouver les comptes bloques par la garde de
                 verification d'email, qui etaient jusqu'ici introuvables. --}}
            <option value="unverified" {{ request('status') === 'unverified' ? 'selected' : '' }}>Email non vérifié</option>
        </select>
        <select name="organization_id" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
            <option value="">{{ __('admin.users_org_all') }}</option>
            @foreach($organizations as $org)
            <option value="{{ $org->id }}" {{ request('organization_id') == $org->id ? 'selected' : '' }}>
                {{ $org->name }}
            </option>
            @endforeach
        </select>
        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">Filtrer</button>
        @if(request()->hasAny(['search', 'status', 'organization_id', 'sort']))
        <a href="{{ route('admin.users') }}" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700">Effacer</a>
        @endif
    </form>

    @php
        // TASK-1714 (demande Cyril, 27/10) — quatre colonnes passent en vue
        // DETAILLEE : Points, Services, Echanges et Statut. Elles occupaient de
        // la largeur sans servir au geste courant, qui est de retrouver
        // quelqu'un et d'agir sur son compte.
        //
        // Un parametre d'URL, et non un reglage en base ni du JavaScript : la
        // vue se partage par copier-coller, survit a un rechargement, et
        // `array_merge(request()->query(), ...)` la conserve a travers les
        // filtres et les tris deja en place.
        $vueDetaillee = request()->boolean('detail');
        $colonnesVisibles = $vueDetaillee ? 9 : 5;
    @endphp

    <div class="flex flex-wrap items-center justify-between gap-3 mb-2">
        <p class="text-xs text-gray-500 dark:text-gray-400">
            {{ __('admin.users_results_count', ['count' => $users->total()]) }}
        </p>

        {{-- TASK-1714 (demande Cyril, 27/10) — la bascule vers la vue detaillee.

             Un LIEN, pas un bouton a JavaScript : l'etat vit dans l'URL, donc il
             se partage, survit au rechargement et se conserve a travers les
             filtres et les tris grace a `array_merge(request()->query(), ...)`,
             idiome deja utilise par les en-tetes triables de ce tableau. --}}
        <a href="{{ route('admin.users', array_merge(request()->query(), ['detail' => $vueDetaillee ? null : 1])) }}"
           data-marqueur="bascule-colonnes"
           class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border text-xs font-medium transition
                  {{ $vueDetaillee
                     ? 'border-indigo-400 text-indigo-700 bg-indigo-50 dark:bg-indigo-900/20 dark:text-indigo-300 dark:border-indigo-500'
                     : 'border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700' }}">
            {{ $vueDetaillee ? __('admin.users_columns_hide_detail') : __('admin.users_columns_show_detail') }}
        </a>
    </div>

    {{-- TASK-1640 — `overflow-hidden` COUPAIT les colonnes hors cadre : sur
         telephone, les dernieres colonnes (dont Actions) etaient inatteignables,
         pas seulement serrees. Le defilement horizontal est borne a ce conteneur,
         donc le corps de page ne defile jamais lateralement. --}}
    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-x-auto">
        {{-- TASK-1714 volet 3 — la largeur minimale devient `md:`.

             Elle protege la colonne Organisation, qui est elle-meme
             `hidden md:table-cell` : en dessous de `md` elle n'existe pas, et
             la largeur ne protegeait donc plus rien — elle forcait 736 px sur
             un telephone de 390, soit 396 px de debordement pour TROIS
             colonnes visibles. L'intention de TASK-1640 est conservee
             exactement la ou elle s'applique.

             Note d'origine (TASK-1640) : `min-w-[46rem]` n'est pas cosmetique : sans largeur minimale, la colonne
             Actions est ecrasee a ~130 px sur telephone et empile ses dix liens
             verticalement, ce qui porte chaque ligne a ~350 px — une ligne par
             ecran. Mesure a 375 px. Avec ce plancher, la colonne retrouve la place
             de disposer ses actions et les lignes reviennent a une hauteur normale ;
             le defilement horizontal, lui, est deja borne au conteneur. --}}
        <table class="w-full md:min-w-[46rem] text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    {{-- TASK-1714 — plafond sur la colonne Utilisateur (retour Cyril).
                         Sans lui, elle absorbe toute la largeur disponible et la
                         colonne Actions reste etroite : mesure avant, Utilisateur
                         414 px pour Actions 368. --}}
                    <th class="px-4 py-3 md:max-w-[22rem] text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                        <a href="{{ route('admin.users', array_merge(request()->query(), ['sort' => 'name', 'direction' => request('sort') === 'name' && request('direction') === 'asc' ? 'desc' : 'asc'])) }}" class="hover:text-indigo-600 dark:hover:text-indigo-400">
                            Utilisateur
                            @if(request('sort') === 'name') <span>{{ request('direction') === 'asc' ? '↑' : '↓' }}</span>@endif
                        </a>
                    </th>
                    <th class="hidden md:table-cell px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                        <a href="{{ route('admin.users', array_merge(request()->query(), ['sort' => 'organization_id', 'direction' => request('sort') === 'organization_id' && request('direction') === 'asc' ? 'desc' : 'asc'])) }}" class="hover:text-indigo-600 dark:hover:text-indigo-400">
                            Organisation
                            @if(request('sort') === 'organization_id') <span>{{ request('direction') === 'asc' ? '↑' : '↓' }}</span>@endif
                        </a>
                    </th>
                    @if($vueDetaillee)
                    <th class="hidden lg:table-cell px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                        <a href="{{ route('admin.users', array_merge(request()->query(), ['sort' => 'points_balance', 'direction' => request('sort') === 'points_balance' && request('direction') === 'asc' ? 'desc' : 'asc'])) }}" class="hover:text-indigo-600 dark:hover:text-indigo-400">
                            Points
                            @if(request('sort') === 'points_balance') <span>{{ request('direction') === 'asc' ? '↑' : '↓' }}</span>@endif
                        </a>
                    </th>
                    @endif
                    @if($vueDetaillee)
                    <th class="hidden lg:table-cell px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                        <a href="{{ route('admin.users', array_merge(request()->query(), ['sort' => 'services_count', 'direction' => request('sort') === 'services_count' && request('direction') === 'asc' ? 'desc' : 'asc'])) }}" class="hover:text-indigo-600 dark:hover:text-indigo-400">
                            Services
                            @if(request('sort') === 'services_count') <span>{{ request('direction') === 'asc' ? '↑' : '↓' }}</span>@endif
                        </a>
                    </th>
                    @endif
                    @if($vueDetaillee)
                    <th class="hidden xl:table-cell px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                        <a href="{{ route('admin.users', array_merge(request()->query(), ['sort' => 'exchange_count', 'direction' => request('sort') === 'exchange_count' && request('direction') === 'asc' ? 'desc' : 'asc'])) }}" class="hover:text-indigo-600 dark:hover:text-indigo-400">
                            Échanges
                            @if(request('sort') === 'exchange_count') <span>{{ request('direction') === 'asc' ? '↑' : '↓' }}</span>@endif
                        </a>
                    </th>
                    @endif
                    <th class="hidden xl:table-cell px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                        <a href="{{ route('admin.users', array_merge(request()->query(), ['sort' => 'rating', 'direction' => request('sort') === 'rating' && request('direction') === 'asc' ? 'desc' : 'asc'])) }}" class="hover:text-indigo-600 dark:hover:text-indigo-400">
                            Note
                            @if(request('sort') === 'rating') <span>{{ request('direction') === 'asc' ? '↑' : '↓' }}</span>@endif
                        </a>
                    </th>
                    @if($vueDetaillee)
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                        <a href="{{ route('admin.users', array_merge(request()->query(), ['sort' => 'status', 'direction' => request('sort') === 'status' && request('direction') === 'asc' ? 'desc' : 'asc'])) }}" class="hover:text-indigo-600 dark:hover:text-indigo-400">
                            Statut
                            @if(request('sort') === 'status') <span>{{ request('direction') === 'asc' ? '↑' : '↓' }}</span>@endif
                        </a>
                    </th>
                    @endif
                    <th class="hidden sm:table-cell px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                        <a href="{{ route('admin.users', array_merge(request()->query(), ['sort' => 'created_at', 'direction' => request('sort') === 'created_at' && request('direction') === 'asc' ? 'desc' : 'asc'])) }}" class="hover:text-indigo-600 dark:hover:text-indigo-400">
                            Inscrit le
                            @if(request('sort') === 'created_at') <span>{{ request('direction') === 'asc' ? '↑' : '↓' }}</span>@endif
                        </a>
                    </th>
                    {{-- TASK-1714 volet 3 — la colonne Actions est COLLANTE a droite.

                         Priorite MASTER : « colonne Actions toujours accessible ». La
                         largeur du tableau depend de son contenu — nom, adresse,
                         selecteur d'Organization — et aucun reglage de largeur ne la
                         ramenera sous 390 px sans mutiler la colonne Utilisateur. Plutot
                         que de lutter contre l'algorithme de table, on sort la colonne du
                         defilement : `sticky right-0` la garde a l'ecran quelle que soit
                         la position horizontale.

                         Le fond opaque est INDISPENSABLE : sans lui, le contenu des
                         autres colonnes defilerait VISIBLEMENT sous celle-ci. --}}
                    <th class="sticky right-0 z-10 w-[32rem] min-w-[32rem] bg-gray-50 dark:bg-gray-700 border-l border-gray-200 dark:border-gray-600 px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($users as $u)
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700 {{ $u->banned_at ? 'opacity-60' : '' }}">
                    <td class="px-4 py-3 max-w-0 w-full md:max-w-[22rem]">
                        {{-- TASK-1714 volet 3 — `min-w-0` sur le conteneur flex, et
                             `max-w-0 w-full` sur la cellule.

                             Sans eux, un enfant flex refuse de descendre sous la largeur
                             de son contenu : `truncate` etait pose sur le nom et l'email
                             depuis TASK-1640 et ne s'appliquait JAMAIS — la colonne
                             poussait le tableau a 535 px sur un telephone de 390, et la
                             colonne Actions sortait du cadre. Mesure avant : UTILISATEUR
                             a 301 px pour un viewport de 390. --}}
                        <div class="flex items-center gap-3 min-w-0">
                            <img src="{{ $u->avatar_url }}" class="w-8 h-8 rounded-full flex-shrink-0" alt="">
                            <div class="min-w-0">
                                <p class="font-medium text-gray-900 dark:text-gray-100 truncate">
                                    {{ $u->full_name }}
                                    @if($u->is_admin)<span class="ml-1 text-xs text-purple-600 dark:text-purple-400">[admin]</span>@endif
                                    @if($u->banned_at)<span class="ml-1 text-xs text-red-500">[banni]</span>@endif
                                </p>
                                {{-- TASK-1714 volet 3 — l'adresse et ses pastilles sont
                                     SEPAREES. Elles vivaient dans le meme `<p class="truncate">`,
                                     et `truncate` etant `white-space: nowrap` + `overflow: hidden`,
                                     une adresse longue emportait les pastilles avec elle : l'etat
                                     « email non verifie » disparaissait sans que rien ne le dise.

                                     `title` donne acces au texte complet — au survol, et lu par les
                                     lecteurs d'ecran. Une troncature sans recours n'est pas une
                                     troncature, c'est une perte. --}}
                                <p class="text-xs text-gray-500 truncate" title="{{ $u->email }}" data-marqueur="email-complet">
                                    {{ $u->email }}
                                </p>
                                <p class="text-xs text-gray-500">
                                    {{-- TASK-1693 bug B — l'etat se lit a cote de
                                         l'adresse qu'il qualifie. Seul le cas NON
                                         verifie porte une pastille : signaler
                                         chaque compte sain noierait l'exception. --}}
                                    @unless($u->hasVerifiedEmail())
                                        <span class="ml-1 inline-flex items-center gap-1 text-amber-600 dark:text-amber-400"
                                              title="Cette personne est renvoyée vers /verify-email sur toute surface d'Organization"
                                              data-user-email-unverified="{{ $u->id }}">
                                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>email non vérifié
                                        </span>
                                    @endunless

                                    {{-- TASK-1714 volet 2 — l'attente de validation se lit
                                         a cote de l'etat de l'email, parce que ce sont deux
                                         etages de la MEME porte : l'adresse d'abord, la
                                         personne ensuite. Seul le cas en attente porte une
                                         pastille — signaler chaque compte sain noierait
                                         l'exception. --}}
                                    @if($u->attendApprobation())
                                        <span class="ml-1 inline-flex items-center gap-1 text-orange-600 dark:text-orange-400"
                                              title="{{ __('admin.users_pending_approval_title') }}"
                                              data-user-pending-approval="{{ $u->id }}">
                                            <span class="w-1.5 h-1.5 rounded-full bg-orange-500"></span>{{ __('admin.users_pending_approval_badge') }}
                                        </span>
                                    @endif
                                </p>

                                {{-- TASK-1640 — sur mobile les colonnes Organisation et
                                     Points sont masquees : l'information ne doit pas etre
                                     PERDUE, elle remonte ici. Masquer une colonne sans
                                     rien mettre a la place aurait rendu l'ecran inutile
                                     sur telephone plutot que simplement etroit. --}}
                                <p class="md:hidden text-xs text-gray-500 truncate">
                                    {{ $u->organization?->name ?? 'Globale' }}
                                    <span class="lg:hidden">&middot; {{ $u->points_balance }} pts</span>
                                </p>

                                {{-- TASK-1693 bug D — sur mobile la colonne
                                     Organisation est masquee : le controle
                                     remonte ici, exactement comme TASK-1640
                                     avait fait remonter l'information. Deplacer
                                     le controle dans une colonne `hidden` sans
                                     ce second rendu aurait RETIRE du telephone
                                     une capacite qui y fonctionnait — le popover
                                     n'etait casse que sur desktop. MEME partiel
                                     dans les deux cas : rien n'est duplique. --}}
                                @if($u->id !== auth()->id())
                                <div class="md:hidden mt-1 w-full max-w-full min-w-0">
                                    @include('admin.partials.user-organization-assign', ['u' => $u, 'organizations' => $organizations, 'context' => 'mobile'])
                                </div>
                                @endif
                            </div>
                        </div>
                    </td>
                    <td class="hidden md:table-cell px-4 py-3 align-top">
                        {{-- TASK-1693 bug D — le SLUG, pas le nom complet.
                             « Organismes de formation solidaires et humanistes »
                             faisait a lui seul la largeur de la colonne, et il
                             paraissait DEUX fois par ligne depuis que le
                             selecteur vit ici. Le slug est court, stable, et
                             c'est deja l'identifiant lu dans les URL
                             `/org/{slug}`. Le nom complet reste au survol. --}}
                        @if($u->organization)
                        <span class="inline-flex items-center gap-1 text-xs font-medium text-indigo-600 dark:text-indigo-400"
                              title="{{ $u->organization->name }}">
                            <span class="w-1.5 h-1.5 rounded-full bg-indigo-500"></span>
                            {{ $u->organization->slug }}
                        </span>
                        @else
                        <span class="text-xs text-gray-400">Globale</span>
                        @endif

                        {{-- TASK-1693 bug D — le changement d'Organization vit
                             desormais dans la colonne qu'il modifie, et non plus
                             en popover dans « Actions » ou le defilement
                             horizontal du tableau le rognait. --}}
                        @if($u->id !== auth()->id())
                            @include('admin.partials.user-organization-assign', ['u' => $u, 'organizations' => $organizations, 'context' => 'desktop'])
                        @endif
                    </td>
                    @if($vueDetaillee)
                    <td class="hidden lg:table-cell px-4 py-3">
                        <div x-data="{ open: false }" class="relative">
                            <button @click="open = !open" class="text-gray-700 dark:text-gray-300 hover:text-indigo-600 font-medium">{{ $u->points_balance }}</button>
                            <div x-show="open" x-cloak @click.outside="open = false"
                                 class="absolute left-0 mt-1 w-56 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl shadow-lg z-10 p-3">
                                <p class="text-xs font-semibold text-gray-700 dark:text-gray-300 mb-2">Ajuster les points</p>
                                <form method="POST" action="{{ route('admin.users.adjust-points', $u) }}" class="flex gap-2">
                                    @csrf
                                    <input type="number" name="delta" placeholder="±pts" required
                                        class="flex-1 px-2 py-1 border border-gray-300 dark:border-gray-600 rounded text-sm bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                                    <button type="submit" class="px-2 py-1 bg-indigo-600 text-white text-xs rounded hover:bg-indigo-700">OK</button>
                                </form>
                                <p class="text-xs text-gray-400 mt-1">Entrez un nombre positif ou négatif.</p>
                            </div>
                        </div>
                    </td>
                    @endif
                    @if($vueDetaillee)
                    <td class="hidden lg:table-cell px-4 py-3 text-gray-700 dark:text-gray-300">{{ $u->services_count }}</td>
                    @endif
                    @if($vueDetaillee)
                    <td class="hidden xl:table-cell px-4 py-3 text-gray-700 dark:text-gray-300">
                        {{ $u->buyer_transactions_count + $u->seller_transactions_count }}
                    </td>
                    @endif
                    <td class="hidden xl:table-cell px-4 py-3 text-gray-700 dark:text-gray-300">
                        {{ $u->rating ? number_format($u->rating, 1).'/5' : '—' }}
                        @if($u->reviews_received_count > 0)
                        <span class="text-xs text-gray-400">({{ $u->reviews_received_count }})</span>
                        @endif
                    </td>
                    @if($vueDetaillee)
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center gap-1 text-xs">
                            <span class="w-1.5 h-1.5 rounded-full {{ $u->banned_at ? 'bg-red-500' : ($u->is_available ? 'bg-green-500' : 'bg-gray-400') }}"></span>
                            {{ $u->banned_at ? 'Banni' : ($u->is_available ? 'Disponible' : 'Indisponible') }}
                        </span>
                    </td>
                    @endif
                    <td class="hidden sm:table-cell px-4 py-3 text-xs text-gray-400">{{ $u->created_at->format('d/m/Y') }}</td>
                    <td class="sticky right-0 z-10 w-[32rem] min-w-[32rem] bg-white dark:bg-gray-800 border-l border-gray-200 dark:border-gray-700 px-4 py-3 align-top">
                        {{-- TASK-1714 volet 3 — ONZE actions tenaient dans une colonne
                             sans largeur : `flex-wrap` les empilait une par ligne, chacune
                             repliee sur deux. Mesure avant correction : lignes de 245 a
                             437 px (mediane 367), colonne Actions HORS CADRE des 1440 px,
                             et 396 px de debordement sur mobile. On voyait deux
                             utilisateurs par ecran.

                             `<details>` natif, et non un popover : un menu positionne en
                             `absolute` serait rogne par l'`overflow-x-auto` du tableau —
                             c'est exactement le defaut de TASK-1693 bug D. Celui-ci
                             s'ouvre EN FLUX : la ligne ne grandit que pour celle qu'on
                             ouvre, rien n'est rogne, et il fonctionne sans JavaScript,
                             donc aussi sur le layout invite ou Alpine est absent
                             (mesure du volet 1).

                             Deux actions restent DEHORS : « Valider compte », urgente et
                             rare, et « Modifier », la plus frequente. Les enfouir ferait
                             payer un clic de plus a ce qu'on fait tout le temps. --}}
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                            @if($u->attendApprobation())
                            <form method="POST" action="{{ route('admin.users.approve', $u) }}"
                                  onsubmit="return confirm(@js(__('admin.users_approve_confirm')))">
                                @csrf
                                <button class="text-xs font-semibold text-emerald-600 hover:underline whitespace-nowrap"
                                        data-user-approve="{{ $u->id }}">
                                    {{ __('admin.users_approve_account') }}
                                </button>
                            </form>
                            @endif

                            @if($u->id !== auth()->id() && !$u->banned_at)
                            <form method="POST" action="{{ route('admin.users.login-as', $u) }}">
                                @csrf
                                <button class="text-xs font-medium text-amber-600 hover:underline whitespace-nowrap">
                                    Se connecter sous
                                </button>
                            </form>
                            @endif

                            <a href="{{ route('admin.users.edit', $u) }}" class="text-xs font-medium text-indigo-600 hover:underline whitespace-nowrap">Modifier</a>

                            {{-- TASK-1705 — poser la photo d'un compte sans passer par la page
                                 Modifier. Cyril : « certaines personnes ne prennent pas le temps
                                 d'uploader leur photo et je vais recuperer leur photo pour eux ».

                                 Ce formulaire est un FRERE de celui de « Se connecter sous »,
                                 jamais un imbrique : un `<form>` dans un `<form>` est invalide en
                                 HTML et reste invisible aux tests serveur (TASK-1668).

                                 Le champ se declenche au choix du fichier : un second clic sur un
                                 bouton « Envoyer » n'apporterait rien ici, l'intention etant deja
                                 exprimee par le choix de l'image. --}}
                            <form method="POST" action="{{ route('admin.users.avatar.update', $u) }}"
                                  enctype="multipart/form-data" class="inline">
                                @csrf
                                <label data-user-avatar-upload="{{ $u->id }}"
                                       title="{{ __('admin.users_avatar_button_title', ['name' => $u->full_name]) }}"
                                       class="cursor-pointer text-xs font-medium text-emerald-600 hover:underline">
                                    {{ __('admin.users_avatar_button') }}
                                    <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp"
                                           x-on:change="$el.form.submit()" class="hidden">
                                </label>
                            </form>
                            {{-- TASK-1666 — `profile.show` est une route FRONT, bornee au tenant
                                 courant : elle fait `abort(404)` des que `organization_id`
                                 differe, et aussi sur un compte banni. Un SuperAdmin n'y
                                 echappe pas, et c'est VOULU — la garde de tenant n'est pas a
                                 percer. On n'affiche donc le lien que la ou il MENE quelque
                                 part ; ailleurs la Fiche, elle, marche toujours. --}}
                            @if($u->organization_id !== null
                                && optional(currentOrganization())->id === $u->organization_id
                                && $u->banned_at === null)
                            <a href="{{ route('profile.show', $u->profileRouteKey()) }}" class="text-xs text-gray-500 hover:underline">Profil</a>
                            @endif

                            {{-- TASK-1640 — la fiche complete, en pop-up. Aucun precheck ni
                                 comptage n'est calcule au rendu : le bouton ne porte que l'id. --}}
                            <button type="button"
                                @click="$dispatch('open-user-profile', { id: '{{ $u->id }}', name: @js($u->full_name) })"
                                class="text-xs font-medium text-sky-600 hover:underline">
                                {{ __('admin.users_profile_button') }}
                            </button>

                            {{-- TASK-1640 — la suppression part d'ICI, plus d'un detour par
                                 Modifier. TASK-1668 — elle mene desormais a une PAGE : la modal
                                 avait grossi a chaque TASK et debordait. Le lien ne porte
                                 toujours AUCUN etat, et aucun precheck n'est calcule au rendu de
                                 la liste. --}}
                            @if($u->id !== auth()->id())
                            <a href="{{ route('admin.users.delete-page', $u) }}"
                               class="text-xs font-medium text-red-600 hover:underline">
                                {{ __('admin.user_delete_row_button') }}
                            </a>
                            @endif

                            <form method="POST" action="{{ route('admin.users.toggle-availability', $u) }}">
                                @csrf @method('PATCH')
                                <button class="text-xs text-gray-500 hover:text-orange-600">
                                    {{ $u->is_available ? __('admin.mark_unavailable') : __('admin.mark_available') }}
                                </button>
                            </form>

                            @if($u->id !== auth()->id())
                            <form method="POST" action="{{ route('admin.users.toggle-admin', $u) }}">
                                @csrf @method('PATCH')
                                <button class="text-xs {{ $u->is_admin ? 'text-purple-600 hover:text-red-600' : 'text-gray-400 hover:text-purple-600' }}">
                                    {{ $u->is_admin ? '−Admin' : '+Admin' }}
                                </button>
                            </form>

                            {{-- TASK-1693 bug B — valider manuellement l'adresse.
                                 Le bouton n'apparait que pour un compte NON
                                 verifie : il n'y a rien a faire sur les autres,
                                 et un geste sans effet est un piege. Confirmation
                                 explicite, parce qu'il contourne une preuve de
                                 possession d'adresse. --}}
                            {{-- TASK-1694 bug J — RENVOYER le lien, avant de
                                 songer a contourner la preuve.
                                 Pose AVANT « Valider l'email » a dessein :
                                 l'ordre de lecture suggere le geste le moins
                                 lourd en premier. Pas de confirmation : renvoyer
                                 un email ne detruit rien et ne contourne rien. --}}
                            @unless($u->hasVerifiedEmail())
                            <form method="POST" action="{{ route('admin.users.resend-verification', $u) }}">
                                @csrf
                                <button class="text-xs text-indigo-600 hover:underline" data-user-resend-verification="{{ $u->id }}">{{ __('admin.users_resend_verification') }}</button>
                            </form>
                            @endunless

                            @unless($u->hasVerifiedEmail())
                            <form method="POST" action="{{ route('admin.users.verify-email', $u) }}"
                                  onsubmit="return confirm('Marquer {{ addslashes($u->email) }} comme vérifiée, sans que cette personne ait cliqué le lien de confirmation ?')">
                                @csrf
                                <button class="text-xs text-amber-600 hover:underline" data-user-verify-email="{{ $u->id }}">Valider l'email</button>
                            </form>
                            @endunless

                            @if($u->banned_at)
                            <form method="POST" action="{{ route('admin.users.unban', $u) }}">
                                @csrf @method('PATCH')
                                <button class="text-xs text-green-600 hover:underline">Débannir</button>
                            </form>
                            @else
                            <form method="POST" action="{{ route('admin.users.ban', $u) }}"
                                  onsubmit="return confirm('Bannir {{ addslashes($u->full_name) }} ?')">
                                @csrf @method('PATCH')
                                <button class="text-xs text-red-500 hover:underline">Bannir</button>
                            </form>
                            @endif
                            @endif

                            {{-- TASK-1693 bug D — l'affectation d'Organization a
                                 QUITTE cette colonne pour celle qu'elle modifie.
                                 Son popover `absolute` etait rogne par le
                                 `overflow-x-auto` du tableau (TASK-1640), donc
                                 inatteignable sur desktop. Voir
                                 `admin/partials/user-organization-assign.blade.php`. --}}

                            {{-- TASK-1694 bug L puis V — « Envoyer un email » ouvre un
                                 MODAL centre, pose hors du tableau.

                                 Bug L avait mis le choix EN FLUX dans la ligne, pour
                                 echapper au rognage de l'`overflow-x-auto` (defaut
                                 mesure en TASK-1693 bug D). Ca marchait, mais c'etait
                                 etroit : Cyril, le 24/10 — « certes contextualise mais
                                 trop petit, et si on a plus de 10 messages types ce
                                 sera ingerable ».

                                 Bug V : la ligne ne porte plus que le DECLENCHEUR. Le
                                 modal est unique pour toute la page et vit hors du
                                 tableau — donc rien a rogner, et toute la largeur
                                 disponible. Le bouton lui passe la personne et l'URL
                                 d'envoi, construite cote SERVEUR par `route()` : rien
                                 n'est recompose en JavaScript. --}}
                            <button type="button"
                                    @click="$dispatch('send-email-context', {
                                        label: @js($u->email),
                                        url: @js(route('admin.users.send-email', $u))
                                    }); $dispatch('open-modal', 'send-email')"
                                    class="text-xs text-gray-400 hover:text-indigo-500"
                                    data-user-send-email-toggle="{{ $u->id }}"
                                    data-user-send-email-url="{{ route('admin.users.send-email', $u) }}">
                                {{ __('admin.emailer_single_action') }}
                            </button>

                            <!-- Envoyer lien de réinitialisation -->
                            <form method="POST" action="{{ route('admin.users.send-password-reset', $u) }}"
                                  onsubmit="return confirm('Envoyer un lien de réinitialisation à ce membre ?')">
                                @csrf
                                <button class="text-xs text-gray-400 hover:text-indigo-500"
                                        data-user-password-reset="{{ $u->id }}">
                                    {{ __('admin.users_password_reset_link') }}
                                </button>
                            </form>

                            <!-- Changer le mot de passe -->
                            <div x-data="{ pwOpen: false }" class="relative">
                                <button @click="pwOpen = !pwOpen"
                                        class="text-xs text-gray-400 hover:text-orange-500">Mdp</button>
                                <div x-show="pwOpen" x-cloak @click.outside="pwOpen = false"
                                     class="absolute right-0 mt-1 w-60 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl shadow-lg z-10 p-3">
                                    <p class="text-xs font-semibold text-gray-700 dark:text-gray-300 mb-2">
                                        Changer le mot de passe
                                    </p>
                                    <form method="POST" action="{{ route('admin.users.password', $u) }}"
                                          class="space-y-2">
                                        @csrf
                                        <input type="password" name="password" placeholder="Nouveau mdp"
                                               required minlength="8"
                                               class="w-full px-2 py-1 border border-gray-300 dark:border-gray-600 rounded text-sm bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                                        <input type="password" name="password_confirmation" placeholder="Confirmer"
                                               required minlength="8"
                                               class="w-full px-2 py-1 border border-gray-300 dark:border-gray-600 rounded text-sm bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                                        <button type="submit"
                                                class="w-full px-2 py-1 bg-orange-600 hover:bg-orange-700 text-white text-xs rounded transition">
                                            Changer
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="{{ $colonnesVisibles }}" class="px-4 py-8 text-center text-sm text-gray-400">Aucun utilisateur trouvé.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($users->hasPages())
    <div class="mt-4">{{ $users->withQueryString()->links() }}</div>
    @endif

    {{-- TASK-1666 — la Fiche a quitte ce fichier pour
         `admin/partials/user-profile-modal.blade.php` : /admin/transactions
         en a besoin aussi. Elle porte sa propre racine Alpine et s'ouvre
         par evenement, d'ou le `$dispatch` sur le bouton plus haut. --}}
    @include('admin.partials.user-profile-modal')

    {{-- TASK-1694 bug V — UN modal « Envoyer un email » pour toute la page,
         hors du tableau : rien a rogner, et toute la largeur disponible.
         La ligne ne porte que le declencheur, qui lui passe la personne et son
         URL d'envoi. --}}
    @include('admin.partials.user-send-email-modal')

    {{-- TASK-1668 — la modal de suppression a laisse place a une PAGE
         (`admin/users/delete.blade.php`). Elle debordait : blocages avec leurs
         liens, contenus a confier par famille avec les leurs, choix du repreneur.
         Les deux ne coexistent pas : garder un raccourci modal doublerait la
         surface a tester pour la meme garantie. --}}
</div>

</x-admin-layout>
