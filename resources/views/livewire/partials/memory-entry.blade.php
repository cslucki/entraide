{{--
    TASK-1550 : UN énoncé de mémoire durable, et le geste qui le corrige.

    Ce partial est l'extraction du bloc livré par T1549 dans le panneau
    « Pourquoi ? ». Il n'est pas une seconde présentation de la mémoire : c'est
    LA présentation, désormais partagée par les deux ancres. Le CDC exige que
    `Pourquoi ?` et `Corriger` de la carte « Depuis cet échange… » réutilisent le
    chemin standard — un second rendu aurait dérivé du premier au premier
    correctif appliqué d'un seul côté.

    Variables attendues :
      $entry         — l'entrée (forme de `ClaimProvenanceReader::provenance()`
                       + `ref`, + `kind` pour la carte)
      $ancre         — `LoopChat::ANCRE_WHY` ou `ANCRE_DIGEST`
      $canCorrect    — cette PERSONNE peut-elle écrire ici (honnêteté
                       d'affichage ; l'autorité reste au serveur)
      $entryMarker   — le marqueur de test de l'entrée, propre à l'ancre
      $correctPrefix — le préfixe des marqueurs du formulaire, propre à l'ancre
      $compact       — mise en page DENSE (la carte) ou complète (le panneau)

    `$compact` est une MISE EN PAGE, jamais un contenu tronqué : les mêmes
    données, les mêmes gestes, les mêmes clés, les mêmes gardes. Mesuré au
    navigateur : en mise en page complète, trois entrées occupaient tout l'écran
    et repoussaient la conversation hors du champ — une carte qui se dit « non
    bloquante » ne peut pas prendre la place de ce qu'elle commente.

    La seule mention qui ne se répète pas en dense est la portée par ligne, et
    seulement quand elle vaut « cette Boucle » : la carte VIT dans cette
    Boucle-là et la nomme déjà dans sa note de pied. Une portée AUTRE reste
    dite, elle, sur la ligne — c'est l'information qui compte.

    Les marqueurs sont DISTINCTS par ancre, et c'est délibéré : les assertions
    négatives de T1549 (`assertDontSeeHtml('data-correct-open-update')`) portent
    sur le panneau, et doivent rester exactes même quand une carte est affichée
    à côté.
--}}
@php($compact = $compact ?? false)
@php($enCorrection = $correctingAnchor === $ancre && $correctingRef === $entry['ref'])
@php($gesteOffert = $canCorrect && $entry['can_correct'] && $entry['ref'] !== null)
<li class="rounded-lg border border-violet-200/70 bg-white dark:border-violet-800/50 dark:bg-gray-900 {{ $compact ? 'px-2.5 py-1.5' : 'px-2.5 py-2' }}" {{ $entryMarker }} data-memory-state="{{ $entry['state'] }}">
    <p class="leading-5">
        @if(($entry['kind'] ?? null) !== null)<span class="mr-1 text-[10px] font-semibold uppercase tracking-wide text-violet-700 dark:text-violet-300" data-memory-kind="{{ $entry['kind'] }}">{{ __('loops.digest_kind_'.$entry['kind']) }}</span>@endif
        @if(($entry['kind'] ?? null) === null && $entry['ref'])<span class="font-mono text-[10px] text-violet-700 dark:text-violet-300">[{{ $entry['ref'] }}]</span>@endif
        <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $entry['statement'] }}</span>
    </p>
    @unless($compact)
    <p class="mt-1 text-[11px] leading-4 text-gray-500 dark:text-gray-400">
        @if($entry['observed_at']){{ __('loops.why_memory_observed', ['date' => $entry['observed_at']]) }} · @endif
        @if($entry['same_loop']){{ __('loops.why_memory_scope_here') }}@else{{ __('loops.why_memory_scope_other', ['loop' => $entry['loop_name']]) }}@endif
    </p>
    @endunless
    {{-- REMÉDIATION R2 (audit Codex F3) : les deux mentions « cet énoncé a
         évolué » / « a été retiré » ne sont PAS ici. Le panneau ne peut pas les
         produire — `noteFromChunk()` est sa seule porte, et une supersession
         emporte le chunk cité (`ClaimMemory::appliquer()` → `forget()`). Les
         promettre était une promesse d'interface qu'aucune donnée ne pouvait
         tenir. La carte de T1550, elle, dit l'opération réellement PERSISTÉE
         (`data-memory-kind`), ce qui est une autre affirmation : une écriture
         constatée, pas un état déduit d'une absence. --}}
    @unless($compact)
        @if($entry['evidence_message_ids'] !== [])
        <div class="mt-1.5 flex flex-wrap items-center gap-1.5" data-memory-evidence>
            @foreach($entry['evidence_message_ids'] as $evidenceId)
            <button type="button" wire:click="showMessageInThread('{{ $evidenceId }}')"
                    class="rounded-full border border-gray-200 bg-white px-2 py-0.5 text-[10px] font-semibold text-gray-600 transition hover:bg-gray-50 hover:text-gray-900 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-gray-800">
                {{ __('loops.why_memory_evidence', ['n' => $loop->iteration]) }}
            </button>
            @endforeach
        </div>
        @endif
    @endunless
    @if($entry['corrections'] !== [])
    <div class="mt-1.5 space-y-0.5" data-memory-corrections>
        @foreach($entry['corrections'] as $correctionEvent)
        <p class="text-[11px] leading-4 text-gray-500 dark:text-gray-400">
            {{ $correctionEvent['by_name'] !== null
                ? __('loops.why_memory_corrected_by', ['name' => $correctionEvent['by_name'], 'date' => $correctionEvent['at'] ?? '—'])
                : __('loops.why_memory_corrected', ['date' => $correctionEvent['at'] ?? '—']) }}
            @if($correctionEvent['message_id'] !== null && $entry['same_loop'])
            <button type="button" wire:click="showMessageInThread('{{ $correctionEvent['message_id'] }}')"
                    class="font-semibold text-violet-700 underline decoration-dotted underline-offset-2 dark:text-violet-300">
                {{ __('loops.why_memory_see_correction') }}
            </button>
            @endif
        </p>
        @endforeach
    </div>
    @endif
    @if($compact)
    {{-- Dense : date, preuves et gestes tiennent sur UNE ligne qui se replie.
         C'est ce qui ramène une entrée de cinq rangées à deux. --}}
    <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1">
        @if($entry['observed_at'] || ! $entry['same_loop'])
        <span class="text-[11px] leading-4 text-gray-500 dark:text-gray-400">
            @if($entry['observed_at']){{ __('loops.why_memory_observed', ['date' => $entry['observed_at']]) }}@endif
            @unless($entry['same_loop']) · {{ __('loops.why_memory_scope_other', ['loop' => $entry['loop_name']]) }}@endunless
        </span>
        @endif
        @if($entry['evidence_message_ids'] !== [])
        <span class="flex flex-wrap items-center gap-1.5" data-memory-evidence>
            @foreach($entry['evidence_message_ids'] as $evidenceId)
            <button type="button" wire:click="showMessageInThread('{{ $evidenceId }}')"
                    class="rounded-full border border-gray-200 bg-white px-2 py-0.5 text-[10px] font-semibold text-gray-600 transition hover:bg-gray-50 hover:text-gray-900 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-gray-800">
                {{ __('loops.why_memory_evidence', ['n' => $loop->iteration]) }}
            </button>
            @endforeach
        </span>
        @endif
        @if($gesteOffert && ! $enCorrection)
        <span class="flex flex-wrap gap-1.5">
            @include('livewire.partials.memory-entry-actions', ['entry' => $entry, 'ancre' => $ancre, 'correctPrefix' => $correctPrefix])
        </span>
        @endif
    </div>
    @endif
    @if($gesteOffert)
        @if($enCorrection)
        <form wire:submit.prevent="submitCorrection" class="mt-2 space-y-2 rounded-lg border border-violet-200 bg-violet-50/60 p-2.5 dark:border-violet-800/50 dark:bg-violet-950/30" {{ $correctPrefix }}-form>
            <p class="text-[11px] font-semibold text-gray-900 dark:text-gray-100">{{ __('loops.correct_form_title') }}</p>
            <p class="text-[11px] leading-4 text-gray-500 dark:text-gray-400">{{ __('loops.correct_scope') }} {{ __('loops.correct_form_note') }}</p>
            {{-- REMÉDIATION R2 (F2) : le mode se change par une ACTION, pas par
                 liaison de propriété — `$correctingMode` est `#[Locked]`, son
                 domaine est contrôlé une fois, à l'entrée. L'état coché vient
                 du serveur. --}}
            <div class="flex flex-wrap gap-3 text-[11px] text-gray-700 dark:text-gray-300">
                <label class="inline-flex items-center gap-1.5"><input type="radio" name="correction-mode" wire:click="setCorrectionMode('update')" @checked($correctingMode === 'update') {{ $correctPrefix }}-mode-update class="h-3 w-3">{{ __('loops.correct_mode_update') }}</label>
                <label class="inline-flex items-center gap-1.5"><input type="radio" name="correction-mode" wire:click="setCorrectionMode('retract')" @checked($correctingMode === 'retract') {{ $correctPrefix }}-mode-retract class="h-3 w-3">{{ __('loops.correct_mode_retract') }}</label>
            </div>
            @if($correctingMode === 'update')
            <div>
                <label for="correction-new-text" class="block text-[11px] font-medium text-gray-700 dark:text-gray-300">{{ __('loops.correct_new_text_label') }}</label>
                <input type="text" id="correction-new-text" wire:model="correctionNewText" {{ $correctPrefix }}-new-text
                       class="mt-1 w-full rounded-lg border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                @error('correctionNewText')<p class="mt-0.5 text-[11px] text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
            </div>
            @endif
            <div>
                <label for="correction-text" class="block text-[11px] font-medium text-gray-700 dark:text-gray-300">{{ __('loops.correct_text_label') }}</label>
                <textarea wire:model="correctionText" id="correction-text" rows="2" {{ $correctPrefix }}-text placeholder="{{ __('loops.correct_text_placeholder') }}"
                          class="mt-1 w-full rounded-lg border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"></textarea>
                @error('correctionText')<p class="mt-0.5 text-[11px] text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" wire:click="cancelCorrection"
                        class="rounded-lg border border-gray-200 px-2.5 py-1 text-[11px] font-semibold text-gray-600 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-800">
                    {{ __('loops.correct_cancel') }}
                </button>
                <button type="submit" wire:loading.attr="disabled" wire:target="submitCorrection" {{ $correctPrefix }}-submit
                        class="rounded-lg bg-violet-600 px-2.5 py-1 text-[11px] font-semibold text-white transition hover:bg-violet-700 disabled:opacity-50">
                    {{ __('loops.correct_submit') }}
                </button>
            </div>
        </form>
        @else
            @unless($compact)
            <div class="mt-1.5 flex flex-wrap gap-1.5">
                @include('livewire.partials.memory-entry-actions', ['entry' => $entry, 'ancre' => $ancre, 'correctPrefix' => $correctPrefix])
            </div>
            @endunless
        @endif
    @endif
</li>
