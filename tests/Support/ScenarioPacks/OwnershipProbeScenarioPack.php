<?php

namespace Tests\Support\ScenarioPacks;

use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Support\ScenarioPacks\Contracts\ScenarioPackDefinition;
use App\Support\ScenarioPacks\ScenarioPackEntityRegistrar;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * Pack sonde, test-only, pour TASK-1245 (ownership du registre). N'est pas
 * enregistre dans `config('scenario_packs.definitions')`.
 *
 * Chaque entite est concue pour que le TEST decide si elle est creee par le
 * pack ou preexistante, sans que le pack la mute quand elle preexiste :
 *  - persona (User) et Boucle "partagee" (Loop) : `firstOrCreate` sur cle
 *    naturelle -> `created` si absentes, `reused` (intactes) si le test les
 *    a creees avant le chargement ;
 *  - Dossier (SoftDeletes) et DossierFile (SoftDeletes + fichier storage) :
 *    `updateOrCreate` sur cle naturelle, toujours produits par le pack dans
 *    un test propre -> `created` ;
 *  - `mutateSharedLoop` : le pack renomme la Boucle partagee au passage
 *    (`updateOrCreate` avec une valeur differente) -> ce que le contrat
 *    interdit sur une entite `reused` ;
 *  - `includeSharedLoop` / `includeFile` a false : simulent une version du
 *    pack qui abandonne l'entite (orphelin au reset).
 */
class OwnershipProbeScenarioPack implements ScenarioPackDefinition
{
    public const DISK = 'dossier_files';

    public function __construct(
        private readonly string $version = '1.0.0',
        private readonly bool $includeSharedLoop = true,
        private readonly bool $includeFile = true,
        private readonly bool $mutateSharedLoop = false,
    ) {}

    public function packId(): string
    {
        return 't1245-ownership-probe';
    }

    public function packVersion(): string
    {
        return $this->version;
    }

    public function packName(): string
    {
        return 'TASK-1245 ownership probe (test only)';
    }

    public function purpose(): string
    {
        return 'Prouver la regle d\'ownership created/reused du moteur scenario pack.';
    }

    public static function personaEmail(Organization $organization): string
    {
        return 't1245-persona-'.$organization->slug.'@probe.test';
    }

    public const SHARED_LOOP_SLUG = 't1245-probe-shared-loop';

    public const SHARED_LOOP_NAME = 'T1245 Shared Loop';

    public const DOSSIER_NAME = 'T1245 Probe Dossier';

    public static function filePath(Organization $organization): string
    {
        return 't1245-probe/'.$organization->slug.'/probe.md';
    }

    public function apply(Organization $organization, ScenarioPackEntityRegistrar $registrar): void
    {
        $persona = User::query()->firstOrCreate(
            ['email' => self::personaEmail($organization)],
            [
                'organization_id' => $organization->id,
                'name' => 'T1245 Persona',
                'first_name' => 'Probe',
                'password' => Hash::make('password'),
                'points_balance' => 0,
            ],
        );
        $registrar->track('persona', 'persona-1', $persona);

        $dossier = Dossier::withTrashed()->updateOrCreate(
            ['organization_id' => $organization->id, 'name' => self::DOSSIER_NAME],
            [
                'owner_id' => $persona->id,
                'visibility' => Dossier::VISIBILITY_PRIVATE,
            ],
        );
        if ($dossier->trashed()) {
            $dossier->restore();
        }
        $registrar->track('folder', 'folder-1', $dossier);

        if ($this->includeFile) {
            $path = self::filePath($organization);
            $contents = "# T1245 probe\n\nversion {$this->version}\n";

            $registrar->assertStoragePathAvailable('folder_file', 'file-1', self::DISK, $path);
            Storage::disk(self::DISK)->put($path, $contents);

            $file = DossierFile::withTrashed()->updateOrCreate(
                ['organization_id' => $organization->id, 'path' => $path],
                [
                    'dossier_id' => $dossier->id,
                    'uploaded_by' => $persona->id,
                    'disk' => self::DISK,
                    'original_name' => 'probe.md',
                    'display_name' => 'Probe',
                    'mime_type' => 'text/markdown',
                    'size_bytes' => strlen($contents),
                    'checksum_sha256' => hash('sha256', $contents),
                    'source' => 'upload',
                ],
            );
            if ($file->trashed()) {
                $file->restore();
            }
            $registrar->track('folder_file', 'file-1', $file);
        }

        // Volontairement APRES le fichier : un refus sur la Boucle
        // (`mutateSharedLoop`) survient alors que le fichier a deja ete
        // ecrit sur le disque — c'est ce qui exerce le nettoyage des fichiers
        // d'un chargement echoue.
        if ($this->includeSharedLoop) {
            $loopValues = [
                'name' => $this->mutateSharedLoop ? 'Renamed by the pack' : self::SHARED_LOOP_NAME,
                'description' => 'Boucle partagee de la sonde T1245.',
                'type' => 'custom',
                'status' => 'active',
                'visibility' => 'private',
                'access_mode' => Loop::ACCESS_REQUEST,
                'created_by' => $persona->id,
            ];
            $loop = $this->mutateSharedLoop
                ? Loop::query()->updateOrCreate(['organization_id' => $organization->id, 'slug' => self::SHARED_LOOP_SLUG], $loopValues)
                : Loop::query()->firstOrCreate(['organization_id' => $organization->id, 'slug' => self::SHARED_LOOP_SLUG], $loopValues);
            $registrar->track('loop', 'shared-loop', $loop);
        }
    }
}
