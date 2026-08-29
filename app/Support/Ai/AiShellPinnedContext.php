<?php

namespace App\Support\Ai;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Session;

/**
 * TASK-1326 (Shell-2) — le contexte epingle du Shell « BouclePro IA » :
 * quelques objets que l'utilisateur choisit de garder sous la main pendant
 * qu'il navigue, et que chaque tour de conversation recoit comme indice
 * d'intention.
 *
 * ## La doctrine, en une phrase
 *
 * Un pin est VISIBLE, RETIRABLE, BORNE, et REVALIDE a chaque usage — jamais
 * une permission persistante, jamais une copie privee durable.
 *
 * Concretement :
 *
 * - **Ce qui est stocke** : des REFERENCES `{kind, id}` en session Laravel,
 *   cloisonnees par Organization dans la cle elle-meme. Jamais un libelle,
 *   jamais une URL, jamais un extrait de contenu — exactement l'idiome des
 *   cartes de tour (T1325) et de `suggested_loop_id` (T1315). La session
 *   expire : c'est la « persistance raisonnable » de la spec, pas une memoire
 *   longue duree.
 *
 * - **Ce qui est revalide** : CHAQUE usage (epingler, afficher, injecter dans
 *   un tour) rejoue `AiShellPageContext::resolve()` — c'est-a-dire la garde de
 *   la page qui gouverne deja l'objet (LoopController::show /
 *   DossierPolicy::view / BlogController::show + garde Manifeste prive T1079).
 *   Un pin dont l'objet ne passe plus sa garde est RETIRE de la session au
 *   moment ou on le constate : ce qui ne passe plus n'existe plus, et
 *   l'utilisateur voit exactement ce qui reste epingle.
 *
 * - **Ce qui est borne** : `ai.shell.max_pins` objets au plus, et la borne est
 *   STRUCTURELLE — `references()` tronque ce qu'elle relit, une session qui en
 *   porterait davantage n'en rend jamais plus.
 *
 * - **La whitelist est structurelle** : Boucle, Dossier, Article. Pas de
 *   Personne en V1 — l'eligibilite People-1 est UNE PROPRIETE D'UNE BOUCLE
 *   (`eligibleFor(organization, loop, user)`), il n'existe aucune autorite qui
 *   dise « cette personne est montrable hors de toute Boucle » ; un pin etant
 *   precisement hors-Boucle, les permissions ne sont pas « claires » au sens
 *   de la spec, donc pas de PersonPin. Un kind inconnu n'est ni epingle, ni
 *   rendu, ni injecte.
 *
 * ## Epingler n'accorde rien
 *
 * `pin()` accepte un couple (kind, id) venu du client et le REVALIDE — meme
 * posture que `prepareRequest($messageId)` (T1325) : un identifiant forge ne
 * peut epingler qu'un objet que l'utilisateur peut deja voir, dans SA
 * Organization, ce qu'il obtiendrait de toute facon en visitant la page. Un
 * objet refuse par sa garde n'est pas epingle.
 */
final class AiShellPinnedContext
{
    /** La whitelist des objets epinglables — Personne exclue en V1 (cf. ci-dessus). */
    public const KINDS = [
        AiShellPageContext::KIND_LOOP,
        AiShellPageContext::KIND_DOSSIER,
        AiShellPageContext::KIND_ARTICLE,
    ];

    public const PIN_OK = 'ok';

    public const PIN_DUPLICATE = 'duplicate';

    public const PIN_LIMIT_REACHED = 'limit_reached';

    public const PIN_REFUSED = 'refused';

    public function __construct(private readonly AiShellPageContext $pageContext) {}

    public function limit(): int
    {
        return max(1, (int) config('ai.shell.max_pins', 3));
    }

    /**
     * Les references epinglees de CETTE Organization — assainies et bornees a
     * la relecture : une entree hors whitelist ou excedentaire n'existe pas,
     * quelle que soit la session.
     *
     * @return list<array{kind: string, id: string}>
     */
    public function references(Organization $organization): array
    {
        $references = [];

        foreach ((array) Session::get($this->key($organization), []) as $entry) {
            if (is_array($entry)
                && in_array($entry['kind'] ?? null, self::KINDS, true)
                && is_string($entry['id'] ?? null)
                && $entry['id'] !== '') {
                $references[] = ['kind' => (string) $entry['kind'], 'id' => $entry['id']];
            }
        }

        return array_slice($references, 0, $this->limit());
    }

    /**
     * Epingle un objet — apres l'avoir REVALIDE a l'instant du geste. Le
     * retour dit pourquoi rien ne s'est passe, sans jamais distinguer
     * « inexistant » d'« interdit » : un refus est un refus.
     */
    public function pin(Organization $organization, User $user, ?string $kind, ?string $objectId): string
    {
        if (! in_array($kind, self::KINDS, true) || $objectId === null || $objectId === '') {
            return self::PIN_REFUSED;
        }

        $resolved = $this->pageContext->resolve($user, $organization, $kind, $objectId);

        if (! is_array($resolved['object'] ?? null)) {
            return self::PIN_REFUSED;
        }

        $references = $this->references($organization);

        foreach ($references as $reference) {
            if ($reference['kind'] === $kind && $reference['id'] === $objectId) {
                return self::PIN_DUPLICATE;
            }
        }

        if (count($references) >= $this->limit()) {
            return self::PIN_LIMIT_REACHED;
        }

        $references[] = ['kind' => $kind, 'id' => $objectId];
        Session::put($this->key($organization), $references);

        return self::PIN_OK;
    }

    /**
     * Retire une reference. Aucune garde a rejouer : on ne fait que retrancher
     * une entree de la PROPRE session de l'utilisateur — une valeur forgee ne
     * retire rien ou retire un pin, deux issues inoffensives.
     */
    public function unpin(Organization $organization, ?string $kind, ?string $objectId): void
    {
        $references = array_values(array_filter(
            $this->references($organization),
            fn (array $reference): bool => ! ($reference['kind'] === $kind && $reference['id'] === $objectId),
        ));

        Session::put($this->key($organization), $references);
    }

    /**
     * Les pins AFFICHABLES et INJECTABLES, re-resolus MAINTENANT par la garde
     * de leur page. Ce qui ne passe plus est retire de la session ici meme :
     * la liste rendue a l'ecran, la liste injectee au tour et la liste stockee
     * sont TOUJOURS la meme liste.
     *
     * @return list<array{kind: string, id: string, label: string, url: string}>
     */
    public function resolved(Organization $organization, User $user): array
    {
        $references = $this->references($organization);
        $kept = [];
        $resolved = [];

        foreach ($references as $reference) {
            $result = $this->pageContext->resolve($user, $organization, $reference['kind'], $reference['id']);
            $object = $result['object'] ?? null;

            if (! is_array($object)) {
                continue;
            }

            $kept[] = $reference;
            $resolved[] = [
                'kind' => $reference['kind'],
                'id' => $reference['id'],
                'label' => (string) $object['label'],
                'url' => (string) $object['url'],
            ];
        }

        if ($kept !== $references) {
            Session::put($this->key($organization), $kept);
        }

        return $resolved;
    }

    /**
     * Organization = Tenant : la frontiere est portee par la CLE de session
     * elle-meme, en plus de la revalidation — changer d'Organization change de
     * pins sans qu'aucun appelant ait a y penser.
     */
    private function key(Organization $organization): string
    {
        return 'ai_shell.pins.'.$organization->id;
    }
}
