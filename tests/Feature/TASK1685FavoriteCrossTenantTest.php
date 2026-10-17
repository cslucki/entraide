<?php

namespace Tests\Feature;

use App\Models\Favorite;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TASK-1685 / P0-1 — un favori ne franchit plus la frontiere d'Organization.
 *
 * ## Ce qui a ete mesure AVANT tout code, en base isolee
 *
 * Un membre de l'Organization A postait sur
 * `/org/B/favorites/{service de B}/toggle` : HTTP 302, et la table `favorites`
 * passait de **0 a 1**, avec la ligne
 * `{user_id: <membre de A>, service_id: <service de B>, organization_id: <B>}`.
 *
 * Ecriture dans le tenant d'autrui, par un non-membre.
 *
 * ## La cause
 *
 * `Service $service` arrivait par liage implicite de route, et RIEN ne
 * confrontait son Organization a celle de l'utilisateur. Le controleur
 * ecrivait ensuite `organization_id => $service->organization_id` — il
 * inscrivait donc fidelement la ligne dans le tenant ETRANGER. Garde absente,
 * pas garde faible.
 *
 * ## Comment ces tests prouvent le refus
 *
 * Par l'ETAT DES TABLES, avant et apres — jamais par le seul code HTTP. Un 302
 * peut accompagner une ecriture (c'est exactement ce qui se passait ici) ; seul
 * le comptage tranche.
 */
class TASK1685FavoriteCrossTenantTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $memberA;

    private User $memberB;

    private Service $serviceA;

    private Service $serviceB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['slug' => 'p01-a', 'is_active' => true, 'is_public' => true]);
        $this->orgB = Organization::factory()->create(['slug' => 'p01-b', 'is_active' => true, 'is_public' => true]);

        $this->memberA = User::factory()->create(['organization_id' => $this->orgA->id, 'is_admin' => false, 'email_verified_at' => now()]);
        $this->memberB = User::factory()->create(['organization_id' => $this->orgB->id, 'is_admin' => false, 'email_verified_at' => now()]);

        $this->serviceA = Service::factory()->create(['organization_id' => $this->orgA->id, 'user_id' => $this->memberA->id]);
        $this->serviceB = Service::factory()->create(['organization_id' => $this->orgB->id, 'user_id' => $this->memberB->id]);
    }

    private function favorites(): int
    {
        return DB::table('favorites')->count();
    }

    // ────────────────────────────────────────────────────────────────────────
    // Le defaut prouve, dans les DEUX SENS
    // ────────────────────────────────────────────────────────────────────────

    public function test_a_member_of_a_cannot_favorite_a_service_of_b(): void
    {
        $before = $this->favorites();

        $response = $this->actingAs($this->memberA)
            ->post("/org/{$this->orgB->slug}/favorites/{$this->serviceB->id}/toggle");

        $response->assertNotFound();
        $this->assertSame($before, $this->favorites(), 'aucune ligne ne doit etre creee');
    }

    public function test_a_member_of_b_cannot_favorite_a_service_of_a(): void
    {
        $before = $this->favorites();

        $response = $this->actingAs($this->memberB)
            ->post("/org/{$this->orgA->slug}/favorites/{$this->serviceA->id}/toggle");

        $response->assertNotFound();
        $this->assertSame($before, $this->favorites());
    }

    /** Un chemin coherent ne doit pas pouvoir agir hors de l'espace qu'il nomme. */
    public function test_the_url_organization_must_match_the_service_organization(): void
    {
        $before = $this->favorites();

        // URL de A, service de B.
        $this->actingAs($this->memberA)
            ->post("/org/{$this->orgA->slug}/favorites/{$this->serviceB->id}/toggle")
            ->assertNotFound();

        // URL de B, service de A.
        $this->actingAs($this->memberA)
            ->post("/org/{$this->orgB->slug}/favorites/{$this->serviceA->id}/toggle")
            ->assertNotFound();

        $this->assertSame($before, $this->favorites());
    }

    public function test_a_nonexistent_service_is_refused_without_disclosing_anything(): void
    {
        $before = $this->favorites();

        $response = $this->actingAs($this->memberA)
            ->post("/org/{$this->orgA->slug}/favorites/01a00000-0000-7000-0000-000000000000/toggle");

        $response->assertNotFound();
        $this->assertSame($before, $this->favorites());
    }

    /**
     * Le refus ne doit rien confirmer du service etranger : un service de B qui
     * EXISTE et un identifiant qui n'existe NULLE PART doivent rendre la meme
     * chose. Sans cela, le refus deviendrait un detecteur d'existence.
     */
    public function test_the_refusal_does_not_disclose_the_foreign_service(): void
    {
        $existing = $this->actingAs($this->memberA)
            ->post("/org/{$this->orgB->slug}/favorites/{$this->serviceB->id}/toggle");

        $nowhere = $this->actingAs($this->memberA)
            ->post("/org/{$this->orgB->slug}/favorites/01a00000-0000-7000-0000-000000000000/toggle");

        $this->assertSame($existing->status(), $nowhere->status());
        $this->assertStringNotContainsString($this->serviceB->title ?? '', $existing->getContent() ?: ' ');
    }

    // ────────────────────────────────────────────────────────────────────────
    // Le flux LEGITIME n'a pas bouge
    // ────────────────────────────────────────────────────────────────────────

    public function test_a_member_still_favorites_a_service_of_their_own_organization(): void
    {
        $before = $this->favorites();

        $this->actingAs($this->memberA)
            ->post("/org/{$this->orgA->slug}/favorites/{$this->serviceA->id}/toggle")
            ->assertRedirect();

        $this->assertSame($before + 1, $this->favorites());

        $row = DB::table('favorites')->latest('created_at')->first();
        $this->assertSame($this->memberA->id, $row->user_id);
        $this->assertSame($this->orgA->id, $row->organization_id);
    }

    /** La bascule inverse reste possible : on retire ce qu'on a mis. */
    public function test_the_toggle_still_removes_an_own_favorite(): void
    {
        $this->actingAs($this->memberA)
            ->post("/org/{$this->orgA->slug}/favorites/{$this->serviceA->id}/toggle");
        $this->assertSame(1, $this->favorites());

        $this->actingAs($this->memberA)
            ->post("/org/{$this->orgA->slug}/favorites/{$this->serviceA->id}/toggle");
        $this->assertSame(0, $this->favorites());
    }

    /**
     * La forme NON prefixee doit rester ouverte a un membre d'une Organization
     * qui n'est PAS celle par defaut de la plateforme. C'est la raison pour
     * laquelle la garde lit le PARAMETRE DE ROUTE et non `currentOrganization()` :
     * sur cette forme, `currentOrganization()` rend l'Organization par defaut,
     * et s'en servir aurait refuse un geste parfaitement legitime.
     */
    public function test_the_unprefixed_form_still_works_for_a_non_default_organization(): void
    {
        $before = $this->favorites();

        $this->actingAs($this->memberA)
            ->post("/favorites/{$this->serviceA->id}/toggle")
            ->assertRedirect();

        $this->assertSame($before + 1, $this->favorites());
    }

    /** Et elle ne devient pas une porte derobee vers le tenant d'autrui. */
    public function test_the_unprefixed_form_is_not_a_back_door(): void
    {
        $before = $this->favorites();

        $this->actingAs($this->memberA)
            ->post("/favorites/{$this->serviceB->id}/toggle")
            ->assertNotFound();

        $this->assertSame($before, $this->favorites());
    }

    // ────────────────────────────────────────────────────────────────────────
    // Le SuperAdmin ne participe pas
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Doctrine TASK-1658 : « inspecter n'est pas participer ». Mettre en favori
     * est un geste de MEMBRE ; aucune exemption `is_admin` ne doit l'ouvrir sur
     * le tenant d'autrui.
     */
    public function test_a_super_admin_gets_no_implicit_participation(): void
    {
        $superAdmin = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $before = $this->favorites();

        $this->actingAs($superAdmin)
            ->post("/org/{$this->orgB->slug}/favorites/{$this->serviceB->id}/toggle")
            ->assertNotFound();

        $this->assertSame($before, $this->favorites(), 'le SuperAdmin ne doit pas ecrire dans un tenant etranger');
    }

    // ────────────────────────────────────────────────────────────────────────
    // On ne supprime pas la ligne d'autrui
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Le chemin de SUPPRESSION de la bascule. Le `where('user_id', ...)`
     * bornait deja l'operation aux lignes de l'appelant ; on le FIGE, parce
     * qu'un correctif futur qui l'oterait rendrait la bascule destructrice.
     */
    public function test_nobody_can_remove_someone_elses_favorite(): void
    {
        $victimFavorite = Favorite::create([
            'user_id' => $this->memberB->id,
            'service_id' => $this->serviceB->id,
            'organization_id' => $this->orgB->id,
        ]);

        $this->actingAs($this->memberA)
            ->post("/org/{$this->orgB->slug}/favorites/{$this->serviceB->id}/toggle")
            ->assertNotFound();

        $this->assertDatabaseHas('favorites', ['id' => $victimFavorite->id]);
    }
}
