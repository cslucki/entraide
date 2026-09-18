<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Tests\TestCase;

class TASK358Lot3PublicLocationTest extends TestCase
{
    /*
     * TASK-1479 (P0 privacy) — la fiche de profil n'est plus servie a un
     * visiteur ANONYME : sur une Organization `is_public = false`, elle rendait
     * 200 sans aucun cookie.
     *
     * Ce fichier regit QUELS CHAMPS un profil montre — ville et pays oui,
     * adresse et code postal non. TASK-1479 regit QUI peut le lire. Les deux
     * decisions se completent et ne se contredisent pas : l'adresse reste
     * masquee pour tout le monde, membre compris.
     */

    public function test_public_profile_does_not_show_legacy_location_and_shows_city_country(): void
    {
        $organization = $this->createOrganization(['show_country' => true]);
        $user = $this->createStructuredUser($organization, [
            'city' => 'Paris',
            'country_code' => 'FR',
            'location' => 'Legacy Secret Location',
        ]);

        $this->actingAs($this->createStructuredUser($organization, ['city' => 'Lecteur']))
            ->get(route('profile.show', $user))
            ->assertOk()
            ->assertSee('Paris, France')
            ->assertDontSee('Legacy Secret Location')
            ->assertDontSee($user->address_line1)
            ->assertDontSee($user->address_line2)
            ->assertDontSee($user->postal_code);
    }

    public function test_public_profile_shows_city_only_when_show_country_is_disabled(): void
    {
        $organization = $this->createOrganization(['show_country' => false]);
        $user = $this->createStructuredUser($organization, [
            'city' => 'Lyon',
            'country_code' => 'FR',
            'location' => 'Legacy Lyon',
        ]);

        $this->actingAs($this->createStructuredUser($organization, ['city' => 'Lecteur']))
            ->get(route('profile.show', $user))
            ->assertOk()
            ->assertSee('Lyon')
            ->assertDontSee('Lyon, France')
            ->assertDontSee('Legacy Lyon');
    }

    public function test_public_profile_has_no_legacy_location_fallback_when_city_is_empty(): void
    {
        $organization = $this->createOrganization();
        $user = $this->createStructuredUser($organization, [
            'city' => null,
            'country_code' => 'FR',
            'location' => 'Legacy Fallback Should Stay Hidden',
        ]);

        $this->actingAs($this->createStructuredUser($organization, ['city' => 'Lecteur']))
            ->get(route('profile.show', $user))
            ->assertOk()
            ->assertDontSee('Legacy Fallback Should Stay Hidden')
            ->assertDontSee('France');
    }

    public function test_members_directory_does_not_show_legacy_location_or_private_address(): void
    {
        $organization = $this->createOrganization();
        $viewer = $this->createStructuredUser($organization, ['name' => 'Viewer']);
        $member = $this->createStructuredUser($organization, [
            'name' => 'Visible Member',
            'city' => 'Marseille',
            'country_code' => 'FR',
            'location' => 'Legacy Member Location',
            'address_line1' => 'Private Member Street',
            'address_line2' => 'Private Member Floor',
            'postal_code' => '13000',
        ]);

        $this->actingAs($viewer)->get(route('members.index'))
            ->assertOk()
            ->assertSee($member->name)
            ->assertSee('Marseille, France')
            ->assertDontSee('Legacy Member Location')
            ->assertDontSee('Private Member Street')
            ->assertDontSee('Private Member Floor')
            ->assertDontSee('13000');
    }

    public function test_search_does_not_show_or_match_legacy_location(): void
    {
        $organization = $this->createOrganization();
        $matchedByCity = $this->createStructuredUser($organization, [
            'name' => 'City Match',
            'city' => 'Paris',
            'country_code' => 'FR',
            'location' => 'Legacy Hidden City',
        ]);
        $this->createStructuredUser($organization, [
            'name' => 'Legacy Only',
            'city' => 'Lyon',
            'country_code' => 'FR',
            'location' => 'Paris Legacy Only',
        ]);

        // TASK-1488 (P0 privacy) : `/search` exige desormais une session de
        // membre — il rendait a un anonyme les memes champs que `/membres`,
        // ferme par TASK-1479. Ce que ce test regit est INCHANGE : quels CHAMPS
        // une fiche montre (ville oui, `location` legacy non). Il regit les
        // champs, TASK-1488 regit le lecteur.
        $this->actingAs($this->inertReader($organization));

        $this->get(route('search', ['q' => 'Paris']))
            ->assertOk()
            ->assertSee($matchedByCity->name)
            ->assertSee('Paris, France')
            ->assertDontSee('Legacy Hidden City')
            ->assertDontSee('Legacy Only')
            ->assertDontSee('Paris Legacy Only');
    }

    public function test_search_finds_users_by_name_bio_and_city(): void
    {
        $organization = $this->createOrganization();
        $nameUser = $this->createStructuredUser($organization, ['name' => 'Alice Searchable']);
        $bioUser = $this->createStructuredUser($organization, ['name' => 'Bio Match', 'bio' => 'Expert comptable solidaire']);
        $cityUser = $this->createStructuredUser($organization, ['name' => 'City Match', 'city' => 'Nantes']);

        // TASK-1488 (P0 privacy) : meme raison — le lecteur devient un membre.
        $this->actingAs($this->inertReader($organization));

        $this->get(route('search', ['q' => 'Alice']))
            ->assertOk()
            ->assertSee($nameUser->name);

        $this->get(route('search', ['q' => 'comptable']))
            ->assertOk()
            ->assertSee($bioUser->name);

        $this->get(route('search', ['q' => 'Nantes']))
            ->assertOk()
            ->assertSee($cityUser->name);
    }

    private function createOrganization(array $attributes = []): Organization
    {
        $organization = Organization::factory()->create(array_merge([
            'show_country' => true,
        ], $attributes));

        app()->instance('current_organization', $organization);

        return $organization;
    }

    /**
     * TASK-1488 — le membre qui LIT la recherche, deliberement inerte.
     *
     * Meme discipline que `createStructuredUser()` ci-dessous et pour la meme
     * raison (TASK-1228) : aucun de ses champs ne doit croiser un terme
     * recherche ici (« Paris », « Alice », « comptable », « Nantes ») ni une
     * sentinelle `assertDontSee`. Son nom est rendu par la navigation une fois
     * connecte — c'est precisement la ou un nom genere par Faker ferait
     * echouer une assertion negative.
     */
    private function inertReader(Organization $organization): User
    {
        return User::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Zzz Inerte',
            'first_name' => 'Zzz',
            'city' => 'Zzzville',
            'country_code' => 'FR',
            'location' => null,
            'bio' => 'Zzz.',
            'banned_at' => null,
        ]);
    }

    private function createStructuredUser(Organization $organization, array $attributes = []): User
    {
        // TASK-1228 (CI, PR #231) : noms DETERMINISTES — le tirage Faker de la
        // factory (`first_name` = « Frances ») faisait echouer
        // `assertDontSee('France')`. Meme correction a la racine que TASK-1218
        // (UserDeactivationTest) : aucune sentinelle testee ne peut apparaitre
        // dans un nom genere.
        return User::factory()->create(array_merge([
            'organization_id' => $organization->id,
            'name' => 'Structured',
            'first_name' => 'Member',
            'city' => 'Paris',
            'country_code' => 'FR',
            'location' => 'Legacy Location',
            'address_line1' => 'Private Street',
            'address_line2' => 'Private Suite',
            'postal_code' => '75000',
        ], $attributes));
    }
}
