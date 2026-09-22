{{--
    TASK-1621 — LA CARTE DE DEBAT « Pour / Contre ».

    Deux `LoopMessage` restent DISTINCTS en base : on ne fusionne que leur
    rendu. La cle de regroupement est `reply_to_id`, le message humain qui a
    declenche le tour — une colonne indexee, pas une metadonnee.

    Ce n'est PAS `correlation_id`. Depuis que les deux roles tournent dans deux
    requetes differees, chacun genere sa propre correlation : regrouper par
    elle ne regrouperait rien (mesure : 2 correlations distinctes pour chaque
    paire).

    IDENTITE DOM STABLE. La carte porte `wire:key="debat-{declencheur}"` et
    existe des la soumission, AVANT toute reponse. Elle se remplit ensuite
    zone par zone — attente, POUR, mire CONTRE, CONTRE — sans jamais etre
    recreee : c'est ce qui evite le clignotement et le saut de mise en page.

    PROJECTION DESKTOP UNIQUEMENT (`hidden md:block`, decision Cyril 22/09) :
    sur telephone, pas de tableau — les bulles classiques du fil restent
    rendues (leur wrapper porte `md:hidden`), et les etats d'attente / d'echec
    se lisent au-dessus du composeur comme avant. Une seule mire visible par
    viewport, jamais deux surfaces pour un meme etat.

    Variables attendues (heritees du scope appelant) :
      $declencheurId, $questionDebat, $messagesDebat, $queue, $states,
      $labels, $models, et le contexte de fil ($isMember,
      $capitalizableMessageIds, $canCapitalize).
--}}
@php
    $roles = ['aperio', 'traverse'];

    // Le role en cours de generation est le PREMIER de la file. Les suivants
    // sont en attente. Hors file et sans message : rien ne viendra plus.
    $enCours = $queue[0] ?? null;
    $enAttente = array_slice($queue, 1);

    // « Ajouter au Dossier » est un geste UNIQUE de la carte, et il porte sur
    // le DEBAT ENTIER (decision Cyril + garde MASTER 22/09) : jamais un
    // demi-debat capitalise en silence. Tant que les deux camps publiables ne
    // sont pas la — l'un genere encore, ou a echoue — le bouton n'existe pas.
    // L'eligibilite reste celle du service (via $capitalizableMessageIds,
    // calculee dans render()), jamais une seconde regle en Blade.
    $bullePour = $messagesDebat['aperio'] ?? null;
    $bulleContre = $messagesDebat['traverse'] ?? null;
    $debatCapitalisable = $bullePour !== null && $bulleContre !== null
        && in_array($bullePour->id, $capitalizableMessageIds, true)
        && in_array($bulleContre->id, $capitalizableMessageIds, true);
@endphp

