<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Organization;
use App\Models\PointLedger;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Entraide\Atelier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1756 (§6.3) — l'atelier de groupe.
 *
 * Deux familles de gardes, et la seconde est celle qui compte vraiment :
 * la BORNE (on ne depasse pas la capacite) et le CALCUL DE LA PART, qui touche
 * des points — donc quelque chose qui ressemble a de l'argent.
 */
class TASK1756AtelierGroupeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $aidant;

    protected function setUp(): void
    {
        parent::setUp();

        // `locale` explicite : `SetLocale` passe par l'Organization, et la
        // fabrique ne garantit pas le francais (lecon T1755).
        $this->organization = Organization::factory()->create([
            'slug' => 'org-1756', 'is_active' => true, 'locale' => 'fr',
        ]);
        app()->instance('current_organization', $this->organization);

        $this->aidant = User::factory()->create(['organization_id' => $this->organization->id]);
    }

    private function atelier(int $points = 45, ?int $capacite = 6, ?int $plancher = 3): Service
    {
        return Service::factory()->create([
            'user_id' => $this->aidant->id,
            'organization_id' => $this->organization->id,
            'category_id' => Category::factory()->create(['organization_id' => $this->organization->id])->id,
            'status' => 'active',
            'points_cost' => $points,
            'capacity' => $capacite,
            'min_participants' => $plancher,
        ]);
    }

    private function participant(int $solde = 500): User
    {
        return User::factory()->create(['organization_id' => $this->organization->id, 'points_balance' => $solde]);
    }

    /** Proposer puis faire accepter : rend l'echange, deja accepte. */
    private function inscrire(Service $annonce, User $qui): Transaction
    {
        $this->actingAs($qui)->post(route('transactions.store'), [
            'service_id' => $annonce->id, 'points_proposed' => $annonce->points_cost,
        ]);

        $echange = Transaction::where('service_id', $annonce->id)->where('buyer_id', $qui->id)->firstOrFail();
        $this->actingAs($this->aidant)->patch(route('transactions.approve', $echange));

        return $echange->fresh();
    }

    // ── La part : c'est ici que l'argent se joue ─────────────────────────────

    /**
     * AUCUNE part n'a de virgule, et l'ecart va TOUJOURS vers l'aidant.
     *
     * Demande explicite de Cyril : « attention a arrondir les chiffres, pour
     * pas qu'on ait des chiffres avec virgules ». Les points sont des entiers
     * en base, donc une virgule serait de toute facon impossible a honorer —
     * ce que ce test fixe, c'est le SENS de l'arrondi.
     *
     * `round()` pourrait faire que la somme des parts soit INFERIEURE a
     * l'annonce : l'aidant serait paye moins que ce qu'il a affiche, sans que
     * personne ne l'ait decide. `ceil()` garantit l'inverse.
     */
    public function test_aucune_part_n_a_de_virgule_et_l_ecart_va_vers_l_aidant(): void
    {
        foreach ([
            // [annonce, plancher, inscrits, part attendue]
            [45, 3, 3, 15],   // le cas nominal : 45 / 3
            [45, 3, 5, 9],    // plus de monde que le plancher : chacun paie moins
            [45, 3, 2, 15],   // moins que le plancher : le plancher protege
            [45, 4, 4, 12],   // 11,25 -> 12, jamais 11
            [50, 3, 3, 17],   // 16,66 -> 17
            [10, 6, 6, 2],    // 1,66 -> 2
        ] as [$points, $plancher, $inscrits, $attendu]) {
            $annonce = $this->atelier($points, capacite: 6, plancher: $plancher);
            $part = Atelier::part($annonce, $inscrits);

            $this->assertSame($attendu, $part, "annonce={$points} plancher={$plancher} inscrits={$inscrits}");
            $this->assertSame($part, (int) $part, 'Une part ne peut pas avoir de virgule.');
            $this->assertGreaterThanOrEqual(
                $points, $part * max($inscrits, $plancher),
                'La somme des parts est INFERIEURE a l\'annonce : l\'aidant serait paye moins que ce qu\'il affiche.',
            );
        }
    }

    /** Une annonce individuelle garde son prix, inchange. */
    public function test_une_annonce_individuelle_garde_son_prix(): void
    {
        $individuelle = $this->atelier(30, capacite: null, plancher: null);

        $this->assertFalse(Atelier::estGroupe($individuelle));
        $this->assertSame(30, Atelier::part($individuelle, 1));
        $this->assertNull(Atelier::placesRestantes($individuelle));
        $this->assertFalse(Atelier::estComplet($individuelle));
    }

    /**
     * Le prix AFFICHE est un plafond, et il est tenu.
     *
     * C'est la promesse de l'option C : le participant connait son prix maximum
     * des l'inscription. Il ne doit JAMAIS payer plus.
     */
    public function test_le_prix_affiche_est_un_plafond_jamais_depasse(): void
    {
        $annonce = $this->atelier(45, capacite: 6, plancher: 3);
        $plafond = Atelier::partMaximale($annonce);

        $this->assertSame(15, $plafond);

        foreach (range(1, 6) as $inscrits) {
            $this->assertLessThanOrEqual($plafond, Atelier::part($annonce, $inscrits),
                "Avec {$inscrits} inscrits, la part depasse le prix affiche.");
        }
    }

    /** Un plancher au-dela de la capacite est borne — sinon le plafond serait inatteignable. */
    public function test_un_plancher_superieur_a_la_capacite_est_borne(): void
    {
        $annonce = $this->atelier(60, capacite: 4, plancher: 9);

        $this->assertSame(4, Atelier::plancher($annonce));
        $this->assertSame(15, Atelier::partMaximale($annonce));
    }

    // ── La borne : on ne depasse pas la capacite ─────────────────────────────

    /**
     * 🔴 La garde qui compte : c'est `approve()` qui tient la borne.
     *
     * Une place est prise par un echange ACCEPTE, pas par une proposition en
     * attente. Trois propositions pour deux places sont donc TOUTES legitimes,
     * et c'est la troisieme ACCEPTATION qui doit etre refusee. Une garde posee
     * uniquement a la proposition laisserait sur-reserver, en silence.
     */
    public function test_la_borne_tient_a_l_acceptation_pas_seulement_a_la_proposition(): void
    {
        $annonce = $this->atelier(60, capacite: 2, plancher: 2);

        $this->inscrire($annonce, $this->participant());
        $this->inscrire($annonce, $this->participant());

        $this->assertSame(0, Atelier::placesRestantes($annonce));
        $this->assertTrue(Atelier::estComplet($annonce));

        // Le troisieme a bien le droit de PROPOSER tant qu'il reste de la place
        // au moment ou il ecrit — mais ici l'atelier est deja plein.
        $troisieme = $this->participant();
        $this->actingAs($troisieme)
            ->post(route('transactions.store'), ['service_id' => $annonce->id, 'points_proposed' => 60])
            ->assertSessionHas('error');

        $this->assertSame(2, Transaction::where('service_id', $annonce->id)->count());
    }

    /**
     * Trois propositions EN ATTENTE pour deux places : la troisieme acceptation
     * est refusee, et l'echange reste en attente plutot que de passer en force.
     */
    public function test_trois_propositions_pour_deux_places_la_troisieme_acceptation_est_refusee(): void
    {
        $annonce = $this->atelier(60, capacite: 2, plancher: 2);

        $echanges = collect(range(1, 3))->map(function () use ($annonce) {
            $qui = $this->participant();
            $this->actingAs($qui)->post(route('transactions.store'), [
                'service_id' => $annonce->id, 'points_proposed' => 60,
            ]);

            return Transaction::where('service_id', $annonce->id)->where('buyer_id', $qui->id)->firstOrFail();
        });

        $this->assertSame(3, $echanges->count(), 'Les trois propositions sont legitimes.');

        $this->actingAs($this->aidant)->patch(route('transactions.approve', $echanges[0]));
        $this->actingAs($this->aidant)->patch(route('transactions.approve', $echanges[1]));

        $this->actingAs($this->aidant)->patch(route('transactions.approve', $echanges[2]))
            ->assertSessionHas('error');

        $this->assertSame('pending', $echanges[2]->fresh()->status,
            'La troisieme acceptation est passee en force.');
        $this->assertSame(2, Atelier::placesPrises($annonce));
    }

    // ── Le bout en bout : les points transferes ──────────────────────────────

    /**
     * La part figee a l'acceptation est celle qui part au ledger.
     *
     * C'est le seul test qui relie le calcul au transfert reel : il mesure les
     * soldes, pas une intention.
     */
    public function test_la_part_figee_est_celle_qui_est_transferee(): void
    {
        $annonce = $this->atelier(45, capacite: 6, plancher: 3);
        $participant = $this->participant(solde: 100);
        $soldeAidant = $this->aidant->points_balance;

        $echange = $this->inscrire($annonce, $participant);

        // Seul inscrit, plancher 3 : il paie le plafond affiche.
        $this->assertSame(15, (int) $echange->points_agreed);

        $this->actingAs($participant)->patch(route('transactions.complete', $echange));
        $this->actingAs($this->aidant)->patch(route('transactions.confirm', $echange));

        $this->assertSame('completed', $echange->fresh()->status);
        $this->assertSame(100 - 15, $participant->fresh()->points_balance);
        $this->assertSame($soldeAidant + 15, $this->aidant->fresh()->points_balance);

        // Le ledger reste a SOMME NULLE : ce qui sort de l'un entre chez l'autre.
        $lignes = PointLedger::where('transaction_id', $echange->id)->pluck('delta');
        $this->assertSame(0, (int) $lignes->sum(), 'Le ledger n\'est pas a somme nulle.');
        $this->assertEqualsCanonicalizing([-15, 15], $lignes->map(fn ($d) => (int) $d)->all());
    }

    /**
     * L'annonce DIT ce que chacun paiera, et combien il reste de places.
     *
     * Sur un atelier, le grand nombre est le temps de la SEANCE ENTIERE : sans
     * cette mention il se lit comme un prix individuel et effraie pour rien.
     */
    public function test_l_annonce_dit_le_prix_par_personne_et_les_places(): void
    {
        $annonce = $this->atelier(45, capacite: 3, plancher: 3);

        $html = $this->actingAs($this->participant())
            ->get(route('services.show', $annonce))->assertOk()->getContent();

        $this->assertStringContainsString('data-entraide-atelier-prix', $html);
        $this->assertStringContainsString('15 min', $html, 'Le prix par personne doit etre affiche.');
        $this->assertStringContainsString('3 places restantes', $html);

        // Une annonce INDIVIDUELLE ne porte rien de tout cela.
        $individuelle = $this->atelier(30, capacite: null, plancher: null);
        $htmlIndiv = $this->actingAs($this->participant())
            ->get(route('services.show', $individuelle))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-entraide-atelier-places', $htmlIndiv);
    }

    /** Atelier COMPLET : le formulaire cede la place a un message clair. */
    public function test_un_atelier_complet_n_affiche_plus_le_formulaire(): void
    {
        $annonce = $this->atelier(60, capacite: 2, plancher: 2);
        $this->inscrire($annonce, $this->participant());
        $this->inscrire($annonce, $this->participant());

        $html = $this->actingAs($this->participant())
            ->get(route('services.show', $annonce))->assertOk()->getContent();

        $this->assertStringContainsString('data-entraide-atelier-complet', $html);
        $this->assertStringNotContainsString('data-entraide-demander', $html,
            'Un bouton qui ne peut que refuser est une promesse qu\'on ne tient pas.');
    }

    /**
     * La part d'un inscrit NE BOUGE PAS quand un autre arrive.
     *
     * C'est la promesse de l'option C — le prix affiche a l'inscription est le
     * prix paye — et c'est la recommandation du CDC sur les points durs.
     */
    public function test_la_part_d_un_inscrit_ne_bouge_pas_quand_un_autre_arrive(): void
    {
        $annonce = $this->atelier(45, capacite: 6, plancher: 3);

        $premier = $this->inscrire($annonce, $this->participant());
        $this->assertSame(15, (int) $premier->points_agreed);

        // Trois de plus : le quatrieme paie moins, le premier ne change pas.
        $this->inscrire($annonce, $this->participant());
        $this->inscrire($annonce, $this->participant());
        $quatrieme = $this->inscrire($annonce, $this->participant());

        $this->assertSame(15, (int) $premier->fresh()->points_agreed,
            'La part d\'un inscrit a bouge sous ses pieds.');
        $this->assertSame(12, (int) $quatrieme->points_agreed, '45 / 4 = 11,25 -> 12.');
    }
}
