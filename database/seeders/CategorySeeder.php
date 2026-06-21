<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Organization;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $orgId = Organization::where('is_default', true)->value('id')
            ?? Organization::orderBy('created_at')->value('id');

        $categories = [
            ['name_b2c' => 'Dépannage informatique', 'name_b2b' => 'Informatique', 'slug' => 'depannage-informatique', 'color' => '#6366f1'],
            ['name_b2c' => 'Visibilité & clients', 'name_b2b' => 'Marketing', 'slug' => 'visibilite-clients', 'color' => '#f59e0b'],
            ['name_b2c' => 'Créer des supports', 'name_b2b' => 'Communication', 'slug' => 'creer-des-supports', 'color' => '#ec4899'],
            ['name_b2c' => 'Trouver un emploi', 'name_b2b' => 'Emploi', 'slug' => 'trouver-un-emploi', 'color' => '#3b82f6'],
            ['name_b2c' => 'Écrire & communiquer', 'name_b2b' => 'Rédaction', 'slug' => 'ecrire-communiquer', 'color' => '#10b981'],
            ['name_b2c' => 'Lancer son activité', 'name_b2b' => 'Entrepreneuriat', 'slug' => 'lancer-son-activite', 'color' => '#8b5cf6'],
            ['name_b2c' => 'Outils numériques', 'name_b2b' => 'Digital', 'slug' => 'outils-numeriques', 'color' => '#6366f1'],
            ['name_b2c' => 'Aides & démarches', 'name_b2b' => 'Vie quotidienne', 'slug' => 'aides-demarches', 'color' => '#ef4444'],
            ['name_b2c' => 'Entraide locale', 'name_b2b' => 'Logistique', 'slug' => 'entraide-locale', 'color' => '#6b7280'],
            ['name_b2c' => 'Bricolage & projets perso', 'name_b2b' => 'Loisirs & pratique', 'slug' => 'bricolage-projets-perso', 'color' => '#f59e0b'],
            ['name_b2c' => 'Bien-être & équilibre', 'name_b2b' => 'Bien-être & quotidien', 'slug' => 'bien-etre-equilibre', 'color' => '#10b981'],
        ];

        foreach ($categories as $cat) {
            Category::firstOrCreate(
                ['slug' => $cat['slug']],
                array_merge($cat, ['organization_id' => $orgId])
            );
        }
    }
}
