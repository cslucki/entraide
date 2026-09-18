<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Support\Api\PublicUserProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1491 — l'API publique ne serialise plus le modele `User`, et elle
 * s'arrete a la frontiere d'Organization.
 *
 * ## Deux defauts distincts, mesures au HEAD 8f540d69
 *
 * **1. La projection.** `Api\ServiceController@show` et
 * `Api\ServiceRequestController@show` chargeaient la relation BRUTE
 * `user:id,name,rating,avatar,location,bio,is_available`.
 *
 * - `location` est le champ LEGACY que TASK-358 Lot 3 a retire de l'interface
 *   pour raison de vie privee. Mesure : la page web ne montrait PAS
 *   « Chezelles (37) » ; l'API le rendait a un anonyme. 24 personnes portent un
 *   `location`, et pour 23 il differe de la ville.
 * - `bio` ne s'affiche que sur `profile/show.blade.php`, que TASK-1479 a ferme
 *   aux MEMBRES de l'Organization.
 *
 * **2. La frontiere.** `/api/users/{uuid}` rendait **200** pour un membre d'une
 * Organization `is_public = false` — nom, biographie, ville — a un appelant sans
 * aucun jeton. C'est le JUMEAU API du P0 que TASK-1479 a ferme sur le web.
 *
 * `Service` et `ServiceRequest` portent `BelongsToOrganizationScope` : c'est ce
 * qui leur faisait deja rendre 404 hors tenant. `User` ne la porte
 * volontairement pas, et rien ne remplacait cette garde.
 *
 * ## Ce que ces tests verrouillent
 *
 * Une ALLOWLIST, pas une denylist. Assurer l'absence de `location` et de `bio`
 * ne suffirait pas : la prochaine colonne ajoutee a `User` serait publiee sans
 * que personne ne l'ait decide — c'est exactement ce qui est arrive a
 * `location`. Le test compare donc l'ENSEMBLE EXACT des cles.
 */
class TASK1491PublicApiProjectionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $public;

    private Organization $private;

    private User $publicMember;

    private User $privateMember;

    private Service $service;

    private ServiceRequest $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->public = Organization::factory()->create([
            'is_default' => true, 'is_public' => true, 'is_active' => true, 'show_country' => false,
        ]);
        $this->private = Organization::factory()->create([
            'is_default' => false, 'is_public' => false, 'is_active' => true,
        ]);

        $this->publicMember = User::factory()->create([
            'organization_id' => $this->public->id,
            'name' => 'Publique Personne',
            'city' => 'Nantes',
            'location' => 'ADRESSE LEGACY T1491',
            'bio' => 'BIOGRAPHIE PRIVEE T1491',
        ]);

        $this->privateMember = User::factory()->create([
            'organization_id' => $this->private->id,
            'name' => 'Privee Personne',
            'city' => 'Marseille',
            'location' => 'LEGACY PRIVE T1491',
            'bio' => 'BIO PRIVEE T1491',
        ]);

        $category = Category::factory()->create(['organization_id' => $this->public->id]);

        $this->service = Service::factory()->forUser($this->publicMember)->forCategory($category)->create([
            'organization_id' => $this->public->id, 'status' => 'active',
        ]);

        $this->request = ServiceRequest::factory()->create([
            'organization_id' => $this->public->id,
            'user_id' => $this->publicMember->id,
            'category_id' => $category->id,
            'status' => 'open',
        ]);
    }

    /**
     * La projection est un ENSEMBLE EXACT. Une denylist laisserait passer la
     * prochaine colonne ajoutee au modele — c'est ainsi que `location` est
     * devenu public sans decision.
     */
    public function test_the_service_endpoint_publishes_exactly_the_allowlisted_user_fields(): void
    {
        $user = $this->getJson('/api/services/'.$this->service->id)->assertOk()->json('user');

        $this->assertSame(
            PublicUserProjection::FIELDS,
            array_keys($user),
            'La projection publique a change sans decision.'
        );
    }

    public function test_the_request_endpoint_publishes_exactly_the_allowlisted_user_fields(): void
    {
        $user = $this->getJson('/api/requests/'.$this->request->id)->assertOk()->json('user');

        $this->assertSame(PublicUserProjection::FIELDS, array_keys($user));
    }

    /** Les deux champs precisement mesures comme fuyants, sur les quatre endpoints. */
    public function test_no_endpoint_serves_the_legacy_location_or_the_bio(): void
    {
        $endpoints = [
            '/api/services/'.$this->service->id,
            '/api/requests/'.$this->request->id,
            '/api/services',
            '/api/requests',
        ];

        foreach ($endpoints as $endpoint) {
            $body = $this->getJson($endpoint)->assertOk()->getContent();

            $this->assertStringNotContainsString('ADRESSE LEGACY T1491', $body, "[$endpoint] publie le champ location LEGACY (TASK-358 Lot 3).");
            $this->assertStringNotContainsString('BIOGRAPHIE PRIVEE T1491', $body, "[$endpoint] publie la biographie (visible des MEMBRES depuis TASK-1479).");
        }
    }

    /** La liste emprunte la meme autorite que la fiche — pas une seconde reponse. */
    public function test_the_index_endpoints_use_the_same_projection(): void
    {
        foreach (['/api/services', '/api/requests'] as $endpoint) {
            $first = $this->getJson($endpoint)->assertOk()->json('data.0.user');

            $this->assertNotNull($first, "[$endpoint] ne renvoie aucune ligne — le test ne mesurerait rien.");
            $this->assertSame(PublicUserProjection::FIELDS, array_keys($first), "[$endpoint] publie une AUTRE forme que la fiche.");
        }
    }

    /**
     * `public_location` est repris de TASK-358 Lot 3 : la ville, jamais le texte
     * libre. On verifie que la valeur PUBLIEE est bien la ville — sans quoi le
     * test precedent passerait avec un champ vide.
     */
    public function test_the_published_location_is_the_city_not_the_legacy_text(): void
    {
        $user = $this->getJson('/api/services/'.$this->service->id)->assertOk()->json('user');

        $this->assertSame('Nantes', $user['public_location']);
    }

    /**
     * Le JUMEAU API du P0 de TASK-1479. Sans jeton, sur une Organization
     * `is_public = false`.
     */
    public function test_the_users_endpoint_refuses_a_member_of_another_organization(): void
    {
        $response = $this->getJson('/api/users/'.$this->privateMember->id);

        $response->assertNotFound();
        $this->assertStringNotContainsString('Privee Personne', $response->getContent());
        $this->assertStringNotContainsString('BIO PRIVEE T1491', $response->getContent());
        $this->assertStringNotContainsString('Marseille', $response->getContent());
    }

    /** L'autre moitie du contrat : la personne de l'Organization resolue reste lisible. */
    public function test_the_users_endpoint_still_serves_a_member_of_the_resolved_organization(): void
    {
        $this->getJson('/api/users/'.$this->publicMember->id)
            ->assertOk()
            ->assertJsonPath('name', 'Publique Personne');
    }

    /** Le contrat deja acquis ne bouge pas : une Organization privee reste 404. */
    public function test_a_private_organization_service_stays_unreachable(): void
    {
        $category = Category::factory()->create(['organization_id' => $this->private->id]);
        $hidden = Service::factory()->forUser($this->privateMember)->forCategory($category)->create([
            'organization_id' => $this->private->id, 'status' => 'active',
        ]);

        $this->getJson('/api/services/'.$hidden->id)->assertNotFound();
    }
}
