<x-admin-layout title="Scenario packs">
    <div class="max-w-4xl mx-auto space-y-6">

        <div>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                Scenario packs
            </h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                Charger, reinitialiser ou retirer un jeu de donnees de demonstration/dogfooding dans
                une Organization qualifiee. Une seule Organization cible a la fois, jamais une action globale.
            </p>
        </div>

        @if (empty($packIds))
            <div class="bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 px-4 py-3 rounded-lg text-sm" data-scenario-packs-empty>
                Aucun scenario pack enregistre (<code>config('scenario_packs.definitions')</code>).
            </div>
        @elseif ($organizations->isEmpty())
            <div class="bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 px-4 py-3 rounded-lg text-sm" data-scenario-packs-no-organization>
                Aucune Organization qualifiee demonstration/dogfooding (<code>config('scenario_packs.allowed_organizations')</code>).
            </div>
        @else
            <form method="GET" action="{{ route('admin.scenario-packs') }}" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 flex flex-wrap items-end gap-4" data-scenario-packs-selector>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1" for="scenario-pack-select">Pack</label>
                    <select id="scenario-pack-select" name="pack" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        @foreach ($packIds as $packId)
                            <option value="{{ $packId }}" @selected($packId === $selectedPackId)>{{ $packId }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1" for="scenario-pack-organization">Organization</label>
                    <select id="scenario-pack-organization" name="organization" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        @foreach ($organizations as $organization)
                            <option value="{{ $organization->slug }}" @selected($selectedOrganization && $organization->is($selectedOrganization))>{{ $organization->name }} ({{ $organization->slug }})</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="px-4 py-2 rounded-lg bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 text-sm font-medium">
                    Voir
                </button>
            </form>

            @if ($pack && $status && $selectedOrganization)
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 space-y-2" data-scenario-pack-preview>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $pack->packName() }}</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $pack->purpose() }}</p>
                    <p class="text-xs text-gray-400 dark:text-gray-500">
                        pack_id <code>{{ $pack->packId() }}</code> · version declaree <code>{{ $pack->packVersion() }}</code>
                    </p>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 space-y-3" data-scenario-pack-status="{{ $status['loaded'] ? 'loaded' : 'not-loaded' }}">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Etat dans « {{ $selectedOrganization->name }} »</h3>

                    @if ($status['loaded'])
                        <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                            <dt class="text-gray-500 dark:text-gray-400">Version chargee</dt>
                            <dd class="text-gray-900 dark:text-gray-100" data-scenario-pack-loaded-version>{{ $status['pack_version'] }}</dd>
                            <dt class="text-gray-500 dark:text-gray-400">Charge le</dt>
                            <dd class="text-gray-900 dark:text-gray-100">{{ $status['loaded_at'] }}</dd>
                            <dt class="text-gray-500 dark:text-gray-400">Dernier reset</dt>
                            <dd class="text-gray-900 dark:text-gray-100">{{ $status['reset_at'] ?? 'jamais' }}</dd>
                            <dt class="text-gray-500 dark:text-gray-400">Entites</dt>
                            <dd class="text-gray-900 dark:text-gray-100" data-scenario-pack-entity-total>{{ $status['total'] }}</dd>
                        </dl>
                        @if (!empty($status['counts']))
                            <ul class="text-xs text-gray-500 dark:text-gray-400 list-disc list-inside">
                                @foreach ($status['counts'] as $type => $count)
                                    <li data-scenario-pack-count-type="{{ $type }}">{{ $type }} : {{ $count }}</li>
                                @endforeach
                            </ul>
                        @endif
                    @else
                        <p class="text-sm text-gray-500 dark:text-gray-400" data-scenario-pack-not-loaded>Jamais charge dans cette Organization.</p>
                    @endif
                </div>

                <div class="flex flex-wrap gap-3">
                    <form method="POST" action="{{ route('admin.scenario-packs.load') }}">
                        @csrf
                        <input type="hidden" name="pack" value="{{ $pack->packId() }}">
                        <input type="hidden" name="organization" value="{{ $selectedOrganization->slug }}">
                        <button type="submit" class="px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-medium" data-scenario-pack-action="load">
                            Charger
                        </button>
                    </form>

                    <form method="POST" action="{{ route('admin.scenario-packs.reset') }}" onsubmit="return confirm('Reinitialiser « {{ $pack->packName() }} » dans « {{ $selectedOrganization->name }} » ? Les entites d\'une version anterieure non reproduites seront retirees.')">
                        @csrf
                        <input type="hidden" name="pack" value="{{ $pack->packId() }}">
                        <input type="hidden" name="organization" value="{{ $selectedOrganization->slug }}">
                        <button type="submit" class="px-4 py-2 rounded-lg bg-amber-500 text-white text-sm font-medium" data-scenario-pack-action="reset">
                            Reset
                        </button>
                    </form>

                    <form method="POST" action="{{ route('admin.scenario-packs.delete') }}" onsubmit="return confirm('Retirer « {{ $pack->packName() }} » de « {{ $selectedOrganization->name }} » ? Seules les entites creees par ce pack sont supprimees.')">
                        @csrf
                        <input type="hidden" name="pack" value="{{ $pack->packId() }}">
                        <input type="hidden" name="organization" value="{{ $selectedOrganization->slug }}">
                        <button type="submit" class="px-4 py-2 rounded-lg bg-red-600 text-white text-sm font-medium" data-scenario-pack-action="delete">
                            Supprimer
                        </button>
                    </form>
                </div>
            @endif
        @endif
    </div>
</x-admin-layout>
