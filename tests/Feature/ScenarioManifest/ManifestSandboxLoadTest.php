<?php

namespace Tests\Feature\ScenarioManifest;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\ScenarioPackEntity;
use App\Models\ScenarioPackLoad;
use App\Models\User;
use App\Support\ScenarioManifest\ScenarioManifestValidator;
use App\Support\ScenarioPacks\Contracts\ScenarioPackDefinition;
use App\Support\ScenarioPacks\Exceptions\ScenarioPackOrganizationNotAllowedException;
use App\Support\ScenarioPacks\Manifest\ManifestNotLoadableException;
use App\Support\ScenarioPacks\Manifest\ManifestSandboxLoadResult;
use App\Support\ScenarioPacks\Manifest\ManifestSandboxLoadService;
use App\Support\ScenarioPacks\Manifest\ManifestScenarioPack;
use App\Support\ScenarioPacks\Manifest\ScenarioManifest;
use App\Support\ScenarioPacks\Manifest\ScenarioSandboxProvisioner;
use App\Support\ScenarioPacks\ScenarioPackEntityRegistrar;
use App\Support\ScenarioPacks\ScenarioPackLoader;
use App\Support\ScenarioPacks\ScenarioPackRemover;
use App\Support\ScenarioPacks\ScenarioPackResetter;
use Tests\TestCase;

/**
 * TASK-1642 — le pont entre un manifeste VALIDE et le moteur Scenario Packs.
 *
 * Toute la suite part de la fixture VERSIONNEE d'AMT, jamais de la spec :
 * `TODO/` est gitignore et la CI n'en dispose pas (lecon de T1641).
 *
 * Le perimetre teste est FOUNDATION : ce qui doit exister apres un
 * chargement, et — tout aussi important — ce qui ne doit PAS encore exister.
 * Un test qui ne verifierait que le premier laisserait passer un Core Loader
 * cache, c'est-a-dire l'inverse exact d'une livraison incrementale.
 */
