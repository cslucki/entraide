{{-- TASK-1879 — la configuration IA PLATEFORME d'un plugin a assistants
     (TASK-1617/1622), extraite telle quelle de l'ancien index. Attendus :
     $plugin, $assistantModels, $freeModels, $paidModels, $catalogState. --}}
                        <div class="mt-5 rounded-2xl border border-gray-200 bg-gray-50/60 p-4 dark:border-gray-700 dark:bg-gray-900/40"
                             data-section="plugin-models">

                            <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-400">
                                {{ __('loops.plugins_models_title') }}
                            </h3>
                            <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ __('loops.plugins_models_intro') }}</p>

                            {{-- L'etat du catalogue, dit en toutes lettres : un
                                 releve echoue ne doit pas ressembler a « aucun
                                 modele gratuit n'existe ». --}}
                            <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                                <p class="text-xs {{ $catalogState['ok'] ? 'text-gray-500 dark:text-gray-400' : 'text-red-600 dark:text-red-400' }}"
                                   data-catalog-state="{{ $catalogState['ok'] ? 'ok' : 'failed' }}">
                                    @if(! $catalogState['ok'])
                                        {{ __('loops.plugins_models_catalog_failed', ['reason' => $catalogState['error'] ?? '—']) }}
                                    @elseif($catalogState['fetched_at'])
                                        {{ __('loops.plugins_models_catalog_ok', [
                                            'count' => count($freeModels),
                                            'date' => \Illuminate\Support\Carbon::parse($catalogState['fetched_at'])->format('d/m/Y H:i'),
                                        ]) }}
                                    @else
                                        {{ __('loops.plugins_models_catalog_never') }}
                                    @endif
                                </p>

                                <form method="POST" action="{{ route('admin.loop-plugins.models.refresh', $plugin['key']) }}">
                                    @csrf
                                    <button type="submit" data-action="refresh-models"
                                            class="min-h-[44px] rounded-xl border border-gray-300 px-4 text-sm font-medium text-gray-700 hover:bg-white dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800">
                                        {{ __('loops.plugins_models_refresh') }}
                                    </button>
                                </form>
                            </div>

                            <div class="mt-4 space-y-3">
                                @foreach($assistantModels as $assistant)
                                    @php
                                        // TASK-1585 : ne jamais nommer une variable
                                        // de vue `$loop`, Blade se la reserve.
                                        //
                                        // TASK-1622 — le contrat PAYANT a ses deux
                                        // etats a lui : approuve et tarife, ou
                                        // invalide (tarif retire du releve, ligne
                                        // forgee sans approbation). Les etats FREE
                                        // ne changent pas.
                                        $paye = $assistant['model_type'] === \App\Models\LoopPluginAiModel::TYPE_PAID_APPROVED;
                                        $etat = $assistant['model_slug'] === null ? 'unset'
                                            : ($paye ? ($assistant['eligible'] ? 'paid' : 'paid_invalid')
                                            : (! $assistant['still_free'] ? 'gone'
                                            : (! $assistant['proof_fresh'] ? 'stale' : 'ok')));
                                    @endphp

                                    <div class="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800"
                                         data-assistant-model="{{ $assistant['assistant_key'] }}">

                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <p class="text-sm font-bold uppercase tracking-wide text-gray-800 dark:text-gray-100">{{ $assistant['label'] }}</p>
                                            <span data-model-status="{{ $etat }}"
                                                  class="rounded-full px-2.5 py-1 text-[11px] font-medium
                                                         {{ $etat === 'ok'
                                                            ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300'
                                                            : ($etat === 'paid'
                                                               ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300'
                                                               : ($etat === 'unset'
                                                                  ? 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400'
                                                                  : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300')) }}">
                                                {{ __('loops.plugins_models_status_'.$etat) }}
                                            </span>
                                        </div>

                                        @if($assistant['model_slug'])
                                            <p class="mt-1 font-mono text-xs text-gray-500 dark:text-gray-400">{{ $assistant['model_slug'] }}</p>
                                            @if($paye && $assistant['approved_at'])
                                                {{-- TASK-1622 — l'audit se LIT : qui a approuve,
                                                     quand, et a quel tarif. Un cout affiche vaut
                                                     mieux qu'un cout decouvert au ledger. --}}
                                                <p class="text-[11px] text-gray-400" data-paid-approved-line>
                                                    {{ __('loops.plugins_models_paid_approved', [
                                                        'date' => $assistant['approved_at']->format('d/m/Y H:i'),
                                                        'name' => $assistant['approved_by'] ?? '—',
                                                    ]) }}
                                                    @if($assistant['paid_rate'])
                                                        · {{ __('loops.plugins_models_rate', [
                                                            'in' => number_format($assistant['paid_rate']['input_per_1m'], 3, ',', ' '),
                                                            'out' => number_format($assistant['paid_rate']['output_per_1m'], 3, ',', ' '),
                                                        ]) }}
                                                    @endif
                                                </p>
                                            @elseif(! $paye && $assistant['verified_free_at'])
                                                <p class="text-[11px] text-gray-400">
                                                    {{ __('loops.plugins_models_free_verified', ['date' => $assistant['verified_free_at']->format('d/m/Y H:i')]) }}
                                                    @if($assistant['catalog_entry']['context_length'] ?? null)
                                                        · {{ __('loops.plugins_models_context', ['tokens' => number_format($assistant['catalog_entry']['context_length'], 0, ',', ' ')]) }}
                                                    @endif
                                                </p>
                                            @endif
                                        @endif

                                        {{-- TASK-1622 — UN formulaire, UN geste : le type
                                             choisi decide quel selecteur poste. Le selecteur
                                             inactif est `disabled` — il ne voyage pas, donc
                                             `model_slug` n'arrive jamais en double. Les deux
                                             radios portent le contrat : gratuit VERIFIE ou
                                             payant APPROUVE, jamais un melange. --}}
                                        <form method="POST" action="{{ route('admin.loop-plugins.models.update', $plugin['key']) }}"
                                              class="mt-2 space-y-2"
                                              x-data="{ type: '{{ $paye ? \App\Models\LoopPluginAiModel::TYPE_PAID_APPROVED : \App\Models\LoopPluginAiModel::TYPE_FREE_VERIFIED }}' }">
                                            @csrf
                                            @method('PUT')
                                            <input type="hidden" name="assistant_key" value="{{ $assistant['assistant_key'] }}">

                                            <div class="flex flex-wrap items-center gap-4" data-model-type-choice="{{ $assistant['assistant_key'] }}">
                                                <label class="inline-flex min-h-[32px] items-center gap-1.5 text-xs text-gray-700 dark:text-gray-200">
                                                    <input type="radio" name="model_type" value="free_verified" x-model="type"
                                                           class="border-gray-300 text-indigo-600 dark:border-gray-600 dark:bg-gray-900">
                                                    {{ __('loops.plugins_models_type_free') }}
                                                </label>
                                                <label class="inline-flex min-h-[32px] items-center gap-1.5 text-xs text-gray-700 dark:text-gray-200">
                                                    <input type="radio" name="model_type" value="paid_approved" x-model="type"
                                                           class="border-gray-300 text-indigo-600 dark:border-gray-600 dark:bg-gray-900">
                                                    {{ __('loops.plugins_models_type_paid') }}
                                                </label>
                                            </div>

                                            <div class="flex flex-wrap items-center gap-2">
                                                <select name="model_slug" required
                                                        x-show="type === 'free_verified'"
                                                        x-bind:disabled="type !== 'free_verified'"
                                                        data-model-select="{{ $assistant['assistant_key'] }}"
                                                        class="min-h-[44px] flex-1 rounded-xl border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                                                    <option value="">{{ __('loops.plugins_models_choose') }}</option>
                                                    @forelse($freeModels as $slug => $modele)
                                                        <option value="{{ $slug }}" @selected(! $paye && $assistant['model_slug'] === $slug)>
                                                            {{ $modele['name'] }} — {{ $slug }}
                                                        </option>
                                                    @empty
                                                        <option value="" disabled>{{ __('loops.plugins_models_none') }}</option>
                                                    @endforelse
                                                </select>

                                                {{-- La shortlist payante : petite, explicite, et
                                                     chaque option DIT son tarif. Un slug sans tarif
                                                     au releve reste visible mais inerte — voir
                                                     l'entree manquante vaut mieux que la deviner. --}}
                                                <select name="model_slug" required
                                                        x-show="type === 'paid_approved'"
                                                        x-bind:disabled="type !== 'paid_approved'"
                                                        x-cloak
                                                        data-paid-model-select="{{ $assistant['assistant_key'] }}"
                                                        class="min-h-[44px] flex-1 rounded-xl border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                                                    <option value="">{{ __('loops.plugins_models_choose') }}</option>
                                                    @foreach($paidModels as $candidat)
                                                        <option value="{{ $candidat['slug'] }}"
                                                                @selected($paye && $assistant['model_slug'] === $candidat['slug'])
                                                                @disabled($candidat['rate'] === null)>
                                                            {{ $candidat['label'] }} — {{ $candidat['slug'] }}
                                                            @if($candidat['rate'])
                                                                · {{ number_format($candidat['rate']['input_per_1m'], 3, ',', ' ') }} $ / {{ number_format($candidat['rate']['output_per_1m'], 3, ',', ' ') }} $ /1M
                                                            @else
                                                                · {{ __('loops.plugins_models_rate_missing') }}
                                                            @endif
                                                        </option>
                                                    @endforeach
                                                </select>

                                                <button type="submit"
                                                        class="min-h-[44px] rounded-xl bg-indigo-600 px-4 text-sm font-medium text-white hover:bg-indigo-700">
                                                    {{ __('loops.plugins_assistants_save') }}
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                @endforeach
                            </div>

                            <p class="mt-3 text-[11px] leading-5 text-gray-400">{{ __('loops.plugins_models_fail_closed') }}</p>
                        </div>
