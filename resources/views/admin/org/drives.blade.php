<x-org-admin-layout :title="__('drives.title')" :organization="$organization">
    {{-- TASK-1513 — la supervision des fichiers de l'Organization.
         Elle existe parce que cette liste vivait dans « IA & connaissances »,
         ou elle n'est qu'un sous-produit : cette console repond a « qu'est-ce
         que l'IA connait », donc elle ne montre que les formats ingerables. Un
         `.zip` ou un `.png` y est invisible — alors que savoir qu'un document
         ne sera JAMAIS indexe est une reponse, pas un silence. --}}
    @php
        // Pas de `use` dans un bloc @php : Blade le compile DANS le corps de la
        // fonction de rendu, ou PHP interdit une importation (TASK-1506, 500 a
        // l'execution que `Blade::compileString` ne voit pas). Nom qualifie.
        $stateStyles = [
            \App\Services\Dossiers\OrganizationFileInventory::STATE_INDEXED => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-200',
            \App\Services\Dossiers\OrganizationFileInventory::STATE_NOT_INDEXED => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
            \App\Services\Dossiers\OrganizationFileInventory::STATE_NOT_INGESTIBLE => 'bg-slate-100 text-slate-600 dark:bg-slate-700/60 dark:text-slate-300',
        ];

        $humanSize = static function (int $bytes): string {
            if ($bytes < 1024) {
                return $bytes.' o';
            }

            $units = ['Ko', 'Mo', 'Go'];
            $value = $bytes / 1024;

            foreach ($units as $unit) {
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
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('drives.title') }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 max-w-3xl">{{ __('drives.subtitle') }}</p>
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 dark:border-emerald-500/30 bg-emerald-50 dark:bg-emerald-500/10 px-3 py-2 text-sm text-emerald-800 dark:text-emerald-200" data-drives-flash>{{ session('success') }}</div>
    @endif

    <form method="GET" class="mb-5 flex flex-wrap gap-3" data-drives-filters>
        <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="{{ __('drives.search_placeholder') }}"
               aria-label="{{ __('drives.search') }}"
               class="flex-1 min-w-48 px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">

        <select name="dossier" aria-label="{{ __('drives.filter_dossier') }}"
                class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
            <option value="">{{ __('drives.filter_dossier') }} — {{ __('drives.filter_all') }}</option>
            @foreach($options['dossiers'] as $dossier)
                <option value="{{ $dossier['id'] }}" @selected($filters['dossier'] === $dossier['id'])>{{ $dossier['name'] }}</option>
            @endforeach
        </select>

        <select name="state" aria-label="{{ __('drives.filter_state') }}"
                class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
            <option value="">{{ __('drives.filter_state') }} — {{ __('drives.filter_all') }}</option>
            @foreach($options['states'] as $state)
                <option value="{{ $state }}" @selected($filters['state'] === $state)>{{ __('drives.state_'.$state) }}</option>
            @endforeach
        </select>

        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700 min-h-[40px]">{{ __('drives.filter_apply') }}</button>

        @if($filters['search'] !== '' || $filters['dossier'] !== '' || $filters['state'] !== '')
            <a href="{{ route('organization.admin.drives', $organization) }}"
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
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('drives.col_dossier') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('drives.col_format') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('drives.col_size') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('drives.col_created') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('drives.col_state') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('drives.col_chunks') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('drives.col_indexed_at') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('drives.col_actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($files as $file)
                        <tr data-drives-row="{{ $file['id'] }}" data-state="{{ $file['state'] }}">
                            <td class="px-4 py-3 text-gray-900 dark:text-gray-100 font-medium" data-title data-label="{{ __('drives.col_file') }}">{{ $file['name'] }}</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300" data-label="{{ __('drives.col_dossier') }}">{{ $file['dossier_name'] }}</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300" data-label="{{ __('drives.col_format') }}">{{ $format($file['mime_type'], $file['original_name']) }}</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300" data-label="{{ __('drives.col_size') }}">{{ $humanSize($file['size_bytes']) }}</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300" data-label="{{ __('drives.col_created') }}">{{ $file['created_at'] ? \Carbon\CarbonImmutable::parse($file['created_at'])->translatedFormat('d/m/Y') : '—' }}</td>
                            <td class="px-4 py-3" data-label="{{ __('drives.col_state') }}">
                                {{-- Le libelle porte sa propre explication : « non indexable »
                                     n'est pas une panne, et le dire evite un ticket. --}}
                                <span class="inline-block rounded-full px-2 py-0.5 text-xs font-medium {{ $stateStyles[$file['state']] }}"
                                      title="{{ __('drives.state_'.$file['state'].'_hint') }}"
                                      data-drives-state="{{ $file['state'] }}">{{ __('drives.state_'.$file['state']) }}</span>
                            </td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300" data-label="{{ __('drives.col_chunks') }}">{{ $file['chunks'] }}</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300" data-label="{{ __('drives.col_indexed_at') }}">{{ $file['last_indexed_at'] ? \Carbon\CarbonImmutable::parse($file['last_indexed_at'])->translatedFormat('d/m/Y H:i') : '—' }}</td>
                            <td class="px-4 py-3" data-actions data-label="{{ __('drives.col_actions') }}">
                                @if($file['state'] !== \App\Services\Dossiers\OrganizationFileInventory::STATE_NOT_INGESTIBLE)
                                    {{-- Une ECRITURE ne se pre-charge pas : formulaire POST,
                                         jamais un lien. --}}
                                    <form method="POST" action="{{ route('organization.admin.drives.reindex', ['organization' => $organization->slug, 'file' => $file['id']]) }}" class="inline">
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
</x-org-admin-layout>