class ManifestSandboxLoadTest extends TestCase
{
    private function manifestJson(): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/ScenarioManifest/amt-formation-ia.json'));
    }

    private function approvedDigest(): string
    {
        return (string) (new ScenarioManifestValidator)->validate($this->manifestJson())->digest();
    }

    private function load(): ManifestSandboxLoadResult
    {
        return app(ManifestSandboxLoadService::class)->load($this->manifestJson(), $this->approvedDigest());
    }

    // =====================================================================
    // FOUNDATION — ce qui doit exister
    // =====================================================================

    public function test_loading_the_amt_manifest_creates_a_new_sandbox_with_its_foundation(): void
    {
        $result = $this->load();
        $organization = $result->organization;

        $this->assertNotNull($organization->scenario_sandbox_created_at, 'The sandbox must carry its server-side provenance.');
        $this->assertSame('amt-formation-ia', $result->sandboxSlug());
        $this->assertFalse($organization->is_public);
        $this->assertFalse($organization->is_default);
        $this->assertSame('fr', $organization->locale);

        $this->assertSame(22, User::withoutGlobalScopes()->where('organization_id', $organization->id)->count());
        $this->assertSame(2, Loop::withoutGlobalScopes()->where('organization_id', $organization->id)->count());
        $this->assertSame(44, LoopMember::where('organization_id', $organization->id)->count());

        // Un Dossier racine par Boucle, cree par la primitive canonique.
        $this->assertSame(2, Dossier::withoutGlobalScopes()->where('organization_id', $organization->id)->count());

        // Les trois profils IA declares dans AMT (deux formateurs, un stagiaire).
        $this->assertSame(3, MemberAiProfile::withoutGlobalScopes()->where('organization_id', $organization->id)->count());
    }

    public function test_the_declared_roles_and_owners_are_materialised(): void
    {
        $organization = $this->load()->organization;

        $training = Loop::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('type', 'training')
            ->firstOrFail();

        // AMT : trainer-1 (owner) + trainer-2 (facilitator) + 20 stagiaires.
        $this->assertSame(22, LoopMember::where('loop_id', $training->id)->count());
        $this->assertSame(1, LoopMember::where('loop_id', $training->id)->where('role', 'owner')->count());
        $this->assertSame(1, LoopMember::where('loop_id', $training->id)->where('role', 'facilitator')->count());

        $owner = User::withoutGlobalScopes()->find(
            LoopMember::where('loop_id', $training->id)->where('role', 'owner')->value('user_id')
        );

        $this->assertSame('Nora', $owner->first_name);
        $this->assertTrue((bool) $owner->is_admin, "AMT declares Nora with the organization role 'admin'.");
    }

    public function test_personas_receive_a_sandbox_scoped_fictional_identity(): void
    {
        $organization = $this->load()->organization;

        $emails = User::withoutGlobalScopes()->where('organization_id', $organization->id)->pluck('email');

        foreach ($emails as $email) {
            // La garde d'adresse fictive survit a la derivation.
            $this->assertStringEndsWith('.test', $email);
            $this->assertStringContainsString($organization->slug, $email);
        }

        // Aucun mot de passe ne vient du JSON : le manifeste n'en porte pas et
        // n'en recoit pas (spec 7.2).
        $this->assertStringNotContainsString('password', $this->manifestJson());
    }

    // =====================================================================
    // FOUNDATION — ce qui ne doit PAS encore exister
    // =====================================================================

    public function test_the_families_out_of_scope_for_this_task_are_not_loaded(): void
    {
        $organization = $this->load()->organization;

        // AMT declare 1 article, 2 fichiers, 4 messages, 1 sondage, 1
        // evenement, 1 decision, 1 element de roadmap, 2 modules de formation.
        // T1642 n'en materialise AUCUN : ces familles appartiennent aux TASKs
        // suivantes, et une livraison incrementale se prouve autant par ce
        // qu'elle n'a pas fait.
        $trackedTypes = ScenarioPackEntity::query()
            ->where('organization_id', $organization->id)
            ->distinct()
            ->pluck('entity_type')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            'manifest_loop',
            'manifest_member_ai_profile',
            'manifest_membership',
            'manifest_root_document',
            'manifest_root_dossier',
            'manifest_user',
        ], $trackedTypes);

        // Les seuls articles presents sont les documents racines produits par
        // la primitive canonique — jamais l'article declare par le manifeste.
        $titles = BlogPost::withoutGlobalScopes()->where('organization_id', $organization->id)->pluck('title');

        $this->assertCount(2, $titles);

        foreach ($titles as $title) {
            $this->assertStringNotContainsString("Charte d'usage responsable", $title);
        }
    }

    // =====================================================================
    // DIGEST — aucune ecriture avant la preuve
    // =====================================================================

    public function test_a_wrong_approved_digest_creates_no_organization_at_all(): void
    {
        $before = Organization::withoutGlobalScopes()->count();

        $this->expectException(ManifestNotLoadableException::class);

        try {
            app(ManifestSandboxLoadService::class)->load($this->manifestJson(), str_repeat('0', 64));
        } finally {
            $this->assertSame($before, Organization::withoutGlobalScopes()->count(), 'A digest mismatch must be refused before any write.');
        }
    }

    public function test_a_manifest_mutated_after_approval_creates_no_organization_at_all(): void
    {
        $approved = $this->approvedDigest();

        // Le scenario que la spec redoute : le document change ENTRE
        // l'approbation humaine et le chargement.
        $mutated = json_decode($this->manifestJson(), false, 512, JSON_THROW_ON_ERROR);
        $mutated->users[0]->first_name = 'Mallory';
        $mutatedJson = json_encode($mutated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $before = Organization::withoutGlobalScopes()->count();

        $this->expectException(ManifestNotLoadableException::class);

        try {
            app(ManifestSandboxLoadService::class)->load($mutatedJson, $approved);
        } finally {
            $this->assertSame($before, Organization::withoutGlobalScopes()->count());
        }
    }

    public function test_an_invalid_manifest_creates_no_organization_at_all(): void
    {
        $invalid = json_decode($this->manifestJson(), false, 512, JSON_THROW_ON_ERROR);
        $invalid->organization_id = 'some-existing-tenant';
        $invalidJson = json_encode($invalid, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $before = Organization::withoutGlobalScopes()->count();

        $this->expectException(ManifestNotLoadableException::class);

        try {
            app(ManifestSandboxLoadService::class)->load($invalidJson, $this->approvedDigest());
        } finally {
            $this->assertSame($before, Organization::withoutGlobalScopes()->count());
        }
    }

    // =====================================================================
    // TENANT — jamais une Organization existante
    // =====================================================================

    public function test_a_slug_collision_creates_another_sandbox_and_never_adopts_the_existing_one(): void
    {
        $first = $this->load();
        $second = $this->load();

        $this->assertSame('amt-formation-ia', $first->sandboxSlug());
        $this->assertNotSame($first->sandboxSlug(), $second->sandboxSlug());
        $this->assertTrue($second->proposedSlugWasTaken());
        $this->assertNotSame($first->organization->id, $second->organization->id);

        // Chacune porte son monde COMPLET : la seconde n'a rien emprunte a la
        // premiere.
        foreach ([$first, $second] as $result) {
            $this->assertSame(22, User::withoutGlobalScopes()->where('organization_id', $result->organization->id)->count());
            $this->assertSame(2, Loop::withoutGlobalScopes()->where('organization_id', $result->organization->id)->count());
        }
    }

    public function test_an_existing_client_organization_is_never_touched_by_a_manifest_load(): void
    {
        // Une Organization cliente qui porte EXACTEMENT le slug propose par
        // le manifeste : le piege que la spec 10.2 nomme.
        $client = Organization::create([
            'name' => 'Client reel',
            'slug' => 'amt-formation-ia',
            'is_active' => true,
            'locale' => 'fr',
        ]);

        $result = $this->load();

        $this->assertNotSame($client->id, $result->organization->id);
        $this->assertNull($client->fresh()->scenario_sandbox_created_at, 'A client organization must never acquire sandbox provenance.');
        $this->assertSame(0, User::withoutGlobalScopes()->where('organization_id', $client->id)->count());
        $this->assertSame(0, Loop::withoutGlobalScopes()->where('organization_id', $client->id)->count());
        $this->assertSame(0, ScenarioPackLoad::where('organization_id', $client->id)->count());
    }

    public function test_the_guard_still_refuses_an_organization_that_is_neither_allowlisted_nor_a_sandbox(): void
    {
        $client = Organization::create([
            'name' => 'Client reel',
            'slug' => 'client-reel',
            'is_active' => true,
            'locale' => 'fr',
        ]);

        $manifest = ScenarioManifest::fromApprovedJson($this->manifestJson(), $this->approvedDigest());

        $this->expectException(ScenarioPackOrganizationNotAllowedException::class);

        app(ScenarioPackLoader::class)
            ->load(new ManifestScenarioPack($manifest), $client);
    }

    // =====================================================================
    // ATOMICITE / IDEMPOTENCE / CYCLE DE VIE
    // =====================================================================

    /**
     * La garantie la plus difficile a tenir : un `apply()` qui echoue APRES
     * avoir deja ecrit.
     *
     * La transaction du loader annule ce que le pack a ecrit ; l'Organization,
     * elle, a ete creee AVANT d'y entrer — le loader exige une Organization
     * pour poser son verrou. Sans rattrapage explicite, un echec laisserait
     * une sandbox vide que plus rien ne sait relier a un chargement, donc que
     * plus rien ne sait supprimer.
     */
    public function test_a_failure_during_apply_leaves_neither_a_partial_world_nor_an_orphan_sandbox(): void
    {
        $before = Organization::withoutGlobalScopes()->count();

        $service = new class(app(ScenarioSandboxProvisioner::class), app(ScenarioPackLoader::class)) extends ManifestSandboxLoadService
        {
            protected function makePack(ScenarioManifest $manifest): ScenarioPackDefinition
            {
                return new class($manifest) extends ManifestScenarioPack
                {
                    public function apply(Organization $organization, ScenarioPackEntityRegistrar $registrar): void
                    {
                        // Ecrit d'abord, echoue ensuite : c'est le seul
                        // scenario qui teste vraiment le rollback.
                        parent::apply($organization, $registrar);

                        throw new \RuntimeException('apply failed halfway');
                    }
                };
            }
        };

        try {
            $service->load($this->manifestJson(), $this->approvedDigest());
            $this->fail('The load should have propagated the failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('apply failed halfway', $exception->getMessage());
        }

        $this->assertSame($before, Organization::withoutGlobalScopes()->count(), 'No orphan sandbox may survive a failed load.');
        $this->assertSame(0, Organization::withoutGlobalScopes()->whereNotNull('scenario_sandbox_created_at')->count());
        $this->assertSame(0, ScenarioPackLoad::query()->count());
        $this->assertSame(0, ScenarioPackEntity::query()->count());
        $this->assertSame(0, User::withoutGlobalScopes()->where('email', 'like', '%amt-demo.test')->count());
        $this->assertSame(0, Loop::withoutGlobalScopes()->count());
    }

    public function test_replaying_the_same_load_in_the_same_sandbox_duplicates_nothing(): void
    {
        $first = $this->load();
        $organization = $first->organization;

        $manifest = ScenarioManifest::fromApprovedJson($this->manifestJson(), $this->approvedDigest());
        $replay = app(ScenarioPackLoader::class)
            ->load(new ManifestScenarioPack($manifest), $organization);

        $this->assertFalse($replay->wasFirstLoad);
        $this->assertSame(22, User::withoutGlobalScopes()->where('organization_id', $organization->id)->count());
        $this->assertSame(2, Loop::withoutGlobalScopes()->where('organization_id', $organization->id)->count());
        $this->assertSame(44, LoopMember::where('organization_id', $organization->id)->count());
        $this->assertSame(1, ScenarioPackLoad::where('organization_id', $organization->id)->count());
    }

    public function test_resetting_returns_the_sandbox_to_the_manifest_skeleton(): void
    {
        $result = $this->load();
        $organization = $result->organization;

        // Une derive posterieure au chargement : un membre retire a la main.
        $removed = LoopMember::where('organization_id', $organization->id)->where('role', 'member')->first();
        $loopId = $removed->loop_id;
        $removed->delete();

        $this->assertSame(43, LoopMember::where('organization_id', $organization->id)->count());

        $manifest = ScenarioManifest::fromApprovedJson($this->manifestJson(), $this->approvedDigest());
        app(ScenarioPackResetter::class)->reset(new ManifestScenarioPack($manifest), $organization);

        $this->assertSame(44, LoopMember::where('organization_id', $organization->id)->count());
        $this->assertSame(22, LoopMember::where('loop_id', $loopId)->count());
        $this->assertSame(22, User::withoutGlobalScopes()->where('organization_id', $organization->id)->count());
    }

    public function test_removing_the_pack_purges_only_what_it_owns(): void
    {
        $result = $this->load();
        $organization = $result->organization;

        $manifest = ScenarioManifest::fromApprovedJson($this->manifestJson(), $this->approvedDigest());
        app(ScenarioPackRemover::class)->remove((new ManifestScenarioPack($manifest))->packId(), $organization);

        $this->assertSame(0, ScenarioPackLoad::where('organization_id', $organization->id)->count());
        $this->assertSame(0, ScenarioPackEntity::where('organization_id', $organization->id)->count());
        $this->assertSame(0, Loop::withoutGlobalScopes()->where('organization_id', $organization->id)->count());
        $this->assertSame(0, User::withoutGlobalScopes()->where('organization_id', $organization->id)->count());
    }
}
