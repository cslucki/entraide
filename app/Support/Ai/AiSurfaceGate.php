<?php

namespace App\Support\Ai;

use App\Ai\ProviderResolver;
use App\Models\Organization;

/**
 * TASK-1693 bug AF — « l'IA est-elle utilisable ici ? », pour les ECRANS.
 *
 * ## Le defaut
 *
 * Une Organization dont le SuperAdmin a decoche « Activer les fonctions IA »
 * continuait de se voir offrir : l'etape « Creer mon agent IA » du tableau de
 * bord, l'aide a reformuler sur Proposer et Demander de l'aide, la generation
 * d'article, la carte « Questionner un passage » et « Generer la synthese »
 * d'un Dossier.
 *
 * Le serveur, lui, REFUSAIT deja : `ProviderResolver` leve avant tout appel.
 * Aucune capacite ne fuyait — c'etaient des poignees posees sur une porte
 * muree. Mais une poignee qui ne mene nulle part est un defaut a part entiere
 * (meme famille que le bug K, ou « Rejoindre » menait a un 403).
 *
 * ## Pourquoi ce service, et pas un drapeau lu dans chaque vue
 *
 * `ProviderResolver::isAvailableFor()` repond « `resolve()` aboutirait-il ? »
 * en lisant la MEME liste de conditions que la garde qui refuse — pas une
 * copie. Lire `is_enabled` a la main laisserait visibles les surfaces d'une
 * Organization activee mais SANS credential : le premier geste echouerait,
 * soit le meme defaut deplace d'un cran. C'est le raisonnement deja tenu par
 * `AiFabContext` (TASK-1685) pour le FAB et le Shell ; ce service l'etend aux
 * ecrans que cette passe-la n'avait pas couverts.
 *
 * La memoisation par identifiant d'Organization evite de rejouer la meme
 * question a chaque appel d'une page qui l'interroge plusieurs fois.
 */
class AiSurfaceGate
{
    /** @var array<string, bool> */
    private array $memo = [];

    public function __construct(private readonly ProviderResolver $resolver) {}

    /** L'IA est-elle utilisable pour cette Organization ? */
    public function availableFor(?string $organizationId): bool
    {
        if ($organizationId === null || $organizationId === '') {
            return false;
        }

        return $this->memo[$organizationId] ??= $this->resolver->isAvailableFor($organizationId);
    }

    /** La meme question, pour l'Organization de la requete courante. */
    public function availableHere(): bool
    {
        return $this->availableFor($this->organizationCourante()?->id);
    }

    /**
     * Les AGENTS DE PROFIL demandent DEUX conditions.
     *
     * Le reglage « Agents profil IA par organisation » (`ai_profiles_enabled`,
     * pose depuis `/admin/ai-config`) est un choix PRODUIT, distinct de la
     * disponibilite technique. Les deux doivent etre vrais : un agent de
     * profil autorise sur une Organization dont l'IA est eteinte ne pourrait
     * rien produire.
     */
    public function memberAgentAvailableFor(?Organization $organization): bool
    {
        if ($organization === null) {
            return false;
        }

        return $organization->ai_profiles_enabled && $this->availableFor($organization->id);
    }

    public function memberAgentAvailableHere(): bool
    {
        return $this->memberAgentAvailableFor($this->organizationCourante());
    }

    private function organizationCourante(): ?Organization
    {
        $organization = function_exists('currentOrganization') ? currentOrganization() : null;

        return $organization instanceof Organization ? $organization : null;
    }
}
