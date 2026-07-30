<?php

namespace Tests\Feature\Commands;

use App\Models\AdminAiPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedOfferMasterPromptsTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_run_creates_fr_and_en_active()
    {
        $this->artisan('ai:seed-offer-master-prompts')->assertSuccessful();

        $fr = AdminAiPrompt::active()->where('scenario_id', 'service_offer_master_fr')->first();
        $en = AdminAiPrompt::active()->where('scenario_id', 'service_offer_master_en')->first();

        $this->assertNotNull($fr);
        $this->assertNotNull($en);
        $this->assertSame('service_offer_master_fr', $fr->scenario_id);
        $this->assertSame('service_offer_master_en', $en->scenario_id);
        $this->assertTrue($fr->is_active);
        $this->assertTrue($en->is_active);
        $this->assertNotEmpty($fr->prompt_text);
        $this->assertNotEmpty($en->prompt_text);
        $this->assertStringContainsString('français', $fr->prompt_text);
        $this->assertStringContainsString('English', $en->prompt_text);
    }

    public function test_second_run_without_changes_does_not_create_duplicate()
    {
        $this->artisan('ai:seed-offer-master-prompts')->assertSuccessful();

        $this->artisan('ai:seed-offer-master-prompts')->assertSuccessful();

        $frAll = AdminAiPrompt::where('scenario_id', 'service_offer_master_fr')->get();
        $enAll = AdminAiPrompt::where('scenario_id', 'service_offer_master_en')->get();

        $this->assertCount(1, $frAll);
        $this->assertCount(1, $enAll);

        $this->assertEquals(1, AdminAiPrompt::active()->where('scenario_id', 'service_offer_master_fr')->count());
        $this->assertEquals(1, AdminAiPrompt::active()->where('scenario_id', 'service_offer_master_en')->count());
    }

    public function test_only_one_active_version_per_scenario()
    {
        $this->artisan('ai:seed-offer-master-prompts')->assertSuccessful();

        $activeFr = AdminAiPrompt::active()->where('scenario_id', 'service_offer_master_fr')->get();
        $activeEn = AdminAiPrompt::active()->where('scenario_id', 'service_offer_master_en')->get();

        $this->assertCount(1, $activeFr);
        $this->assertCount(1, $activeEn);
    }

    public function test_fr_and_en_contents_are_recorded_correctly()
    {
        $this->artisan('ai:seed-offer-master-prompts')->assertSuccessful();

        $fr = AdminAiPrompt::active()->where('scenario_id', 'service_offer_master_fr')->first();
        $en = AdminAiPrompt::active()->where('scenario_id', 'service_offer_master_en')->first();

        $this->assertStringContainsString('Tu es un assistant expert en formulation', $fr->prompt_text);
        $this->assertStringContainsString('You are an expert assistant in formulating', $en->prompt_text);

        $this->assertStringContainsString('description_markdown', $fr->prompt_text);
        $this->assertStringContainsString('description_markdown', $en->prompt_text);
    }
}
