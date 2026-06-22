<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\TranslationOverride;
use App\Models\User;
use App\Services\TranslationOverrideService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArtSciLabOverrideTest extends TestCase
{
    use RefreshDatabase;

    private TranslationOverrideService $service;

    private Organization $artscilab;

    private Organization $bouclepro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(TranslationOverrideService::class);

        $this->artscilab = Organization::factory()->create([
            'name' => 'ArtSciLab',
            'slug' => 'artscilab',
            'locale' => 'en',
        ]);

        $this->bouclepro = Organization::factory()->create([
            'name' => 'BouclePro',
            'slug' => 'main',
            'locale' => 'en',
        ]);

        $userId = User::factory()->create(['is_admin' => true])->id;

        $this->service->set('home', 'enter_main_loop', 'fr', 'Rejoindre Launch-Pals', $this->artscilab, $userId);
        $this->service->set('home', 'enter_main_loop', 'en', 'Join Launch-Pals', $this->artscilab, $userId);
        $this->service->set('home', 'welcome', 'en', 'Welcome to Launch-Pals', $this->artscilab, $userId);
        $this->service->set('home', 'tagline', 'en', 'Work, innovate, move forward together.', $this->artscilab, $userId);
    }

    public function test_artscilab_en_returns_launchpals_overrides(): void
    {
        $this->assertEquals('Join Launch-Pals', $this->service->get('home', 'enter_main_loop', 'en', $this->artscilab));
        $this->assertEquals('Welcome to Launch-Pals', $this->service->get('home', 'welcome', 'en', $this->artscilab));
        $this->assertEquals('Work, innovate, move forward together.', $this->service->get('home', 'tagline', 'en', $this->artscilab));
    }

    public function test_artscilab_fr_returns_french_override(): void
    {
        $this->assertEquals('Rejoindre Launch-Pals', $this->service->get('home', 'enter_main_loop', 'fr', $this->artscilab));
    }

    public function test_artscilab_fr_falls_back_to_lang_file_when_no_override(): void
    {
        $this->assertEquals('Bienvenue', $this->service->get('home', 'welcome', 'fr', $this->artscilab));
    }

    public function test_bouclepro_not_impacted_by_artscilab_overrides(): void
    {
        $this->assertEquals('Enter the main loop', $this->service->get('home', 'enter_main_loop', 'en', $this->bouclepro));
        $this->assertEquals('Welcome', $this->service->get('home', 'welcome', 'en', $this->bouclepro));
        $this->assertEquals('Work, help each other, move forward.', $this->service->get('home', 'tagline', 'en', $this->bouclepro));
    }

    public function test_deactivation_restores_lang_file_fallback(): void
    {
        $this->service->deactivate('home', 'enter_main_loop', 'en', $this->artscilab);

        $this->assertEquals('Enter the main loop', $this->service->get('home', 'enter_main_loop', 'en', $this->artscilab));
    }

    public function test_nonexistent_key_falls_back_to_lang_file(): void
    {
        $this->assertEquals('Enter the main loop', $this->service->get('home', 'enter_main_loop', 'en', $this->bouclepro));
        $this->assertEquals('Work, help each other, move forward.', $this->service->get('home', 'tagline', 'en', $this->bouclepro));
    }

    public function test_global_override_does_not_leak_to_artscilab_when_org_override_exists(): void
    {
        TranslationOverride::create([
            'organization_id' => null,
            'locale' => 'en',
            'group' => 'home',
            'key' => 'enter_main_loop',
            'value' => 'Global Enter',
            'is_active' => true,
        ]);

        $this->assertEquals('Join Launch-Pals', $this->service->get('home', 'enter_main_loop', 'en', $this->artscilab));
    }
}