<div wire:key="debat-{{ $declencheurId }}"
     data-pour-contre-debat="{{ $declencheurId }}"
     x-data="{ copie: false }"
     {{-- La carte est BORNEE en largeur. Sans plafond, elle occupait tout le
          fil et chaque colonne rendait des lignes de 150 caracteres : la place
          disponible sur grand ecran ne doit pas devenir de la longueur de
          ligne. A 5xl, chaque colonne tient ~500 px, soit une mesure lisible.
          Elle reste calee a GAUCHE, comme toute reponse recue.

          `hidden md:block` : la carte est une projection DESKTOP (decision
          Cyril 22/09). Sur telephone, les bulles classiques du fil restent
          rendues (elles portent `md:hidden`) — pas de tableau.

          `x-data` vit sur CETTE racine : le bouton « copier » cherche les
          colonnes par `$root.querySelectorAll(...)`, et `$root` est l'element
          porteur du x-data. Pose sur la barre d'actions (une soeur des
          colonnes), il ne trouvait jamais rien et le bouton etait inerte. --}}
     class="mt-1 hidden w-full max-w-full overflow-hidden rounded-2xl border border-gray-200 bg-white/60 md:block lg:max-w-5xl dark:border-gray-700 dark:bg-gray-800/40">

    {{-- La question, UNE SEULE FOIS. Le gros bloc cite de chaque bulle
         consommait deux fois la meme information sur quatre lignes. --}}
    @if($questionDebat !== '')
        <p data-pour-contre-question
           title="{{ $questionDebat }}"
           class="flex items-center gap-1.5 truncate border-b border-gray-200 px-3 py-1.5 text-[11px] italic text-gray-500 dark:border-gray-700 dark:text-gray-400">
            <svg class="h-3 w-3 shrink-0 opacity-70" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3"/></svg>
            <span class="truncate">« {{ $questionDebat }} »</span>
        </p>
    @endif

    {{-- Deux colonnes des que la largeur le permet, empilees sinon. Pas de
         colonnes etroites sur telephone : on lit POUR puis CONTRE. --}}
    <div class="grid grid-cols-1 divide-y divide-gray-200 md:grid-cols-2 md:divide-x md:divide-y-0 dark:divide-gray-700">
        @foreach($roles as $role)
            @php
                $message = $messagesDebat[$role] ?? null;
                $etat = $states[$role] ?? null;
                $estContre = $role === 'traverse';
                $teinte = $estContre
                    ? 'bg-rose-50/70 text-rose-800 dark:bg-rose-950/40 dark:text-rose-200'
                    : 'bg-emerald-50/70 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200';
                $modele = $models[$role] ?? null;
            @endphp

            <section data-pour-contre-colonne="{{ $role }}" class="flex min-w-0 flex-col">
                {{-- Le badge vit dans l'EN-TETE de sa section : il ne consomme
                     plus une ligne entiere en bas de bulle. Le modele reste
                     accessible — en infobulle ici, et en clair dans
                     « Pourquoi cette reponse ? ». --}}
                <header class="flex items-center gap-2 px-3 py-1.5 text-[10px] font-semibold uppercase tracking-wide {{ $teinte }}"
                        @if($modele) title="{{ __('loops.plugins_multi_ai_model_hint', ['model' => $modele]) }}" @endif>
                    <svg class="h-3 w-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v18m0-18 7.5 4.5M12 3 4.5 7.5m15 0-2.25 6.75a3 3 0 0 0 4.5 0zm-15 0L2.25 14.25a3 3 0 0 0 4.5 0z"/></svg>
                    <span class="truncate">{{ $labels[$role] ?? $role }}</span>
                    @if($modele)
                        <span data-pour-contre-modele="{{ $role }}" class="ml-auto truncate text-[10px] font-normal normal-case tracking-normal opacity-60">{{ $modele }}</span>
                    @endif
                </header>

                <div class="min-w-0 flex-1 px-1.5 py-1.5">
                    @if($message)
                        @include('livewire.partials.loop-chat-pour-contre-reponse', ['msg' => $message])
                    @elseif($enCours === $role)
                        {{-- La mire, COMPACTE : une section en attente ne
                             reserve jamais une grande hauteur vide. --}}
                        <p data-pour-contre-mire="{{ $role }}" role="status" aria-live="polite"
                           class="flex items-center gap-2 px-2 py-2 text-[11px] text-gray-600 dark:text-gray-300">
                            <svg class="h-3.5 w-3.5 shrink-0 animate-spin opacity-60" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                            <span class="truncate">{{ __('loops.plugins_multi_ai_preparing', ['assistant' => mb_strtolower($labels[$role] ?? $role)]) }}</span>
                        </p>
                    @elseif(in_array($role, $enAttente, true))
                        <p data-pour-contre-attente="{{ $role }}"
                           class="px-2 py-2 text-[11px] italic text-gray-400 dark:text-gray-500">{{ __('loops.plugins_multi_ai_queued') }}</p>
                    @elseif($etat)
                        {{-- L'echec d'UN role, compact, sans emporter l'autre.
                             « Reessayer » agit sur CE role, pas sur la paire. --}}
                        @php
                            [$titre, $corps] = match ($etat['status']) {
                                'rate_limited' => [
                                    __('loops.plugins_multi_ai_rate_limited_title', ['assistant' => $etat['label']]),
                                    __('loops.plugins_multi_ai_rate_limited_body', ['assistant' => $etat['label']]),
                                ],
                                'refused' => [
                                    __('loops.plugins_multi_ai_refused_title', ['assistant' => $etat['label']]),
                                    __('loops.plugins_multi_ai_refused_body', ['assistant' => $etat['label']]),
                                ],
                                default => [
                                    __('loops.plugins_multi_ai_failed_title', ['assistant' => $etat['label']]),
                                    __('loops.plugins_multi_ai_failed_body'),
                                ],
                            };
                        @endphp
                        <div data-pour-contre-echec="{{ $role }}"
                             class="rounded-lg bg-amber-50 px-2.5 py-2 text-[11px] text-amber-900 dark:bg-amber-900/25 dark:text-amber-100">
                            <p class="font-semibold">{{ $titre }}</p>
                            <p class="mt-0.5 opacity-90">{{ $corps }}</p>
                            @if($etat['retryable'])
                                <button type="button"
                                        wire:click="retryAssistant('{{ $role }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="retryAssistant,runNextPourContre"
                                        data-multi-ai-retry="{{ $role }}"
                                        class="mt-1.5 rounded-full border border-amber-400 bg-white px-2 py-0.5 font-semibold text-amber-900 transition hover:bg-amber-50 disabled:opacity-50 dark:border-amber-600 dark:bg-amber-950/50 dark:text-amber-100">
                                    {{ __('loops.plugins_multi_ai_retry') }}
                                </button>
                            @endif
                        </div>
                    @else
                        {{-- Historique : un tour ancien n'ayant qu'un seul role.
                             La colonne reste, vide et DISCRETE — elle ne
                             reserve pas de hauteur. --}}
                        <p data-pour-contre-absent="{{ $role }}" class="px-2 py-1 text-[11px] italic text-gray-300 dark:text-gray-600">—</p>
                    @endif
                </div>
            </section>
        @endforeach
    </div>

    {{-- LES ACTIONS DU DEBAT, une seule fois pour la carte entiere.

         Recette : repondre / epingler / copier / supprimer affiches dans
         CHAQUE colonne se lisaient comme une interface en double. Ces gestes
         concernent le debat, pas un camp. Decision Cyril 22/09 : DEUX gestes,
         uniques —

           - AJOUTER AU DOSSIER capitalise le DEBAT ENTIER (POUR puis CONTRE)
             dans un seul brouillon relu par l'humain. Il n'apparait que
             lorsque les deux camps publiables sont la : un demi-debat ne se
             capitalise jamais en silence (garde MASTER). Si un camp est
             ecourte, le brouillon le dit — c'est `startDebateCapitalization`
             qui l'ecrit, pas la vue ;
           - COPIER prend les deux reponses, dans l'ordre POUR puis CONTRE.
             Geste client, aucun aller-retour serveur.

         REPONDRE a ete RETIRE (meme decision) : repondre a la question se
         fait dans le fil, comme pour n'importe quel message. EPINGLER et
         SUPPRIMER ne sont pas ici non plus : ils visent UN message, et
         « epingler le debat » n'a pas de representation. --}}
    @if($isMember)
        <div data-pour-contre-actions
             class="flex items-center justify-end gap-1 border-t border-gray-200 px-2 py-1 dark:border-gray-700">
            @if($debatCapitalisable)
                <button type="button"
                        @if($canCapitalize)
                            wire:click="startDebateCapitalization('{{ $declencheurId }}')"
                            wire:loading.attr="disabled"
                            wire:target="startDebateCapitalization('{{ $declencheurId }}')"
                        @else
                            disabled
                        @endif
                        data-pour-contre-capitaliser
                        data-capitalize-allowed="{{ $canCapitalize ? '1' : '0' }}"
                        title="{{ __('loops.capitalize_action') }}"
                        class="mr-auto inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700 transition disabled:cursor-not-allowed disabled:opacity-40 enabled:hover:bg-emerald-100 dark:text-emerald-400 dark:enabled:hover:bg-emerald-900/40">
                    <svg class="h-3 w-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 10.5v4m2-2h-4"/></svg>
                    <span>{{ __('loops.capitalize_action') }}</span>
                </button>
            @endif

            <button type="button"
                    data-pour-contre-copier
                    x-on:click="
                        const zones = $root.querySelectorAll('[data-pour-contre-colonne] [x-ref=copyContent]');
                        const texte = Array.from(zones).map(z => z.innerText.trim()).filter(Boolean).join('\n\n');
                        if (texte) { navigator.clipboard.writeText(texte); copie = true; setTimeout(() => copie = false, 1500); }
                    "
                    :title="copie ? @js(__('messages.copied')) : @js(__('messages.copy'))"
                    :aria-label="copie ? @js(__('messages.copied')) : @js(__('messages.copy'))"
                    class="inline-flex h-6 w-6 items-center justify-center rounded-full text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-700 dark:hover:text-gray-200">
                <svg x-show="! copie" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 0 0-3.375-3.375h-1.5a1.125 1.125 0 0 1-1.125-1.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H9.75"/></svg>
                <svg x-show="copie" x-cloak class="h-3.5 w-3.5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
            </button>
        </div>
    @endif
</div>
