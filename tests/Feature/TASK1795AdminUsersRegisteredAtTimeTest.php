<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1795 correctif 8 — l'HEURE d'inscription sur `/admin/users`.
 *
 * Cyril : « on va ajouter l'heure de l'inscription dans la colonne "Inscrit le" ».
 *
 * La colonne affichait `d/m/Y`. Elle est pourtant TRIABLE sur `created_at` :
 * le tri connaissait l'heure, l'affichage non. Deux comptes crees le meme jour
 * apparaissaient donc identiques, et leur ordre semblait arbitraire alors qu'il
 * ne l'etait pas.
 */
class TASK1795AdminUsersRegisteredAtTimeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create([
            'slug' => 'org-1795h',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        Http::preventStrayRequests();
        Http::fake();
    }

    public function test_la_colonne_inscrit_le_porte_la_date_ET_l_heure(): void
    {
        $membre = User::factory()->create(['organization_id' => $this->org->id]);
        $membre->forceFill(['created_at' => '2026-03-04 09:07:00'])->save();

        $reponse = $this->actingAs($this->superAdmin)
            ->get(route('admin.users'))
            ->assertOk();

        // L'heure est ce que le correctif ajoute : l'exiger explicitement, car
        // `assertSee('04/03/2026')` passerait encore sans elle — une date est
        // un PREFIXE de la date horodatee.
        $reponse->assertSee('04/03/2026 09:07');
    }

    /**
     * Deux comptes du MEME JOUR se distinguent desormais a l'oeil.
     *
     * C'est la raison d'etre du correctif : sans l'heure, ces deux lignes
     * affichaient exactement la meme chose dans une colonne pourtant triee sur
     * l'instant precis.
     */
    public function test_deux_comptes_du_meme_jour_ne_s_affichent_plus_a_l_identique(): void
    {
        $matin = User::factory()->create(['organization_id' => $this->org->id]);
        $matin->forceFill(['created_at' => '2026-03-04 08:15:00'])->save();

        $soir = User::factory()->create(['organization_id' => $this->org->id]);
        $soir->forceFill(['created_at' => '2026-03-04 21:42:00'])->save();

        $reponse = $this->actingAs($this->superAdmin)
            ->get(route('admin.users'))
            ->assertOk();

        $reponse->assertSee('04/03/2026 08:15');
        $reponse->assertSee('04/03/2026 21:42');
    }
}
