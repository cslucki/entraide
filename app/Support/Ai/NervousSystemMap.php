<?php

namespace App\Support\Ai;

use App\Ai\Constitution;
use App\Ai\NervousSystemCoverage;
use App\Models\Organization;
use App\Models\OrganizationAiConstitution;
use App\Models\OrganizationAiDoctrine;
use App\Models\PlatformAiConstitution;
use Illuminate\Support\Facades\Route;

/**
 * TASK-1481 — le PLAN de la gouvernance IA d'une Organization.
 *
 * ## Ce que cette classe n'est pas
 *
 * Elle n'est **pas une autorite**. Elle ne decide rien, ne configure rien,
 * n'ecrit rien. Elle assemble un plan de situation a partir des autorites qui
 * existent deja, et renvoie vers l'ecran qui gouverne chacune.
 *
 * Le besoin n'etait pas de construire un « systeme nerveux » : mesure faite, il
 * existe. `PlatformAiConstitution`, `OrganizationAiConstitution`,
 * `OrganizationAiDoctrine`, `CapabilityRegistry` et `NervousSystemCoverage`
 * sont tous en place, et `ai-behavior` en montre deja une partie. Le manque
 * etait de pouvoir repondre en UNE page a « pourquoi l'IA de cette
 * Organization se comporte-t-elle ainsi, et ou se regle chaque regle ? » —
 * reponse qui demandait jusqu'ici de connaitre une dizaine d'URL.
 *
 * ## La regle qui evite la carte fictive
 *
 * **Un noeud n'existe que si son autorite existe.** Chaque entree est derivee
 * d'une classe reelle ; si l'objet est absent, le noeud le DIT (« aucune
 * version active ») plutot que de disparaitre ou d'inventer.
 *
 * ## `locked` / `configurable` sont MESURES, pas declares
 *
 * La tentation etait d'ecrire une table de verite a la main — et elle aurait
 * menti au premier changement de route. L'etat est donc derive d'un fait
 * verifiable : **existe-t-il une route d'ECRITURE, dans la zone d'administration
 * de l'Organization, pour cette autorite ?**
 *
 * - oui  -> `configurable` : cet Admin peut la changer ;
 * - non  -> `locked` : elle s'applique a lui, et se gouverne ailleurs.
 *
 * Le jour ou une route d'edition apparait, le plan le refletera sans qu'on y
 * touche. Le jour ou elle disparait, aussi.
 */
final class NervousSystemMap
{
    public const LEVEL_PLATFORM = 'platform';

    public const LEVEL_ORGANIZATION = 'organization';

    public const LEVEL_USER = 'user';

    public const STATE_LOCKED = 'locked';

    public const STATE_CONFIGURABLE = 'configurable';

    public function __construct(private readonly NervousSystemCoverage $coverage) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function forOrganization(Organization $organization): array
    {
        $organizationId = (string) $organization->id;

        return [
            $this->platformConstitution(),
            $this->organizationConstitution($organization, $organizationId),
            $this->doctrine($organization, $organizationId),
            $this->providerSettings($organization),
            $this->capabilities(),
            $this->knowledge($organization),
            $this->consumption($organization),
        ];
    }

    // =================================================================
    // Les noeuds. Chacun lit UNE autorite, et n'en invente aucune.
    // =================================================================

    private function platformConstitution(): array
    {
        $active = PlatformAiConstitution::active();

        return $this->node(
            key: 'platform_constitution',
            level: self::LEVEL_PLATFORM,
            // Aucune route d'ecriture cote Organization : elle s'applique, elle
            // ne se negocie pas. C'est mesure, pas decrete.
            writeRoute: null,
            organization: null,
            status: $active !== null
                ? 'v'.$active->version
                : Constitution::VERSION.' ('.__('ai.map_status_seed').')',
            // Le SuperAdmin la gouverne ailleurs ; le lien n'est propose qu'a
            // qui peut reellement l'ouvrir.
            adminRoute: 'admin.ai-constitution',
            adminRouteParams: [],
            adminRequiresPlatformAdmin: true,
        );
    }

