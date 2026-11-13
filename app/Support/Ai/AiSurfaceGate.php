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

    /** Identite de la requete a laquelle `$memo` appartient. */
    private ?int $requeteMemo = null;

    public function __construct(private readonly ProviderResolver $resolver) {}

    /** L'IA est-elle utilisable pour cette Organization ? */
    public function availableFor(?string $organizationId): bool
    {
        if ($organizationId === null || $organizationId === '') {
            return false;
        }

        $this->oublierSiNouvelleRequete();

        return $this->memo[$organizationId] ??= $this->resolver->isAvailableFor($organizationId);
    }

    /**
     * TASK-1813 correctif 05 — le memo meurt avec la requete, pour de vrai.
     *
     * `scoped()` ne suffit PAS a le garantir : dans cette version du
     * framework, `forgetScopedInstances()` n'est appele que par
     * `QueueServiceProvider`. Le noyau HTTP ne l'appelle jamais. En PROD la
     * mort du processus PHP-FPM masque l'ecart (`usesOctane: false`), mais le
     * contrat n'etait tenu que par cet accident d'hebergement.
     *
     * Mesure qui a motive cette garde : deux requetes HTTP successives dans un
     * meme conteneur de test rendaient la MEME instance, et le verdict restait
     * `true` apres extinction de l'IA de l'Organization entre les deux.
     *
     * Le prix est nul : a l'interieur d'une requete, l'identite de l'objet
     * `Request` ne change pas, donc le memo sert exactement comme avant.
     */
    private function oublierSiNouvelleRequete(): void
    {
        $requete = app()->bound('request') ? spl_object_id(app('request')) : null;

        if ($requete !== $this->requeteMemo) {
            $this->memo = [];
            $this->requeteMemo = $requete;
        }
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
