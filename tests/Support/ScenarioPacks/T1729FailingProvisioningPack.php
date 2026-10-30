<?php

namespace Tests\Support\ScenarioPacks;

use App\Models\Organization;
use App\Models\User;
use App\Support\ScenarioPacks\Contracts\ProvisionsItsOrganization;
use App\Support\ScenarioPacks\Contracts\ScenarioPackDefinition;
use App\Support\ScenarioPacks\ScenarioPackEntityRegistrar;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Pack sonde, test-only — TASK-1729 phase 2, non-atteignabilite du site 5.
 *
 * Il provisionne son Organization, puis son `apply()` CREE un compte et leve.
 * C'est exactement la forme la plus defavorable pour le `forceDelete()` de
 * `ScenarioPackLoadCommand` : si ce compte survivait a la levee, la FK
 * `ON DELETE SET NULL` l'orphelinerait au nettoyage.
 *
 * L'affirmation a prouver est que cela n'arrive pas, parce que
 * `ScenarioPackLoader::load()` enveloppe `apply()` dans une transaction. Le
 * test ne la suppose pas : il la MESURE.
 */
class T1729FailingProvisioningPack implements ProvisionsItsOrganization, ScenarioPackDefinition
{
    public const PACK_ID = 't1729-failing-provisioning';

    public const SLUG = 't1729-echec-provision';

    public const EMAIL = 'persona-t1729@echec.test';

    public function packId(): string
    {
        return self::PACK_ID;
    }

    public function packVersion(): string
    {
        return '1.0.0';
    }

    public function packName(): string
    {
        return 'T1729 — provisionnement qui echoue';
    }

    public function purpose(): string
    {
        return "Prouve qu'un apply() qui echoue apres avoir cree un compte ne laisse aucun compte rattache.";
    }

    public function organizationSlug(): string
    {
        return self::SLUG;
    }

    public function provisionOrganization(): Organization
    {
        return Organization::query()->create([
            'name' => 'T1729 echec provision',
            'slug' => self::SLUG,
            'is_active' => true,
        ]);
    }

    public function assertOrganizationAdoptable(Organization $organization): void
    {
        // Rien a verifier : ce pack n'est jamais charge dans une Organization
        // preexistante par les tests qui l'emploient.
    }

    public function apply(Organization $organization, ScenarioPackEntityRegistrar $registrar): void
    {
        $user = User::query()->create([
            'name' => 'Persona condamne',
            'email' => self::EMAIL,
            'password' => Hash::make('password'),
            'organization_id' => $organization->getKey(),
        ]);

        $registrar->track('t1729_persona', 'condamne', $user);

        // APRES l'ecriture, et c'est tout le sujet.
        throw new RuntimeException('Echec volontaire du pack sonde T1729.');
    }
}
