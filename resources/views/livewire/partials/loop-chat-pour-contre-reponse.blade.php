{{--
    TASK-1621 — UNE reponse dans la carte de debat.

    C'est le MEME composant de bulle que le reste du fil. Ce qui change, ce
    sont les props — pas le composant :
      - `width-class` : la bulle occupe sa colonne au lieu de plafonner ;
      - ni `name`, ni `time`, ni `reply-to` : l'en-tete de section porte
        l'identite, et la question est citee UNE fois en tete de carte ;
      - ni `ai-mode`, ni `ai-mode-label` : le badge vit dans l'en-tete de
        section, il ne consomme plus une ligne en bas de bulle ;
      - `ai-truncated` reste : « Reponse ecourtee » ne se perd jamais.

    AUCUNE action par colonne (decision Cyril 22/09) : « Ajouter au Dossier »
    et « Copier » sont des gestes UNIQUES de la carte, portes par sa barre
    d'actions — ils concernent le debat entier, pas un camp. Le pied
    « Ajouter au Dossier » par reponse a donc disparu d'ici.

    Les booleens d'action sont LIES (`:`), jamais passes en chaine : la
    chaine "false" est truthy et rallumait le bouton copier dans chaque
    bulle — c'est l'icone en double constatee sur la capture de recette.
--}}
<x-conversation.message-bubble
    type="received"
    width-class="w-full max-w-none"
    :ai-truncated="($msg->metadata['partial'] ?? null) === 'output_truncated'"
    :message-id="$msg->id"
    :show-reply-button="false"
    :show-pin-button="false"
    :show-copy-button="false"
    :show-delete-button="false"
    :show-reactions="false"
    :is-edited="$msg->edited_at !== null"
>
    {!! $msg->body !!}
</x-conversation.message-bubble>
