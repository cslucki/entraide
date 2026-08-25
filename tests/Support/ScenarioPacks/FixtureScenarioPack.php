<?php

namespace Tests\Support\ScenarioPacks;

use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\User;
use App\Support\ScenarioPacks\Contracts\ScenarioPackDefinition;
use App\Support\ScenarioPacks\ScenarioPackEntityRegistrar;
use Illuminate\Support\Facades\Hash;

/**
 * Pack minimal, fictif, utilise UNIQUEMENT par les tests du moteur
 * TASK-1240 (`TASK1240ScenarioPackEngineTest`). N'est ni enregistre dans
 * `config('scenario_packs.definitions')`, ni un apercu du contenu reel
 * ArtSciLab/Roger (TASK-1242).
 *
 * Couvre les quatre types d'entites du contrat TASK-1239 pertinents pour le
 * moteur : persona (User), Boucle (Loop), Dossier, interaction (LoopMessage).
 * `$includeInteraction` simule une nouvelle version de pack qui abandonne une
 * entite, pour tester la detection d'orphelins au reset.
 */
class FixtureScenarioPack implements ScenarioPackDefinition
{
    public function __construct(
        private readonly string $version = '1.0.0',
        private readonly bool $includeInteraction = true,
    ) {}

    public function packId(): string
    {
        return 'task1240-fixture';
    }

    public function packVersion(): string
    {
        return $this->version;
    }

    public function packName(): string
    {
        return 'TASK-1240 fixture (test only)';
    }

    public function purpose(): string
    {
        return 'Exercer le moteur de chargement de scenario pack sans donnees demonstratives reelles.';
    }

    /**
     * Email du persona du pack pour une Organization donnee. Organization-
     * specifique depuis TASK-1245 : `users.email` est unique globalement, et
     * un email partage faisait "voler" a l'Organization B le persona deja
     * cree pour A (`updateOrCreate` le rattachait a B) — une mutation d'une
     * entite reutilisee, desormais refusee par le registrar
     * (`ScenarioPackReusedEntityMutatedException`). Chaque Organization a
     * donc son propre persona, reellement `created`.
     */
    public static function personaEmail(Organization $organization): string
    {
        return 'fixture-persona-'.$organization->slug.'@task1240-demo.test';
    }

    public function apply(Organization $organization, ScenarioPackEntityRegistrar $registrar): void
    {
        $persona = User::query()->updateOrCreate(
            ['email' => self::personaEmail($organization)],
            [
                'organization_id' => $organization->id,
                'name' => 'Fixture Persona',
                'first_name' => 'Fixture',
                'password' => Hash::make('password'),
                'points_balance' => 0,
            ],
        );
        $registrar->track('persona', 'persona-1', $persona);

        $loop = Loop::query()->updateOrCreate(
            ['organization_id' => $organization->id, 'slug' => 'task1240-fixture-loop'],
            [
                'name' => 'Fixture Loop',
                'description' => 'Boucle de test du moteur scenario pack.',
                'type' => 'custom',
                'status' => 'active',
                'visibility' => 'private',
                'access_mode' => Loop::ACCESS_REQUEST,
                'created_by' => $persona->id,
            ],
        );
        $registrar->track('loop', 'loop-1', $loop);

        $dossier = Dossier::query()->updateOrCreate(
            ['organization_id' => $organization->id, 'name' => 'Fixture Dossier'],
            [
                'owner_id' => $persona->id,
                'visibility' => Dossier::VISIBILITY_PRIVATE,
            ],
        );
        $registrar->track('folder', 'folder-1', $dossier);

        if ($this->includeInteraction) {
            $message = LoopMessage::query()->updateOrCreate(
                ['loop_id' => $loop->id, 'body' => '[FIXTURE-1240] Message de demonstration.'],
                [
                    'organization_id' => $organization->id,
                    'sender_id' => $persona->id,
                    'type' => 'user',
                    'metadata' => ['scenario' => 'task1240-fixture'],
                ],
            );
            $registrar->track('interaction', 'interaction-1', $message);
        }
    }
}
