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
    // ── Le prix PROPOSE : defaut de recette du 05/11 ─────────────────────────

    /**
     * 🔴 LE DEFAUT QUI BLOQUAIT L'INSCRIPTION.
     *
     * Mesure de recette navigateur : l'annonce affichait « au maximum 15 min
     * par personne » et, deux centimetres plus bas, le bouton disait
     * « Demander cette aide · 45 minutes » — et proposait reellement 45.
     *
     * Consequence, et elle est bloquante : la garde de solde de `store()`
     * comparait le solde a 45. Un membre avec 40 minutes au compteur se
     * faisait refuser une place qui lui aurait coute 15. Deux nombres pour une
     * meme chose, et c'est le mauvais qui partait au serveur.
     */
    public function test_le_formulaire_d_un_atelier_propose_la_part_et_non_le_prix_entier(): void
    {
        $annonce = $this->atelier(points: 45, capacite: 3, plancher: 3);
        $pauvre = $this->participant(solde: 40);

        $page = $this->actingAs($pauvre)->get(route('services.show', $annonce))->assertOk()->getContent();

        $this->assertStringContainsString('name="points_proposed" value="15"', $page,
            'Le formulaire doit proposer la PART, pas le prix de l\'atelier entier.');
        $this->assertStringNotContainsString('name="points_proposed" value="45"', $page);

        // Et le geste PASSE, alors qu'il etait refuse au solde.
        $this->actingAs($pauvre)
            ->post(route('transactions.store'), ['service_id' => $annonce->id, 'points_proposed' => 15])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('error');

        $this->assertDatabaseHas('transactions', [
            'service_id' => $annonce->id, 'buyer_id' => $pauvre->id, 'points_proposed' => 15,
        ]);
    }

    /**
     * Et la vue ne suffit pas : un POST FORGE est ramene a la part.
     *
     * C'est la difference entre « l'ecran n'invite plus a se tromper » et « le
     * produit ne peut plus se tromper ». Sans cette normalisation, n'importe
     * qui pouvait s'inscrire a un prix de son choix sur un atelier — et
     * `approve()` aurait ensuite ecrit un `points_agreed` different du
     * `points_proposed` annonce au fil.
     */
    public function test_un_temps_propose_de_travers_sur_un_atelier_est_ramene_a_la_part(): void
    {
        $annonce = $this->atelier(points: 45, capacite: 3, plancher: 3);
        $malin = $this->participant();

        $this->actingAs($malin)->post(route('transactions.store'), [
            'service_id' => $annonce->id, 'points_proposed' => 1,
        ]);

        $echange = Transaction::where('service_id', $annonce->id)->where('buyer_id', $malin->id)->firstOrFail();

        $this->assertSame(15, (int) $echange->points_proposed,
            'Une part choisie par le demandeur casserait le partage : deux participants paieraient deux prix.');
    }

    /**
     * On ne NEGOCIE pas sa part d'atelier — ni par le lien, ni par la route.
     *
     * `approve()` recalculant la part de toute facon, un ajustement n'aurait
     * rien preleve de different : il aurait seulement ecrit au fil « le temps
     * passe a 5 minutes » avant d'en prelever 15. Un message systeme qui
     * annonce un chiffre que le produit ne tiendra pas est un defaut a lui
     * seul.
     */
    public function test_on_n_ajuste_pas_sa_part_d_atelier_mais_on_ajuste_une_aide_individuelle(): void
    {
        $annonce = $this->atelier(points: 45, capacite: 3, plancher: 3);
        $participant = $this->participant();

        $this->actingAs($participant)->post(route('transactions.store'), [
            'service_id' => $annonce->id, 'points_proposed' => 15,
        ]);
        $echange = Transaction::where('service_id', $annonce->id)->where('buyer_id', $participant->id)->firstOrFail();

        $this->actingAs($participant)
            ->patch(route('transactions.adjust', $echange), ['points_proposed' => 5])
            ->assertSessionHas('error');

        $this->assertSame(15, (int) $echange->fresh()->points_proposed);

        // Le lien d'ajustement disparait aussi de l'annonce.
        //
        // Marqueur `data-entraide-negocier` et non `data-entraide-autre-temps` :
        // ce dernier est un PREFIXE de `data-entraide-autre-temps-champ`, porte
        // par le champ masque, et l'assertion rougissait sur le mauvais
        // element. Un marqueur prefixe d'un autre ne se mesure pas.
        $this->assertStringNotContainsString('data-entraide-negocier',
            $this->actingAs($this->participant())->get(route('services.show', $annonce))->getContent());

        // 🔴 LA RECIPROQUE — sans elle, ce test passerait si l'ajustement
        // etait casse PARTOUT, ce qui n'est pas ce qu'on veut mesurer.
        $individuelle = $this->atelier(points: 30, capacite: null, plancher: null);
        $autre = $this->participant();

        $this->actingAs($autre)->post(route('transactions.store'), [
            'service_id' => $individuelle->id, 'points_proposed' => 30,
        ]);
        $echange2 = Transaction::where('service_id', $individuelle->id)->where('buyer_id', $autre->id)->firstOrFail();

        $this->actingAs($autre)
            ->patch(route('transactions.adjust', $echange2), ['points_proposed' => 20])
            ->assertSessionHasNoErrors();

        $this->assertSame(20, (int) $echange2->fresh()->points_proposed,
            'Une aide individuelle, elle, reste negociable.');
    }

    /**
     * La carte de l'explorateur dit elle aussi la PART.
     *
     * Meme mecanisme que le defaut de recette, sur l'autre surface ou le membre
     * decide de cliquer : le grand nombre d'un atelier est le temps de la
     * SEANCE ENTIERE. La carte annoncait « 45 min » pour une place a 15.
     *
     * Et la RECIPROQUE : une aide individuelle ne porte pas cette mention,
     * sinon « / pers. » apparaitrait sur toutes les annonces du site.
     */
    public function test_la_carte_de_l_explorateur_annonce_la_part_pour_un_atelier_seulement(): void
    {
        $this->atelier(points: 45, capacite: 3, plancher: 3)->update(['title' => 'ATELIER DE GROUPE']);
        $this->atelier(points: 30, capacite: null, plancher: null)->update(['title' => 'AIDE INDIVIDUELLE']);

        $page = $this->actingAs($this->participant())->get(route('explorer'))->assertOk()->getContent();

        $this->assertStringContainsString('data-entraide-atelier-carte', $page);
        $this->assertStringContainsString('atelier · 15 min / pers.', $page);
        $this->assertSame(1, substr_count($page, 'data-entraide-atelier-carte'),
            'Une seule des deux annonces est un atelier.');
    }
}
