<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\PointGuideline;
use Illuminate\Database\Seeder;

class PointGuidelineSeeder extends Seeder
{
    public function run(): void
    {
        $guidelines = [
            'tech-digital' => [
                ['level' => 'simple', 'points_min' => 20, 'points_max' => 50, 'duration_label' => '1 à 2 h'],
                ['level' => 'intermediate', 'points_min' => 50, 'points_max' => 150, 'duration_label' => '3 à 8 h'],
                ['level' => 'advanced', 'points_min' => 150, 'points_max' => 400, 'duration_label' => '1 à 3 j'],
            ],
            'design' => [
                ['level' => 'simple', 'points_min' => 15, 'points_max' => 40, 'duration_label' => '1 à 2 h'],
                ['level' => 'intermediate', 'points_min' => 40, 'points_max' => 120, 'duration_label' => '3 à 6 h'],
                ['level' => 'advanced', 'points_min' => 120, 'points_max' => 300, 'duration_label' => '1 à 2 j'],
            ],
            'marketing' => [
                ['level' => 'simple', 'points_min' => 15, 'points_max' => 35, 'duration_label' => '1 à 2 h'],
                ['level' => 'intermediate', 'points_min' => 35, 'points_max' => 100, 'duration_label' => '3 à 5 h'],
                ['level' => 'advanced', 'points_min' => 100, 'points_max' => 250, 'duration_label' => '1 à 2 j'],
            ],
            'redaction' => [
                ['level' => 'simple', 'points_min' => 10, 'points_max' => 30, 'duration_label' => '1 à 3 h'],
                ['level' => 'intermediate', 'points_min' => 30, 'points_max' => 80, 'duration_label' => '3 à 6 h'],
                ['level' => 'advanced', 'points_min' => 80, 'points_max' => 200, 'duration_label' => '1 j+'],
            ],
            'conseil' => [
                ['level' => 'simple', 'points_min' => 20, 'points_max' => 50, 'duration_label' => '1 à 2 h'],
                ['level' => 'intermediate', 'points_min' => 50, 'points_max' => 150, 'duration_label' => '2 à 4 h'],
                ['level' => 'advanced', 'points_min' => 150, 'points_max' => 350, 'duration_label' => '1 j+'],
            ],
            'formation' => [
                ['level' => 'simple', 'points_min' => 15, 'points_max' => 40, 'duration_label' => '1 à 2 h'],
                ['level' => 'intermediate', 'points_min' => 40, 'points_max' => 100, 'duration_label' => '2 à 4 h'],
                ['level' => 'advanced', 'points_min' => 100, 'points_max' => 250, 'duration_label' => '1 j+'],
            ],
            'traduction' => [
                ['level' => 'simple', 'points_min' => 10, 'points_max' => 30, 'duration_label' => '< 500 mots'],
                ['level' => 'intermediate', 'points_min' => 30, 'points_max' => 80, 'duration_label' => '500 à 2000 mots'],
                ['level' => 'advanced', 'points_min' => 80, 'points_max' => 200, 'duration_label' => '2000+ mots'],
            ],
            'autre' => [
                ['level' => 'simple', 'points_min' => 10, 'points_max' => 30, 'duration_label' => '1 à 2 h'],
                ['level' => 'intermediate', 'points_min' => 30, 'points_max' => 80, 'duration_label' => '3 à 6 h'],
                ['level' => 'advanced', 'points_min' => 80, 'points_max' => 200, 'duration_label' => '1 j+'],
            ],
        ];

        foreach ($guidelines as $categorySlug => $levels) {
            $category = Category::where('slug', $categorySlug)->first();
            if (!$category) continue;

            foreach ($levels as $guideline) {
                PointGuideline::firstOrCreate(
                    ['category_id' => $category->id, 'level' => $guideline['level']],
                    array_merge($guideline, ['category_id' => $category->id])
                );
            }
        }
    }
}
