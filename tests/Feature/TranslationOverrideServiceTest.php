<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\TranslationOverride;
use App\Services\TranslationOverrideService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TranslationOverrideServiceTest extends TestCase
{
    use RefreshDatabase;

    private TranslationOverrideService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(TranslationOverrideService::class);
    }

    public function test_creates_global_override(): void
    {
        $override = $this->service->set('home', 'welcome', 'fr', 'Bienvenue sur la plateforme');

        $this->assertDatabaseHas('translation_overrides', [
            'id' => $override->id,
            'organization_id' => null,
            'locale' => 'fr',
            'group' => 'home',
            'key' => 'welcome',
            'value' => 'Bienvenue sur la plateforme',
            'is_active' => true,
        ]);
    }

    public function test_creates_organization_override(): void
    {
        $organization = Organization::factory()->create();

        $override = $this->service->set('home', 'welcome', 'en', 'Welcome!', $organization);

        $this->assertEquals($organization->id, $override->organization_id);
        $this->assertEquals('Welcome!', $override->value);
    }

    public function test_override_priority_organization_over_global(): void
    {
        $organization = Organization::factory()->create();

        TranslationOverride::create([
            'organization_id' => null,
            'locale' => 'en',
            'group' => 'home',
            'key' => 'welcome',
            'value' => 'Global override',
            'is_active' => true,
        ]);

        TranslationOverride::create([
            'organization_id' => $organization->id,
            'locale' => 'en',
            'group' => 'home',
            'key' => 'welcome',
            'value' => 'Organization override',
            'is_active' => true,
        ]);

        $result = $this->service->get('home', 'welcome', 'en', $organization);

        $this->assertEquals('Organization override', $result);
    }

    public function test_inactive_override_is_ignored(): void
    {
        $organization = Organization::factory()->create();

        TranslationOverride::create([
            'organization_id' => $organization->id,
            'locale' => 'en',
            'group' => 'home',
            'key' => 'welcome',
            'value' => 'Inactive override',
            'is_active' => false,
        ]);

        $result = $this->service->get('home', 'welcome', 'en', $organization);

        $this->assertEquals('Welcome', $result);
    }

    public function test_unique_constraint_for_same_organization_locale_group_key(): void
    {
        $this->expectException(QueryException::class);

        $organization = Organization::factory()->create();

        TranslationOverride::create([
            'organization_id' => $organization->id,
            'locale' => 'en',
            'group' => 'home',
            'key' => 'welcome',
            'value' => 'First',
            'is_active' => true,
        ]);

        TranslationOverride::create([
            'organization_id' => $organization->id,
            'locale' => 'en',
            'group' => 'home',
            'key' => 'welcome',
            'value' => 'Second',
            'is_active' => true,
        ]);
    }

    public function test_falls_back_to_lang_file_when_no_override(): void
    {
        $organization = Organization::factory()->create();

        $result = $this->service->get('home', 'welcome', 'en', $organization);

        $this->assertEquals('Welcome', $result);
    }

    public function test_organization_isolation(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        TranslationOverride::create([
            'organization_id' => $orgA->id,
            'locale' => 'en',
            'group' => 'home',
            'key' => 'welcome',
            'value' => 'Only for A',
            'is_active' => true,
        ]);

        $resultA = $this->service->get('home', 'welcome', 'en', $orgA);
        $resultB = $this->service->get('home', 'welcome', 'en', $orgB);

        $this->assertEquals('Only for A', $resultA);
        $this->assertEquals('Welcome', $resultB);
    }

    public function test_applies_placeholder_replacements(): void
    {
        $organization = Organization::factory()->create();

        TranslationOverride::create([
            'organization_id' => $organization->id,
            'locale' => 'fr',
            'group' => 'home',
            'key' => 'welcome',
            'value' => 'Bienvenue :name !',
            'is_active' => true,
        ]);

        $result = $this->service->get('home', 'welcome', 'fr', $organization, ['name' => 'Cyril']);

        $this->assertEquals('Bienvenue Cyril !', $result);
    }

    public function test_falls_back_to_global_when_organization_has_no_override(): void
    {
        $organization = Organization::factory()->create();

        TranslationOverride::create([
            'organization_id' => null,
            'locale' => 'en',
            'group' => 'home',
            'key' => 'welcome',
            'value' => 'Global fallback',
            'is_active' => true,
        ]);

        $result = $this->service->get('home', 'welcome', 'en', $organization);

        $this->assertEquals('Global fallback', $result);
    }

    public function test_has_returns_true_when_override_exists(): void
    {
        TranslationOverride::create([
            'organization_id' => null,
            'locale' => 'fr',
            'group' => 'home',
            'key' => 'welcome',
            'value' => 'Override FR',
            'is_active' => true,
        ]);

        $this->assertTrue($this->service->has('home', 'welcome', 'fr'));
    }

    public function test_has_returns_false_when_no_override(): void
    {
        $this->assertFalse($this->service->has('home', 'nonexistent', 'fr'));
    }

    public function test_cache_stores_plain_array_not_models(): void
    {
        $organization = Organization::factory()->create();

        TranslationOverride::create([
            'organization_id' => $organization->id,
            'locale' => 'en',
            'group' => 'home',
            'key' => 'welcome',
            'value' => 'Hello',
            'is_active' => true,
        ]);

        TranslationOverride::create([
            'organization_id' => $organization->id,
            'locale' => 'en',
            'group' => 'home',
            'key' => 'tagline',
            'value' => 'Tagline',
            'is_active' => true,
        ]);

        $this->service->get('home', 'welcome', 'en', $organization);

        $cacheKey = 'translation_overrides:'.$organization->id.':en';
        $cached = Cache::get($cacheKey);

        $this->assertIsArray($cached, 'Cache must store plain array, not Collection');
        $this->assertArrayHasKey('home.welcome', $cached);
        $this->assertArrayHasKey('home.tagline', $cached);
        $this->assertSame('Hello', $cached['home.welcome']);
        $this->assertSame('Tagline', $cached['home.tagline']);

        foreach ($cached as $key => $value) {
            $this->assertIsString($key);
            $this->assertIsString($value);
            $this->assertStringContainsString('.', $key);
        }
    }

    public function test_inactive_override_not_in_cache(): void
    {
        $organization = Organization::factory()->create();

        TranslationOverride::create([
            'organization_id' => $organization->id,
            'locale' => 'en',
            'group' => 'home',
            'key' => 'welcome',
            'value' => 'Active',
            'is_active' => true,
        ]);

        TranslationOverride::create([
            'organization_id' => $organization->id,
            'locale' => 'en',
            'group' => 'home',
            'key' => 'tagline',
            'value' => 'Inactive',
            'is_active' => false,
        ]);

        $this->service->get('home', 'welcome', 'en', $organization);

        $cacheKey = 'translation_overrides:'.$organization->id.':en';
        $cached = Cache::get($cacheKey);

        $this->assertArrayHasKey('home.welcome', $cached);
        $this->assertArrayNotHasKey('home.tagline', $cached, 'Inactive overrides must not be cached');
    }

    public function test_cache_is_serializable_with_database_driver(): void
    {
        $organization = Organization::factory()->create();

        TranslationOverride::create([
            'organization_id' => $organization->id,
            'locale' => 'en',
            'group' => 'home',
            'key' => 'welcome',
            'value' => 'Serializable',
            'is_active' => true,
        ]);

        $this->service->get('home', 'welcome', 'en', $organization);

        $cacheKey = 'translation_overrides:'.$organization->id.':en';
        $cached = Cache::get($cacheKey);

        $serialized = serialize($cached);
        $unserialized = unserialize($serialized);

        $this->assertIsArray($unserialized);
        $this->assertSame('Serializable', $unserialized['home.welcome']);
    }
}
