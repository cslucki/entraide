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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Events\Dispatcher;
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

    /**
     * Une variante REELLEMENT distincte du manifeste : son digest differe,
     * donc ce n'est jamais un rejeu.
     *
     * @param  callable(\stdClass): void  $mutation
     * @return array{0: string, 1: string} [json, digest]
     */
    private function variant(callable $mutation): array
    {
        $document = json_decode($this->manifestJson(), false, 512, JSON_THROW_ON_ERROR);
        $mutation($document);
        $json = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $result = (new ScenarioManifestValidator)->validate($json);
        $this->assertTrue($result->isValid(), 'The variant used by this test must itself be a VALID manifest.');

        return [$json, (string) $result->digest()];
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

    /**
     * Collision de slug != rejeu.
     *
     * Ce test utilisait deux fois EXACTEMENT le meme JSON, ce qui est depuis
     * la correction de revue un rejeu — donc precisement ce qui ne doit PAS
     * creer une seconde sandbox. Il prouve desormais ce qu'il devait prouver :
     * deux manifestes REELLEMENT distincts qui proposent le meme slug donnent
     * deux sandboxes sures, et jamais l'adoption de la premiere.
     */
    public function test_two_distinct_manifests_proposing_the_same_slug_get_two_safe_sandboxes(): void
    {
        $first = $this->load();

        [$otherJson, $otherDigest] = $this->variant(static function (\stdClass $document): void {
            $document->id = 'amt-formation-ia-promo-2';
            $document->name = 'AMT — Formation IA, promotion 2';
            // Le slug PROPOSE reste le meme : c'est tout l'interet du cas.
        });

        $second = app(ManifestSandboxLoadService::class)->load($otherJson, $otherDigest);

        $this->assertSame('amt-formation-ia', $first->sandboxSlug());
        $this->assertNotSame($first->sandboxSlug(), $second->sandboxSlug());
        $this->assertTrue($second->proposedSlugWasTaken());
        $this->assertNotSame($first->organization->id, $second->organization->id);
        $this->assertFalse($second->wasReplay);

        foreach ([$first, $second] as $result) {
            $this->assertSame(22, User::withoutGlobalScopes()->where('organization_id', $result->organization->id)->count());
            $this->assertSame(2, Loop::withoutGlobalScopes()->where('organization_id', $result->organization->id)->count());
        }
    }

    // =====================================================================
    // REJEU — spec 5.2 : meme manifeste + meme digest = chargement existant
    // =====================================================================

    public function test_an_exact_replay_returns_the_existing_sandbox_and_writes_nothing(): void
    {
        $first = $this->load();

        $organizations = Organization::withoutGlobalScopes()->count();
        $users = User::withoutGlobalScopes()->count();
        $loops = Loop::withoutGlobalScopes()->count();
        $loads = ScenarioPackLoad::query()->count();
        $entities = ScenarioPackEntity::query()->count();

        // Le double clic / rejeu reseau.
        $second = $this->load();

        $this->assertTrue($second->wasReplay);
        $this->assertSame($first->organization->id, $second->organization->id);
        $this->assertSame($first->packLoad->load->id, $second->packLoad->load->id);
        $this->assertSame($first->digest(), $second->digest());

        $this->assertSame($organizations, Organization::withoutGlobalScopes()->count());
        $this->assertSame($users, User::withoutGlobalScopes()->count());
        $this->assertSame($loops, Loop::withoutGlobalScopes()->count());
        $this->assertSame($loads, ScenarioPackLoad::query()->count());
        $this->assertSame($entities, ScenarioPackEntity::query()->count());
    }

    public function test_an_exact_replay_keeps_the_very_same_personas_and_emails(): void
    {
        $first = $this->load();
        $before = User::withoutGlobalScopes()
            ->where('organization_id', $first->organization->id)
            ->orderBy('email')
            ->pluck('email')
            ->all();

        $second = $this->load();
        $after = User::withoutGlobalScopes()
            ->where('organization_id', $second->organization->id)
            ->orderBy('email')
            ->pluck('email')
            ->all();

        // La derivation d'adresse par slug reel ne doit plus produire de
        // NOUVELLES identites sur un simple rejeu : meme sandbox, memes users.
        $this->assertSame($before, $after);
        $this->assertCount(22, $after);
    }

    public function test_the_same_pack_id_with_a_different_digest_is_not_a_replay(): void
    {
        $first = $this->load();

        [$changedJson, $changedDigest] = $this->variant(static function (\stdClass $document): void {
            // Meme `id`, donc meme pack_id : seul le CONTENU change.
            $document->description = 'Une autre description, donc un autre monde approuve.';
        });

        $second = app(ManifestSandboxLoadService::class)->load($changedJson, $changedDigest);

        $this->assertFalse($second->wasReplay, 'A different approved content must never silently return the previous load.');
        $this->assertNotSame($first->organization->id, $second->organization->id);
        $this->assertNotSame($first->digest(), $second->digest());
        $this->assertSame(2, ScenarioPackLoad::query()->count());
    }

    public function test_a_replay_after_a_reset_does_not_create_a_second_sandbox(): void
    {
        $first = $this->load();

        $manifest = ScenarioManifest::fromApprovedJson($this->manifestJson(), $this->approvedDigest());
        app(ScenarioPackResetter::class)->reset(new ManifestScenarioPack($manifest), $first->organization);

        $organizations = Organization::withoutGlobalScopes()->count();

        $replay = $this->load();

        $this->assertTrue($replay->wasReplay);
        $this->assertSame($first->organization->id, $replay->organization->id);
        $this->assertSame($organizations, Organization::withoutGlobalScopes()->count());
        $this->assertSame(1, ScenarioPackLoad::query()->count());
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

    /**
     * ATOMICITE DU PROVISIONING (finding de revue).
     *
     * La version precedente inserait l'Organization, PUIS ecrivait sa
     * provenance par un UPDATE separe. Un echec entre les deux laissait une
     * Organization orpheline a `scenario_sandbox_created_at` NULL : une ligne
     * que le garde ne reconnait pas comme sandbox, que rien ne relie a un
     * chargement, et que plus rien ne sait supprimer.
     *
     * Le test injecte une panne sur TOUT UPDATE d'Organization pendant le
     * provisioning, et verifie l'invariant qui compte : il n'existe jamais une
     * Organization creee par le provisioner sans sa provenance.
     *
     * Avec l'ancien code (INSERT puis UPDATE) il ROUGIT : l'UPDATE leve et
     * l'INSERT, deja commite, laisse l'orpheline. Avec la correction il n'y a
     * qu'une seule ecriture, donc aucun entre-deux ou echouer.
     */
    public function test_a_failure_while_writing_the_provenance_leaves_no_orphan_organization(): void
    {
        $before = Organization::withoutGlobalScopes()->count();

        // Dispatcher DEDIE, restaure ensuite : `flushEventListeners()`
        // retirerait tous les listeners du modele pour le reste du processus
        // de test, et le prix se paierait dans une autre suite, loin d'ici.
        $originalDispatcher = Organization::getEventDispatcher();
        Organization::setEventDispatcher(new Dispatcher);

        Organization::updating(static function (): void {
            throw new \RuntimeException('provenance write failed');
        });

        try {
            $manifest = ScenarioManifest::fromApprovedJson($this->manifestJson(), $this->approvedDigest());
            app(ScenarioSandboxProvisioner::class)->provision($manifest);
        } catch (\RuntimeException $exception) {
            $this->assertSame('provenance write failed', $exception->getMessage());
        } finally {
            Organization::setEventDispatcher($originalDispatcher);
        }

        $orphans = Organization::withoutGlobalScopes()
            ->whereNull('scenario_sandbox_created_at')
            ->count() - $before;

        $this->assertSame(0, $orphans, 'Provisioning must never leave an Organization without its sandbox provenance.');
    }

    /**
     * CONCURRENCE — deux appels simultanes sur le meme monde approuve.
     *
     * Les deux peuvent passer la recherche de rejeu sans rien trouver. Seule
     * la contrainte unique `(pack_id, manifest_digest)` tranche : le perdant
     * defait ce qu'il a cree et rend le chargement gagnant. Jamais deux
     * sandboxes durables, jamais une erreur remontee a l'appelant.
     *
     * Le concurrent est simule a l'interieur meme de `apply()` : c'est le seul
     * endroit ou l'on est certain d'etre APRES la recherche de rejeu et AVANT
     * l'inscription du digest, c'est-a-dire exactement dans la fenetre de
     * course.
     */
    public function test_a_concurrent_load_of_the_same_manifest_never_leaves_two_sandboxes(): void
    {
        $json = $this->manifestJson();
        $digest = $this->approvedDigest();

        $service = new class(app(ScenarioSandboxProvisioner::class), app(ScenarioPackLoader::class)) extends ManifestSandboxLoadService
        {
            public ?string $winnerOrganizationId = null;

            protected function makePack(ScenarioManifest $manifest): ScenarioPackDefinition
            {
                $test = $this;

                return new class($manifest, $test) extends ManifestScenarioPack
                {
                    public function __construct(ScenarioManifest $manifest, private readonly object $service)
                    {
                        parent::__construct($manifest);
                    }

                    public function apply(Organization $organization, ScenarioPackEntityRegistrar $registrar): void
                    {
                        parent::apply($organization, $registrar);

                        // L'autre requete finit AVANT nous et inscrit son
                        // digest : a partir d'ici, notre propre inscription
                        // violera la contrainte unique.
                        $winner = app(ScenarioSandboxProvisioner::class)
                            ->provision($this->manifest());

                        $load = ScenarioPackLoad::query()->create([
                            'pack_id' => $this->packId(),
                            'pack_version' => $this->packVersion(),
                            'organization_id' => $winner->id,
                            'loaded_at' => now(),
                        ]);

                        $load->forceFill([
                            'manifest_digest' => $this->manifest()->digest(),
                            'organization_created_by_pack' => true,
                        ])->save();

                        $this->service->winnerOrganizationId = $winner->id;
                    }
                };
            }
        };

        $result = $service->load($json, $digest);

        // Un resultat est rendu, pas une erreur : le rejeu gagne la course.
        $this->assertTrue($result->wasReplay);
        $this->assertSame($service->winnerOrganizationId, $result->organization->id);

        // Une seule ligne de chargement porte ce digest, et une seule sandbox
        // survit : celle du gagnant.
        $this->assertSame(1, ScenarioPackLoad::query()->where('manifest_digest', $digest)->count());
        $this->assertSame(1, Organization::withoutGlobalScopes()->whereNotNull('scenario_sandbox_created_at')->count());
    }

    /**
     * Une violation d'unicite METIER, levee depuis `apply()`, ne doit jamais
     * etre confondue avec une course sur le digest.
     *
     * `apply()` ecrit des objets metier, et ces ecritures ont leurs propres
     * contraintes d'unicite — une adresse e-mail, un slug de Boucle. Si le
     * traitement de course les englobait, un defaut metier serait nettoye puis
     * masque derriere un faux rejeu, et personne ne le verrait jamais.
     *
     * Cas A : aucun gagnant n'existe.
     */
    public function test_a_business_unique_violation_in_apply_surfaces_as_itself_and_cleans_its_sandbox(): void
    {
        $before = Organization::withoutGlobalScopes()->count();

        $service = $this->serviceFailingInApplyWith(static function (Organization $organization): void {
            // Une seconde persona avec l'adresse de la premiere : violation
            // d'unicite ORDINAIRE, sans rapport avec le digest.
            $taken = User::withoutGlobalScopes()->where('organization_id', $organization->id)->value('email');

            User::query()->create([
                'organization_id' => $organization->id,
                'first_name' => 'Doublon',
                'name' => 'Doublon',
                'email' => $taken,
                'password' => 'x',
            ]);
        });

        try {
            $service->load($this->manifestJson(), $this->approvedDigest());
            $this->fail('The business unique violation should have surfaced.');
        } catch (ManifestNotLoadableException $exception) {
            $this->fail('A business unique violation must never be reported as a manifest load problem: '.$exception->getMessage());
        } catch (UniqueConstraintViolationException $exception) {
            $this->assertStringContainsStringIgnoringCase('users', $exception->getMessage());
        }

        $this->assertSame($before, Organization::withoutGlobalScopes()->count(), 'The sandbox of this invocation must be cleaned up.');
        $this->assertSame(0, ScenarioPackLoad::query()->count());
    }

    /**
     * Cas B : un gagnant EXISTE au moment ou `apply()` echoue.
     *
     * C'est le cas dangereux. Si le traitement de course englobait `apply()`,
     * le service nettoierait la sandbox puis rendrait le gagnant — un FAUX
     * SUCCES, alors que le chargement demande a reellement echoue.
     */
    public function test_a_business_unique_violation_in_apply_never_returns_an_existing_winner_as_a_false_success(): void
    {
        // Le gagnant est cree AVANT l'appel, donc durable ; la recherche de
        // rejeu est neutralisee au premier passage pour reproduire la fenetre
        // "il est apparu apres l'etape 2".
        $winner = $this->load();
        $winnerUsers = User::withoutGlobalScopes()->where('organization_id', $winner->organization->id)->count();

        $service = $this->serviceFailingInApplyWith(static function (Organization $organization): void {
            $taken = User::withoutGlobalScopes()->where('organization_id', $organization->id)->value('email');

            User::query()->create([
                'organization_id' => $organization->id,
                'first_name' => 'Doublon',
                'name' => 'Doublon',
                'email' => $taken,
                'password' => 'x',
            ]);
        }, skipFirstReplayLookup: true);

        $organizations = Organization::withoutGlobalScopes()->count();

        try {
            $service->load($this->manifestJson(), $this->approvedDigest());
            $this->fail('The business unique violation should have surfaced instead of returning the winner.');
        } catch (UniqueConstraintViolationException) {
            // Attendu : l'exception d'origine, pas un resultat.
        }

        // Le gagnant est INTACT.
        $this->assertNotNull(Organization::withoutGlobalScopes()->find($winner->organization->id));
        $this->assertSame($winnerUsers, User::withoutGlobalScopes()->where('organization_id', $winner->organization->id)->count());
        $this->assertSame(1, ScenarioPackLoad::query()->count());

        // Le perdant est nettoye : on revient au compte d'avant l'appel.
        $this->assertSame($organizations, Organization::withoutGlobalScopes()->count());
    }

    /**
     * Un service dont le pack echoue DANS `apply()`, apres avoir ecrit.
     *
     * @param  callable(Organization): void  $failure
     */
    private function serviceFailingInApplyWith(callable $failure, bool $skipFirstReplayLookup = false): ManifestSandboxLoadService
    {
        return new class(app(ScenarioSandboxProvisioner::class), app(ScenarioPackLoader::class), $failure, $skipFirstReplayLookup) extends ManifestSandboxLoadService
        {
            private bool $replayLookupSkipped = false;

            public function __construct(
                ScenarioSandboxProvisioner $provisioner,
                ScenarioPackLoader $loader,
                private $failure,
                private readonly bool $skipFirstReplayLookup,
            ) {
                parent::__construct($provisioner, $loader);
            }

            protected function findExistingLoad(ScenarioPackDefinition $pack, ScenarioManifest $manifest): ?ManifestSandboxLoadResult
            {
                if ($this->skipFirstReplayLookup && ! $this->replayLookupSkipped) {
                    $this->replayLookupSkipped = true;

                    return null;
                }

                return parent::findExistingLoad($pack, $manifest);
            }

            protected function makePack(ScenarioManifest $manifest): ScenarioPackDefinition
            {
                $failure = $this->failure;

                return new class($manifest, $failure) extends ManifestScenarioPack
                {
                    public function __construct(ScenarioManifest $manifest, private $failure)
                    {
                        parent::__construct($manifest);
                    }

                    public function apply(Organization $organization, ScenarioPackEntityRegistrar $registrar): void
                    {
                        parent::apply($organization, $registrar);

                        ($this->failure)($organization);
                    }
                };
            }
        };
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
