<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TASK-1896 — l'avatar de repli est dessine localement : plus aucun appel a
 * ui-avatars.com, qui recevait le nom complet de chaque membre sans photo.
 */
class TASK1896LocalFallbackAvatarTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIXE = 'data:image/svg+xml;base64,';

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1896', 'is_active' => true, 'is_public' => true, 'locale' => 'fr',
        ]);
        app()->instance('current_organization', $this->organization);
    }

    private function membre(array $attributs = []): User
    {
        return User::factory()->complete()->create(array_merge([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
            'avatar' => null,
        ], $attributs));
    }

    private static function svg(string $uri): string
    {
        return (string) base64_decode(substr($uri, strlen(self::PREFIXE)), true);
    }

    public function test_sans_photo_le_repli_est_un_svg_local_portant_les_initiales(): void
    {
        $membre = $this->membre(['first_name' => 'Jeanne', 'name' => 'Durand']);

        $this->assertStringStartsWith(self::PREFIXE, $membre->avatar_url);
        $this->assertStringNotContainsString('ui-avatars', $membre->avatar_url);
        $this->assertStringNotContainsString('Durand', $membre->avatar_url,
            'Le nom complet ne doit plus figurer dans l\'URL de l\'avatar.');

        $svg = self::svg($membre->avatar_url);

        $this->assertNotFalse(simplexml_load_string($svg), 'Le SVG genere doit etre du XML valide.');
        $this->assertStringContainsString('>JD</text>', $svg);
        $this->assertStringNotContainsString('Jeanne', $svg);
    }

    public function test_les_initiales_sont_echappees_dans_le_svg(): void
    {
        $membre = $this->membre(['first_name' => '<script>', 'name' => '&Co']);

        $svg = self::svg($membre->avatar_url);

        $this->assertNotFalse(simplexml_load_string($svg), 'Des caracteres speciaux ne doivent pas casser le SVG.');
        $this->assertStringContainsString('>&lt;&amp;</text>', $svg);
        $this->assertStringNotContainsString('<script', $svg);
    }

    public function test_une_photo_existante_reste_servie_telle_quelle(): void
    {
        $membre = $this->membre(['avatar' => 'avatars/photo-1896.jpg']);

        $this->assertStringNotContainsString('data:', $membre->avatar_url);
        $this->assertStringContainsString('avatars/photo-1896', $membre->avatar_url);
    }

    public function test_sans_photo_og_image_retombe_sur_le_logo_bouclepro(): void
    {
        $lecteur = $this->membre();
        $profil = $this->membre(['first_name' => 'Sans', 'name' => 'Photo']);

        $page = $this->actingAs($lecteur)->get(route('profile.show', $profil))->assertOk();

        $page->assertSee('<meta property="og:image" content="'.asset('brand/bouclepro-symbol-64.png').'">', false);
        $this->assertStringNotContainsString('ui-avatars.com', $page->getContent());
    }
}
