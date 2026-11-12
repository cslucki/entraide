<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1795 correctif 9 — compteurs d'utilisateurs ACTIFS par Organization.
 *
 * Cyril : « on va organiser des compteurs pour le nombre d'utilisateurs actif
 * par Org. Prévoir l'espace pour 10 organisations maximum, puisque la
 * plateforme ne fera pas plus de 10 organisations. »
 *
 * Deux choses se jouent ici, et le second point est le plus facile a casser :
 *
 * 1. « Actif » est le predicat que `userListStats()` emploie deja pour
 *    « Disponibles » (`banned_at is null and is_available`). Le redefinir
 *    aurait donne deux chiffres qui se contredisent sur le meme ecran.
 * 2. UNE requete, pas une par Organization. `TASK1640UserDeleteFromListTest`
 *    mesure deja la pente de cet ecran sur l'axe des COMPTES ; ce fichier la
 *    mesure sur l'axe des ORGANIZATIONS.
 */
class TASK1795AdminUsersOrgCountersTest extends TestCase
{
    use RefreshDatabase;

    private Organization $main;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->main = Organization::factory()->create([
            'slug' => 'main-1795i',
            'name' => 'Principale',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->main->id,
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        Http::preventStrayRequests();
        Http::fake();
    }

    private function organisation(string $nom): Organization
    {
        return Organization::factory()->create([
            'name' => $nom,
            'is_active' => true,
            'is_default' => false,
        ]);
    }

    private function membres(Organization $org, int $actifs, int $inactifs = 0, int $bannis = 0): void
    {
        User::factory()->count($actifs)->create([
            'organization_id' => $org->id,
            'is_available' => true,
            'banned_at' => null,
        ]);

        User::factory()->count($inactifs)->create([
            'organization_id' => $org->id,
            'is_available' => false,
            'banned_at' => null,
        ]);

        foreach (range(1, max($bannis, 0)) as $i) {
            if ($bannis < 1) {
                break;
            }
            User::factory()->create([
                'organization_id' => $org->id,
                'is_available' => true,
            ])->forceFill(['banned_at' => now()])->save();
        }
    }

    public function test_chaque_organization_a_son_compteur_d_actifs(): void
    {
        $ofsh = $this->organisation('OFSH');
        $this->membres($ofsh, actifs: 4, inactifs: 2, bannis: 1);

        $reponse = $this->actingAs($this->superAdmin)
            ->get(route('admin.users'))
            ->assertOk();

        $comptes = $reponse->viewData('comptesParOrganization');

        // Ni les indisponibles ni les bannis ne sont « actifs » : 4 sur 7.
        $this->assertSame(4, $comptes[$ofsh->id]['actifs']);
        $this->assertSame(7, $comptes[$ofsh->id]['total']);

        $reponse->assertSee('data-users-org-counter="'.$ofsh->id.'"', false);
        $reponse->assertSee('OFSH');
    }

    /**
     * La somme des tuiles retombe sur le compteur global « Disponibles ».
     *
     * C'est la verification qui interdit de redefinir « actif » : si les deux
     * chiffres divergeaient, l'ecran se contredirait a trois centimetres
     * d'ecart.
     */
    public function test_la_somme_des_compteurs_retombe_sur_le_compteur_global(): void
    {
        $a = $this->organisation('Alpha');
        $b = $this->organisation('Beta');
        $this->membres($a, actifs: 3, inactifs: 1);
        $this->membres($b, actifs: 5, bannis: 2);

        $reponse = $this->actingAs($this->superAdmin)
            ->get(route('admin.users'))
            ->assertOk();

        $sommeDesTuiles = collect($reponse->viewData('comptesParOrganization'))->sum('actifs');

        $this->assertSame(
            $reponse->viewData('stats')['disponibles'],
            $sommeDesTuiles,
            'la somme des tuiles doit egaler le compteur « Disponibles »',
        );
    }

    /**
     * DIX Organizations ne coutent pas dix requetes de plus.
     *
     * C'est la garde que l'enonce rend indispensable : « prévoir l'espace pour
     * 10 organisations ». Un compteur calcule dans la boucle d'affichage aurait
     * donne un ecran correct et un cout qui grandit avec chaque Organization —
     * le defaut ne se serait vu qu'en production.
     */
    public function test_dix_organizations_ne_coutent_pas_plus_de_requetes_qu_une_seule(): void
    {
        $this->membres($this->main, actifs: 2);

        $compter = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->superAdmin)->get(route('admin.users'))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $avecUne = $compter();

        // Neuf de plus, pour atteindre les dix annoncees.
        foreach (range(1, 9) as $i) {
            $this->membres($this->organisation('Org '.$i), actifs: 2);
        }

        $avecDix = $compter();

        $this->assertLessThanOrEqual(
            $avecUne + 1,
            $avecDix,
            "dix Organizations ont coute $avecDix requetes contre $avecUne pour une seule : ".
            'le compteur doit etre un agregat groupe, pas une requete par Organization',
        );
    }

    public function test_la_tuile_mene_au_filtre_de_son_organization(): void
    {
        $ofsh = $this->organisation('OFSH');
        $this->membres($ofsh, actifs: 2);

        $reponse = $this->actingAs($this->superAdmin)
            ->get(route('admin.users'))
            ->assertOk();

        // L'URL est demandee au ROUTEUR, jamais reecrite a la main.
        $reponse->assertSee(route('admin.users', ['organization_id' => $ofsh->id]), false);
    }

    /** Filtrer sur une Organization ne fait pas disparaitre les autres tuiles. */
    public function test_le_filtre_ne_reduit_pas_la_rangee_a_une_seule_tuile(): void
    {
        $a = $this->organisation('Alpha');
        $b = $this->organisation('Beta');
        $this->membres($a, actifs: 2);
        $this->membres($b, actifs: 3);

        $reponse = $this->actingAs($this->superAdmin)
            ->get(route('admin.users', ['organization_id' => $a->id]))
            ->assertOk();

        $reponse->assertSee('data-users-org-counter="'.$a->id.'"', false);
        $reponse->assertSee('data-users-org-counter="'.$b->id.'"', false);

        // Les tuiles gardent la repartition REELLE, pas celle du filtre.
        $comptes = $reponse->viewData('comptesParOrganization');
        $this->assertSame(2, $comptes[$a->id]['actifs']);
        $this->assertSame(3, $comptes[$b->id]['actifs']);
    }

    /** Une seule Organization : la rangee n'a rien a dire, elle ne s'affiche pas. */
    public function test_la_rangee_est_absente_quand_il_n_y_a_qu_une_organization(): void
    {
        $this->membres($this->main, actifs: 2);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.users'))
            ->assertOk()
            ->assertDontSee('data-users-org-counters', false);
    }
}
