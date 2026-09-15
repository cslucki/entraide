<?php

namespace App\Ai\Context;

use App\Ai\ContexteIa;
use App\Ai\ProviderResolver;
use App\Models\AiProviderInvocation;
use App\Services\Ai\AiProviderInvocationLedger;
use App\Services\Dossiers\DossierSemanticSearchService;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\Data\RankedDocument;
use Throwable;

/**
 * TASK-1560 — le RERANK documentaire, entre le bassin de candidats et la
 * selection finale.
 *
 * ## Ce qu'il fait, et c'est tout
 *
 * Il REORDONNE des lignes qu'on lui donne. Il n'en cherche aucune, n'en ajoute
 * aucune, n'en retire aucune. L'univers des candidats a deja ete borne par
 * l'ACL bien en amont ; ce service ne le voit meme pas se constituer.
 *
 * ## Pourquoi la securite tient par CONSTRUCTION
 *
 * La sortie est batie en INDEXANT dans le tableau d'entree
 * (`RankedDocument::index`). Il n'existe aucun chemin de code capable de
 * produire une ligne qui n'y etait pas : un document d'une autre Organization
 * ne peut pas apparaitre, non parce qu'on le filtre ensuite, mais parce qu'on
 * n'a jamais eu de quoi l'inventer. C'est l'architecture exigee —
 * `ACL -> Dense -> Cohere`, jamais `retrieval global -> Cohere -> filtre ACL`.
 *
 * ## Le repli n'est pas un rattrapage
 *
 * Toute defaillance — rerank desactive, aucun credential tenant, provider
 * injoignable, reponse inattendue — rend le tableau d'entree TEL QUEL. L'ordre
 * dense n'est ni recalcule, ni re-trie, ni reconstruit : c'est le meme tableau,
 * dans le meme ordre. Un rerank absent doit etre indiscernable, pour l'aval,
 * d'un rerank qui n'a jamais existe.
 *
 * ## Mode B
 *
 * Le document soumis reproduit la SEMANTIQUE de representation validee au
 * Bench — l'en-tete factuel du document, puis le texte du chunk — avec les
 * champs du produit :
 *
 *     Bench    "Document : {titre}" [+ "\nSection : {heading}"] + "\n\n" + {texte}
 *     Produit  "Document : " . displayTitle($row) . "\n\n" . $row['content']
 *
 * `displayTitle()` est l'autorite existante qui nomme un document pour les
 * TROIS familles de sources (article, fichier, connaissance derivee). La ligne
 * `Section :` du Bench etait optionnelle et n'a pas d'equivalent produit : les
 * lignes de retrieval ne portent aucun heading de section. Elle est donc OMISE,
 * jamais fabriquee.
 */
final class DossierRerank
{
    /**
     * En deca de deux candidats, reordonner ne peut rien changer : on
     * n'appelle pas un provider pour confirmer un ordre qui n'a pas d'alternative.
     */
    private const MIN_CANDIDATES = 2;

