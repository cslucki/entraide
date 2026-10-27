{{-- TASK-1693 bug D — affecter une Organization, EN LIGNE dans la cellule.

     ## Pourquoi l'ancien controle etait inaccessible sur desktop

     C'etait un popover Alpine : `absolute right-0 … z-10`, pose dans la colonne
     Actions. Or le conteneur du tableau porte `overflow-x-auto` depuis
     TASK-1640 — qui l'avait mis a la place d'un `overflow-hidden` pour cesser
     de COUPER les colonnes. Un ancetre en `overflow-x: auto` etablit un
     conteneur de defilement qui ROGNE ses descendants positionnes en
     `absolute`, et `z-index` n'y change rien : la pile de contexte ne sort pas
     du cadre qui la rogne. La colonne Actions etant au bord droit du tableau,
     le panneau s'ouvrait hors cadre et restait invisible.

     Le defaut etait donc un effet de bord du correctif TASK-1640, invisible
     jusqu'ici parce qu'un enfant `absolute` ne declenche aucune erreur : il
     s'affiche simplement ailleurs qu'on croit.

     ## Le choix de forme

     Plus de superposition du tout : un `<select>` et un bouton, dans le flux.
     Rien a rogner, rien a empiler, aucune dependance a Alpine. Le `<select>`
     porte l'Organization courante en option selectionnee, si bien que la
     cellule CONTINUE d'afficher l'affectation — l'information n'est pas
     remplacee par un outil.

     Le bouton reste EXPLICITE : pas de soumission automatique au `change`.
     Reaffecter le tenant de quelqu'un n'est pas un reglage d'affichage ; un
     clic de travers ne doit pas y suffire.

     `$organizations` vient du controleur, deja filtre sur `is_active` et trie
     par nom. L'ancien popover refaisait cette requete A CHAQUE LIGNE
     (`Organization::where('is_active', true)->get()` dans la boucle) : un N+1
     sur un ecran qui porte justement une garde de cout.

     @param \App\Models\User $u
     @param \Illuminate\Support\Collection $organizations
     @param string $context 'desktop' ou 'mobile' — distingue les deux rendus
            du MEME partiel ; sans quoi les `id`/marqueurs seraient en double
            dans le document.
--}}
<form method="POST" action="{{ route('admin.users.assign-organization', $u) }}"
      class="mt-1 flex items-center gap-1"
      {{-- UN marqueur composite plutot que deux attributs : Blade les ecrit
           sur des lignes separees, et une assertion qui les cherchait
           cote a cote mesurait le FORMATAGE du gabarit, pas le rendu. --}}
      data-user-assign-organization="{{ $u->id }}:{{ $context }}">
    @csrf @method('PATCH')
    <label class="sr-only" for="assign-org-{{ $context }}-{{ $u->id }}">
        {{ __('admin.users_assign_organization') }}
    </label>
    {{-- Le SLUG, pas le nom. « Organismes de formation solidaires et
         humanistes » faisait a lui seul la largeur de la colonne, et il
         apparaissait DEUX fois par ligne (libelle au-dessus, puis option
         selectionnee). Le slug est court, stable, et c'est deja l'identifiant
         que l'administrateur lit dans les URL `/org/{slug}`. Le nom complet
         reste accessible au survol, via `title`. --}}
    <select name="organization_id"
            id="assign-org-{{ $context }}-{{ $u->id }}"
            title="{{ $u->organization?->name ?? __('admin.users_assign_organization_none') }}"
            class="min-w-0 max-w-[9rem] px-1.5 py-1 border border-gray-300 dark:border-gray-600 rounded text-xs bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
        <option value="">{{ __('admin.users_assign_organization_none_short') }}</option>
        @foreach($organizations as $organization)
        <option value="{{ $organization->id }}"
                title="{{ $organization->name }}"
                {{ $u->organization_id === $organization->id ? 'selected' : '' }}>
            {{ $organization->slug }}
        </option>
        @endforeach
    </select>
    <button type="submit"
            class="flex-shrink-0 px-2 py-1 bg-indigo-600 hover:bg-indigo-700 text-white text-xs rounded transition">
        {{ __('admin.users_assign_organization_submit') }}
    </button>
</form>
