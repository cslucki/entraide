<?php

namespace App\Support\Ai;

use App\Models\UsageReference;
use App\Services\UsageReference\UsageReferenceResolver;

/**
 * TASK-1477 — « a quoi sert cet endroit », pour le Shell MEMBRE.
 *
 * ## La cause, pas le symptome
 *
 * Le Shell nommait correctement la surface — « Vous etes sur l'agenda » — puis
 * enchainait partout sur la meme negation : « Cette page n'a pas d'action IA
 * qui lui soit propre. » La phrase n'etait pas fausse ; elle etait la SEULE
 * chose disponible, parce que la couche qui explique un lieu n'avait aucun
 * consommateur cote membre. TASK-1473 l'avait deja constate :
 * `GuestPublicContextBuilder` etait le seul appelant du resolver.
 *
 * ## Ce que cette classe est, et n'est pas
 *
 * Elle est un LECTEUR. Elle ne resout pas de surface (`AiShellPageContext` le
 * fait), ne calcule aucune action (`AiFabContext` le fait), n'accorde aucun
 * droit et n'ecrit rien. Elle demande une reference publiee a l'autorite qui
 * existe deja — `UsageReferenceResolver`, avec son repli de locale et son
 * fail-closed — et la rend, ou rend `null`.
 *
 * ## Le verrou qui compte : une reference PUBLIQUE ne franchit pas
 *
 * `UsageReference::SURFACES` contient desormais les surfaces publiques ET
 * membre. Sans garde, une cle homonyme servirait au membre l'aide ecrite pour
 * un visiteur — precisement le risque que TASK-1473 avait desamorce en
 * separant `organization_home` de `dashboard`.
 *
 * Cette classe n'accepte donc QUE `SURFACES_MEMBER`. Une surface publique, une
 * surface inconnue, `unknown` : `null`. Un test le mesure en publiant une
 * reference pour l'accueil public et en verifiant qu'elle n'atteint aucune
 * page membre.
 */
final class AiShellUsageReference
{
    public function __construct(private readonly UsageReferenceResolver $resolver) {}

    /**
     * @return array{title: string, content: string}|null
     */
    public function forSurface(string $surface, string $locale): ?array
    {
        if (! in_array($surface, UsageReference::SURFACES_MEMBER, true)) {
            return null;
        }

        $reference = $this->resolver->resolve($surface, $locale);

        if ($reference === null) {
            return null;
        }

        $content = trim((string) $reference->content);

        // Une reference vide n'est pas une reference : mieux vaut le repli
        // neutre qu'un bloc d'explication qui n'explique rien.
        if ($content === '') {
            return null;
        }

        return ['title' => trim((string) $reference->title), 'content' => $content];
    }
}
