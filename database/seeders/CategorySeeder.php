<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Tech & Digital', 'slug' => 'tech-digital', 'color' => '#6366f1'],
            ['name' => 'Design', 'slug' => 'design', 'color' => '#ec4899'],
            ['name' => 'Marketing', 'slug' => 'marketing', 'color' => '#f59e0b'],
            ['name' => 'Rédaction', 'slug' => 'redaction', 'color' => '#10b981'],
            ['name' => 'Conseil', 'slug' => 'conseil', 'color' => '#3b82f6'],
            ['name' => 'Formation', 'slug' => 'formation', 'color' => '#8b5cf6'],
            ['name' => 'Traduction', 'slug' => 'traduction', 'color' => '#ef4444'],
            ['name' => 'Autre', 'slug' => 'autre', 'color' => '#6b7280'],
        ];

        foreach ($categories as $cat) {
            Category::firstOrCreate(['slug' => $cat['slug']], $cat);
        }
    }
}
