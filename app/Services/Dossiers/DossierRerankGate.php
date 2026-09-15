<?php

namespace App\Services\Dossiers;

use App\Models\Organization;

/**
 * TASK-1562 — QUI a le droit de reranker, Organization par Organization.
 *
 * ## Pourquoi une porte, et pas un interrupteur
 *
 * TASK-1560 a livre la CAPACITE de reranker, derriere un unique
 * `ai.knowledge.rerank.enabled`. C'est tout ou rien, par environnement : on ne
 * peut pas l'allumer pour une Organization pilote sans l'allumer pour TOUTES
 * celles qui partagent le meme environnement — avec un appel provider de plus
 * a chaque question documentaire, facture au tenant.
 *
 * Un pilote qu'on ne peut pas borner n'est pas un pilote.
 *
 * ## Le defaut est FERME, et ce n'est pas un detail
 *
 * Sans allowlist, les deux listes sont vides et cette porte rend `false`.
 * PERSONNE n'est active par omission. Le seul moyen d'activer une Organization
 * est de la nommer — par son id ou par son slug.
 *
 * Deux verrous en serie, et non un seul : le drapeau maitre
 * `ai.knowledge.rerank.enabled` coupe tout l'environnement, l'allowlist
 * designe qui, dans cet environnement, est concerne. Couper le premier suffit
 * a tout eteindre sans avoir a defaire les listes.
 *
 * ## Pourquoi une classe SOEUR de DossierSemanticSearchGate
 *
 * Ce Gate reproduit exactement la semantique de
 * `DossierSemanticSearchGate` — motif deja eprouve dans ce depot. Mais il ne
 * la REUTILISE pas : qu'une Organization ait la recherche semantique n'implique
 * pas qu'elle doive financer un rerank. Ce sont deux decisions distinctes, et
 * les confondre rendrait impossible d'ouvrir le pilote a une seule.
 *
 * ## Tenant
 *
 * Organization = Tenant. Cette porte ne connait QUE l'Organization courante :
 * jamais un utilisateur global, jamais une Loop, jamais `community_id`.
 */
class DossierRerankGate
{
    public function isEnabledFor(string $organizationId): bool
    {
        if (! (bool) config('ai.knowledge.rerank.enabled', false)) {
            return false;
        }

        $organizationId = $this->normalize($organizationId);

        if ($organizationId === '') {
            return false;
        }

        foreach ($this->configuredOrganizationIds() as $allowedId) {
            if ($organizationId === $allowedId) {
                return true;
            }
        }

        // Le slug se verifie EN BASE, et borne a cette Organization
        // (`whereKey`) : un slug de l'allowlist n'autorise que l'Organization
        // qui le porte, jamais une autre qui aurait le meme identifiant a un
        // caractere pres.
        $slugs = $this->configuredOrganizationSlugs();

        return $slugs !== [] && Organization::query()
            ->whereKey($organizationId)
            ->whereIn('slug', $slugs)
            ->exists();
    }

    /**
     * @return array<int, string>
     */
    private function configuredOrganizationIds(): array
    {
        return $this->listeConfiguree('ai.knowledge.rerank.organization_ids');
    }

    /**
     * @return array<int, string>
     */
    private function configuredOrganizationSlugs(): array
    {
        return $this->listeConfiguree('ai.knowledge.rerank.organization_slugs');
    }

    /**
     * Une liste d'allowlist peut arriver de deux facons : deja decoupee par
     * `config/ai.php`, ou en une seule chaine separee par des virgules si
     * quelqu'un l'ecrit ainsi. Les deux sont acceptees, et TOUTE valeur qui
     * n'est ni l'une ni l'autre rend une liste VIDE — c'est-a-dire ferme la
     * porte. Une configuration qu'on ne sait pas lire n'ouvre rien.
     *
     * @return array<int, string>
     */
    private function listeConfiguree(string $cle): array
    {
        $valeurs = config($cle, []);

        if (is_string($valeurs)) {
            $valeurs = explode(',', $valeurs);
        }

        if (! is_array($valeurs)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (mixed $v): string => $this->normalize(is_scalar($v) ? (string) $v : ''), $valeurs),
            fn (string $v): bool => $v !== ''
        ));
    }

    /**
     * `mb_strtolower(trim())`, comme `DossierSemanticSearchGate` — et pas
     * seulement `trim()`.
     *
     * La revue du SHA dff7fe52 a trouve l'ecart : mon docblock annoncait la
     * MEME semantique que le Gate voisin, et la casse n'etait pas traitee. Un
     * slug d'allowlist saisi `Pilote-Cohere` alors que la base porte
     * `pilote-cohere` ne matchait pas.
     *
     * L'echec etait FERME, donc sans danger. Mais il etait SILENCIEUX, et il
     * se produisait au moment precis ou un exploitant croit ouvrir son pilote.
     * Une porte qui refuse sans rien dire a quelqu'un qui vient de la
     * deverrouiller est un piege, pas une securite.
     */
    private function normalize(string $valeur): string
    {
        return mb_strtolower(trim($valeur));
    }
}
