<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\LoopEvent;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\OrganizationAiDoctrine;
use App\Models\OrganizationAiSetting;
use App\Models\ScenarioPackEntity;
use App\Models\ScenarioPackLoad;
use App\Models\User;
use App\Support\ScenarioPacks\Packs\ArtSciLabEnglishPack;
use App\Support\ScenarioPacks\ScenarioPackCatalog;
use App\Support\ScenarioPacks\ScenarioPackLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

/**
 * TASK-1351 — cycle de vie du pack de dogfooding ANGLAIS.
 *
 * Ce que cette suite prouve, et qui n'existait pour aucun pack avant elle :
 * une Organization de demonstration peut naitre du chargement, vivre le rejeu,
 * puis disparaitre au retrait — sans geste manuel prealable, et sans jamais
 * toucher a une Organization que le pack n'a pas creee.
 *
 *  A. ABSENT -> LOAD -> STATUS -> DELETE -> ABSENT ;
 *  B. EMPTY ORG -> LOAD -> DELETE -> EMPTY ORG (une Organization preexistante,
 *     meme vide, SURVIT au retrait : elle n'a jamais appartenu au pack) ;
 *  C. anti-adoption : une Organization preexistante qui porte de la donnee
 *     metier est refusee AVANT toute ecriture ;
 *  D. idempotence du rejeu ;
 *  E. hard-bound : le pack refuse toute autre Organization ;
 *  F. le persona « nouvel arrivant » est reellement pauvre en contexte.
 *
 * Les deux packs anterieurs ne sont pas touches : ils n'implementent pas
 * `ProvisionsItsOrganization` et gardent exactement le comportement teste par
 * TASK1242 / TASK1269.
 */
