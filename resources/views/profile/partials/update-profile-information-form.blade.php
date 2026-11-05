{{-- TASK-1762 point 16 — l'en-tete « Informations du profil / Mettez à jour
     les informations de votre compte et votre adresse e-mail » est retire.

     Il titrait un formulaire qui n'a plus un seul sujet : depuis ce point, la
     page porte DEUX blocs nommes — « Informations de profil » et
     « Présentation » — et un troisieme titre au-dessus d'eux ne faisait que
     repeter le premier. --}}
<section>
    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form method="post" action="{{ route('profile.update') }}" class="space-y-6" enctype="multipart/form-data">
        @csrf
        @method('put')


        {{-- TASK-1762 point 16 — DEUX blocs distincts, demande de Cyril.

             Chacun porte son propre cadre : la separation doit se VOIR, un
             simple titre au milieu d'une colonne continue ne la donne pas.

             Les fonds alternent sur les jetons de THEME de l'Organization
             (jamais une couleur figee) : `--bp-panel` ici, `--bp-surface-soft`
             pour le bloc suivant — memes jetons que `theme-tokens.blade.php`
             emet deja en clair ET en sombre pour chaque theme.

             PAS `--bp-surface` : sur le theme par defaut il est quasi
             identique au fond de page (`--bp-page`), le bloc devenait
             invisible (recette Cyril, capture a l'appui). --}}
        <div class="p-4 sm:p-8 bg-[var(--bp-panel)] shadow sm:rounded-lg" data-profile-block="identite">
        <div class="max-w-xl space-y-6">

            <div>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 uppercase tracking-wider">{{ __('profile.identity_section') }}</h3>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('profile.identity_description') }}</p>
            </div>

        {{-- TASK-1749 — meme zone de depot que la fenetre du profil public.

             Le champ gardait la commande NATIVE (« Choisir un fichier / Aucun
             fichier choisi »), qui ne ressemble a rien d'autre dans ce
             formulaire et se laisse oublier entre « Profil LinkedIn » et la
             case de disponibilite. Il reste ici — on modifie bien sa photo en
             modifiant son profil —, mais il se voit.

             `<label for>` et non un `<div>` au clic simule : le clavier y
             arrive sans qu'on ait a l'ecrire. L'`<input>` est masque
             visuellement (`sr-only`), pas `hidden` — un champ `hidden` ne se
             declenche pas au clavier. --}}
        <div x-data="{
                 preview: null,
                 survol: false,
                 nom: null,
                 choisir(fichiers) {
                     const f = fichiers && fichiers[0];
                     if (! f) return;
                     this.nom = f.name;
                     this.preview = URL.createObjectURL(f);
                 },
                 deposer(e) {
                     this.survol = false;
                     this.$refs.champ.files = e.dataTransfer.files;
                     this.choisir(e.dataTransfer.files);
                 }
             }">
            <x-input-label for="avatar" :value="__('profile.avatar')" />
            <div class="mt-2 flex items-center gap-4">
                <img :src="preview ?? '{{ $user->avatar_url }}'"
                     class="w-14 h-14 flex-shrink-0 rounded-full object-cover border-2 border-gray-200 dark:border-gray-600" alt="">
                <label for="avatar"
                       @dragover.prevent="survol = true"
                       @dragleave.prevent="survol = false"
                       @drop.prevent="deposer($event)"
                       :class="survol
                           ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-900/20'
                           : 'border-gray-300 dark:border-gray-600 hover:border-indigo-400 hover:bg-gray-50 dark:hover:bg-gray-700/40'"
                       class="flex-1 cursor-pointer rounded-xl border-2 border-dashed px-4 py-3 text-center transition">
                    <span class="block text-sm font-semibold text-indigo-600 dark:text-indigo-400">{{ __('profile.avatar_drop') }}</span>
                    <span class="mt-0.5 block text-xs text-gray-400" x-text="nom ?? '{{ __('profile.avatar_help') }}'"></span>
                </label>
                <input id="avatar" name="avatar" type="file" accept="image/*"
                    x-ref="champ" @change="choisir($event.target.files)"
                    class="sr-only">
            </div>
            <x-input-error class="mt-2" :messages="$errors->get('avatar')" />
        </div>

            {{-- TASK-1762 point 1 — la structure, AVANT prenom et nom.

                 Place demandee par Cyril. Sur toute la largeur et non dans la
                 grille a deux colonnes : un nom de structure est long la ou un
                 prenom est court, et le mettre en face du prenom l'aurait
                 tronque des le premier « Association ... ». --}}
            @if (structureIsShown($organization))
            <div data-profile-structure-field>
                <x-input-label for="structure" :value="__('profile.structure')" />
                <x-text-input id="structure" name="structure" type="text" class="mt-1 block w-full"
                              :value="old('structure', $user->structure)"
                              maxlength="255" autocomplete="organization" />
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('profile.structure_help') }}</p>
                <x-input-error class="mt-2" :messages="$errors->get('structure')" />
            </div>
            @endif

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <x-input-label for="first_name" :value="__('profile.first_name')" />
                    <x-text-input id="first_name" name="first_name" type="text" class="mt-1 block w-full" :value="old('first_name', $user->first_name)" autocomplete="given-name" />
                    <x-input-error class="mt-2" :messages="$errors->get('first_name')" />
                </div>

                <div>
                    <x-input-label for="name" :value="__('profile.name')" />
                    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $user->name)" required autofocus autocomplete="family-name" />
                    <x-input-error class="mt-2" :messages="$errors->get('name')" />
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <x-input-label for="email" :value="__('profile.email')" />
                    <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', $user->email)" required autocomplete="username" />
                    <x-input-error class="mt-2" :messages="$errors->get('email')" />

                    @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                        <div>
                            <p class="text-sm mt-2 text-gray-800 dark:text-gray-200">
                                {{ __('Your email address is unverified.') }}

                                <button form="send-verification" class="underline text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 dark:focus:ring-offset-gray-800">
                                    {{ __('Click here to re-send the verification email.') }}
                                </button>
                            </p>

                            @if (session('status') === 'verification-link-sent')
                                <p class="mt-2 font-medium text-sm text-green-600 dark:text-green-400">
                                    {{ __('A new verification link has been sent to your email address.') }}
                                </p>
                            @endif
                        </div>
                    @endif
                </div>

                <div>
                    <x-input-label for="phone" :value="__('profile.phone')" />
                    <x-text-input id="phone" name="phone" type="text" class="mt-1 block w-full" :value="old('phone', $user->phone)" required placeholder="{{ __('profile.phone_placeholder') }}" autocomplete="tel" />
                    <p class="mt-1 text-xs text-gray-500">{{ __('profile.phone_help') }}</p>
                    <x-input-error class="mt-2" :messages="$errors->get('phone')" />
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <x-input-label for="city" :value="__('profile.city')" />
                    <x-text-input id="city" name="city" type="text" class="mt-1 block w-full" :value="old('city', $user->city)" autocomplete="address-level2" />
                    <x-input-error class="mt-2" :messages="$errors->get('city')" />
                </div>

                <div>
                    <x-input-label for="country_code" :value="__('profile.country')" />
                    <select id="country_code" name="country_code" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm" autocomplete="country">
                        <option value="">{{ __('profile.country_placeholder') }}</option>
                        @foreach ($countries as $country)
                            <option value="{{ $country->code }}" @selected(old('country_code', $user->country_code) === $country->code)>
                                {{ $country->getLocalizedName() }}
                            </option>
                        @endforeach
                    </select>
                    <x-input-error class="mt-2" :messages="$errors->get('country_code')" />
                </div>
            </div>

            {{-- TASK-1762 point 16 — le champ d'appartenance ne figurait pas
                 dans la liste de Cyril ; il EXISTE et reste donc ici, avec les
                 autres informations d'identite. Toujours conditionne au
                 reglage « Activer le champ d'appartenance ». --}}
            @if ($organization?->membership_enabled)
            <div class="md:w-1/2" data-profile-membership-field>
                <x-input-label for="membership_value" :value="app()->getLocale() === 'en' ? ($organization->membership_label_en ?: __('profile.membership')) : ($organization->membership_label_fr ?: __('profile.membership'))" />
                <x-text-input id="membership_value" name="membership_value" type="text" class="mt-1 block w-full" :value="old('membership_value', $user->membership_value)" />
                <x-input-error class="mt-2" :messages="$errors->get('membership_value')" />
            </div>
            @endif

        </div>
        </div>

        {{-- Second bloc : fond "Surface douce" du theme, pour que la
             separation entre les deux blocs se voie sans etre massive. --}}
        <div class="p-4 sm:p-8 bg-[var(--bp-surface-soft)] shadow sm:rounded-lg" data-profile-block="presentation">
        <div class="max-w-xl space-y-6">

            <div>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 uppercase tracking-wider">{{ __('profile.presentation_section') }}</h3>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('profile.presentation_description') }}</p>
            </div>

            {{-- TASK-1762 point 16 — la presentation passe en editeur riche.

                 `x-markdown-wysiwyg-editor` est le MEME composant que
                 `services/create` : une seconde barre d'outils ecrite a la main
                 aurait diverge de celle-la a la premiere retouche.

                 `tall` et `fullscreen` sont des attributs ADDITIFS du
                 composant — `services/create`, qui ne les passe pas, rend
                 exactement comme avant.

                 **Consequence assumee** : la presentation devient du Markdown.
                 Les trois surfaces qui l'affichent la rendent donc par
                 `markdown()`, sinon les membres liraient `**gras**` en clair.
                 La limite passe de 500 a 2000 caracteres : le balisage est
                 compte dedans. --}}
            <div>
                <x-input-label for="bio" :value="__('profile.bio')" />
                <div class="mt-1">
                    <x-markdown-wysiwyg-editor name="bio" :value="old('bio', $user->bio)"
                                               :placeholder="__('profile.bio_placeholder')"
                                               rows="8" tall fullscreen
                                               :invalid="$errors->has('bio')" />
                </div>
                <x-input-error class="mt-2" :messages="$errors->get('bio')" />
            </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <x-input-label for="website" :value="__('profile.website')" />
                <div class="mt-1 flex rounded-md shadow-sm">
                    <span class="inline-flex items-center px-3 rounded-l-md border border-r-0 border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-500 text-sm">🌐</span>
                    <x-text-input id="website" name="website" type="url" class="rounded-l-none block w-full"
                        :value="old('website', $user->website)"
                        placeholder="https://monsite.fr" />
                </div>
                <x-input-error class="mt-2" :messages="$errors->get('website')" />
            </div>

            <div>
                <x-input-label for="linkedin_url" :value="__('profile.linkedin')" />
                <div class="mt-1 flex rounded-md shadow-sm">
                    <span class="inline-flex items-center px-3 rounded-l-md border border-r-0 border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-500 text-sm">in</span>
                    <x-text-input id="linkedin_url" name="linkedin_url" type="url" class="rounded-l-none block w-full"
                        :value="old('linkedin_url', $user->linkedin_url)"
                        placeholder="https://linkedin.com/in/votre-profil" />
                </div>
                <x-input-error class="mt-2" :messages="$errors->get('linkedin_url')" />
            </div>
        </div>

        {{-- TASK-1750 — « Autres liens », EN DESSOUS des deux champs ci-dessus.

            Les deux champs du haut restent les liens mis en avant : ce bloc les
            complete, il ne les remplace pas.

            Alpine porte l'ajout et le retrait de lignes. Il EST disponible
            ici — c'est ce qui fait marcher la modale photo de T1749 — a ne pas
            confondre avec `layouts/guest`, qui ne l'embarque pas.

            L'index de chaque ligne vient d'un compteur PROPRE (`suivant`) et
            non de la position dans le tableau : apres un retrait, reutiliser la
            position ferait porter a deux lignes le meme `name`, et la seconde
            ecraserait la premiere a l'envoi.
        --}}
        <div class="mt-6"
             x-data="{
                liens: @js(old('profile_links', $user->liensDeProfil())),
                suivant: {{ count(old('profile_links', $user->liensDeProfil())) }},
                ajouter() {
                    if (this.liens.length >= {{ \App\Support\ProfileLinks::MAXIMUM }}) return;
                    this.liens.push({ type: '{{ \App\Support\ProfileLinks::clefs()[0] }}', url: '', _k: this.suivant++ });
                },
                retirer(i) { this.liens.splice(i, 1); },
             }"
             data-profile-links>
            <div class="flex items-center justify-between">
                <x-input-label :value="__('profile.links_heading')" />
                <span class="text-xs text-gray-500 dark:text-gray-400" x-text="liens.length + ' / {{ \App\Support\ProfileLinks::MAXIMUM }}'"></span>
            </div>

            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('profile.links_help') }}</p>

            <template x-for="(lien, i) in liens" :key="lien._k ?? i">
                <div class="mt-2 flex items-start gap-2" data-profile-link-row>
                    <select :name="'profile_links[' + i + '][type]'" x-model="lien.type"
                            class="w-40 shrink-0 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 text-sm">
                        @foreach(\App\Support\ProfileLinks::types() as $clef => $type)
                            <option value="{{ $clef }}">{{ __($type['label']) }}</option>
                        @endforeach
                    </select>

                    <div class="min-w-0 flex-1">
                        <input type="url" :name="'profile_links[' + i + '][url]'" x-model="lien.url"
                               placeholder="https://..."
                               class="block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 text-sm" />
                    </div>

                    <button type="button" @click="retirer(i)" data-profile-link-remove
                            class="mt-1 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-gray-400 transition hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/30"
                            :aria-label="'{{ __('profile.links_remove') }}'">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </template>

            <button type="button" @click="ajouter()" data-profile-link-add
                    x-show="liens.length < {{ \App\Support\ProfileLinks::MAXIMUM }}"
                    class="mt-3 inline-flex items-center gap-1.5 rounded-lg border border-dashed border-gray-300 px-3 py-1.5 text-sm text-gray-600 transition hover:border-indigo-400 hover:text-indigo-600 dark:border-gray-600 dark:text-gray-300">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                {{ __('profile.links_add') }}
            </button>

            <x-input-error class="mt-2" :messages="$errors->get('profile_links')" />
            @foreach($errors->get('profile_links.*.url') as $messages)
                <x-input-error class="mt-2" :messages="$messages" />
            @endforeach
        </div>

            {{-- TASK-1693 bug Z — l'adresse publique, choisie par la personne.

                 Derivee de « prenom-nom » a l'inscription, modifiable ensuite.
                 La laisser au choix plutot que de l'imposer repond aussi a une
                 question de discretion : qui tient a la sienne prend un
                 pseudonyme, qui veut etre trouve prend son nom. --}}
            <div class="mt-6" data-profile-slug-field>
                {{-- TASK-1762 point 4 bis — l'adresse ACTUELLE, a droite du libelle.

                     Demande de Cyril : « ainsi les gens comprennent plus
                     facilement qu'ils ont interet a personnaliser ». Qui n'a
                     rien choisi lit son identifiant technique en toutes
                     lettres, et l'argument se passe de phrase.

                     `profileRouteKey()` et non `$user->slug` : c'est l'adresse
                     qui SERT aujourd'hui — le slug choisi, ou l'identifiant a
                     defaut. Afficher le slug seul laisserait un « Actuellement :
                     …/profile/ » vide a ceux-la memes qu'il s'agit de
                     convaincre.

                     `break-all` : un UUID fait 36 caracteres et deborderait du
                     telephone. `flex-wrap` le fait passer sous le libelle quand
                     la place manque, au lieu de le serrer. --}}
                <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                    {{-- TASK-1762 point 16 — « Adresse de votre profil sur <Organization> ».
                     Le nom du tenant retire l'ambiguite : cette adresse vaut ICI,
                     pas sur la plateforme entiere. Repli sur l'ancien libelle
                     quand il n'y a pas d'Organization resolue. --}}
                <x-input-label for="slug" :value="$organization ? __('profile.slug_on', ['organization' => $organization->name]) : __('profile.slug')" />
                    <span class="break-all text-xs text-gray-500 dark:text-gray-400" data-profile-slug-current>
                        {{ __('profile.slug_current') }}
                        {{-- L'adresse en « Accent / Loop » (`--bp-accent`), la couleur
                             du theme choisi par l'Organization — #8A2CFF sur Zen.
                             Ecrire `#8A2CFF` en dur aurait fige la couleur d'UN theme
                             sur tous les autres.

                             Seule l'ADRESSE est coloree : « Actuellement : » reste
                             gris, pour que l'oeil tombe sur la valeur et non sur le
                             mot qui l'introduit. --}}
                        <span class="font-mono font-semibold text-[var(--bp-accent)]">{{ __('profile.slug_prefix') }}{{ $user->profileRouteKey() }}</span>
                    </span>
                </div>
                <div class="mt-1 flex items-center gap-1">
                    <span class="shrink-0 text-sm text-gray-400">{{ __('profile.slug_prefix') }}</span>
                    <x-text-input id="slug" name="slug" type="text" class="block w-full"
                                  :value="old('slug', $user->slug)"
                                  maxlength="60" autocomplete="off" />
                </div>
                <x-input-error class="mt-2" :messages="$errors->get('slug')" />
                {{-- TASK-1762 point 4 — une seule phrase, avec de VRAIES adresses.

                     Les trois phrases precedentes recitaient la regle de forme
                     (« lettres minuscules, chiffres et tirets, entre 3 et 60
                     caracteres ») et la politique de conservation des anciennes
                     adresses. Cyril : « Dire juste choisissez une adresse qui
                     vous represente, par exemple ... ».

                     La regle de forme n'est pas perdue : elle a rejoint le
                     message d'ERREUR, c'est-a-dire le seul moment ou elle sert.
                     L'afficher en permanence faisait porter a tout le monde le
                     prix d'un cas rare.

                     Les exemples sont construits sur l'adresse REELLE de
                     l'Organization : ecrits en dur, ils auraient menti partout
                     ailleurs que sur `main`. --}}
                @php
                    $_exempleSlugComplet = ($_slugOrg = $organization?->slug) && Route::has('organization.profile.show')
                        ? route('organization.profile.show', ['organization' => $_slugOrg, 'user' => 'ofsh'])
                        : route('profile.show', 'ofsh');
                    $_exempleSlugCourt = ($_slugOrg && Route::has('organization.profile.show'))
                        ? '/org/'.$_slugOrg.'/profile/camille'
                        : '/profile/camille';
                @endphp
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" data-profile-slug-help>
                    {{ __('profile.slug_help', ['exemple' => $_exempleSlugComplet, 'exemple_court' => $_exempleSlugCourt]) }}
                </p>
            </div>

        <div class="p-4 bg-gray-50 dark:bg-gray-900/50 rounded-xl space-y-3 border border-gray-200 dark:border-gray-700">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 uppercase tracking-wider">{{ __('profile.privacy') }}</h3>

            <div class="flex items-center gap-3">
                <input id="show_email" name="show_email" type="checkbox" value="1"
                    {{ old('show_email', $user->show_email) ? 'checked' : '' }}
                    class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 dark:bg-gray-800">
                <x-input-label for="show_email" :value="__('profile.show_email')" class="font-normal text-gray-700 dark:text-gray-300" />
            </div>

            <div class="flex items-center gap-3">
                <input id="show_phone" name="show_phone" type="checkbox" value="1"
                    {{ old('show_phone', $user->show_phone) ? 'checked' : '' }}
                    class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 dark:bg-gray-800">
                <x-input-label for="show_phone" :value="__('profile.show_phone')" class="font-normal text-gray-700 dark:text-gray-300" />
            </div>
        </div>

            {{-- TASK-1762 point 16 — « Langue principale » devient sa propre
                 section, et elle disparait sur un site MONOLINGUE.

                 Cyril : « si c'est décoché, donc le site web n'a qu'une langue,
                 donc logique de ne pas afficher Langue principale ». Proposer
                 un choix qui n'en est pas un est pire que ne rien proposer.

                 La garde vaut aussi au SERVEUR : voir `ProfileController`. --}}
            @if($organization?->multilingual_enabled)
            <div class="space-y-6 border-t border-gray-200 dark:border-gray-700 pt-6" data-profile-locale-section>
                <div>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 uppercase tracking-wider">{{ __('profile.locale_section') }}</h3>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('profile.locale_description') }}</p>
                </div>

                <div class="md:w-1/2">
                    <x-input-label for="preferred_locale" :value="__('profile.preferred_locale')" />
                    <select id="preferred_locale" name="preferred_locale" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm">
                        <option value="">{{ __('profile.preferred_locale_placeholder') }}</option>
                        <option value="fr" @selected(old('preferred_locale', $user->preferred_locale) === 'fr')>{{ __('profile.locale_fr') }}</option>
                        <option value="en" @selected(old('preferred_locale', $user->preferred_locale) === 'en')>{{ __('profile.locale_en') }}</option>
                    </select>
                    <x-input-error class="mt-2" :messages="$errors->get('preferred_locale')" />
                </div>
            </div>
            @endif

        {{-- TASK-1762 point 15 — la section de facturation n'existe que si
             l'Organization propose des abonnements.

             Cyril : « si on ne propose pas d'abonnement, pas la peine de parler
             de facturation, ça fait peur aux utilisateurs. Puis ainsi on évite
             de recueillir des coordonnées pour rien. »

             La garde vaut aussi au SERVEUR (`ProfileController::update`) :
             masquer trois champs ne garde rien, un `PUT` forge les ecrirait. --}}
        @if(billingIsCollected($organization))
        <div class="space-y-6 border-t border-gray-200 dark:border-gray-700 pt-6" data-profile-billing-section>
            <div>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 uppercase tracking-wider">{{ __('profile.private_billing_section') }}</h3>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('profile.private_billing_description') }}</p>
            </div>

            <div>
                <x-input-label for="address_line1" :value="__('profile.address_line1')" />
                <x-text-input id="address_line1" name="address_line1" type="text" class="mt-1 block w-full" :value="old('address_line1', $user->address_line1)" autocomplete="address-line1" />
                <x-input-error class="mt-2" :messages="$errors->get('address_line1')" />
            </div>

            <div>
                <x-input-label for="address_line2" :value="__('profile.address_line2')" />
                <x-text-input id="address_line2" name="address_line2" type="text" class="mt-1 block w-full" :value="old('address_line2', $user->address_line2)" autocomplete="address-line2" />
                <x-input-error class="mt-2" :messages="$errors->get('address_line2')" />
            </div>

            <div>
                <x-input-label for="postal_code" :value="__('profile.postal_code')" />
                <x-text-input id="postal_code" name="postal_code" type="text" class="mt-1 block w-full" :value="old('postal_code', $user->postal_code)" autocomplete="postal-code" />
                <x-input-error class="mt-2" :messages="$errors->get('postal_code')" />
            </div>
        </div>
        @endif

        {{-- TASK-1694 bug AE — cinquieme surface, trouvee a l'inventaire : le
             reglage de son propre profil. Le retirer avec la fonctionnalite evite
             de laisser quelqu'un regler une valeur que plus aucun ecran n'affiche.

             Aucun risque de perte de donnee en la masquant, et pour une raison
             qui merite d'etre ecrite : cette case etait DEJA INERTE. Mesure du
             24/10 — `is_available` n'est ni dans les regles de
             `ProfileController::update()`, ni dans son traitement des cases a
             cocher. Elle AFFICHAIT la valeur, l'enregistrement ne la touchait
             jamais. Seule la bascule du menu (`profile.availability`) la change.

             Defaut prexistant, signale a Cyril plutot que corrige ici : une case
             qui ressemble a un reglage et n'enregistre rien est un autre geste
             que celui demande. --}}
        @if(availabilityIsShown())
        <div class="flex items-center gap-3" data-availability-field>
            <input id="is_available" name="is_available" type="checkbox" value="1"
                {{ $user->is_available ? 'checked' : '' }}
                class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
            <x-input-label for="is_available" :value="__('profile.available_label')" />
        </div>
        @endif

        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('profile.save') }}</x-primary-button>

            @if (session('status') === 'profile-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2000)"
                    class="text-sm text-gray-600 dark:text-gray-400"
                >{{ __('profile.saved') }}</p>
            @endif
        </div>
        </div>
        </div>
    </form>
</section>
