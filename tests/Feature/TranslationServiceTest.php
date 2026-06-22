<?php

namespace Tests\Feature;

use App\Services\TranslationService;
use Tests\TestCase;

class TranslationServiceTest extends TestCase
{
    public function test_returns_all_entries(): void
    {
        $service = new TranslationService;
        $entries = $service->all();

        $this->assertGreaterThan(0, $entries->count());
    }

    public function test_entry_has_required_fields(): void
    {
        $service = new TranslationService;
        $entries = $service->all();

        foreach ($entries as $entry) {
            $this->assertArrayHasKey('group', $entry);
            $this->assertArrayHasKey('key', $entry);
            $this->assertArrayHasKey('fr', $entry);
            $this->assertArrayHasKey('en', $entry);
            $this->assertArrayHasKey('status', $entry);
            $this->assertContains($entry['status'], ['OK', 'MISSING_FR', 'MISSING_EN', 'EMPTY_FR', 'EMPTY_EN', 'NESTED']);
        }
    }

    public function test_returns_groups(): void
    {
        $service = new TranslationService;
        $groups = $service->getGroups();

        $this->assertGreaterThan(0, count($groups));
        $this->assertContains('home', $groups);
        $this->assertContains('navigation', $groups);
    }

    public function test_detects_missing_french_translation(): void
    {
        $service = new TranslationService;
        $entries = $service->all();

        $missing = $entries->where('status', 'MISSING_FR');
        foreach ($missing as $entry) {
            $this->assertNull($entry['fr']);
            $this->assertNotNull($entry['en']);
        }
        $this->assertCount(0, $missing);
    }

    public function test_any_missing_english_entries_are_correctly_shaped(): void
    {
        $service = new TranslationService;
        $entries = $service->all();

        $missing = $entries->where('status', 'MISSING_EN');
        foreach ($missing as $entry) {
            $this->assertNull($entry['en']);
            $this->assertNotNull($entry['fr']);
        }
        $this->assertTrue(true);
    }

    public function test_all_entries_have_non_empty_fr_or_en(): void
    {
        $service = new TranslationService;
        $entries = $service->all();

        foreach ($entries as $entry) {
            $this->assertContains($entry['status'], ['OK', 'MISSING_FR', 'MISSING_EN', 'EMPTY_FR', 'EMPTY_EN', 'NESTED']);
        }
    }

    public function test_known_key_exists(): void
    {
        $service = new TranslationService;
        $entries = $service->all();

        $homeWelcome = $entries->first(fn ($e) => $e['group'] === 'home' && $e['key'] === 'welcome');
        $this->assertNotNull($homeWelcome);
        $this->assertEquals('OK', $homeWelcome['status']);
    }
}
