{{--
    TASK-1621 — UNE reponse dans la carte de debat.

    C'est le MEME composant de bulle que le reste du fil : les actions
    (repondre, epingler, copier, supprimer, reactions) restent celles du
    message, et agissent sur CE message-la. Rien n'est reimplemente.

    Ce qui change, ce sont les props — pas le composant :
      - `width-class` : la bulle occupe sa colonne au lieu de plafonner ;
      - ni `name`, ni `time`, ni `reply-to` : l'en-tete de section porte
        l'identite, et la question est citee UNE fois en tete de carte ;
      - ni `ai-mode`, ni `ai-mode-label` : le badge vit dans l'en-tete de
        section, il ne consomme plus une ligne en bas de bulle ;
      - `ai-truncated` reste : « Reponse ecourtee » ne se perd jamais.

    Le pied est COMPACT : une seule action, « Ajouter au Dossier », qui appelle
    la meme methode Livewire avec l'identifiant de CE message.
--}}
<x-conversation.message-bubble
    type="received"
    width-class="w-full max-w-none"
    :ai-truncated="($msg->metadata['partial'] ?? null) === 'output_truncated'"
    :message-id="$msg->id"
    {{-- Les icones d'action ne vivent PLUS par colonne (decision de recette) :
         repondre, epingler, copier, supprimer affiches deux fois dans une meme
         carte se lisaient comme une interface en double. Elles remontent au
         niveau de la CARTE, ou elles concernent le debat entier. --}}
    :show-reply-button="false"
    :show-pin-button="false"
    show-copy-button="false"
    :show-delete-button="false"
    :show-reactions="false"
    :is-edited="$msg->edited_at !== null"
>
    <x-slot:footer>
        @if(in_array($msg->id, $capitalizableMessageIds, true))
            {{-- UNE seule action par colonne, et elle agit sur CE message.

                 « Pourquoi cette reponse ? » a ete RETIRE de cette carte
                 (decision de recette) : ce panneau explique une reponse
                 DOCUMENTAIRE — sources consultees, extraits retenus, grounding.
                 « Pour / Contre » ne cite rien et ne consulte aucun Dossier :
                 le panneau n'aurait presque rien a montrer. Le seul element
                 qu'on y lisait encore — le modele appele — vit desormais dans
                 l'en-tete de la colonne, a la vue de tous.

                 Le retrait est LOCAL a ce module : le bouton reste sur toutes
                 les autres bulles IA du fil, ou il a du sens. --}}
            <div class="mt-1 flex items-center border-t border-gray-200/70 pt-1 opacity-100 transition-opacity focus-within:opacity-100 dark:border-gray-700/70 md:opacity-60 md:group-hover:opacity-100">
                <button type="button"
                        @if($canCapitalize)
                            wire:click="startCapitalization('{{ $msg->id }}')"
                            wire:loading.attr="disabled"
                            wire:target="startCapitalization('{{ $msg->id }}')"
                        @else
                            disabled
                        @endif
                        data-capitalize-open="{{ $msg->id }}"
                        data-capitalize-allowed="{{ $canCapitalize ? '1' : '0' }}"
                        title="{{ __('loops.capitalize_action') }}"
                        class="inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700 transition disabled:cursor-not-allowed disabled:opacity-40 enabled:hover:bg-emerald-100 dark:text-emerald-400 dark:enabled:hover:bg-emerald-900/40">
                    <svg class="h-3 w-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 10.5v4m2-2h-4"/></svg>
                    <span>{{ __('loops.capitalize_action') }}</span>
                </button>
            </div>
        @endif
    </x-slot:footer>

    {!! $msg->body !!}
</x-conversation.message-bubble>
