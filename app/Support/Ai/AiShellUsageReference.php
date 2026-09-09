<?php

namespace App\Support\Ai;

use App\Models\UsageReference;
use App\Services\UsageReference\UsageReferenceResolver;
use Illuminate\Support\Str;

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
    /**
     * La borne de l'extrait INJECTE dans le prompt. Voir `groundingFor()` :
     * elle est derivee de la longueur reelle des references publiees et du
     * budget de contexte du Shell, pas d'un chiffre rond.
     */
    public const GROUNDING_MAX_CHARS = 1200;

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

    /**
     * TASK-1484 — le MEME texte, mais pour ANCRER le modele au lieu d'etre
     * recite a l'ecran.
     *
     * ## Le defaut mesure, et son sens exact
     *
     * `usage_reference` n'avait, avant cette TASK, qu'un seul consommateur cote
     * membre : le rendu Blade du Shell. `grep` dans le chemin du prompt : zero
     * occurrence. Le seul endroit du produit ou une UsageReference atteignait
     * reellement un modele etait `GuestPublicContextBuilder` — le Shell
     * VISITEUR.
     *
     * Le membre avait donc l'inverse de ce qu'il fallait : le texte etait
     * affiche en permanence a quelqu'un qui n'avait rien demande, et absent de
     * la seule chose qui aurait pu s'en servir. « C'est quoi cette page ? » sur
     * l'agenda recevait une reponse generique sur les categories et les
     * boucles — pas une hallucination : une absence d'ancrage.
     *
     * ## Pourquoi une methode distincte de `forSurface()`
     *
     * Parce que les deux usages n'ont pas le meme budget. L'ecran peut afficher
     * un texte de 4 000 caracteres ; un prompt qui en heriterait repousserait
     * la garde de langue et le transcript hors de la fenetre du modele —
     * exactement ce que `situated()` documente vouloir eviter en posant la
     * langue EN TETE.
     *
     * ## La borne est MESUREE, pas choisie
     *
     * Longueur reelle des references publiees en base au 2026-09-09 :
     * 207 a 513 caracteres cote membre (maximum : `agenda` FR, 513). La borne
     * est posee a 1 200 — plus du double de la plus longue reference reelle, et
     * moins d'un tiers de `ai.shell.max_context_chars` (4 000). Une reference
     * ecrite au maximum autorise ne peut donc pas evincer le fil.
     *
     * ## Ce texte est une AUTORITE, pas une donnee d'utilisateur
     *
     * Il est ecrit et publie par un humain de la plateforme, comme le prompt
     * administrable. Il n'est donc pas tronque a 120 comme un nom d'objet, qui
     * lui vient d'un membre. Il n'accorde toujours AUCUN droit et ne promet
     * aucune fonction : le runtime reste seul juge de ce qui est possible.
     */
    public function groundingFor(string $surface, string $locale): ?string
    {
        $reference = $this->forSurface($surface, $locale);

        if ($reference === null) {
            return null;
        }

        return __('ai.shell_prompt_usage_reference', [
            'title' => $reference['title'],
            'content' => Str::limit($reference['content'], self::GROUNDING_MAX_CHARS, '…'),
        ]);
    }
}
