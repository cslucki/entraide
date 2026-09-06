<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Support\ScenarioPacks\Packs\ArtSciLabEnglishPack;
use App\Support\ScenarioPacks\ScenarioPackLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
     * Rejouer sur un tenant deja renomme n'ecrit plus le nom.
     *
     * L'idempotence, mesuree sur la colonne que la reconciliation gouverne —
     * et sur elle seule.
     *
     * ## Pourquoi pas `updated_at`
     *
     * La premiere version de cette mesure comparait `updated_at` avant et
     * apres, en lisant « un no-op ne doit pas toucher la ligne ». Deux
     * mesures l'ont tuee :
     *
     * - `load()` ecrit TOUJOURS la ligne, et c'est legitime : le pack designe
     *   Elena comme administratrice, d'ou un
     *   `update "organizations" set "admin_id" = ?, "updated_at" = ?` a chaque
     *   chargement. La ligne EST touchee, meme quand le nom ne l'est pas.
     * - `updated_at` est stocke a la SECONDE. L'ancienne assertion passait
     *   donc uniquement quand `load()` s'achevait dans la meme seconde que la
     *   creation du tenant — vert en local en 0,3 s, rouge sur un shard
     *   PostgreSQL de quatre minutes. Une garde qui depend de l'horloge ne
     *   garde rien.
     *
     * L'instrument juste est la requete elle-meme : aucune ecriture ne doit
     * porter sur la colonne `name`.
     *
     * ## Ce qu'il attrape, et ce qu'il n'attrape pas
     *
     * Mesure faite, pas supposee. Une reconciliation rendue INCONDITIONNELLE
     * via le query builder fait rougir cette garde. La meme reconciliation
     * rendue inconditionnelle via `Model::update()` la laisse VERTE : Eloquent
     * ne genere aucune requete quand la valeur est identique, et c'est alors
     * son controle de « dirty », pas la garde du pack, qui produit le no-op.
     * La garde qui protege reellement la donnee est celle du nom choisi par
     * une personne, juste au-dessus — elle rougit dans les deux cas.
     */
    public function test_replaying_on_an_already_correct_name_never_writes_the_name(): void
    {
        $organization = $this->organisation('ArtSciLab — English test');

        $ecrituresDuNom = [];

        DB::listen(function ($requete) use (&$ecrituresDuNom): void {
            if (str_contains($requete->sql, 'update "organizations"') && str_contains($requete->sql, '"name"')) {
                $ecrituresDuNom[] = $requete->sql;
            }
        });

        app(ScenarioPackLoader::class)->load(app(ArtSciLabEnglishPack::class), $organization);

        $this->assertSame('ArtSciLab — English test', $organization->refresh()->name);
        $this->assertSame([], $ecrituresDuNom, 'Le nom etait deja canonique : la reconciliation ne doit rien ecrire.');
    }

    /**
     * Et le contre-exemple de l'instrument lui-meme.
     *
     * Une garde qui compte des ecritures ne vaut que si elle sait en compter
     * au moins une. Sur un tenant portant encore un nom historique, la meme
     * ecoute DOIT voir passer l'ecriture du nom — sans quoi le no-op mesure
     * juste au-dessus serait vert pour n'importe quelle raison.
     */
    public function test_the_listener_really_sees_the_rename_when_it_happens(): void
    {
        $organization = $this->organisation('ArtSciLab — Test anglais');

        $ecrituresDuNom = [];

        DB::listen(function ($requete) use (&$ecrituresDuNom): void {
            if (str_contains($requete->sql, 'update "organizations"') && str_contains($requete->sql, '"name"')) {
                $ecrituresDuNom[] = $requete->sql;
            }
        });

        app(ScenarioPackLoader::class)->load(app(ArtSciLabEnglishPack::class), $organization);

        $this->assertNotSame([], $ecrituresDuNom, 'Le renommage doit passer par une ecriture visible.');
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
