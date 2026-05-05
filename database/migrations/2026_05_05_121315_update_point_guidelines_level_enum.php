<?php

use App\Models\Category;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite does not support ALTER COLUMN — recreate with new enum values.
        Schema::create('point_guidelines_new', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('category_id')->constrained()->cascadeOnDelete();
            $table->enum('level', ['essentiel', 'standard', 'complet']);
            $table->integer('points_min');
            $table->integer('points_max');
            $table->string('duration_label');
            $table->timestamps();
            $table->unique(['category_id', 'level']);
        });

        Schema::drop('point_guidelines');
        Schema::rename('point_guidelines_new', 'point_guidelines');

        // Re-seed the guidelines so prod data is never lost.
        $levels = [
            ['level' => 'essentiel', 'points_min' => 40, 'points_max' => 60,  'duration_label' => '20 à 30 min'],
            ['level' => 'standard',  'points_min' => 60, 'points_max' => 80,  'duration_label' => '30 à 45 min'],
            ['level' => 'complet',   'points_min' => 80, 'points_max' => 100, 'duration_label' => '45 à 60 min'],
        ];

        $slugs = ['tech-digital', 'design', 'marketing', 'redaction', 'conseil', 'formation', 'traduction', 'autre'];

        foreach ($slugs as $slug) {
            $category = Category::where('slug', $slug)->first();
            if (! $category) {
                continue;
            }
            foreach ($levels as $guideline) {
                DB::table('point_guidelines')->insert(array_merge($guideline, [
                    'id'          => \Illuminate\Support\Str::uuid(),
                    'category_id' => $category->id,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]));
            }
        }
    }

    public function down(): void
    {
        Schema::create('point_guidelines_rollback', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('category_id')->constrained()->cascadeOnDelete();
            $table->enum('level', ['simple', 'intermediate', 'advanced']);
            $table->integer('points_min');
            $table->integer('points_max');
            $table->string('duration_label');
            $table->timestamps();
            $table->unique(['category_id', 'level']);
        });

        Schema::drop('point_guidelines');
        Schema::rename('point_guidelines_rollback', 'point_guidelines');
    }
};
