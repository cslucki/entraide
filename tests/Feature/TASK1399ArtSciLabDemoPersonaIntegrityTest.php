<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Support\ScenarioPacks\Packs\ArtSciLabEnglishPack;
use App\Support\ScenarioPacks\ScenarioPackLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TASK-1399 — les personas de demonstration portent des donnees tenables.
 *
 * ## Trois defauts, un seul responsable
 *
 * Les trois se voient sur le chemin de Roger, et les trois viennent du PACK.
 *
 * | ce que le visiteur voit | cause mesuree |
 * |---|---|
 * | « Marcus Marcus Whitfield » | `User::fullName` vaut `trim(first_name.' '.name)`, et le pack ecrivait le nom COMPLET dans `name` |
 * | « Prepare a help request » renvoie vers `/profile/edit` | `EnsureProfileComplete` exige `phone` ; les personas l'avaient a `null` |
 * | « Échangez vos talents » sous un tenant anglais | `platform_tagline` nul, et `AppServiceProvider` replie sur un litteral francais |
 *
 * Aucun ne se corrige dans le modele `User`, dans le middleware, ni dans le
 * repli global : tous les trois se corrigent dans la source canonique des
 * personas de demonstration.
 *
 * ## La difficulte reelle : le tenant existe deja
 *
 * C'est la lecon de TASK-1395, puis de TASK-1396, et elle vaut ici trois fois.
 * `provisionOrganization()` n'est appele QUE si l'Organization n'existe pas, et
 * `updateOrCreate` ne met a jour que les champs qu'on lui PASSE. Corriger une
 * constante ne change donc rien au tenant de la demonstration. Chaque champ
 * doit etre reecrit ou reconcilie au chargement — et c'est ce que ces gardes
 * mesurent, en partant systematiquement d'un tenant DEJA dans l'ancien etat.
 */
class TASK1399ArtSciLabDemoPersonaIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(ArtSciLabEnglishPack::DISK);
        config(['scenario_packs.allowed_organizations' => [ArtSciLabEnglishPack::ORGANIZATION_SLUG]]);
    }

    // =====================================================================
    // Les noms
    // =====================================================================

    /**
     * Le nom affiche ne repete plus le prenom.
     *
     * La mesure porte sur `fullName`, c'est-a-dire sur ce que le produit
     * AFFICHE — pas sur la colonne. Une garde posee sur `name` seul serait
     * verte pour n'importe quelle valeur bien formee, y compris une qui
     * casserait l'affichage.
     */
    public function test_no_persona_displays_a_duplicated_first_name(): void
    {
        $organization = $this->tenantCharge();

        foreach ($this->personas($organization) as $persona) {
            $this->assertSame(
                $persona->first_name.' '.$persona->name,
                $persona->fullName,
                'fullName doit rester la concatenation attendue.',
            );

            $this->assertStringNotContainsString(
                $persona->first_name.' '.$persona->first_name,
                $persona->fullName,
                "Le prenom de {$persona->email} apparait deux fois.",
            );
        }
    }

    /**
     * Le nom complet reste JUSTE.
     *
     * Le contre-exemple du precedent : vider `name` supprimerait le doublon et
     * passerait la garde ci-dessus, en detruisant l'identite des personas.
     */
    public function test_the_displayed_names_are_still_the_expected_people(): void
    {
        $organization = $this->tenantCharge();

        $noms = $this->personas($organization)->map->fullName->sort()->values()->all();

        $this->assertSame(
            ['Elena Cho', 'Marcus Whitfield', 'Priya Nandakumar', 'Sam Okafor', 'Wen Zhao'],
            $noms,
        );
    }

    /**
     * Un tenant DEJA provisionne sous l'ancien nom complet est corrige.
     *
     * Le coeur de la tranche. Sans rejeu du champ, la correction de la
     * constante n'aurait aucun effet sur la demonstration — et c'est
     * exactement l'erreur que TASK-1394 avait commise avant que TASK-1395 ne
     * la rattrape.
     */
    public function test_replaying_repairs_a_persona_that_still_carries_the_full_name(): void
    {
        $organization = $this->tenantCharge();

        User::query()->where('email', 'marcus@artscilab-en.test')
            ->update(['name' => 'Marcus Whitfield']);

        $this->recharger($organization);

        $marcus = User::query()->where('email', 'marcus@artscilab-en.test')->firstOrFail();
        $this->assertSame('Whitfield', $marcus->name);
        $this->assertSame('Marcus Whitfield', $marcus->fullName);
    }

    // =====================================================================
    // Le telephone
    // =====================================================================

    /**
     * Les personas actifs ont un telephone, donc ne rebondissent plus.
     *
     * La cause exacte du renvoi vers `/profile/edit` au milieu de la
     * demonstration : `EnsureProfileComplete` exige `phone` autant que
     * `bio` ou `city`.
     */
    public function test_active_personas_satisfy_the_profile_completeness_rule(): void
    {
        $organization = $this->tenantCharge();

        foreach ($this->personas($organization)->where('email', '!=', 'wen@artscilab-en.test') as $persona) {
            $this->assertNotEmpty($persona->phone, "{$persona->email} n'a pas de telephone.");
            $this->assertNotEmpty($persona->first_name);
            $this->assertNotEmpty($persona->name);
            $this->assertNotEmpty($persona->city);
            $this->assertNotEmpty($persona->country_code);
            $this->assertNotEmpty($persona->bio);
        }
    }

    /**
     * Le telephone est fictif, et se donne pour tel.
     *
     * Une donnee de demonstration qui ressemble a une vraie donnee est un
     * risque, pas un detail : le prefixe `000` n'est attribuable par aucun
     * operateur nord-americain, et le marqueur `(DEMO)` le dit a qui le lit.
     *
     * Le compte est affirme AVANT la boucle. Sans lui, la garde serait verte
     * a vide le jour ou plus aucun persona n'aurait de telephone — le
     * sabotage « omettre phone du rejeu » l'a d'ailleurs rendue `risky`, zero
     * assertion executee, ce qui est une facon silencieuse de ne rien garder.
     */
    public function test_the_demo_phone_can_never_be_mistaken_for_a_real_one(): void
    {
        $organization = $this->tenantCharge();

        $avecTelephone = $this->personas($organization)->whereNotNull('phone');

        $this->assertCount(4, $avecTelephone, 'Les quatre personas actifs doivent avoir un telephone.');

        foreach ($avecTelephone as $persona) {
            $this->assertStringContainsString('(DEMO)', $persona->phone);
            $this->assertStringContainsString('000', $persona->phone);
        }
    }

    /**
     * Un persona sans telephone en base en recoit un au rejeu.
     *
     * Meme mecanique que pour le nom, et meme piege : `updateOrCreate`
     * n'ecrit que les cles qu'on lui passe. Omettre `phone` laisserait le
     * tenant de la demonstration exactement dans l'etat qui casse le parcours.
     */
    public function test_replaying_gives_a_phone_to_a_persona_that_had_none(): void
    {
        $organization = $this->tenantCharge();

        User::query()->where('email', 'marcus@artscilab-en.test')->update(['phone' => null]);

        $this->recharger($organization);

        $this->assertNotEmpty(
            User::query()->where('email', 'marcus@artscilab-en.test')->value('phone'),
        );
    }

    /**
     * Wen reste incomplet — c'est le sujet du personnage.
     *
     * Le contre-exemple indispensable : completer tout le monde supprimerait
     * l'etat vide honnete que le pack met en scene depuis TASK-1393. La
     * correction ne doit pas devenir un nivellement du dataset.
     */
    public function test_the_new_member_persona_stays_deliberately_incomplete(): void
    {
        $organization = $this->tenantCharge();

        $wen = User::query()->where('email', 'wen@artscilab-en.test')->firstOrFail();

        $this->assertNull($wen->phone);
        $this->assertNull($wen->bio);
    }

    // =====================================================================
    // L'accroche du tenant
    // =====================================================================

    /**
     * Le tenant anglais porte une accroche anglaise.
     *
     * Sans valeur, `AppServiceProvider` replie sur « Échangez vos talents » :
     * l'ecran de connexion du tenant anglais s'ouvrait en francais, avant
     * meme que quiconque se soit identifie.
     */
    public function test_the_english_tenant_carries_an_english_tagline(): void
    {
        $organization = $this->tenantCharge();

        $this->assertSame(
            ArtSciLabEnglishPack::PLATFORM_TAGLINE,
            $organization->refresh()->platform_tagline,
        );
        $this->assertStringNotContainsString('talents', (string) $organization->platform_tagline);
    }

    /**
     * Un tenant DEJA provisionne sans accroche en recoit une.
     *
     * `provisionOrganization()` ne rejoue jamais : declarer la valeur a la
     * creation n'aurait rien change au tenant de la demonstration, qui est
     * precisement celui qui n'en a pas.
     */
    public function test_replaying_fills_a_tagline_that_was_missing(): void
    {
        $organization = $this->tenantCharge();
        $organization->update(['platform_tagline' => null]);

        $this->recharger($organization);

        $this->assertSame(
            ArtSciLabEnglishPack::PLATFORM_TAGLINE,
            $organization->refresh()->platform_tagline,
        );
    }

    /**
     * Une accroche choisie par une PERSONNE n'est jamais ecrasee.
     *
     * Le contre-exemple, et il est indispensable : une reconciliation sans
     * condition transformerait une correction en perte de donnee — meme motif
     * que la garde de nom de TASK-1396.
     */
    public function test_a_human_chosen_tagline_is_never_overwritten(): void
    {
        $organization = $this->tenantCharge();
        $organization->update(['platform_tagline' => 'Our own words']);

        $this->recharger($organization);

        $this->assertSame('Our own words', $organization->refresh()->platform_tagline);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function tenantCharge(): Organization
    {
        $organization = app(ArtSciLabEnglishPack::class)->provisionOrganization();

        $this->recharger($organization);

        return $organization;
    }

    private function recharger(Organization $organization): void
    {
        app(ScenarioPackLoader::class)->load(app(ArtSciLabEnglishPack::class), $organization->refresh());
    }

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function personas(Organization $organization): \Illuminate\Support\Collection
    {
        return User::query()
            ->where('organization_id', $organization->id)
            ->orderBy('email')
            ->get();
    }
}
