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
            'depannage-informatique' => [
                'Dépannage PC/Mac',
                'Installation logicielle',
                'Réseau & Wi-Fi',
                'Sécurité informatique',
                'Récupération de données',
            ],
            'visibilite-clients' => [
                'SEO / SEA',
                'Réseaux sociaux',
                'Email marketing',
                'Stratégie digitale',
                'Copywriting',
            ],
            'creer-des-supports' => [
                'UI/UX Design',
                'Identité visuelle',
                'Motion design',
                'Illustration',
                'Amélioration photo / image',
            ],
            'trouver-un-emploi' => [
                'CV & lettre de motivation',
                'Préparation entretien',
                'Réseautage professionnel',
                'Orientation professionnelle',
            ],
            'ecrire-communiquer' => [
                'Articles de blog',
                'Rédaction technique',
                'Correction/Relecture',
                'Scénarios & scripts',
            ],
            'lancer-son-activite' => [
                'Stratégie business',
                'Finance & comptabilité',
                'Juridique',
                'RH & recrutement',
            ],
            'outils-numeriques' => [
                'Formation logiciels',
                'Sites web simples',
                'E-commerce basique',
                'Automatisation',
            ],
            'aides-demarches' => [
                'Aides administratives',
                'Droits & démarches',
                'Logement',
                'Santé',
            ],
            'entraide-locale' => [
                'Déménagement',
                'Bricolage',
                'Coursier / livraison',
                'Garde d\'animaux',
            ],
            'bricolage-projets-perso' => [
                'Bricolage maison',
                'Jardinage',
                'Cuisine',
                'Loisirs créatifs',
            ],
            'bien-etre-equilibre' => [
                'Coaching bien-être',
                'Sophrologie',
                'Activité physique',
                'Soutien psychologique',
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