    public function __construct(
        private readonly ProviderResolver $providers,
        private readonly AiProviderInvocationLedger $ledger,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $rows  candidats DEJA bornes par l'ACL
     */
    public function order(ContexteIa $contexte, string $query, array $rows): DossierRerankOutcome
    {
        if (count($rows) < self::MIN_CANDIDATES || trim($query) === '') {
            return DossierRerankOutcome::notAttempted($rows);
        }

        // La RESOLUTION du credential est sous le meme filet que l'appel
        // provider, et pas devant lui.
        //
        // `resolveRerankingInstance()` ne rend pas seulement `null` : sur une
        // configuration incomplete — rerank actif mais `url` ou `model` vide —
        // elle leve `DomainException`. Hors du `try`, cette exception
        // traversait tout : `ContextBuilder` n'attrape que `SourceDenied`, donc
        // une variable d'environnement videe faisait tomber la source
        // documentaire ENTIERE au lieu de la laisser continuer en ordre dense.
        //
        // Cette classe promet qu'aucune defaillance ne traverse. Une promesse
        // qui a une exception n'en est pas une.
        try {
            $instance = $this->providers->resolveRerankingInstance($contexte->organizationId);
        } catch (Throwable $exception) {
            // La raison est portee jusqu'au log : une configuration cassee qui
            // desactive le rerank en silence serait indiscernable d'un tenant
            // sans credential, et se deguiserait en « rien a reranker ».
            return DossierRerankOutcome::notAttempted($rows, $exception::class);
        }

        // Pas de credential tenant capable de reranker : le chemin documentaire
        // continue sur l'ordre dense. Aucun repli plateforme, jamais.
        if ($instance === null) {
            return DossierRerankOutcome::notAttempted($rows);
        }

        $model = (string) config('ai.knowledge.rerank.model');
        $documents = array_map(static fn (array $row): string => self::document($row), $rows);

        $debut = microtime(true);

        try {
            $reponse = Reranking::of($documents)->rerank($query, $instance, $model);

            $ordonnees = [];

            foreach ($reponse->results as $resultat) {
                if (! $resultat instanceof RankedDocument) {
                    continue;
                }

                // La SEULE facon d'obtenir une ligne : la reprendre dans le
                // tableau d'entree. Un index hors bornes est ignore, il ne
                // fabrique pas de ligne.
                if (array_key_exists($resultat->index, $rows)) {
                    $ordonnees[$resultat->index] = $rows[$resultat->index];
                }
            }

            // Un reranker peut rendre moins de resultats qu'il n'a recu. Les
            // candidats qu'il n'a pas classes ne sont pas PERDUS : ils
            // reprennent leur rang dense derriere ceux qu'il a classes. Le
            // bassin sort donc toujours complet — reordonne, jamais ampute.
            foreach ($rows as $i => $row) {
                if (! array_key_exists($i, $ordonnees)) {
                    $ordonnees[$i] = $row;
                }
            }

            $this->ledger->recordRerank(
                $contexte->organizationId, $contexte->userId, $contexte->capability,
                $contexte->source, 'openrouter', $model, $instance,
                AiProviderInvocation::STATUS_SUCCESS, null,
                $contexte->correlationId, $debut, $contexte->feature,
            );

            return new DossierRerankOutcome(
                rows: array_values($ordonnees),
                attempted: true,
                succeeded: true,
                candidateCount: count($rows),
                provider: 'openrouter',
                model: $model,
                durationMs: (int) round((microtime(true) - $debut) * 1000),
            );
        } catch (Throwable $exception) {
            // Convention d'echec du depot : la tentative est inscrite au ledger
            // avec la CLASSE de l'exception, jamais son message — il pourrait
            // porter du contenu de passage.
            $this->ledger->recordRerank(
                $contexte->organizationId, $contexte->userId, $contexte->capability,
                $contexte->source, 'openrouter', $model, $instance,
                AiProviderInvocation::STATUS_FAILED, $exception::class,
                $contexte->correlationId, $debut, $contexte->feature,
            );

            return new DossierRerankOutcome(
                rows: $rows,
                attempted: true,
                succeeded: false,
                candidateCount: count($rows),
                provider: 'openrouter',
                model: $model,
                durationMs: (int) round((microtime(true) - $debut) * 1000),
                failureReason: $exception::class,
            );
        }
    }

    /**
     * Mode B — l'en-tete factuel du document, puis le texte du chunk. Rien
     * n'est resume, rien n'est invente : le titre vient de l'autorite qui le
     * rend deja au lecteur.
     *
     * @param  array<string, mixed>  $row
     */
    private static function document(array $row): string
    {
        $titre = trim(DossierSemanticSearchService::displayTitle($row));
        $contenu = (string) ($row['content'] ?? '');

        return 'Document : '.$titre."\n\n".$contenu;
    }
}
