<x-admin-layout :title="__('admin.guest_shell_config')">
    {{-- TASK-1500 — la page de configuration Shell Welcome par Organization.
         La section vient de /admin/ai-config, refaite au passage (hierarchie,
         signal d'abord, formulaire partage avec le cockpit /admin/shell-welcome). --}}
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('admin.guest_shell_config') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 max-w-2xl">{{ __('admin.guest_shell_config_hint') }}</p>
        </div>
        <div class="flex flex-wrap gap-2 text-sm">
            <a href="{{ route('admin.guest-shell') }}" class="px-3 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700/50">{{ __('admin.guest_shell_observability_nav') }}</a>
            <a href="{{ route('admin.ai-organizations') }}" class="px-3 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700/50">Organizations &amp; IA</a>
        </div>
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 dark:border-emerald-500/30 bg-emerald-50 dark:bg-emerald-500/10 px-3 py-2 text-sm text-emerald-800 dark:text-emerald-200" data-guest-shell-saved>{{ session('success') }}</div>
    @endif

        {{-- TASK-1429 — SW-1 : Shell Welcome par Organization (politique seulement ; provider/modele/cle = autorite IA existante).
             TASK-1500 : la section est REFAITE. Neuf lignes identiques ou la seule
             organisation active — et payante — ne se distinguait pas des huit
             dormantes ; des tuiles plates ; huit « 0.0000 USD » qui criaient plus
             fort que le seul montant reel. Ce qui change est la HIERARCHIE, pas
             l'information : les memes donnees, les memes crochets `data-*`.
             Le formulaire par organisation vit dans `admin/partials/guest-shell-policy-row`,
             partage avec /admin/shell-welcome. --}}
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6" data-guest-shell-config>
            @php
                // TASK-1474 : le RESUME de tete. Aucune logique economique nouvelle —
                // on compte les diagnostics que `GuestShellDiagnosis` produit deja,
                // organisation par organisation. Le plafond plateforme est lu par
                // l'autorite qui le detient, jamais reinterprete ici.
                $diagnoses = collect($organizations)->map(fn ($o) => \App\Support\GuestShell\GuestShellDiagnosis::for($guestShellStates[$o->id]));
                $summary = [
                    'ready' => $diagnoses->where('tone', \App\Support\GuestShell\GuestShellDiagnosis::TONE_READY)->count(),
                    'disabled' => $diagnoses->where('tone', \App\Support\GuestShell\GuestShellDiagnosis::TONE_NEUTRAL)->count(),
                    'action' => $diagnoses->where('tone', \App\Support\GuestShell\GuestShellDiagnosis::TONE_ACTION)->count(),
                    'budget' => $diagnoses->where('tone', \App\Support\GuestShell\GuestShellDiagnosis::TONE_BUDGET)->count(),
                ];
                $platformCeiling = \App\Services\GuestShell\GuestShellPolicyService::platformCeilingUsd();

                // La teinte dit le SENS d'un chiffre, et seulement quand il est non nul :
                // un zero reste neutre, il ne doit pas attirer l'oeil.
                $tileTone = [
                    'ready'    => $summary['ready'] > 0    ? 'border-emerald-200 dark:border-emerald-500/30 bg-emerald-50/60 dark:bg-emerald-500/5 [&_[data-n]]:text-emerald-700 dark:[&_[data-n]]:text-emerald-300' : '',
                    'action'   => $summary['action'] > 0   ? 'border-amber-300 dark:border-amber-500/40 bg-amber-50/70 dark:bg-amber-500/5 [&_[data-n]]:text-amber-700 dark:[&_[data-n]]:text-amber-300' : '',
                    'budget'   => $summary['budget'] > 0   ? 'border-rose-200 dark:border-rose-500/30 bg-rose-50/60 dark:bg-rose-500/5 [&_[data-n]]:text-rose-700 dark:[&_[data-n]]:text-rose-300' : '',
                    'disabled' => '',
                ];

                // TASK-1500 : le SIGNAL d'abord. Une organisation qui reclame un geste
                // ou a epuise son budget passe en tete et s'ouvre ; une organisation
                // prete suit ; les desactivees se replient sous un seul en-tete, avec
                // leur nombre. C'etait neuf blocs de meme poids, par ordre alphabetique.
                $rows = collect($organizations)->map(fn ($o) => ['org' => $o, 'state' => $guestShellStates[$o->id], 'tone' => \App\Support\GuestShell\GuestShellDiagnosis::for($guestShellStates[$o->id])['tone']]);
                $rowsAttention = $rows->whereIn('tone', [\App\Support\GuestShell\GuestShellDiagnosis::TONE_ACTION, \App\Support\GuestShell\GuestShellDiagnosis::TONE_BUDGET]);
                $rowsReady = $rows->where('tone', \App\Support\GuestShell\GuestShellDiagnosis::TONE_READY);
                $rowsDormant = $rows->reject(fn ($r) => $rowsAttention->contains($r) || $rowsReady->contains($r));
            @endphp

            <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-6" data-guest-shell-summary>
                @foreach($summary as $key => $count)
                <div class="rounded-xl border border-gray-200 dark:border-gray-700 px-4 py-3 {{ $tileTone[$key] }}" data-guest-shell-summary-item="{{ $key }}" data-guest-shell-summary-value="{{ $count }}">
                    <div class="text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.guest_shell_summary_'.$key) }}</div>
                    <div class="mt-1 text-2xl font-bold tabular-nums leading-none text-gray-900 dark:text-gray-100" data-n>{{ $count }}</div>
                </div>
                @endforeach
                <div class="rounded-xl border px-4 py-3 {{ $platformCeiling === null ? 'border-amber-300 dark:border-amber-500/40 bg-amber-50/70 dark:bg-amber-500/5' : 'border-gray-200 dark:border-gray-700' }}" data-guest-shell-summary-item="platform_ceiling" data-guest-shell-summary-value="{{ $platformCeiling === null ? 'unset' : 'set' }}">
                    <div class="text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.guest_shell_summary_platform_ceiling') }}</div>
                    <div class="mt-1 text-base font-semibold tabular-nums leading-tight {{ $platformCeiling === null ? 'text-amber-700 dark:text-amber-300' : 'text-gray-900 dark:text-gray-100' }}">{{ $platformCeiling === null ? __('admin.guest_shell_summary_ceiling_unset') : number_format($platformCeiling, 2).' USD' }}</div>
                </div>
            </div>

            @if($rows->isEmpty())
                <div class="text-sm text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-700/50 rounded-lg px-4 py-3">{{ __('admin.ai_no_profiles_config') }}</div>
            @else
                <div class="space-y-2">
                    @foreach($rowsAttention->concat($rowsReady) as $row)
                        @include('admin.partials.guest-shell-policy-row', ['org' => $row['org'], 'state' => $row['state']])
                    @endforeach

                    @if($rowsDormant->isNotEmpty())
                    {{-- `group-open:` et non un variant `[details[open]>&]` : ce dernier est
                         ABSENT du build (mesure : 0 occurrence dans app-*.css), donc un
                         chevron qui ne tournerait jamais, sans aucune erreur. --}}
                    <details class="group rounded-xl border border-dashed border-gray-200 dark:border-gray-700" data-guest-shell-dormant="{{ $rowsDormant->count() }}" @if($rowsAttention->isEmpty() && $rowsReady->isEmpty()) open @endif>
                        <summary class="flex items-center gap-2 px-4 py-2.5 cursor-pointer select-none text-xs font-medium text-gray-500 dark:text-gray-400 list-none [&::-webkit-details-marker]:hidden">
                            <svg class="h-3.5 w-3.5 transition group-open:rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 5l7 7-7 7"/></svg>
                            {{ __('admin.guest_shell_summary_disabled') }} <span class="rounded-full bg-gray-100 dark:bg-gray-700 px-1.5 py-0.5 tabular-nums">{{ $rowsDormant->count() }}</span>
                        </summary>
                        <div class="space-y-2 px-2 pb-2">
                            @foreach($rowsDormant as $row)
                                @include('admin.partials.guest-shell-policy-row', ['org' => $row['org'], 'state' => $row['state']])
                            @endforeach
                        </div>
                    </details>
                    @endif
                </div>
            @endif
        </div>

</x-admin-layout>
