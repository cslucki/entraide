<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Loop;
use App\Models\LoopCard;
use App\Models\LoopEvent;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TASK-1814 — deux N+1 que le dépôt savait déjà éviter.
 *
 * ## Ce qui a été mesuré, avant tout code (jumeau `prod_work`, VERSION 1.813)
 *
 * | surface | requêtes applicatives | dont la cause |
 * |---|---|---|
 * | `/membres` | 44 | 13 `organizations` + 13 `countries`, une paire par carte |
 * | `/agenda` | 62 | 24 `loop_cards`, une par prédicat de permission |
 *
 * ## Pourquoi ce n'est pas un cache
 *
 * Les deux corrections sont du **préchargement Eloquent**. Rien n'est retenu
 * entre deux requêtes, rien ne peut périmer, et aucune permission n'est
 * touchée : `activeCardsFor()` préfère DÉJÀ la relation chargée quand elle
 * l'est (`LoopTypeRegistry::548-550`), et rend exactement le même verdict.
 *
 * ## La forme des assertions
 *
 * On compte les requêtes d'une TABLE, et on les compare au nombre d'objets
 * affichés. Un plafond en valeur absolue rougirait au premier membre ajouté
 * à la fixture ; ce qui doit être vrai, c'est que le coût **ne suit plus le
 * nombre de lignes rendues**.
 */
class TASK1814PrechargementsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, string> */
    private function requetesSur(string $table, callable $geste): array
    {
        $vues = [];
        DB::listen(function ($requete) use (&$vues, $table): void {
            $sql = preg_replace('/\s+/', ' ', $requete->sql);
            if (str_contains($sql, '"'.$table.'"') || str_contains($sql, '`'.$table.'`')) {
                $vues[] = $sql;
            }
        });

        $geste();

        return $vues;
    }

    // ────────────────────────────────────────────────────────────────────────
    // Correctif 01 — l'annuaire
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Le coût ne suit PAS le nombre de membres affichés.
     *
     * On rend deux fois la même page, avec deux parcs de tailles
     * différentes, et on compare. Un plafond en valeur absolue aurait été
     * arbitraire : la page porte un socle de lectures (layout, résolution de
     * tenant) qui n'a rien à voir avec la grille. Ce qui doit être vrai, c'est
     * que ce socle ne GROSSIT pas avec le nombre de cartes.
     */
    private function lecturesPourUnParcDe(int $membres, string $table, string $suffixe): int
    {
        $organization = Organization::factory()->create(['slug' => 't1814-'.$suffixe, 'is_public' => true]);
        $pays = Country::firstOrCreate(['code' => 'FR'], ['name_fr' => 'France', 'name_en' => 'France']);

        $lecteur = User::factory()->create(['organization_id' => $organization->id]);
        User::factory()->count($membres)->create([
            'organization_id' => $organization->id,
            'city' => 'Marseille',
            'country_code' => $pays->code,
        ]);

        // Route explicitement scopee : deux Organizations coexistent dans ce
        // test, et la route non prefixee resout l'Organization par DEFAUT de
        // la plateforme — elle rendrait 404 pour la seconde. Nommer le tenant
        // retire cette ambiguite, et c'est de toute facon la surface qu'on
        // veut mesurer.
        $url = '/org/'.$organization->slug.'/membres';

        return count($this->requetesSur($table, function () use ($lecteur, $url): void {
            $this->actingAs($lecteur)->get($url)->assertOk();
        }));
    }

    public function test_le_cout_en_organizations_ne_suit_pas_le_nombre_de_membres(): void
    {
        $petit = $this->lecturesPourUnParcDe(2, 'organizations', 'org-petit');
        $grand = $this->lecturesPourUnParcDe(20, 'organizations', 'org-grand');

        $this->assertSame(
            $petit,
            $grand,
            "L annuaire lit une Organization par carte : {$petit} lectures pour 3 membres, {$grand} pour 21. "
            .'Le prechargement ne sert pas.'
        );
    }

    public function test_le_cout_en_pays_ne_suit_pas_le_nombre_de_membres(): void
    {
        $petit = $this->lecturesPourUnParcDe(2, 'countries', 'pays-petit');
        $grand = $this->lecturesPourUnParcDe(20, 'countries', 'pays-grand');

        $this->assertSame(
            $petit,
            $grand,
            "L annuaire lit un pays par carte : {$petit} lectures pour 3 membres, {$grand} pour 21. "
            .'Le prechargement ne sert pas.'
        );
    }

    /**
     * Le préchargement ne doit RIEN changer à ce qui s'affiche.
     *
     * C'est la contrainte « aucun changement fonctionnel » : la localisation
     * publique reste exactement celle que l'accesseur calculait seul.
     */
    public function test_la_localisation_publique_est_inchangee(): void
    {
        $organization = Organization::factory()->create(['slug' => 't1814-affiche', 'is_public' => true, 'show_country' => true]);
        $pays = Country::firstOrCreate(['code' => 'FR'], ['name_fr' => 'France', 'name_en' => 'France']);

        $lecteur = User::factory()->create(['organization_id' => $organization->id]);
        $habitant = User::factory()->create([
            'organization_id' => $organization->id,
            'city' => 'Marseille',
            'country_code' => $pays->code,
        ]);

        $this->actingAs($lecteur)->get('/membres')
            ->assertOk()
            ->assertSeeText($habitant->public_location);
    }

    /**
     * SABOTAGE — l'isolation de tenant ne doit rien devoir au préchargement.
     */
    public function test_le_prechargement_ne_fait_pas_entrer_une_autre_organization(): void
    {
        $ici = Organization::factory()->create(['slug' => 't1814-ici', 'is_public' => true]);
        $ailleurs = Organization::factory()->create(['slug' => 't1814-ailleurs', 'is_public' => true]);

        $lecteur = User::factory()->create(['organization_id' => $ici->id]);
        $voisin = User::factory()->create(['organization_id' => $ici->id, 'name' => 'Ici Present']);
        $etranger = User::factory()->create(['organization_id' => $ailleurs->id, 'name' => 'Ailleurs Absent']);

        $this->actingAs($lecteur)->get('/membres')
            ->assertOk()
            ->assertSeeText($voisin->name)
            ->assertDontSeeText($etranger->name);
    }
}
