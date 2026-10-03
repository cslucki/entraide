{{-- ================================================================
     TASK-1666 — LA FICHE, EN PARTIEL PARTAGE.

     Elle vivait dans `admin/users.blade.php`, a l'interieur du composant
     Alpine `adminUserDelete()`. Deux ecrans en ont desormais besoin
     (`/admin/users` et `/admin/transactions`), et un appel direct
     `openProfile(...)` ne franchit pas la frontiere d'un composant Alpine.

     Elle est donc sa PROPRE racine `x-data`, pilotee par un evenement
     fenetre. N'importe quel bouton, dans n'importe quel composant, l'ouvre
     sans rien savoir d'elle :

         @click="$dispatch('open-user-profile', { id: '...', name: '...' })"

     Rappel du garde-fou : une directive Alpine sans racine `x-data` sur un
     ancetre est inerte ET silencieuse. Ce partiel porte la sienne.
     ================================================================ --}}
<div x-data="adminUserProfileModal()"
     @open-user-profile.window="openProfile($event.detail.id, $event.detail.name)">

    {{-- ================================================================
         TASK-1640 — FICHE MEMBRE, une seule modal pour toute la page.
         Elle rend des COMPTAGES, jamais le contenu lui-meme : un ecran
         d'administration n'a pas a faire passer les messages ni les prompts IA
         d'une personne sous les yeux de l'admin pour repondre a « que fait-elle
         sur la plateforme ». C'est une decision, pas une limite technique.
         ================================================================ --}}
    <div x-show="profileOpen" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4"
         @keydown.escape.window="profileOpen = false">
        <div class="absolute inset-0 bg-black/50" @click="profileOpen = false"></div>

        <div class="relative bg-white dark:bg-gray-800 rounded-2xl shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
            <div class="sticky top-0 bg-white dark:bg-gray-800 border-b border-gray-100 dark:border-gray-700 px-5 sm:px-6 py-4 flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100 truncate"
                        x-text="'{{ __('admin.users_profile_title') }}'.replace(':name', profileName)"></h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 truncate" x-text="profile?.identite?.email ?? ''"></p>
                </div>
                <button type="button" @click="profileOpen = false"
                    class="flex-shrink-0 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 text-sm">
                    {{ __('admin.users_profile_close') }}
                </button>
            </div>

            <div class="px-5 sm:px-6 py-5">
                <template x-if="profileLoading">
                    <p class="text-sm text-gray-500 dark:text-gray-400 py-6">{{ __('admin.users_profile_loading') }}</p>
                </template>

                <template x-if="! profileLoading && profileError">
                    <p class="text-sm text-red-600 py-6">{{ __('admin.users_profile_error') }}</p>
                </template>

                <template x-if="! profileLoading && ! profileError && profile">
                    <div class="space-y-6">
                        {{-- Identite --}}
                        <div>
                            <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-400 mb-2">{{ __('admin.users_profile_section_identity') }}</h4>
                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                                <div class="rounded-lg bg-gray-50 dark:bg-gray-900/40 px-3 py-2">
                                    <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('admin.users_profile_org') }}</p>
                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate"
                                       x-text="profile.identite.organization ?? '{{ __('admin.users_profile_org_none') }}'"></p>
                                </div>
                                <div class="rounded-lg bg-gray-50 dark:bg-gray-900/40 px-3 py-2">
                                    <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('admin.users_status_label') }}</p>
                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100" x-text="profile.identite.statut"></p>
                                </div>
                                <div class="rounded-lg bg-gray-50 dark:bg-gray-900/40 px-3 py-2">
                                    <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('admin.users_profile_points') }}</p>
                                    <p class="text-sm font-semibold tabular-nums text-gray-900 dark:text-gray-100" x-text="profile.identite.points"></p>
                                </div>
                                <div class="rounded-lg bg-gray-50 dark:bg-gray-900/40 px-3 py-2">
                                    <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('admin.users_profile_registered') }}</p>
                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100" x-text="profile.identite.inscrit_le"></p>
                                </div>
                                <div class="rounded-lg bg-gray-50 dark:bg-gray-900/40 px-3 py-2">
                                    <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('admin.users_profile_last_login') }}</p>
                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100"
                                       x-text="profile.identite.derniere_connexion ?? '{{ __('admin.users_profile_never') }}'"></p>
                                </div>
                                <div class="rounded-lg bg-gray-50 dark:bg-gray-900/40 px-3 py-2">
                                    <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('admin.users_profile_rating') }}</p>
                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100" x-text="profile.identite.note ?? '—'"></p>
                                </div>
                            </div>
                        </div>

                        {{-- Contributions et interactions : meme forme, deux sources. --}}
                        <template x-for="section in [
                            { titre: '{{ __('admin.users_profile_section_contributions') }}', data: profile.contributions },
                            { titre: '{{ __('admin.users_profile_section_interactions') }}', data: profile.interactions }
                        ]" :key="section.titre">
                            <div>
                                <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-400 mb-2" x-text="section.titre"></h4>
                                <div class="rounded-xl border border-gray-100 dark:border-gray-700 divide-y divide-gray-100 dark:divide-gray-700">
                                    <template x-for="[libelle, valeur] in Object.entries(section.data)" :key="libelle">
                                        <div class="flex items-center justify-between px-3 py-2">
                                            <span class="text-sm text-gray-600 dark:text-gray-300" x-text="libelle"></span>
                                            <span class="text-sm font-semibold tabular-nums"
                                                  :class="valeur > 0 ? 'text-gray-900 dark:text-gray-100' : 'text-gray-300 dark:text-gray-600'"
                                                  x-text="valeur"></span>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>

                        {{-- IA --}}
                        <div>
                            <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-400 mb-2">{{ __('admin.users_profile_section_ai') }}</h4>
                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-3">
                                <div class="rounded-lg border border-indigo-100 dark:border-indigo-900/50 bg-indigo-50/60 dark:bg-indigo-900/20 px-3 py-2">
                                    <p class="text-[11px] text-indigo-700 dark:text-indigo-300">{{ __('admin.users_profile_ai_calls') }}</p>
                                    <p class="text-lg font-bold tabular-nums text-indigo-700 dark:text-indigo-300" x-text="profile.ia.appels"></p>
                                </div>
                                <div class="rounded-lg border border-indigo-100 dark:border-indigo-900/50 bg-indigo-50/60 dark:bg-indigo-900/20 px-3 py-2">
                                    <p class="text-[11px] text-indigo-700 dark:text-indigo-300">{{ __('admin.users_profile_ai_tokens') }}</p>
                                    <p class="text-lg font-bold tabular-nums text-indigo-700 dark:text-indigo-300"
                                       x-text="new Intl.NumberFormat('{{ app()->getLocale() }}').format(profile.ia.tokens)"></p>
                                </div>
                                <div class="rounded-lg border border-indigo-100 dark:border-indigo-900/50 bg-indigo-50/60 dark:bg-indigo-900/20 px-3 py-2">
                                    <p class="text-[11px] text-indigo-700 dark:text-indigo-300">{{ __('admin.users_profile_ai_cost') }}</p>
                                    <p class="text-lg font-bold tabular-nums text-indigo-700 dark:text-indigo-300" x-text="profile.ia.cout"></p>
                                </div>
                            </div>
                            <div class="rounded-xl border border-gray-100 dark:border-gray-700 divide-y divide-gray-100 dark:divide-gray-700">
                                <template x-for="[libelle, valeur] in [
                                    ['{{ __('admin.users_profile_ai_interactions') }}', profile.ia.interactions],
                                    ['{{ __('admin.users_profile_ai_shell') }}', profile.ia.shell],
                                    ['{{ __('admin.users_profile_ai_feedback') }}', profile.ia.retours]
                                ]" :key="libelle">
                                    <div class="flex items-center justify-between px-3 py-2">
                                        <span class="text-sm text-gray-600 dark:text-gray-300" x-text="libelle"></span>
                                        <span class="text-sm font-semibold tabular-nums"
                                              :class="valeur > 0 ? 'text-gray-900 dark:text-gray-100' : 'text-gray-300 dark:text-gray-600'"
                                              x-text="valeur"></span>
                                    </div>
                                </template>
                                <div class="flex items-center justify-between px-3 py-2">
                                    <span class="text-sm text-gray-600 dark:text-gray-300">{{ __('admin.users_profile_ai_profile_label') }}</span>
                                    <span class="text-xs font-medium px-2 py-0.5 rounded-full"
                                          :class="profile.ia.profil_ia ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400'"
                                          x-text="profile.ia.profil_ia ? '{{ __('admin.users_profile_ai_profile_yes') }}' : '{{ __('admin.users_profile_ai_profile_no') }}'"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    function adminUserProfileModal() {
        return {
            profileOpen: false,
            profileLoading: false,
            profileError: false,
            profileName: '',
            profile: null,

            async openProfile(id, name) {
                this.profileOpen = true;
                this.profileLoading = true;
                this.profileError = false;
                this.profileName = name;
                // Remis a null a CHAQUE ouverture : sinon la fiche afficherait un
                // instant les chiffres du membre precedent, ce qui est pire qu'un
                // ecran vide sur un ecran d'administration.
                this.profile = null;

                try {
                    const response = await fetch(
                        '{{ route('admin.users.profile-summary', ['user' => '__ID__']) }}'.replace('__ID__', id),
                        { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } }
                    );

                    if (! response.ok) { throw new Error(response.status); }

                    this.profile = await response.json();
                } catch (e) {
                    this.profileError = true;
                } finally {
                    this.profileLoading = false;
                }
            },
        };
    }
</script>
@endpush
