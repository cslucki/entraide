<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Support\ScenarioPacks\Packs\ArtSciLabEnglishPack;
use App\Support\ScenarioPacks\ScenarioPackLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TASK-1396 — le tenant anglais porte un nom anglais.
 *
 * ## Le constat
 *
 * `organizations.name` valait `ArtSciLab — Test anglais` : un libelle
 * FRANCAIS pour l'Organization anglaise de la demonstration. Il s'affiche dans
 * l'en-tete, dans le selecteur de tenant et sous les cartes du Shell — donc
 * sur le chemin que Roger verra.
 *
 * L'autorite est le PACK (`provisionOrganization()`), pas la base : une simple
 * mise a jour SQL serait reintroduite a toute recreation depuis zero.
 *
 * ## La difficulte reelle : le pack ne renomme jamais
 *
 * `provisionOrganization()` n'est appele QUE si l'Organization n'existe pas
 * (`ScenarioPackLoadCommand`). Sur un tenant deja provisionne — le cas de la
 * demonstration — corriger la constante ne change donc **rien**.
 *
 * C'est exactement la lecon de TASK-1395 : changer le code ne change pas la
 * donnee. Le pack doit RECONCILIER le nom a chaque chargement.
 *
 * ## Et pourquoi il ne le fait pas aveuglement
 *
 * Reconcilier sans condition ecraserait un nom qu'une personne aurait choisi.
 * La reconciliation n'agit donc que si le nom actuel est une variante
 * HISTORIQUE connue — le meme motif que la migration de convergence T1354, qui
 * s'etait donne la meme garde pour la meme raison.
 */
class TASK1396ArtSciLabTenantNameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(ArtSciLabEnglishPack::DISK);
        config(['scenario_packs.allowed_organizations' => [ArtSciLabEnglishPack::ORGANIZATION_SLUG]]);
    }

    /**
     * Le nom canonique du pack est en ANGLAIS.
     *
     * La mesure la plus directe : elle porte sur la source d'autorite, avant
     * toute question de propagation.
     */
    public function test_the_pack_declares_an_english_name(): void
    {
        $pack = app(ArtSciLabEnglishPack::class);

        $this->assertSame('ArtSciLab — English test', $pack->packName());
        $this->assertStringNotContainsString('anglais', $pack->packName());
    }

    /**
     * Un tenant DEJA provisionne sous l'ancien nom est renomme au rechargement.
     *
     * Le coeur de la tranche. `provisionOrganization()` n'est jamais rappele
     * sur une Organization existante : sans reconciliation explicite, corriger
     * la constante n'aurait aucun effet sur la demonstration.
     */
    public function test_reloading_renames_a_tenant_that_still_carries_the_french_name(): void
    {
        $organization = $this->organisation('ArtSciLab — Test anglais');

        app(ScenarioPackLoader::class)->load(app(ArtSciLabEnglishPack::class), $organization);

        $this->assertSame('ArtSciLab — English test', $organization->refresh()->name);
    }

    /**
     * Un nom choisi par une PERSONNE n'est jamais ecrase.
     *
     * Le contre-exemple, et il est indispensable : une reconciliation sans
     * condition transformerait une correction en perte de donnee. Seules les
     * variantes historiques connues sont reprises.
     */
    public function test_a_human_chosen_name_is_never_overwritten(): void
    {
        $organization = $this->organisation('ArtSciLab UT Dallas');

        app(ScenarioPackLoader::class)->load(app(ArtSciLabEnglishPack::class), $organization);

        $this->assertSame('ArtSciLab UT Dallas', $organization->refresh()->name);
    }

    /**
     * Rejouer sur un tenant deja renomme ne change plus rien.
     *
     * L'idempotence, mesuree sur la valeur cible elle-meme : une fois le nom
     * correct, la reconciliation doit devenir un no-op.
     */
    public function test_replaying_on_an_already_correct_name_is_a_no_op(): void
    {
        $organization = $this->organisation('ArtSciLab — English test');
        $avant = $organization->refresh()->updated_at;

        app(ScenarioPackLoader::class)->load(app(ArtSciLabEnglishPack::class), $organization);

        $apres = $organization->refresh();
        $this->assertSame('ArtSciLab — English test', $apres->name);
        $this->assertEquals($avant, $apres->updated_at, 'Un no-op ne doit pas toucher la ligne.');
    }

    private function organisation(string $nom): Organization
    {
        return Organization::factory()->create([
            'slug' => ArtSciLabEnglishPack::ORGANIZATION_SLUG,
            'name' => $nom,
            'locale' => 'en',
            'is_active' => true,
            'loops_enabled' => true,
            'ai_profiles_enabled' => true,
        ]);
    }
}
