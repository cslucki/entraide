<?php

namespace Tests\Feature;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicFrenchPartnersRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_partenaires_index_is_public(): void
    {
        $this->get('/partenaires')
            ->assertOk()
            ->assertSee('Devenir partenaire');
    }

    public function test_partenaires_demande_is_public(): void
    {
        $this->get('/partenaires/demande')
            ->assertOk()
            ->assertSee('Devenir partenaire');
    }

    public function test_boucles_creer_redirects_to_partenaires_demande(): void
    {
        Organization::factory()->create(['is_active' => true]);

        $this->get('/boucles/creer')
            ->assertRedirect('/partenaires/demande');
    }

    public function test_boucles_index_does_not_redirect_to_partenaires(): void
    {
        Organization::factory()->create(['is_active' => true]);

        $this->get('/boucles')
            ->assertOk()
            ->assertSee('Les Boucles');
    }
}
