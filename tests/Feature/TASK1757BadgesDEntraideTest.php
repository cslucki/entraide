<?php

namespace Tests\Feature;

use App\Models\Badge;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BadgeService;
use App\Support\Entraide\CatalogueDeBadges;
use Database\Seeders\BadgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1757 (§9.7 du CDC entraide) — les badges d'entraide, et le lexique.
 *
 * ## Les deux choses que ce fichier garde
 *
 * 1. **Les trois badges parlent d'AIDE DONNEE.** Les badges herites comptent
 *    les echanges dans les DEUX sens : on obtient « Premiere entraide » en se
 *    faisant aider. Confondre les deux rendrait les nouveaux badges
 *    redondants, et surtout faux.
 * 2. **La ligne du badge est creee si elle manque.** C'est le trou le plus
 *    silencieux de l'existant : aucune migration ne pose les badges, seulement
 *    `BadgeSeeder`. Un environnement deja deploye aurait rempli la condition,
 *    trouve la ligne absente, et rendu la main sans un mot.
 */
class TASK1757BadgesDEntraideTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private BadgeService $badges;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['slug' => 'org-a-1757b', 'is_active' => true, 'locale' => 'fr']);
        $this->orgB = Organization::factory()->create(['slug' => 'org-b-1757b', 'is_active' => true, 'locale' => 'fr']);
        app()->instance('current_organization', $this->orgA);

        $this->badges = app(BadgeService::class);
    }

    private function membre(Organization $org): User
    {
        return User::factory()->create(['organization_id' => $org->id, 'points_balance' => 500]);
    }

    /** Une entraide TERMINEE : `$aidant` a aide `$aide`. */
    private function entraide(Organization $org, User $aidant, User $aide, int $points = 30): Transaction
    {
        $annonce = Service::factory()->create([
            'user_id' => $aidant->id,
            'organization_id' => $org->id,
            'category_id' => Category::factory()->create(['organization_id' => $org->id])->id,
            'status' => 'active',
            'points_cost' => $points,
        ]);

        return Transaction::factory()->create([
            'organization_id' => $org->id,
            'service_id' => $annonce->id,
            'seller_id' => $aidant->id,
            'buyer_id' => $aide->id,
            'status' => 'completed',
            'points_proposed' => $points,
            'points_agreed' => $points,
        ]);
    }

    private function clefsDe(User $membre): array
    {
        return $membre->badges()->pluck('key')->all();
    }

    // ── Les trois badges ─────────────────────────────────────────────────────

    /**
     * 🔴 LA DISTINCTION QUI COMPTE — aider n'est pas etre aide.
     *
     * Sans cette reciproque, les trois badges feraient doublon avec les
     * herites : « Premiere entraide » s'obtient deja en se faisant aider.
     */
    public function test_le_premier_coup_de_main_se_merite_en_aidan_t_pas_en_etant_aide(): void
    {
        $aidant = $this->membre($this->orgA);
        $aide = $this->membre($this->orgA);

        $this->entraide($this->orgA, $aidant, $aide);

        $this->badges->checkAndAward($aidant, $this->orgA);
        $this->badges->checkAndAward($aide, $this->orgA);

        $this->assertContains(CatalogueDeBadges::PREMIER_COUP_DE_MAIN, $this->clefsDe($aidant));

        $this->assertNotContains(CatalogueDeBadges::PREMIER_COUP_DE_MAIN, $this->clefsDe($aide),
            'Se faire aider ne donne pas « Premier coup de main ».');

        // Et la preuve que la distinction n'est pas un artefact : le badge
        // HERITE, lui, est bien acquis par les deux.
        $this->assertContains('first_exchange', $this->clefsDe($aide));
    }

    /** 5 personnes DISTINCTES, pas 5 echanges. */
    public function test_cinq_personnes_aidees_compte_les_personnes_et_non_les_fois(): void
    {
        $aidant = $this->membre($this->orgA);

        // Quatre personnes, mais six entraides.
        $quatre = collect(range(1, 4))->map(fn () => $this->membre($this->orgA));
        foreach ($quatre as $personne) {
            $this->entraide($this->orgA, $aidant, $personne);
        }
        $this->entraide($this->orgA, $aidant, $quatre->first());
        $this->entraide($this->orgA, $aidant, $quatre->first());

        $this->badges->checkAndAward($aidant, $this->orgA);
        $this->assertNotContains(CatalogueDeBadges::CINQ_PERSONNES_AIDEES, $this->clefsDe($aidant),
            'Six entraides avec quatre personnes ne font pas cinq personnes aidees.');

        // La cinquieme personne, elle, declenche le badge.
        $this->entraide($this->orgA, $aidant, $this->membre($this->orgA));
        $this->badges->checkAndAward($aidant->fresh(), $this->orgA);

        $this->assertContains(CatalogueDeBadges::CINQ_PERSONNES_AIDEES, $this->clefsDe($aidant->fresh()));
    }

    /** Une DISCUSSION directe n'aide personne. */
    public function test_cinq_bonjours_ne_donnent_aucun_badge_d_entraide(): void
    {
        $bavard = $this->membre($this->orgA);

        foreach (range(1, 5) as $i) {
            Transaction::factory()->create([
                'organization_id' => $this->orgA->id,
                'service_id' => null, 'request_id' => null,
                'seller_id' => $bavard->id, 'buyer_id' => $this->membre($this->orgA)->id,
                'status' => 'completed', 'points_proposed' => 0, 'points_agreed' => 0,
            ]);
        }

        $this->badges->checkAndAward($bavard, $this->orgA);

        $clefs = $this->clefsDe($bavard);
        $this->assertNotContains(CatalogueDeBadges::PREMIER_COUP_DE_MAIN, $clefs);
        $this->assertNotContains(CatalogueDeBadges::CINQ_PERSONNES_AIDEES, $clefs);
    }

    /**
     * « A aide un nouveau membre » : la personne aidee n'avait AUCUNE autre
     * entraide terminee.
     *
     * Definition sans calendrier — un seuil en jours serait arbitraire et se
     * perimerait. La reciproque est testee dans la foulee : aider un habitue ne
     * donne pas le badge.
     */
    public function test_aider_un_nouveau_donne_le_badge_aider_un_habitue_non(): void
    {
        $aidant = $this->membre($this->orgA);
        $habitue = $this->membre($this->orgA);

        // L'habitue a deja ete aide par quelqu'un d'autre.
        $this->entraide($this->orgA, $this->membre($this->orgA), $habitue);

        $this->entraide($this->orgA, $aidant, $habitue);
        $this->badges->checkAndAward($aidant, $this->orgA);

        $this->assertNotContains(CatalogueDeBadges::AIDE_UN_NOUVEAU, $this->clefsDe($aidant),
            'L\'habitue comptait deja deux entraides : ce n\'etait pas son premier pas.');

        // Un vrai nouveau, dont c'est la toute premiere entraide.
        $this->entraide($this->orgA, $aidant, $this->membre($this->orgA));
        $this->badges->checkAndAward($aidant->fresh(), $this->orgA);

        $this->assertContains(CatalogueDeBadges::AIDE_UN_NOUVEAU, $this->clefsDe($aidant->fresh()));
    }

    /** 🔴 La frontiere de tenant, heritee de la correction T1755. */
    public function test_une_entraide_d_un_autre_tenant_ne_donne_pas_le_badge(): void
    {
        $aidant = $this->membre($this->orgA);

        $this->entraide($this->orgB, $aidant, $this->membre($this->orgB));

        $this->badges->checkAndAward($aidant, $this->orgA);

        $this->assertNotContains(CatalogueDeBadges::PREMIER_COUP_DE_MAIN, $this->clefsDe($aidant),
            'Une entraide d\'une autre Organization a ete comptee.');
    }

    // ── Le trou silencieux ───────────────────────────────────────────────────

    /**
     * 🔴 LE BADGE EST CREE SI SA LIGNE MANQUE.
     *
     * Aucune migration ne pose les badges — seulement `BadgeSeeder`. Sans cette
     * creation, un environnement deja deploye aurait rempli la condition,
     * trouve la ligne absente, et rendu la main SANS UN MOT : le badge
     * n'existerait jamais en PROD, et aucun test de condition ne le verrait.
     */
    public function test_le_badge_est_cree_si_sa_ligne_n_existe_pas(): void
    {
        Badge::query()->delete();
        $this->assertSame(0, Badge::count(), 'La base de test doit bien partir sans aucun badge.');

        $aidant = $this->membre($this->orgA);
        $this->entraide($this->orgA, $aidant, $this->membre($this->orgA));

        $this->badges->checkAndAward($aidant, $this->orgA);

        $this->assertContains(CatalogueDeBadges::PREMIER_COUP_DE_MAIN, $this->clefsDe($aidant));
        $this->assertDatabaseHas('badges', ['key' => CatalogueDeBadges::PREMIER_COUP_DE_MAIN]);
    }

    /** Deux passes ne decernent pas deux fois le meme badge. */
    public function test_l_attribution_reste_idempotente(): void
    {
        $aidant = $this->membre($this->orgA);
        $this->entraide($this->orgA, $aidant, $this->membre($this->orgA));

        $this->badges->checkAndAward($aidant, $this->orgA);
        $this->badges->checkAndAward($aidant->fresh(), $this->orgA);

        $this->assertSame(1, collect($this->clefsDe($aidant->fresh()))
            ->filter(fn ($c) => $c === CatalogueDeBadges::PREMIER_COUP_DE_MAIN)->count());
    }

    /** Sans Organization resoluble, les badges d'entraide ne sont pas evalues. */
    public function test_sans_organization_les_badges_d_entraide_sont_sautes_et_les_herites_restent(): void
    {
        $aidant = $this->membre($this->orgA);
        $this->entraide($this->orgA, $aidant, $this->membre($this->orgA));

        $this->badges->checkAndAward($aidant);

        $clefs = $this->clefsDe($aidant);
        $this->assertNotContains(CatalogueDeBadges::PREMIER_COUP_DE_MAIN, $clefs);
        $this->assertContains('first_exchange', $clefs, 'Les badges herites ne doivent pas avoir change de comportement.');
    }

    // ── Le lexique ───────────────────────────────────────────────────────────

    /**
     * 🔴 LE CRITERE N°1 DE LA TASK A, sur la surface qu'il avait ratee.
     *
     * « plus AUCUNE occurrence visible de transaction / acheteur / vendeur /
     * prestation / achat / vente dans l'UI membre ». Le catalogue de badges est
     * de la DONNEE, semee en base : la passe de lexique s'est arretee aux
     * fichiers de langue et « Prestataire » est reste sur le profil.
     */
    public function test_le_profil_n_affiche_plus_l_ancien_vocabulaire_des_badges(): void
    {
        $this->seed(BadgeSeeder::class);

        $membre = $this->membre($this->orgA);
        $membre->badges()->attach(
            Badge::where('key', 'first_service')->firstOrFail()->id,
            ['earned_at' => now()]
        );

        $page = $this->actingAs($this->membre($this->orgA))
            ->get(route('profile.show', $membre))->assertOk()->getContent();

        $this->assertStringContainsString('Première annonce', $page);
        $this->assertStringNotContainsString('Prestataire', $page,
            'L\'ancien vocabulaire est encore affiche sur le profil.');
    }

    /** Et le libelle est TRADUIT — la colonne en base ne l'etait dans aucune langue. */
    public function test_le_libelle_d_un_badge_est_traduit(): void
    {
        $this->seed(BadgeSeeder::class);

        $badge = Badge::where('key', CatalogueDeBadges::PREMIER_COUP_DE_MAIN)->firstOrFail();

        app()->setLocale('fr');
        $this->assertSame('Premier coup de main', $badge->libelle());

        app()->setLocale('en');
        $this->assertSame('First helping hand', $badge->libelle());
    }

    /**
     * Le REPLI : un badge absent du fichier de langue s'affiche quand meme.
     *
     * Load-bearing — un badge pose demain par une migration ou par une
     * Organization n'aurait aucune cle, et le profil afficherait du vide.
     */
    public function test_un_badge_inconnu_du_lexique_retombe_sur_sa_colonne(): void
    {
        $badge = Badge::create([
            'key' => 'badge_venu_d_ailleurs',
            'name' => 'Libellé en base',
            'description' => 'Description en base',
            'icon' => '🧪',
            'color' => '#000000',
        ]);

        $this->assertSame('Libellé en base', $badge->libelle());
        $this->assertSame('Description en base', $badge->explication());
    }
}
