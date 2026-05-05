<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\PointGuideline;
use Illuminate\Database\Seeder;

class PointGuidelineSeeder extends Seeder
{
    public function run(): void
    {
        $levels = [
            ['level' => 'essentiel', 'points_min' => 40, 'points_max' => 60, 'duration_label' => '20 à 30 min'],
            ['level' => 'standard',  'points_min' => 60, 'points_max' => 80, 'duration_label' => '30 à 45 min'],
            ['level' => 'complet',   'points_min' => 80, 'points_max' => 100, 'duration_label' => '45 à 60 min'],
        ];

        $categorySlugs = [
            'tech-digital', 'design', 'marketing', 'redaction',
            'conseil', 'formation', 'traduction', 'autre',
        ];

        foreach ($categorySlugs as $slug) {
            $category = Category::where('slug', $slug)->first();
            if (! $category) {
                continue;
            }

            PointGuideline::where('category_id', $category->id)->delete();

            foreach ($levels as $guideline) {
                PointGuideline::create(array_merge($guideline, ['category_id' => $category->id]));
            }
        }
    }
}
