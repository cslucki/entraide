<x-admin-layout :title="__('drives.platform_title')">
    {{-- TASK-1514 — les fichiers de TOUTES les Organizations.

         Aucun `var(--bp-*)` ici : `layouts/admin` n'emet AUCUN jeton de theme
         (mesure de TASK-1506 — bouton blanc sur transparent, bordure noire).
         La palette du superadmin, c'est `indigo`. Un test l'interdit. --}}
    @php
        // Pas de `use` dans un bloc @php : Blade le compile dans le corps de la
        // fonction de rendu, ou PHP interdit une importation (TASK-1506).
        $stateStyles = [
            \App\Services\Dossiers\OrganizationFileInventory::STATE_INDEXED => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-200',
            \App\Services\Dossiers\OrganizationFileInventory::STATE_NOT_INDEXED => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
            \App\Services\Dossiers\OrganizationFileInventory::STATE_NOT_INGESTIBLE => 'bg-slate-100 text-slate-600 dark:bg-slate-700/60 dark:text-slate-300',
        ];

        $humanSize = static function (int $bytes): string {
            if ($bytes < 1024) {
                return $bytes.' o';
            }

            $value = $bytes / 1024;

            foreach (['Ko', 'Mo', 'Go'] as $unit) {
                if ($value < 1024 || $unit === 'Go') {
                    return number_format($value, $value < 10 ? 1 : 0, ',', ' ').' '.$unit;
                }

                $value /= 1024;
            }

            return $bytes.' o';
        };

        $format = static function (string $mime, string $name): string {
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            return $extension !== '' ? strtoupper($extension) : strtoupper(explode('/', $mime)[1] ?? $mime);
        };
    @endphp

    {{-- TASK-1515 — la premiere brique cliente de cette page. `layouts/admin`
         charge Alpine via `@livewireScripts`, et `@stack('scripts')` est empile
         JUSTE AVANT : notre ecouteur `alpine:init` est donc enregistre avant
         qu'Alpine ne demarre. Sans cette racine `x-data`, tout `@click` plus
         bas serait inerte et SILENCIEUX (mesure de TASK-1509). --}}
    <div x-data="drivesConsole({
            chunksUrlTemplate: '{{ route('admin.drives.chunks', ['organization' => '__ORG__', 'file' => '__ID__']) }}'
         })" @click="handleClick($event)">

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('drives.platform_title') }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 max-w-3xl">{{ __('drives.platform_subtitle') }}</p>
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 dark:border-emerald-500/30 bg-emerald-50 dark:bg-emerald-500/10 px-3 py-2 text-sm text-emerald-800 dark:text-emerald-200" data-drives-flash>{{ session('success') }}</div>
    @endif

    @if($unknownOrganization !== null)
        {{-- Un slug inconnu ne doit pas filtrer en silence : sans ce mot,
             l'ecran ressemblerait a une Organization vide. --}}
        <div class="mb-4 rounded-lg border border-amber-200 dark:border-amber-500/30 bg-amber-50 dark:bg-amber-500/10 px-3 py-2 text-sm text-amber-800 dark:text-amber-200" data-drives-unknown-organization>
            {{ __('drives.unknown_organization', ['slug' => $unknownOrganization]) }}
        </div>
    @endif

    <form method="GET" class="mb-5 flex flex-wrap gap-3" data-drives-filters>
        <select name="organization" aria-label="{{ __('drives.filter_organization') }}"
                class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm"
                data-drives-organization-filter>
            <option value="">{{ __('drives.filter_organization_all') }}</option>
            @foreach($organizations as $organization)
                <option value="{{ $organization->slug }}" @selected($selected !== null && $selected->slug === $organization->slug)>{{ $organization->name }}</option>
            @endforeach
        </select>

        <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="{{ __('drives.search_placeholder') }}"
               aria-label="{{ __('drives.search') }}"
               class="flex-1 min-w-48 px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">

        <select name="state" aria-label="{{ __('drives.filter_state') }}"
                class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
            <option value="">{{ __('drives.filter_state') }} — {{ __('drives.filter_all') }}</option>
            @foreach($states as $state)
                <option value="{{ $state }}" @selected($filters['state'] === $state)>{{ __('drives.state_'.$state) }}</option>
            @endforeach
        </select>

        <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm min-h-[40px]">{{ __('drives.filter_apply') }}</button>

        @if($filters['search'] !== '' || $filters['state'] !== '' || $selected !== null)
            <a href="{{ route('admin.drives') }}"
               class="inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-600 dark:text-gray-400 min-h-[40px]">{{ __('drives.filter_reset') }}</a>
        @endif
    </form>

    <p class="mb-3 text-xs text-gray-500 dark:text-gray-400" data-drives-total>{{ number_format($files->total(), 0, ',', ' ') }} {{ __('drives.count_total') }}</p>

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <x-admin-table>
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('drives.col_file') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('drives.col_organization') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('drives.col_dossier') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('drives.col_format') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('drives.col_size') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('drives.col_created') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('drives.col_state') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('drives.col_chunks') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('drives.col_actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($files as $file)
                        <tr data-drives-row="{{ $file['id'] }}" data-state="{{ $file['state'] }}" data-organization="{{ $file['organization_slug'] }}">
                            {{-- Le nom est l'identite du fichier : il se renvoie a la ligne, il ne se
                                 tronque JAMAIS. Sans cette borne, une poignee de noms longs pousse
                                 la table a 1221px dans 1135px et met la colonne Actions hors ecran
                                 a 1440 (mesure du 10/09). --}}
                            <td class="px-4 py-3 text-gray-900 dark:text-gray-100 font-medium max-w-[21rem] break-words" data-title data-label="{{ __('drives.col_file') }}">{{ $file['name'] }}</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300" data-label="{{ __('drives.col_organization') }}">{{ $file['organization_name'] }}</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300" data-label="{{ __('drives.col_dossier') }}">{{ $file['dossier_name'] }}</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300" data-label="{{ __('drives.col_format') }}">{{ $format($file['mime_type'], $file['original_name']) }}</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300" data-label="{{ __('drives.col_size') }}">{{ $humanSize($file['size_bytes']) }}</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300" data-label="{{ __('drives.col_created') }}">{{ $file['created_at'] ? \Carbon\CarbonImmutable::parse($file['created_at'])->translatedFormat('d/m/Y') : '—' }}</td>
                            <td class="px-4 py-3" data-label="{{ __('drives.col_state') }}">
                                <span class="inline-block rounded-full px-2 py-0.5 text-xs font-medium {{ $stateStyles[$file['state']] }}"
                                      title="{{ __('drives.state_'.$file['state'].'_hint') }}"
                                      data-drives-state="{{ $file['state'] }}">{{ __('drives.state_'.$file['state']) }}</span>
                            </td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300" data-label="{{ __('drives.col_chunks') }}">{{ $file['chunks'] }}</td>
                            <td class="px-4 py-3" data-actions data-label="{{ __('drives.col_actions') }}">
                                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                    {{-- « Voir extraits » n'existe que s'il Y A des extraits : un
                                         tiroir vide ne renseigne personne et laisse croire a une panne. --}}
                                    @if($file['chunks'] > 0)
                                        <button type="button"
                                                class="text-indigo-600 dark:text-indigo-400 hover:underline text-sm"
                                                data-drives-inspect
                                                data-drives-organization="{{ $file['organization_slug'] }}"
                                                data-drives-file="{{ $file['id'] }}">{{ __('drives.action_view_chunks') }}</button>
                                    @endif

                                    @if($file['state'] !== \App\Services\Dossiers\OrganizationFileInventory::STATE_NOT_INGESTIBLE)
                                        <form method="POST" action="{{ route('admin.drives.reindex', ['organization' => $file['organization_slug'], 'file' => $file['id']]) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="text-indigo-600 dark:text-indigo-400 hover:underline text-sm" data-drives-reindex>{{ __('drives.action_reindex') }}</button>
                                        </form>
                                    @endif

                                    {{-- Destructif : `type="button"`, et le bouton de SOUMISSION vit
                                         dans la fenetre de confirmation. Sans JavaScript, ce bouton ne
                                         fait rien — c'est le repli voulu : mieux vaut une action
                                         indisponible qu'un fichier efface par accident. --}}
                                    <button type="button"
                                            class="text-red-600 dark:text-red-400 hover:underline text-sm"
                                            data-drives-delete
                                            data-drives-delete-name="{{ $file['name'] }}"
                                            data-drives-delete-action="{{ route('admin.drives.destroy', ['organization' => $file['organization_slug'], 'file' => $file['id']]) }}">{{ __('drives.action_delete') }}</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-10 text-center text-sm text-gray-400" data-empty data-drives-empty>{{ __('drives.empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </x-admin-table>
    </div>

    <div class="mt-4" data-drives-pagination>{{ $files->links() }}</div>

    {{-- Tiroir « Voir extraits » : le fragment vient du serveur, deja echappe
         par Blade, et ne contient jamais de vecteur embedding. --}}
    <div x-show="inspectOpen" x-cloak
         class="fixed inset-0 z-50 flex items-start justify-end bg-gray-900/40 p-4 sm:p-6"
         @click.self="inspectOpen = false" @keydown.escape.window="inspectOpen = false"
         data-drives-drawer>
        <div class="flex h-full w-full max-w-lg flex-col overflow-hidden rounded-xl bg-white shadow-xl dark:bg-gray-800">
            <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('drives.inspect_title') }}</h2>
                <button type="button" @click="inspectOpen = false" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200" aria-label="{{ __('drives.inspect_close') }}">&times;</button>
            </div>
            <div class="flex-1 overflow-y-auto px-4 py-4">
                <p x-show="inspectLoading" class="text-sm text-gray-500 dark:text-gray-400">{{ __('drives.inspect_loading') }}</p>
                <div x-show="!inspectLoading" x-html="inspectHtml" data-drives-drawer-body></div>
            </div>
        </div>
    </div>

    {{-- Confirmation de suppression. Elle NOMME le fichier : c'est la seule
         chose qui distingue la ligne voulue de sa voisine. Le formulaire est
         ici, et nulle part ailleurs. --}}
    <div x-show="deleteOpen" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4"
         @click.self="deleteOpen = false" @keydown.escape.window="deleteOpen = false"
         data-drives-delete-dialog>
        <div class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl dark:bg-gray-800" role="dialog" aria-modal="true">
            <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ __('drives.delete_title') }}</h2>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300" data-drives-delete-body x-text="deleteBody"></p>
            <form method="POST" :action="deleteAction" class="mt-5 flex flex-wrap justify-end gap-3">
                @csrf
                @method('DELETE')
                <button type="button" @click="deleteOpen = false"
                        class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-600 dark:text-gray-300 min-h-[40px]">{{ __('drives.delete_cancel') }}</button>
                <button type="submit"
                        class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm min-h-[40px]"
                        data-drives-delete-submit>{{ __('drives.delete_submit') }}</button>
            </form>
        </div>
    </div>

    </div>{{-- /racine Alpine --}}

    @push('scripts')
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('drivesConsole', (config) => ({
                chunksUrlTemplate: config.chunksUrlTemplate || '',
                deleteBodyTemplate: @json(__('drives.delete_body', ['name' => '__NAME__'])),

                inspectOpen: false,
                inspectLoading: false,
                inspectHtml: '',

                deleteOpen: false,
                deleteAction: '',
                deleteBody: '',

                // Delegation : la table est paginee et rerendue par le
                // serveur, aucun ecouteur n'est pose ligne par ligne.
                handleClick(event) {
                    const inspect = event.target.closest('[data-drives-inspect]');
                    if (inspect) {
                        this.openInspect(inspect.dataset.drivesOrganization, inspect.dataset.drivesFile);
                        return;
                    }

                    const remove = event.target.closest('[data-drives-delete]');
                    if (remove) {
                        this.askDelete(remove.dataset.drivesDeleteAction, remove.dataset.drivesDeleteName);
                    }
                },

                async openInspect(organization, fileId) {
                    this.inspectOpen = true;
                    this.inspectLoading = true;
                    this.inspectHtml = '';
                    try {
                        const url = this.chunksUrlTemplate
                            .replace('__ORG__', encodeURIComponent(organization))
                            .replace('__ID__', encodeURIComponent(fileId));
                        const response = await fetch(url, {
                            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' },
                            credentials: 'same-origin',
                        });
                        this.inspectHtml = response.ok ? await response.text() : '';
                    } catch (error) {
                        this.inspectHtml = '';
                    } finally {
                        this.inspectLoading = false;
                    }
                },

                askDelete(action, name) {
                    this.deleteAction = action || '';
                    this.deleteBody = this.deleteBodyTemplate.replace('__NAME__', name || '');
                    this.deleteOpen = true;
                },
            }));
        });
    </script>
    @endpush
</x-admin-layout>
