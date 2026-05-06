<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            Informations du profil
        </h2>

        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            Mettez à jour les informations de votre compte et votre adresse e-mail.
        </p>
    </header>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form method="post" action="{{ route('profile.update') }}" class="mt-6 space-y-6" enctype="multipart/form-data">
        @csrf
        @method('put')

        <div>
            <x-input-label for="name" value="Nom" />
            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $user->name)" required autofocus autocomplete="name" />
            <x-input-error class="mt-2" :messages="$errors->get('name')" />
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <x-input-label for="email" value="Adresse e-mail" />
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
                <x-input-label for="phone" value="Numéro de téléphone *" />
                <x-text-input id="phone" name="phone" type="text" class="mt-1 block w-full" :value="old('phone', $user->phone)" required placeholder="Ex: 06 12 34 56 78" />
                <p class="mt-1 text-xs text-gray-500">Obligatoire pour pouvoir publier des services ou demandes.</p>
                <x-input-error class="mt-2" :messages="$errors->get('phone')" />
            </div>
        </div>

        <div class="p-4 bg-gray-50 dark:bg-gray-900/50 rounded-xl space-y-3 border border-gray-200 dark:border-gray-700">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 uppercase tracking-wider">Confidentialité</h3>

            <div class="flex items-center gap-3">
                <input id="show_email" name="show_email" type="checkbox" value="1"
                    {{ old('show_email', $user->show_email) ? 'checked' : '' }}
                    class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 dark:bg-gray-800">
                <x-input-label for="show_email" value="Afficher mon adresse e-mail sur mon profil public et dans l'annuaire" class="font-normal text-gray-700 dark:text-gray-300" />
            </div>

            <div class="flex items-center gap-3">
                <input id="show_phone" name="show_phone" type="checkbox" value="1"
                    {{ old('show_phone', $user->show_phone) ? 'checked' : '' }}
                    class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 dark:bg-gray-800">
                <x-input-label for="show_phone" value="Afficher mon numéro de téléphone sur mon profil public et dans l'annuaire" class="font-normal text-gray-700 dark:text-gray-300" />
            </div>
        </div>

        <div>
            <x-input-label for="location" value="Localisation (Ville, Département)" />
            <x-text-input id="location" name="location" type="text" class="mt-1 block w-full" :value="old('location', $user->location)" placeholder="Ex: Lyon, Rhône" />
            <x-input-error class="mt-2" :messages="$errors->get('location')" />
        </div>

        <div>
            <x-input-label for="bio" value="Présentation" />
            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Décrivez qui vous êtes, vos expertises, votre parcours. Cette présentation apparaît sur votre profil public et rassure les membres avant de vous contacter.</p>
            <textarea id="bio" name="bio" rows="5" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm" maxlength="500" placeholder="Ex : Consultante RH indépendante depuis 2015, je m'occupe de recrutement, de formation et d'accompagnement managérial pour les PME...">{{ old('bio', $user->bio) }}</textarea>
            <p class="mt-1 text-xs text-gray-400">Max 500 caractères — <span x-data x-text="500 - ($refs.bio?.value?.length ?? 0)" x-ref="bioCount">500</span> restants</p>
            <x-input-error class="mt-2" :messages="$errors->get('bio')" />
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <x-input-label for="website" value="Site web" />
                <div class="mt-1 flex rounded-md shadow-sm">
                    <span class="inline-flex items-center px-3 rounded-l-md border border-r-0 border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-500 text-sm">🌐</span>
                    <x-text-input id="website" name="website" type="url" class="rounded-l-none block w-full"
                        :value="old('website', $user->website)"
                        placeholder="https://monsite.fr" />
                </div>
                <x-input-error class="mt-2" :messages="$errors->get('website')" />
            </div>

            <div>
                <x-input-label for="linkedin_url" value="Profil LinkedIn" />
                <div class="mt-1 flex rounded-md shadow-sm">
                    <span class="inline-flex items-center px-3 rounded-l-md border border-r-0 border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-500 text-sm">in</span>
                    <x-text-input id="linkedin_url" name="linkedin_url" type="url" class="rounded-l-none block w-full"
                        :value="old('linkedin_url', $user->linkedin_url)"
                        placeholder="https://linkedin.com/in/votre-profil" />
                </div>
                <x-input-error class="mt-2" :messages="$errors->get('linkedin_url')" />
            </div>
        </div>

        <div x-data="{ preview: null }">
            <x-input-label for="avatar" value="Avatar" />
            <div class="mt-2 flex items-center gap-4">
                <img :src="preview ?? '{{ $user->avatar_url }}'"
                     class="w-14 h-14 rounded-full object-cover border-2 border-gray-200 dark:border-gray-600" alt="">
                <div class="flex-1">
                    <input id="avatar" name="avatar" type="file" accept="image/*"
                        @change="preview = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : null"
                        class="block w-full text-sm text-gray-600 dark:text-gray-400 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-indigo-50 file:text-indigo-700 dark:file:bg-indigo-900/30 dark:file:text-indigo-300 hover:file:bg-indigo-100">
                    <p class="text-xs text-gray-400 mt-1">JPG, PNG ou GIF — max 2 Mo</p>
                </div>
            </div>
            <x-input-error class="mt-2" :messages="$errors->get('avatar')" />
        </div>

        <div class="flex items-center gap-3">
            <input id="is_available" name="is_available" type="checkbox" value="1"
                {{ $user->is_available ? 'checked' : '' }}
                class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
            <x-input-label for="is_available" value="Je suis disponible pour des échanges" />
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button>Enregistrer</x-primary-button>

            @if (session('status') === 'profile-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2000)"
                    class="text-sm text-gray-600 dark:text-gray-400"
                >Enregistré.</p>
            @endif
        </div>
    </form>
</section>
