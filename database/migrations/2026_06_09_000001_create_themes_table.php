<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('themes', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->string('label', 100);
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->json('tokens');
            $table->json('dark_tokens');
            $table->timestamps();
        });

        $themes = config('bouclepro_themes.themes');
        $defaultKey = config('bouclepro_themes.default', 'zen');
        $now = now();

        foreach ($themes as $key => $theme) {
            DB::table('themes')->insert([
                'key' => $key,
                'label' => $theme['label'],
                'description' => $theme['description'] ?? null,
                'is_default' => $key === $defaultKey,
                'tokens' => json_encode($theme['tokens']),
                'dark_tokens' => json_encode($theme['dark'] ?? []),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('themes');
    }
};
