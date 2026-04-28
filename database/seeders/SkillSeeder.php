<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Skill;
use Illuminate\Database\Seeder;

class SkillSeeder extends Seeder
{
    public function run(): void
    {
        $skillsByCategory = [
            'tech-digital' => [
                'Développement web',
                'Développement mobile',
                'DevOps & Cloud',
                'Bases de données',
                'API & Intégrations',
            ],
            'design' => [
                'UI/UX Design',
                'Identité visuelle',
                'Motion design',
                'Illustration',
                'Photographie',
            ],
            'marketing' => [
                'SEO / SEA',
                'Réseaux sociaux',
                'Email marketing',
                'Stratégie digitale',
                'Copywriting',
            ],
            'redaction' => [
                'Articles de blog',
                'Rédaction technique',
                'Correction/Relecture',
                'Scénarios & scripts',
            ],
            'conseil' => [
                'Stratégie business',
                'Finance & comptabilité',
                'Juridique',
                'RH & recrutement',
            ],
            'formation' => [
                'Formations techniques',
                'Coaching professionnel',
                'Ateliers créatifs',
                'Tutorat',
            ],
            'traduction' => [
                'Anglais ↔ Français',
                'Espagnol',
                'Allemand',
                'Autres langues',
            ],
            'autre' => [
                'Administratif',
                'Logistique',
                'Divers',
            ],
        ];

        foreach ($skillsByCategory as $categorySlug => $skills) {
            $category = Category::where('slug', $categorySlug)->first();
            if (!$category) continue;

            foreach ($skills as $skillName) {
                $slug = \Illuminate\Support\Str::slug($skillName);
                Skill::firstOrCreate(
                    ['slug' => $slug],
                    ['category_id' => $category->id, 'name' => $skillName, 'slug' => $slug]
                );
            }
        }
    }
}