    private function organizationConstitution(Organization $organization, string $organizationId): array
    {
        $active = OrganizationAiConstitution::activeFor($organizationId);

        return $this->node(
            key: 'organization_constitution',
            level: self::LEVEL_ORGANIZATION,
            writeRoute: 'organization.admin.ai-behavior.constitution.update',
            organization: $organization,
            status: $active !== null ? 'v'.$active->version : __('ai.map_status_none'),
            adminRoute: 'organization.admin.ai-behavior',
        );
    }

    private function doctrine(Organization $organization, string $organizationId): array
    {
        $active = OrganizationAiDoctrine::activeFor($organizationId);

        return $this->node(
            key: 'doctrine',
            level: self::LEVEL_ORGANIZATION,
            writeRoute: 'organization.admin.ai-behavior.doctrine.update',
            organization: $organization,
            status: $active !== null ? 'v'.$active->version : __('ai.map_status_none'),
            adminRoute: 'organization.admin.ai-behavior',
        );
    }

    private function providerSettings(Organization $organization): array
    {
        $setting = $organization->aiSetting;

        return $this->node(
            key: 'provider',
            level: self::LEVEL_ORGANIZATION,
            writeRoute: 'organization.admin.ai.update',
            organization: $organization,
            // Le MODELE, jamais la cle. Rien de secret ne transite par un plan.
            status: $setting?->model ?: __('ai.map_status_none'),
            adminRoute: 'organization.admin.ai',
        );
    }

    private function capabilities(): array
    {
        $covered = $this->coverage->coveredCount();
        $total = $this->coverage->totalCount();

        return $this->node(
            key: 'capabilities',
            level: self::LEVEL_PLATFORM,
            // Le registre est du CODE. Aucune route ne l'edite, et c'est
            // precisement ce qui garantit qu'une capability ne s'invente pas
            // depuis une interface.
            writeRoute: null,
            organization: null,
            status: __('ai.map_status_coverage', ['covered' => $covered, 'total' => $total]),
            adminRoute: 'organization.admin.ai-behavior',
            adminRouteParams: ['organization' => null],
        );
    }

    private function knowledge(Organization $organization): array
    {
        return $this->node(
            key: 'knowledge',
            level: self::LEVEL_ORGANIZATION,
            // La connaissance se nourrit par les Dossiers, pas par un reglage
            // de cette zone : aucune route d'ecriture ici.
            writeRoute: null,
            organization: $organization,
            status: null,
            adminRoute: 'organization.admin.ai-knowledge',
        );
    }

    private function consumption(Organization $organization): array
    {
        return $this->node(
            key: 'consumption',
            level: self::LEVEL_ORGANIZATION,
            writeRoute: 'organization.admin.ai.user-credit.update',
            organization: $organization,
            status: null,
            adminRoute: 'organization.admin.ai-consumption',
        );
    }

    // =================================================================
    // Primitives
    // =================================================================

    /**
     * @return array<string, mixed>
     */
    private function node(
        string $key,
        string $level,
        ?string $writeRoute,
        ?Organization $organization,
        ?string $status,
        string $adminRoute,
        array $adminRouteParams = [],
        bool $adminRequiresPlatformAdmin = false,
    ): array {
        $params = $adminRouteParams;

        if (array_key_exists('organization', $params) || str_starts_with($adminRoute, 'organization.')) {
            $params['organization'] = $organization ?? request()->route('organization');
        }

        return [
            'key' => $key,
            'level' => $level,
            'state' => $writeRoute !== null && Route::has($writeRoute)
                ? self::STATE_CONFIGURABLE
                : self::STATE_LOCKED,
            'status' => $status,
            'admin_url' => $this->url($adminRoute, $params),
            'admin_requires_platform_admin' => $adminRequiresPlatformAdmin,
        ];
    }

    /**
     * Un lien n'est propose que si la route EXISTE. Une carte qui pointe vers
     * une route absente serait pire qu'une carte sans lien.
     */
    private function url(string $name, array $params): ?string
    {
        if (! Route::has($name)) {
            return null;
        }

        try {
            return route($name, array_filter($params, static fn ($v) => $v !== null));
        } catch (\Throwable) {
            return null;
        }
    }
}
