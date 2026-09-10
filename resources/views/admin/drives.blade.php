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
                            <td class="px-4 py-3 text-gray-900 dark:text-gray-100 font-medium" data-title data-label="{{ __('drives.col_file') }}">{{ $file['name'] }}</td>
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
                                @if($file['state'] !== \App\Services\Dossiers\OrganizationFileInventory::STATE_NOT_INGESTIBLE)
                                    <form method="POST" action="{{ route('admin.drives.reindex', ['organization' => $file['organization_slug'], 'file' => $file['id']]) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="text-indigo-600 dark:text-indigo-400 hover:underline text-sm" data-drives-reindex>{{ __('drives.action_reindex') }}</button>
                                    </form>
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
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
</x-admin-layout>