class TASK1351EnglishDogfoodingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Le disque reel porte les fichiers des chargements CLI faits sur la
        // base de developpement : sans faux disque, la garde anti-ecrasement
        // du registrar refuserait — a juste titre — le chargement de test.
        Storage::fake(ArtSciLabEnglishPack::DISK);
    }

    private function load(string $slug = ArtSciLabEnglishPack::ORGANIZATION_SLUG): int
    {
        return $this->artisan('scenario-pack:load', [
            'pack' => ArtSciLabEnglishPack::PACK_ID,
            'organization' => $slug,
        ])->run();
    }

    private function removePack(string $slug = ArtSciLabEnglishPack::ORGANIZATION_SLUG): int
    {
        return $this->artisan('scenario-pack:delete', [
            'pack' => ArtSciLabEnglishPack::PACK_ID,
            'organization' => $slug,
            '--yes' => true,
        ])->run();
    }

    private function organization(): ?Organization
    {
        return Organization::query()
            ->withoutGlobalScopes()
            ->where('slug', ArtSciLabEnglishPack::ORGANIZATION_SLUG)
            ->first();
    }

    public function test_absent_load_status_delete_returns_to_absent(): void
    {
        $this->assertNull($this->organization(), 'Etat de depart : ABSENT.');

        $this->assertSame(0, $this->load());

        $organization = $this->organization();
        $this->assertNotNull($organization, 'Le chargement doit avoir provisionne son Organization.');
        $this->assertSame('en', $organization->locale);
        $this->assertTrue((bool) $organization->loops_enabled);
        $this->assertTrue((bool) $organization->ai_profiles_enabled);
        $this->assertFalse((bool) $organization->is_public);

        $load = ScenarioPackLoad::query()
            ->where('organization_id', $organization->id)
            ->where('pack_id', ArtSciLabEnglishPack::PACK_ID)
            ->firstOrFail();
        $this->assertTrue(
            (bool) $load->organization_created_by_pack,
            'La provenance doit dire que CE chargement a cree l\'Organization.'
        );

        $counts = ScenarioPackEntity::query()
            ->where('scenario_pack_load_id', $load->id)
            ->selectRaw('entity_type, count(*) as total')
            ->groupBy('entity_type')
            ->pluck('total', 'entity_type')
            ->all();

        // Le contrat valide par le MASTER, verifie type par type. Un compte
        // qui derive silencieusement est un dataset qui a change sans que
        // personne l'ait decide.
        $this->assertSame(5, (int) $counts['persona']);
        $this->assertSame(6, (int) $counts['loop']);
        $this->assertSame(6, (int) $counts['folder']);
        $this->assertSame(17, (int) $counts['loop_member']);
        $this->assertSame(16, (int) $counts['folder_file']);
        $this->assertSame(21, (int) $counts['loop_message']);
        $this->assertSame(1, (int) $counts['loop_poll']);
        $this->assertSame(3, (int) $counts['loop_decision']);
        $this->assertSame(4, (int) $counts['loop_roadmap_item']);
        $this->assertSame(1, (int) $counts['loop_event']);
        $this->assertSame(2, (int) $counts['marketplace_request']);
        $this->assertSame(2, (int) $counts['marketplace_service']);
        $this->assertSame(2, (int) $counts['category']);
        $this->assertSame(1, (int) $counts['organization_doctrine']);
        $this->assertSame(1, (int) $counts['organization_ai_setting']);

        $this->assertSame(0, $this->removePack());

        $this->assertNull($this->organization(), 'Apres retrait : ABSENT a nouveau.');
        $this->assertSame(0, User::query()->withoutGlobalScopes()->where('email', 'like', '%@artscilab-en.test')->count());
        $this->assertSame(0, ScenarioPackLoad::query()->where('pack_id', ArtSciLabEnglishPack::PACK_ID)->count());
        $this->assertSame(0, ScenarioPackEntity::query()->count());
        $this->assertSame(
            [],
            Storage::disk(ArtSciLabEnglishPack::DISK)->allFiles(ArtSciLabEnglishPack::ORGANIZATION_SLUG),
            'Le retrait doit emporter les fichiers de corpus, pas seulement leurs lignes.'
        );
    }

    public function test_a_preexisting_empty_organization_survives_the_removal(): void
    {
        // Une Organization vide creee AILLEURS : le pack peut s'y charger,
        // mais elle ne lui appartient pas et ne doit jamais disparaitre.
        $organization = Organization::create([
            'name' => 'Pre-existing shell',
            'slug' => ArtSciLabEnglishPack::ORGANIZATION_SLUG,
            'is_active' => true,
        ]);

        $this->assertSame(0, $this->load());

        $load = ScenarioPackLoad::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertFalse(
            (bool) $load->organization_created_by_pack,
            'Une Organization preexistante n\'est jamais declaree creee par le pack.'
        );

        $this->assertSame(0, $this->removePack());

        $survivor = $this->organization();
        $this->assertNotNull($survivor, 'L\'Organization preexistante doit survivre au retrait.');
        $this->assertSame($organization->id, $survivor->id);
        $this->assertSame(0, User::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->count());
        $this->assertSame(0, Loop::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->count());
        $this->assertSame(0, ScenarioPackEntity::query()->count());
    }

    public function test_a_preexisting_organization_holding_business_data_is_refused(): void
    {
        $organization = Organization::create([
            'name' => 'Someone else\'s tenant',
            'slug' => ArtSciLabEnglishPack::ORGANIZATION_SLUG,
            'is_active' => true,
        ]);

        $stranger = User::factory()->create([
            'organization_id' => $organization->id,
            'email' => 'stranger@example.test',
        ]);

        $this->assertSame(1, $this->load(), 'Le chargement doit echouer.');

        $this->assertSame(0, ScenarioPackLoad::query()->count(), 'Aucun chargement ne doit avoir ete enregistre.');
        $this->assertSame(0, ScenarioPackEntity::query()->count());
        $this->assertSame(
            0,
            User::query()->withoutGlobalScopes()->where('email', 'like', '%@artscilab-en.test')->count(),
            'Aucun persona ne doit avoir ete ecrit dans le tenant de quelqu\'un d\'autre.'
        );
        $this->assertNotNull($stranger->fresh(), 'La donnee preexistante doit etre intacte.');
    }

    public function test_reload_is_idempotent(): void
    {
        $this->assertSame(0, $this->load());

        $organization = $this->organization();
        $firstCounts = ScenarioPackEntity::query()->count();

        $this->assertSame(0, $this->load());

        $this->assertSame(1, ScenarioPackLoad::query()->count(), 'Un seul chargement, meme rejoue.');
        $this->assertSame($firstCounts, ScenarioPackEntity::query()->count());
        $this->assertSame(5, User::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->count());
        $this->assertSame(6, Loop::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->count());
        $this->assertSame(
            17,
            LoopMember::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->count()
        );
        $this->assertSame(
            1,
            OrganizationAiDoctrine::query()->where('organization_id', $organization->id)->count(),
            'Le rejeu ne doit pas empiler une nouvelle version de doctrine.'
        );
    }

    public function test_the_pack_refuses_any_other_organization(): void
    {
        // Meme cible qu'un AUTRE pack, elle aussi allowlistee : le garde-fou
        // d'allowlist ne suffit donc pas ici, c'est le hard-bound du pack qui
        // doit refuser. Le loader est appele directement, comme dans TASK1242 :
        // la garde leve une LogicException (erreur de programmation, pas une
        // condition d'execution), que la commande ne capture volontairement pas.
        $other = Organization::create([
            'name' => 'Other demo',
            'slug' => 'test20260822',
            'is_active' => true,
        ]);

        $pack = app(ScenarioPackCatalog::class)->get(ArtSciLabEnglishPack::PACK_ID);

        try {
            app(ScenarioPackLoader::class)->load($pack, $other);
            $this->fail('Le pack aurait du refuser une Organization qui n\'est pas la sienne.');
        } catch (LogicException $e) {
            $this->assertStringContainsString(ArtSciLabEnglishPack::ORGANIZATION_SLUG, $e->getMessage());
        }

        $this->assertSame(
            0,
            User::query()->withoutGlobalScopes()->where('organization_id', $other->id)->count(),
            'Rien ne doit avoir ete ecrit dans une Organization qui n\'est pas la sienne.'
        );
        $this->assertSame(0, ScenarioPackEntity::query()->count());
    }

    public function test_the_organization_is_never_registered_as_a_pack_entity(): void
    {
        $this->assertSame(0, $this->load());

        $this->assertSame(
            0,
            ScenarioPackEntity::query()->where('entity_model', Organization::class)->count(),
            'Une Organization ne porte pas d\'organization_id : elle ne peut pas etre inscrite au registre.'
        );
    }

    public function test_the_new_member_persona_is_deliberately_low_context(): void
    {
        $this->assertSame(0, $this->load());

        $wen = User::query()->withoutGlobalScopes()->where('email', 'wen@artscilab-en.test')->firstOrFail();

        $this->assertSame('en', $wen->preferred_locale);
        $this->assertNull($wen->bio, 'Le vide du profil EST la donnee de recette.');
        $this->assertNull($wen->city);
        $this->assertSame(
            1,
            LoopMember::query()->withoutGlobalScopes()->where('user_id', $wen->id)->count(),
            'La nouvelle arrivante n\'appartient qu\'a la Boucle d\'orientation.'
        );

        $loop = Loop::query()
            ->withoutGlobalScopes()
            ->whereIn('id', LoopMember::query()->withoutGlobalScopes()->where('user_id', $wen->id)->pluck('loop_id'))
            ->firstOrFail();
        $this->assertSame('Circle Orientation', $loop->name);
    }

    public function test_the_corpus_is_written_thematically_under_its_own_loop(): void
    {
        $this->assertSame(0, $this->load());

        $files = Storage::disk(ArtSciLabEnglishPack::DISK)->allFiles(ArtSciLabEnglishPack::ORGANIZATION_SLUG);
        $this->assertCount(16, $files);

        // Le rattachement n'est pas arbitraire : chaque document appartient au
        // Dossier racine de la Boucle dont il parle. Un corpus reparti au
        // hasard (`$i % count($dossiers)`) rendrait toute question RAG
        // incoherente — c'est le defaut releve sur le pack ArtSciLab en
        // PHASE 0, et la raison pour laquelle ce pack ne le reproduit pas.
        $sonicTerrain = Loop::query()
            ->withoutGlobalScopes()
            ->where('name', 'Sonic Terrain — Climate Data Sonification')
            ->firstOrFail();
        $dossier = Dossier::query()->withoutGlobalScopes()->where('loop_id', $sonicTerrain->id)->firstOrFail();

        $method = DossierFile::query()
            ->withoutGlobalScopes()
            ->where('original_name', 'sonification-method-notes.md')
            ->firstOrFail();

        $this->assertSame($dossier->id, $method->dossier_id);
        $this->assertStringContainsString('artscilab-en/sonic_terrain/', $method->path);

        // La question sentinelle du contrat doit trouver sa matiere dans ce
        // fichier — sans quoi la recette RAG teste le hasard.
        $body = Storage::disk(ArtSciLabEnglishPack::DISK)->get($method->path);
        $this->assertStringContainsString('pitch', strtolower($body));
        $this->assertStringContainsString('uncertainty', strtolower($body));
    }

    public function test_the_corpus_never_explains_bouclepro_itself(): void
    {
        $this->assertSame(0, $this->load());

        // Invariant du contrat : GUIDE PRODUIT != CORPUS RAG METIER. Un corpus
        // qui explique le produit contaminerait les reponses documentaires avec
        // de la matiere qui appartient au Guide.
        foreach (Storage::disk(ArtSciLabEnglishPack::DISK)->allFiles(ArtSciLabEnglishPack::ORGANIZATION_SLUG) as $path) {
            $body = strtolower(Storage::disk(ArtSciLabEnglishPack::DISK)->get($path));

            $this->assertStringNotContainsString('bouclepro', $body, "Le document {$path} parle du produit.");
        }
    }

    public function test_the_only_event_is_in_the_future(): void
    {
        $this->assertSame(0, $this->load());

        $event = LoopEvent::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertTrue(
            $event->starts_at->isFuture(),
            'Un pack de demonstration dont le seul evenement est passe se demode tout seul.'
        );
    }

    public function test_the_pack_configures_the_ai_but_never_a_credential(): void
    {
        $this->assertSame(0, $this->load());

        $organization = $this->organization();
        $settings = OrganizationAiSetting::query()->where('organization_id', $organization->id)->firstOrFail();

        // Ce que le pack POSE : la configuration non secrete, pour que le
        // manque soit visible dans l'ecran SuperAdmin au lieu d'etre invisible.
        $this->assertSame('openrouter', $settings->provider);
        $this->assertNotSame('', trim((string) $settings->model));
        $this->assertTrue((bool) $settings->is_enabled);

        // Ce que le pack NE POSE JAMAIS : le credential. Un scenario pack
        // reproduit des donnees et une configuration, jamais un secret.
        $this->assertSame(
            '',
            trim((string) $settings->getAttribute('api_key')),
            'Un pack ne doit jamais ecrire de cle API, ni la lire depuis l\'environnement.'
        );
    }

    public function test_reloading_never_overwrites_a_credential_a_human_pasted(): void
    {
        $this->assertSame(0, $this->load());

        $organization = $this->organization();
        $settings = OrganizationAiSetting::query()->where('organization_id', $organization->id)->firstOrFail();

        // Un humain colle la cle dans l'ecran SuperAdmin.
        $settings->forceFill(['api_key' => 'sk-pasted-by-a-human', 'api_key_updated_at' => now()])->save();

        $this->assertSame(0, $this->load());

        $reloaded = OrganizationAiSetting::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame(
            'sk-pasted-by-a-human',
            trim((string) $reloaded->getAttribute('api_key')),
            'Un rechargement du pack ne doit jamais effacer la cle posee par un humain.'
        );
    }

    public function test_the_cards_the_dataset_feeds_are_switched_on(): void
    {
        $this->assertSame(0, $this->load());

        // Le preset de type n'active ni les Decisions ni la Roadmap : sans ce
        // passage, le pack ecrirait des decisions et des elements de roadmap
        // que personne ne verrait. Une donnee invisible ne demontre rien.
        $sonicTerrain = Loop::query()
            ->withoutGlobalScopes()
            ->where('name', 'Sonic Terrain — Climate Data Sonification')
            ->firstOrFail();

        foreach (['core.decisions', 'core.roadmap'] as $card) {
            $this->assertTrue(
                (bool) DB::table('loop_cards')
                    ->where('loop_id', $sonicTerrain->id)
                    ->where('card_key', $card)
                    ->value('enabled'),
                "La Card {$card} devrait etre active sur une Boucle dont le dataset l'alimente."
            );
        }

        // Rien n'a ete eteint : l'activation est ADDITIVE.
        $this->assertTrue(
            (bool) DB::table('loop_cards')
                ->where('loop_id', $sonicTerrain->id)
                ->where('card_key', 'core.dossiers')
                ->value('enabled'),
            'Les Cards du preset restent actives.'
        );
    }
}
