<?php

namespace App\Services\Dossiers;

use App\Services\Ai\AiRerankSettings;

/**
 * TASK-1562 puis TASK-1563 — QUI a le droit de reranker, Organization par
 * Organization.
 *
 * ## Pourquoi une porte, et pas un interrupteur
 *
 * TASK-1560 a livre la CAPACITE de reranker derriere un unique drapeau
 * d'environnement. C'etait tout ou rien : on ne pouvait pas l'allumer pour une
 * Organization pilote sans l'allumer pour TOUTES celles qui partagent le meme
 * environnement — avec un appel provider de plus a chaque question
 * documentaire, facture au tenant.
 *
 * Un pilote qu'on ne peut pas borner n'est pas un pilote.
 *
 * ## Deux verrous en serie
 *
 *     plateforme OFF            -> PERSONNE ne reranke
 *     plateforme ON + org OFF   -> cette Organization ne reranke pas
 *     plateforme ON + org ON    -> cette Organization peut reranker
 *
 * Le drapeau plateforme est l'arret d'urgence : le couper eteint tout, sans
 * avoir a repasser sur chaque Organization.
 *
 * ## Le defaut est FERME, et ce n'est pas un detail
 *
 * La colonne `organization_ai_settings.rerank_enabled` a `default(false)`, et
 * une Organization sans reglages IA n'a aucun drapeau. PERSONNE n'est active
 * par omission : le seul moyen d'ouvrir une Organization est de cocher sa case.
 *
 * ## Ce que TASK-1563 a retire
 *
 * L'allowlist d'environnement par Organization (`..._ORGANIZATION_IDS` /
 * `_SLUGS`) N'EXISTE PLUS. Elle a ete remplacee par un interrupteur d'ecran, et
 * les deux ne pouvaient pas coexister : une Organization listee dans
 * l'environnement mais eteinte a l'ecran aurait rerankee quand meme, ce qui
 * rendait la table de verite ci-dessus indefendable.
 *
 * ## Tenant
 *
 * Organization = Tenant. Cette porte ne connait QUE l'Organization courante :
 * jamais un utilisateur global, jamais une Loop, jamais `community_id`.
 */
class DossierRerankGate
{
    public function __construct(private readonly AiRerankSettings $settings) {}

    public function isEnabledFor(string $organizationId): bool
    {
        // Premier verrou : l'arret d'urgence. On ne lit meme pas la base de
        // l'Organization si la plateforme est eteinte.
        if (! $this->settings->platformEnabled()) {
            return false;
        }

        // Second verrou : cette Organization, nommement.
        return $this->settings->organizationEnabled($organizationId);
    }
}
