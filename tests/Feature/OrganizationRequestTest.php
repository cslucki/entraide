<?php

namespace Tests\Feature;

use App\Models\OrganizationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_organization_request_via_post(): void
    {
        $data = [
            'boucle_name' => 'Test Organization',
            'contact_name' => 'Test Contact',
            'contact_email' => 'test@example.com',
            'contact_phone' => '+33 6 12 34 56 78',
            'website_url' => 'https://example.org',
            'description' => 'Test description',
            'context' => 'Test context',
        ];

        $this->post('/partenaires/demande', $data)
            ->assertRedirect('/partenaires')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('organization_requests', [
            'boucle_name' => 'Test Organization',
            'contact_email' => 'test@example.com',
            'contact_phone' => '+33 6 12 34 56 78',
            'website_url' => 'https://example.org',
        ]);
    }

    public function test_organization_request_model_exists_and_works(): void
    {
        $request = OrganizationRequest::create([
            'boucle_name' => 'Test Organization',
            'contact_name' => 'Test Contact',
            'contact_email' => 'test@example.com',
            'contact_phone' => '+33 6 66 77 88 99',
            'website_url' => null,
            'description' => 'Test description',
            'context' => 'Test context',
        ]);

        $this->assertModelExists($request);
        $this->assertEquals('organization_requests', $request->getTable());
        $this->assertEquals('Test Organization', $request->boucle_name);
        $this->assertEquals('+33 6 66 77 88 99', $request->contact_phone);
        $this->assertNull($request->website_url);
    }

    public function test_phone_is_required(): void
    {
        $data = [
            'boucle_name' => 'Test Org',
            'contact_name' => 'Test Contact',
            'contact_email' => 'test@example.com',
            'contact_phone' => '',
            'description' => 'Test description',
        ];

        $this->post('/partenaires/demande', $data)->assertSessionHasErrors('contact_phone');
    }
}
