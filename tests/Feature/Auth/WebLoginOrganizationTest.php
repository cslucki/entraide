<?php

namespace Tests\Feature\Auth;

use App\Http\Requests\Auth\LoginRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WebLoginOrganizationTest extends TestCase
{
    public function test_non_admin_without_organization_is_redirected_to_home_on_login(): void
    {
        $user = User::factory()->create([
            'is_admin' => false,
            'organization_id' => null,
            'password' => Hash::make('password'),
        ]);

        // TASK-1749 — la selection est obligatoire sur `/login`. Un compte
        // DETACHE n'a aucune Organization a nommer : il designe sa situation.
        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
            'organization' => LoginRequest::SANS_ORGANIZATION,
        ])->assertRedirect('/dashboard');
    }

    public function test_non_admin_with_organization_lands_on_chatloop_on_login(): void
    {
        // TASK-1749 — `is_public` explicite : le defaut d'usine est FALSE, et
        // le selecteur de `/login` ne propose que du public et de l'actif. Une
        // Organization privee garde sa propre porte `/org/{slug}/login`.
        $organization = Organization::factory()->create(['is_active' => true, 'is_public' => true, 'slug' => 'test-org']);
        $user = User::factory()->create([
            'is_admin' => false,
            'organization_id' => $organization->id,
            'password' => Hash::make('password'),
        ]);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
            'organization' => $organization->slug,
        ])->assertRedirect('/org/'.$organization->slug.'/loops');
    }

    /**
     * TASK-1749 — CONSEQUENCE NOMMEE : un membre d'Organization DESACTIVEE
     * n'entre plus.
     *
     * Ce test affirmait qu'il se connectait et atterrissait sur `/dashboard`.
     * Depuis que la selection est obligatoire, il n'a plus de porte :
     *
     *  - sur `/login`, le selecteur ne propose que du PUBLIC et de l'ACTIF, son
     *    Organization n'y figure donc pas ;
     *  - `/org/{slug}/login` n'existe pas pour une Organization inactive
     *    (garde `test_une_organization_INACTIVE_n_a_pas_de_porte`, T1728) ;
     *  - la sentinelle ne le concerne pas : elle ne vise que les comptes
     *    DETACHES, et le sien porte bien une Organization.
     *
     * C'est defendable — « Desactiver » suspend l'Organization, et suspendre
     * ses acces va avec. Mais ce n'est PAS ce que le produit faisait hier, et
     * personne ne l'a demande explicitement : signale a Cyril, inversable en
     * ajoutant `is_active` a la portee de la sentinelle.
     */
    public function test_non_admin_with_inactive_organization_can_no_longer_sign_in(): void
    {
        $organization = Organization::factory()->create(['is_active' => false, 'slug' => 'inactive-org']);
        $user = User::factory()->create([
            'is_admin' => false,
            'organization_id' => $organization->id,
            'password' => Hash::make('password'),
        ]);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
            'organization' => $organization->slug,
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }
}
