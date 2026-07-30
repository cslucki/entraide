<?php

namespace App\Console\Commands;

use App\Models\AdminAiPrompt;
use App\Services\Ai\Scenarios\ServiceOfferMasterScenario;
use Illuminate\Console\Command;

class SeedOfferMasterPrompts extends Command
{
    protected $signature = 'ai:seed-offer-master-prompts';

    protected $description = 'Seed Service Offer Master AI prompts (FR + EN) into admin_ai_prompts table';

    public function handle(): int
    {
        $scenario = new ServiceOfferMasterScenario;

        $refl = new \ReflectionClass($scenario);

        $promptFr = '';
        $promptEn = '';

        foreach ($refl->getReflectionConstants() as $constant) {
            if ($constant->isPrivate() && $constant->getName() === 'SYSTEM_PROMPT_FR') {
                $promptFr = $constant->getValue();
            }
            if ($constant->isPrivate() && $constant->getName() === 'SYSTEM_PROMPT_EN') {
                $promptEn = $constant->getValue();
            }
        }

        if ($promptFr === '' || $promptEn === '') {
            $this->error('Could not read prompt constants from ServiceOfferMasterScenario.');

            return self::FAILURE;
        }

        $frMaxVersion = AdminAiPrompt::where('scenario_id', 'service_offer_master_fr')->max('version') ?? 0;
        $frVersion = $frMaxVersion + 1;

        AdminAiPrompt::where('scenario_id', 'service_offer_master_fr')->update(['is_active' => false]);

        AdminAiPrompt::create([
            'scenario_id' => 'service_offer_master_fr',
            'name' => 'Service Offer Master — FR',
            'description' => 'Prompt français pour la formulation de propositions d\'aide.',
            'prompt_text' => $promptFr,
            'version' => $frVersion,
            'is_active' => true,
        ]);

        $this->info("FR prompt version {$frVersion} created.");

        $enMaxVersion = AdminAiPrompt::where('scenario_id', 'service_offer_master_en')->max('version') ?? 0;
        $enVersion = $enMaxVersion + 1;

        AdminAiPrompt::where('scenario_id', 'service_offer_master_en')->update(['is_active' => false]);

        AdminAiPrompt::create([
            'scenario_id' => 'service_offer_master_en',
            'name' => 'Service Offer Master — EN',
            'description' => 'English prompt for formulating help offers.',
            'prompt_text' => $promptEn,
            'version' => $enVersion,
            'is_active' => true,
        ]);

        $this->info("EN prompt version {$enVersion} created.");
        $this->info('Both prompts are now editable at /admin/ai-prompts.');

        return self::SUCCESS;
    }
}
