{{-- TASK-1654 — bandeau du mode persona.

         PERMANENT et non refermable, sur le layout commun : il doit rester
         visible sur chaque page, parce que c'est lui qui empeche de croire
         qu'on agit en son propre nom. Un bandeau qu'on peut fermer est un
         bandeau qui finira ferme au moment ou il comptait.

         Ses donnees viennent du middleware, qui vient de VALIDER le
         contexte : le bandeau ne peut donc pas annoncer un mode deja
         ferme. --}}
    @if(!empty($scenarioPersonaBanner['persona']))
    <div class="bg-rose-600 px-4 py-2 text-sm text-white">
        <div class="mx-auto flex max-w-5xl flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <p class="font-semibold">{{ __('admin.scenario_manager.persona_banner_title') }}</p>
                <p class="text-xs text-rose-100 sm:text-sm">
                    {{ __('admin.scenario_manager.persona_banner_body', [
                        'persona' => $scenarioPersonaBanner['persona']->full_name ?: $scenarioPersonaBanner['persona']->name,
                        'sandbox' => $scenarioPersonaBanner['sandbox']->name ?? '—',
                    ]) }}
                </p>
            </div>
            {{-- POST + CSRF : quitter le mode est une bascule d'identite,
                 jamais une navigation. --}}
            <form method="POST" action="{{ route('admin.outils.scenarios.personas.exit') }}" class="shrink-0" data-form="persona-exit">
                @csrf
                <button type="submit"
                        class="inline-flex w-full items-center justify-center gap-1 rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-rose-700 transition hover:bg-rose-50 sm:w-auto"
                        data-persona-exit>
                    {{ __('admin.scenario_manager.persona_banner_exit') }}
                </button>
            </form>
        </div>
    </div>
    @endif
