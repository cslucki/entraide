<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocaleControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_redirect_to_internal_path_accepted(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('locale.switch', ['locale' => 'fr']), [
            'redirect_to' => url('/org/acme-corp/dossiers/uuid#fichiers'),
        ]);

        $response->assertRedirect(url('/org/acme-corp/dossiers/uuid#fichiers'));
        $this->assertEquals('fr', session('locale'));
    }

    public function test_redirect_to_hash_preserved(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('locale.switch', ['locale' => 'en']), [
            'redirect_to' => url('/org/acme-corp/dossiers/uuid').'#fichiers',
        ]);

        $target = $response->headers->get('Location');
        $this->assertStringContainsString('#fichiers', $target);
        $this->assertEquals('en', session('locale'));
    }

    public function test_redirect_to_external_url_refused(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('locale.switch', ['locale' => 'en']), [
            'redirect_to' => 'https://evil.com/steal',
        ]);

        $response->assertRedirect();
        $target = $response->headers->get('Location');
        $this->assertStringStartsWith(url('/'), $target);
        $this->assertEquals('en', session('locale'));
    }

    public function test_redirect_to_protocol_relative_url_refused(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('locale.switch', ['locale' => 'fr']), [
            'redirect_to' => '//evil.com/steal',
        ]);

        $response->assertRedirect();
        $target = $response->headers->get('Location');
        $this->assertStringStartsWith(url('/'), $target);
        $this->assertEquals('fr', session('locale'));
    }

    public function test_redirect_to_blank_goes_back(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('locale.switch', ['locale' => 'fr']), [
            'redirect_to' => '',
        ]);

        $response->assertRedirect();
        $target = $response->headers->get('Location');
        $this->assertStringStartsWith(url('/'), $target);
        $this->assertEquals('fr', session('locale'));
    }

    public function test_redirect_to_missing_key_goes_back(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('locale.switch', ['locale' => 'en']), []);

        $response->assertRedirect();
        $target = $response->headers->get('Location');
        $this->assertStringStartsWith(url('/'), $target);
    }

    public function test_redirect_to_internal_root_accepted(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('locale.switch', ['locale' => 'fr']), [
            'redirect_to' => url('/'),
        ]);

        $response->assertRedirect(url('/'));
        $this->assertEquals('fr', session('locale'));
    }

    public function test_redirect_to_external_url_with_external_referer_falls_back_to_root(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withHeaders(['Referer' => 'https://evil.com/phishing'])
            ->post(route('locale.switch', ['locale' => 'fr']), [
                'redirect_to' => 'https://evil.com/steal',
            ]);

        $response->assertRedirect(url('/'));
        $this->assertEquals('fr', session('locale'));
    }

    public function test_invalid_locale_returns_404(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('locale.switch', ['locale' => 'de']), [
            'redirect_to' => url('/'),
        ])->assertNotFound();
    }
}
