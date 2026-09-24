<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AdminOutilsController extends Controller
{
    // TASK-1631 — `SENSITIVE_COLUMNS` (liste NOIRE de 13 noms) est partie
    // avec assign-data : le registre declare desormais une liste BLANCHE par
    // dataset. Une liste noire n'a jamais protege que ce qu'elle connaissait
    // — une colonne ajoutee demain a une table etait exposee par defaut.

    private function categoryFixes(): array
    {
        return [
            ['slug' => 'depannage-informatique', 'name_b2c' => 'Dépannage informatique', 'name_b2b' => 'Informatique', 'skills' => ['Sécurité et anti-virus', 'Installation & configuration logiciel', 'Nettoyage & optimisation', 'Sauvegarde de données', 'Aide au choix matériel']],
            ['slug' => 'visibilite-clients', 'name_b2c' => 'Visibilité & clients', 'name_b2b' => 'Marketing', 'skills' => ['Création pitch simple', 'Optimisation profil LinkedIn', 'Mini audit visibilité', 'Recherche partenaires', 'Idées pour trouver des clients']],
            ['slug' => 'creer-des-supports', 'name_b2c' => 'Créer des supports', 'name_b2b' => 'Communication', 'skills' => ['Création flyer simple', 'Amélioration photo / image', 'Création mini logo', 'Visuels pour réseaux sociaux', 'Mise en page document']],
            ['slug' => 'trouver-un-emploi', 'name_b2c' => 'Trouver un emploi', 'name_b2b' => 'Emploi', 'skills' => ['Amélioration CV', 'Lettre de motivation', 'Préparation entretien', 'Recherche missions', 'Aide pour réseauter']],
            ['slug' => 'ecrire-communiquer', 'name_b2c' => 'Écrire & communiquer', 'name_b2b' => 'Rédaction', 'skills' => ['Correction texte', 'Réécriture', 'Rédaction courte', 'Résumé de contenu', 'Traduction texte court']],
            ['slug' => 'lancer-son-activite', 'name_b2c' => 'Lancer son activité', 'name_b2b' => 'Entrepreneuriat', 'skills' => ['Explication statut', 'Aide déclaration', 'Relecture devis / facture', 'Tableau de bord simple', 'Conseils démarrage']],
            ['slug' => 'outils-numeriques', 'name_b2c' => 'Outils numériques', 'name_b2b' => 'Digital', 'skills' => ['Aide utilisation IA', 'Création compte pro', 'Automatiser une tâche', 'Aide création mini-site web', 'Organisation outils']],
            ['slug' => 'aides-demarches', 'name_b2c' => 'Aides & démarches', 'name_b2b' => 'Vie quotidienne', 'skills' => ['Aide démarches administratives', 'Infos aides sociales', 'Aide dossiers', 'Aide recherche logement', 'Aide rédaction courrier administratif']],
            ['slug' => 'entraide-locale', 'name_b2c' => 'Entraide locale', 'name_b2b' => 'Logistique', 'skills' => ['Covoiturage', 'Prêt matériel', 'Prêt salle', 'Aide déménagement', 'Stockage temporaire']],
            ['slug' => 'bricolage-projets-perso', 'name_b2c' => 'Bricolage & projets perso', 'name_b2b' => 'Loisirs & pratique', 'skills' => ['Conseils bricolage', 'Idée DIY', 'Aide réparation', 'Tutoriel personnalisé', 'Conseil projet perso']],
            ['slug' => 'bien-etre-equilibre', 'name_b2c' => 'Bien-être & équilibre', 'name_b2b' => 'Bien-être & quotidien', 'skills' => ['Conseils sport', 'Conseils alimentation', 'Relaxation guidée', 'Organisation perso', 'Routine productive']],
        ];
    }

    // TASK-1631 — `assignData()`, `doAssignData()` et `assignDataDetail()`
    // sont parties dans `AdminAssignDataController`, avec leur registre de
    // 290 lignes. Ce controleur ne garde que « Fix categories ».

    public function fixCategories(): View
    {
        $categories = Category::with(['skills'])
            ->withCount(['services', 'serviceRequests'])
            ->orderBy('name_b2c')
            ->get();

        $mapping = collect($this->categoryFixes())->keyBy('slug');

        return view('admin.outils.fix-categories', compact('categories', 'mapping'));
    }

    public function doFixCategories(): RedirectResponse
    {
        $fixes = $this->categoryFixes();
        $updated = 0;
        $skillsCreated = 0;
        $skillsDeleted = 0;

        foreach ($fixes as $fix) {
            $category = Category::where('slug', $fix['slug'])->first();
            if (! $category) {
                continue;
            }

            $changed = false;
            if ($category->name_b2c !== $fix['name_b2c']) {
                $category->name_b2c = $fix['name_b2c'];
                $changed = true;
            }
            if ($category->name_b2b !== $fix['name_b2b']) {
                $category->name_b2b = $fix['name_b2b'];
                $changed = true;
            }
            if ($changed) {
                $category->save();
            }
            $updated++;

            $newSkillNames = $fix['skills'];
            $existingNames = $category->skills->pluck('name')->all();

            foreach ($newSkillNames as $name) {
                if (! in_array($name, $existingNames)) {
                    $category->skills()->create([
                        'name' => $name,
                        'slug' => Str::slug($name),
                        'organization_id' => $category->organization_id,
                    ]);
                    $skillsCreated++;
                }
            }

            $toDelete = $category->skills()->whereNotIn('name', $newSkillNames);
            $skillsDeleted += $toDelete->count();
            $toDelete->delete();
        }

        $parts = [];
        if ($updated) {
            $parts[] = "{$updated} catégorie(s) vérifiées";
        }
        if ($skillsCreated) {
            $parts[] = "{$skillsCreated} compétence(s) ajoutée(s)";
        }
        if ($skillsDeleted) {
            $parts[] = "{$skillsDeleted} compétence(s) supprimée(s)";
        }

        return redirect()->route('admin.outils.fix-categories')
            ->with('success', implode(', ', $parts) ?: 'Aucun changement nécessaire.');
    }
}
