<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['blog_explorer_note_fr', 'blog_explorer_note_en'] as $scenarioId) {
            $prompt = DB::table('admin_ai_prompts')
                ->where('scenario_id', $scenarioId)
                ->value('prompt_text');

            if (! is_string($prompt)) {
                continue;
            }

            $replacement = str_ends_with($scenarioId, '_fr') ? 'délimiteur de code' : 'code delimiter';

            DB::table('admin_ai_prompts')
                ->where('scenario_id', $scenarioId)
                ->update([
                    'prompt_text' => str_replace('```html', $replacement, $prompt),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Prompt wording changes are intentionally not reverted.
    }
};
