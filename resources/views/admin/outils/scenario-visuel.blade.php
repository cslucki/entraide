{{-- TASK-1651 — l'editeur VISUEL borne.

     Quatre familles editables : General, Personnes, Boucles, Membres. Le JSON
     reste accessible en mode avance, et il edite le MEME document : il n'y a
     qu'une source, `json_source`.

     Le vocabulaire de l'ecran est celui du produit, jamais celui du schema :
     on ne montre ni stable key, ni digest, ni UUID. Cyril construit une petite
     communaute fictive ; il ne remplit pas une fixture. --}}
<x-admin-layout :title="$version->name">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('admin.outils.scenarios.show', $version) }}" class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">&larr; {{ __('admin.scenario_manager.editor_back') }}</a>

        <span data-state="{{ $version->state }}" class="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-200">
            {{ __('admin.scenario_manager.state_'.$version->state) }}
        </span>
    </div>

    <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ __('admin.scenario_manager.visual_title') }}</h1>
    <p class="mt-1 mb-6 text-sm text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.visual_intro') }}</p>

    @if(session('status'))
        <div class="mb-6 rounded-xl border border-green-300 bg-green-50 p-4 text-sm text-green-900 dark:border-green-800 dark:bg-green-900/20 dark:text-green-200">
            {{ session('status') }}
        </div>
    @endif

    @if($errors->any())
        <div data-visual-errors class="mb-6 rounded-xl border border-red-300 bg-red-50 p-4 dark:border-red-800 dark:bg-red-900/20">
            <ul class="space-y-1 text-sm text-red-900 dark:text-red-200">
                @foreach($errors->all() as $erreur)
                    <li>{{ $erreur }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Un document illisible ne se represente PAS : on le dit, on garde le
         texte, et on renvoie au mode JSON qui seul peut le reparer. --}}
    @unless($lisible)
        <div data-visual-unavailable class="rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-900/20">
            <p class="text-sm text-amber-900 dark:text-amber-200">{{ __('admin.scenario_manager.visual_unparsable') }}</p>
            <a href="{{ route('admin.outils.scenarios.edit', $version) }}"
               class="mt-3 inline-flex rounded-lg border border-amber-400 px-3 py-2 text-sm font-semibold text-amber-900 hover:bg-amber-100 dark:border-amber-600 dark:text-amber-200">
                {{ __('admin.scenario_manager.visual_tab_json') }}
            </a>
        </div>
    @else

    {{-- Une version chargee decrit une sandbox VIVANTE : on la montre, on ne
         la modifie pas. L'operateur est renvoye vers les gestes prevus. --}}
    @unless($modifiable)
        <div data-visual-readonly class="mb-6 rounded-xl border border-indigo-300 bg-indigo-50 p-4 dark:border-indigo-800 dark:bg-indigo-900/20">
            <p class="text-sm text-indigo-900 dark:text-indigo-200">{{ __('admin.scenario_manager.visual_loaded') }}</p>
        </div>
    @endunless

    @php
        // Le NOM d'un persona depuis sa stable key. L'ecran parle en noms ; la
        // clef reste dans `data-*` pour la recette. Une clef inconnue se rend
        // telle quelle plutot que de disparaitre : un document en cours de
        // reparation doit montrer ce qui cloche.
        $nomDuPersona = function (?string $cle) use ($personnes): string {
            if ($cle === null || $cle === '') {
                return '—';
            }

            foreach ($personnes as $candidat) {
                if (($candidat['key'] ?? null) === $cle) {
                    return trim(($candidat['first_name'] ?? '').' '.($candidat['name'] ?? '')) ?: $cle;
                }
            }

            return $cle;
        };
    @endphp

    <div x-data="{ onglet: (window.location.hash || '#general').substring(1) }">
        <nav class="mb-6 flex flex-wrap gap-1 border-b border-gray-200 dark:border-gray-700">
            @foreach(['general', 'personnes', 'boucles', 'membres'] as $onglet)
                <button type="button"
                        data-tab="{{ $onglet }}"
                        @click="onglet = '{{ $onglet }}'"
                        :class="onglet === '{{ $onglet }}' ? 'border-indigo-600 text-indigo-700 dark:text-indigo-300' : 'border-transparent text-gray-500'"
                        class="border-b-2 px-4 py-2 text-sm font-semibold">
                    {{ __('admin.scenario_manager.visual_tab_'.$onglet) }}
                </button>
            @endforeach

            <a href="{{ route('admin.outils.scenarios.edit', $version) }}"
               data-tab="json"
               class="border-b-2 border-transparent px-4 py-2 text-sm font-semibold text-gray-500 hover:text-indigo-700">
                {{ __('admin.scenario_manager.visual_tab_json') }}
            </a>
        </nav>

        {{-- ============================ GENERAL ======================== --}}
        <section x-show="onglet === 'general'" data-panel="general">
            <form method="POST" action="{{ route('admin.outils.scenarios.visual.general', $version) }}" data-form="general" class="space-y-4">
                @csrf
                @method('PUT')

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('admin.scenario_manager.visual_field_name') }}</span>
                        <input type="text" name="name" value="{{ old('name', $document['name'] ?? '') }}" required maxlength="120"
                               class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                    </label>

                    <label class="block">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('admin.scenario_manager.visual_field_version') }}</span>
                        <input type="text" name="version" value="{{ old('version', $document['version'] ?? '1.0.0') }}" required maxlength="32"
                               class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                    </label>
                </div>

                <label class="block">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('admin.scenario_manager.visual_field_description') }}</span>
                    <textarea name="description" rows="3" required maxlength="2000"
                              class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700">{{ old('description', $document['description'] ?? '') }}</textarea>
                </label>

                <label class="block">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('admin.scenario_manager.visual_field_purpose') }}</span>
                    <textarea name="purpose" rows="2" required maxlength="500"
                              class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700">{{ old('purpose', $document['purpose'] ?? '') }}</textarea>
                </label>

                {{-- UNE seule langue : `organization.locale` doit etre identique
                     a `locale`, et `ManifestCoreInvariants` refuse sinon. Deux
                     selecteurs offriraient une faute, pas un choix. --}}
                <label class="block max-w-xs">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('admin.scenario_manager.visual_field_locale') }}</span>
                    <select name="locale" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                        @foreach(['fr', 'en'] as $langue)
                            <option value="{{ $langue }}" @selected(old('locale', $document['locale'] ?? 'fr') === $langue)>{{ strtoupper($langue) }}</option>
                        @endforeach
                    </select>
                </label>

                <h2 class="pt-4 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('admin.scenario_manager.visual_org_title') }}</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.visual_org_hint') }}</p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('admin.scenario_manager.visual_field_org_name') }}</span>
                        <input type="text" name="organization_name" value="{{ old('organization_name', $document['organization']['name'] ?? '') }}" required maxlength="120"
                               class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                    </label>

                    <label class="block">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('admin.scenario_manager.visual_field_org_slug') }}</span>
                        <input type="text" name="organization_proposed_slug" value="{{ old('organization_proposed_slug', $document['organization']['proposed_slug'] ?? '') }}" required maxlength="64"
                               class="mt-1 w-full rounded-lg border-gray-300 font-mono dark:border-gray-600 dark:bg-gray-700">
                    </label>
                </div>

                <label class="block">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('admin.scenario_manager.visual_field_org_description') }}</span>
                    <textarea name="organization_description" rows="2" required maxlength="2000"
                              class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700">{{ old('organization_description', $document['organization']['description'] ?? '') }}</textarea>
                </label>

                @if($modifiable)
                    <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                        {{ __('admin.scenario_manager.visual_save') }}
                    </button>
                @endif
            </form>
        </section>

        {{-- =========================== PERSONNES ======================= --}}
        <section x-show="onglet === 'personnes'" data-panel="personnes" style="display:none">
            <ul class="mb-6 divide-y divide-gray-200 dark:divide-gray-700">
                @forelse($personnes as $personne)
                    <li data-person="{{ $personne['key'] ?? '' }}" class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div>
                            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $personne['first_name'] ?? '' }} {{ $personne['name'] ?? '' }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $personne['email'] ?? '' }} ·
                                <span data-person-role="{{ $personne['organization_role'] ?? '' }}">{{ __('admin.scenario_manager.visual_role_'.($personne['organization_role'] ?? 'member')) }}</span>
                            </p>
                        </div>

                        @if($modifiable)
                            <form method="POST" action="{{ route('admin.outils.scenarios.visual.person.destroy', [$version, $personne['key'] ?? '-']) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-xs font-semibold text-red-700 hover:underline dark:text-red-400">
                                    {{ __('admin.scenario_manager.visual_delete') }}
                                </button>
                            </form>
                        @endif
                    </li>
                @empty
                    <li data-person-empty class="py-3 text-sm text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.visual_no_person') }}</li>
                @endforelse
            </ul>

            @if($modifiable)
                <details class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    <summary class="cursor-pointer text-sm font-semibold text-indigo-700 dark:text-indigo-300">{{ __('admin.scenario_manager.visual_add_person') }}</summary>

                    <form method="POST" action="{{ route('admin.outils.scenarios.visual.person.store', $version) }}" data-form="person-create" class="mt-4 space-y-4">
                        @csrf

                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="block">
                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('admin.scenario_manager.visual_field_first_name') }}</span>
                                <input type="text" name="first_name" required maxlength="80" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                            </label>

                            <label class="block">
                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('admin.scenario_manager.visual_field_last_name') }}</span>
                                <input type="text" name="name" required maxlength="120" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                            </label>
                        </div>

                        <label class="block">
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('admin.scenario_manager.visual_field_email') }}</span>
                            <input type="text" name="email" required maxlength="160" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                            <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.visual_email_hint') }}</span>
                        </label>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="block">
                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('admin.scenario_manager.visual_field_role') }}</span>
                                <select name="organization_role" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                                    <option value="member">{{ __('admin.scenario_manager.visual_role_member') }}</option>
                                    <option value="admin">{{ __('admin.scenario_manager.visual_role_admin') }}</option>
                                </select>
                                <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.visual_role_hint') }}</span>
                            </label>

                            <label class="block">
                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('admin.scenario_manager.visual_field_location') }}</span>
                                <input type="text" name="location" maxlength="160" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                            </label>
                        </div>

                        <label class="block">
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('admin.scenario_manager.visual_field_bio') }}</span>
                            <textarea name="bio" rows="2" maxlength="2000" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700"></textarea>
                        </label>

                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="available" value="1" checked class="rounded border-gray-300">
                            <span class="text-sm text-gray-700 dark:text-gray-300">{{ __('admin.scenario_manager.visual_field_available') }}</span>
                        </label>

                        <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                            {{ __('admin.scenario_manager.visual_add_person') }}
                        </button>
                    </form>
                </details>
            @endif
        </section>

        {{-- ============================ BOUCLES ======================== --}}
        <section x-show="onglet === 'boucles'" data-panel="boucles" style="display:none">
            <ul class="mb-6 divide-y divide-gray-200 dark:divide-gray-700">
                @forelse($boucles as $boucle)
                    <li data-loop="{{ $boucle['key'] ?? '' }}" class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div>
                            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $boucle['name'] ?? '' }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ __('admin.scenario_manager.visual_loop_type_'.($boucle['type'] ?? 'private')) }} ·
                                {{ __('admin.scenario_manager.visual_loop_owner') }} :
                                {{-- Le NOM du persona, pas sa stable key : cet ecran ne montre
                                     aucune clef technique. `data-loop-owner` la garde pour la
                                     recette, qui a besoin d'une identite stable. --}}
                                <span data-loop-owner="{{ $boucle['owner'] ?? '' }}">{{ $nomDuPersona($boucle['owner'] ?? null) }}</span>
                            </p>
                        </div>

                        @if($modifiable)
                            <details>
                                <summary class="cursor-pointer text-xs font-semibold text-red-700 dark:text-red-400">{{ __('admin.scenario_manager.visual_delete') }}</summary>
                                <p class="mt-2 max-w-xs text-xs text-gray-600 dark:text-gray-400">{{ __('admin.scenario_manager.visual_delete_loop_hint') }}</p>
                                <form method="POST" action="{{ route('admin.outils.scenarios.visual.loop.destroy', [$version, $boucle['key'] ?? '-']) }}" class="mt-2">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="rounded-lg bg-red-600 px-3 py-2 text-xs font-semibold text-white hover:bg-red-700">
                                        {{ __('admin.scenario_manager.visual_delete_loop_confirm') }}
                                    </button>
                                </form>
                            </details>
                        @endif
                    </li>
                @empty
                    <li data-loop-empty class="py-3 text-sm text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.visual_no_loop') }}</li>
                @endforelse
            </ul>

            @if($modifiable)
                {{-- Sans personne, pas de Boucle : `owner` est obligatoire. On
                     le DIT au lieu d'afficher une liste deroulante vide. --}}
                @if($personnes === [])
                    <p data-loop-blocked class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-200">
                        {{ __('admin.scenario_manager.visual_loop_needs_person') }}
                    </p>
                @else
                    <details class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <summary class="cursor-pointer text-sm font-semibold text-indigo-700 dark:text-indigo-300">{{ __('admin.scenario_manager.visual_add_loop') }}</summary>

                        <form method="POST" action="{{ route('admin.outils.scenarios.visual.loop.store', $version) }}" data-form="loop-create" class="mt-4 space-y-4">
                            @csrf

                            <label class="block">
                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('admin.scenario_manager.visual_field_name') }}</span>
                                <input type="text" name="name" required maxlength="120" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                            </label>

                            <label class="block">
                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('admin.scenario_manager.visual_field_description') }}</span>
                                <textarea name="description" rows="2" required maxlength="2000" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700"></textarea>
                            </label>

                            <div class="grid gap-4 sm:grid-cols-2">
                                <label class="block">
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('admin.scenario_manager.visual_field_loop_type') }}</span>
                                    <select name="type" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                                        @foreach(['general', 'project', 'coaching', 'training'] as $type)
                                            <option value="{{ $type }}">{{ __('admin.scenario_manager.visual_loop_type_'.$type) }}</option>
                                        @endforeach
                                    </select>
                                </label>

                                <label class="block">
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('admin.scenario_manager.visual_loop_owner') }}</span>
                                    <select name="owner" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                                        @foreach($personnes as $personne)
                                            <option value="{{ $personne['key'] ?? '' }}">{{ $personne['first_name'] ?? '' }} {{ $personne['name'] ?? '' }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            </div>

                            <div class="grid gap-4 sm:grid-cols-2">
                                <label class="block">
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('admin.scenario_manager.visual_field_visibility') }}</span>
                                    <select name="visibility" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                                        <option value="private">{{ __('admin.scenario_manager.visual_visibility_private') }}</option>
                                        <option value="public">{{ __('admin.scenario_manager.visual_visibility_public') }}</option>
                                    </select>
                                </label>

                                <label class="block">
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('admin.scenario_manager.visual_field_access') }}</span>
                                    <select name="access_mode" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                                        @foreach(['open', 'request', 'invitation'] as $acces)
                                            <option value="{{ $acces }}">{{ __('admin.scenario_manager.visual_access_'.$acces) }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            </div>

                            <p class="rounded-lg bg-blue-50 p-3 text-xs text-blue-900 dark:bg-blue-900/20 dark:text-blue-200">
                                {{ __('admin.scenario_manager.visual_loop_structure_notice') }}
                            </p>

                            <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                                {{ __('admin.scenario_manager.visual_add_loop') }}
                            </button>
                        </form>
                    </details>
                @endif
            @endif
        </section>

        {{-- ============================ MEMBRES ======================== --}}
        <section x-show="onglet === 'membres'" data-panel="membres" style="display:none">
            @if($personnes === [] || $boucles === [])
                <p data-membership-empty class="rounded-xl border border-gray-200 p-4 text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
                    {{ __('admin.scenario_manager.visual_membership_empty') }}
                </p>
            @else
                @php
                    $roles = [];
                    foreach ($memberships as $ligne) {
                        $roles[($ligne['loop'] ?? '').'|'.($ligne['user'] ?? '')] = $ligne['role'] ?? '';
                    }
                @endphp

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700">
                                <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-300">{{ __('admin.scenario_manager.visual_tab_personnes') }}</th>
                                @foreach($boucles as $boucle)
                                    <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-300">{{ $boucle['name'] ?? '' }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($personnes as $personne)
                                <tr>
                                    <td class="px-3 py-2 text-gray-900 dark:text-gray-100">{{ $personne['first_name'] ?? '' }} {{ $personne['name'] ?? '' }}</td>

                                    @foreach($boucles as $boucle)
                                        @php $role = $roles[($boucle['key'] ?? '').'|'.($personne['key'] ?? '')] ?? ''; @endphp
                                        <td class="px-3 py-2">
                                            <form method="POST" action="{{ route('admin.outils.scenarios.visual.membership', $version) }}">
                                                @csrf
                                                @method('PUT')
                                                <input type="hidden" name="loop" value="{{ $boucle['key'] ?? '' }}">
                                                <input type="hidden" name="user" value="{{ $personne['key'] ?? '' }}">
                                                <select name="role"
                                                        data-membership="{{ $boucle['key'] ?? '' }}|{{ $personne['key'] ?? '' }}"
                                                        data-role="{{ $role }}"
                                                        @disabled(! $modifiable)
                                                        onchange="this.form.submit()"
                                                        class="rounded-lg border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-700">
                                                    <option value="" @selected($role === '')>&mdash;</option>
                                                    @foreach(['member', 'facilitator', 'owner'] as $valeur)
                                                        <option value="{{ $valeur }}" @selected($role === $valeur)>{{ __('admin.scenario_manager.visual_membership_'.$valeur) }}</option>
                                                    @endforeach
                                                </select>
                                            </form>
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.visual_membership_hint') }}</p>
            @endif
        </section>
    </div>

    {{-- Les erreurs du Validator, rendues telles quelles : c'est LE verdict,
         et l'ecran n'en fabrique pas un second. --}}
    @if($erreurs !== [])
        <section data-validation-errors class="mt-8 rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-900/20">
            <h2 class="text-sm font-semibold text-amber-900 dark:text-amber-200">{{ __('admin.scenario_manager.visual_pending_title') }}</h2>
            <p class="mt-1 text-xs text-amber-800 dark:text-amber-300">{{ __('admin.scenario_manager.visual_pending_hint') }}</p>
            <ul class="mt-3 space-y-1 text-xs text-amber-900 dark:text-amber-200">
                @foreach(array_slice($erreurs, 0, 20) as $erreur)
                    <li><span class="font-mono">{{ $erreur['path'] ?? '' }}</span> — {{ $erreur['message'] ?? '' }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    @endunless
</x-admin-layout>
