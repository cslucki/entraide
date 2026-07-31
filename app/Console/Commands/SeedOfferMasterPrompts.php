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

        $this->upsertPrompt('service_offer_master_fr', 'Service Offer Master — FR', $promptFr);
        $this->upsertPrompt('service_offer_master_en', 'Service Offer Master — EN', $promptEn);

        $this->info('Both prompts are now editable at /admin/ai-prompts.');

        return self::SUCCESS;
    }

    private function upsertPrompt(string $scenarioId, string $name, string $promptText): void
    {
        $active = AdminAiPrompt::active()->where('scenario_id', $scenarioId)->orderByDesc('version')->first();

        if ($active && $active->prompt_text === $promptText) {
            $this->info("{$name} v{$active->version} — already up to date (unchanged).");

            return;
        }

        if ($active) {
            $active->update(['is_active' => false]);
        }

        $maxVersion = AdminAiPrompt::where('scenario_id', $scenarioId)->max('version') ?? 0;
        $version = $maxVersion + 1;

        AdminAiPrompt::create([
            'scenario_id' => $scenarioId,
            'name' => $name,
            'description' => 'Prompt for Service Offer Master scenario.',
            'prompt_text' => $promptText,
            'version' => $version,
            'is_active' => true,
        ]);

        $this->info("{$name} v{$version} created.");
    }
}
