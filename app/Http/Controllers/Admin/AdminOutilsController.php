<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Message;
use App\Models\Organization;
use App\Models\PointLedger;
use App\Models\Referral;
use App\Models\ReferralReward;
use App\Models\Report;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Skill;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Tenancy\DefaultOrganizationResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminOutilsController extends Controller
{
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

    public function assignData(): View
    {
        $organizations = Organization::orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $datasets = $this->assignableDatasets();

        return view('admin.outils.assign-data', compact('datasets', 'organizations'));
    }

    public function doAssignData(Request $request): RedirectResponse
    {
        $datasets = $this->assignableDatasets();

        $rules = [
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'datasets' => ['required', 'array', 'min:1'],
            'datasets.*' => ['required', 'string', Rule::in(array_keys($datasets))],
        ];

        if (in_array('users', $request->input('datasets', []))) {
            $rules['confirmation'] = ['required', 'string', Rule::in(['REASSIGN USERS'])];
        }

        $data = $request->validate($rules);

        $organizationId = $data['organization_id'] ?? null;
        if (! $organizationId) {
            $org = DefaultOrganizationResolver::resolve();
            $organizationId = $org?->getKey();
        }

        if (! $organizationId) {
            return redirect()->route('admin.outils.assign-data')
                ->with('error', 'Aucune organisation cible disponible.');
        }

        $updated = [];

        foreach ($data['datasets'] as $key) {
            $updated[$datasets[$key]['label']] = $datasets[$key]['query']()->update([
                'organization_id' => $organizationId,
            ]);
        }

        $orgName = Organization::find($organizationId)?->name ?? 'organisation cible';
        $summary = collect($updated)
            ->map(fn (int $count, string $label) => "{$label}: {$count}")
            ->implode(', ');

        Log::info('assign-data executed', [
            'admin_id' => $request->user()?->id,
            'organization_id' => $organizationId,
            'organization_name' => $orgName,
            'datasets' => $data['datasets'],
            'affected_rows' => $updated,
        ]);

        return redirect()->route('admin.outils.assign-data')
            ->with('success', "Données affectées à l'organisation « {$orgName} » — {$summary}.");
    }

    public function assignDataDetail(Request $request): View
    {
        $datasets = $this->assignableDatasets();

        $data = $request->validate([
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'datasets' => ['required', 'array', 'min:1'],
            'datasets.*' => ['required', 'string', Rule::in(array_keys($datasets))],
        ]);

        $organizationId = $data['organization_id'] ?? null;
        $orgName = $organizationId ? Organization::find($organizationId)?->name : null;

        $previews = [];
        foreach ($data['datasets'] as $key) {
            $total = (clone $datasets[$key]['query']())->count();
            $affectedQuery = clone $datasets[$key]['query']();
            if ($organizationId) {
                $affectedQuery->where('organization_id', '!=', $organizationId);
            }
            $count = $affectedQuery->whereNotNull('organization_id')->count();
            $rows = (clone $datasets[$key]['query']())
                ->limit(5)
                ->get()
                ->map(fn ($row) => (array) $row);

            $previews[$key] = [
                'label' => $datasets[$key]['label'],
                'total' => $total,
                'affected' => $count,
                'rows' => $rows,
            ];
        }

        return view('admin.outils.assign-data-detail', compact('previews', 'orgName'));
    }

    private function assignableDatasets(): array
    {
        return [
            'users' => [
                'label' => 'Utilisateurs',
                'description' => 'Tous les comptes membres et QA.',
                'query' => fn () => User::query(),
                'total' => User::count(),
                'without_organization' => User::whereNull('organization_id')->count(),
            ],
            'services' => [
                'label' => 'Services',
                'description' => 'Offres de services importées depuis la production.',
                'query' => fn () => Service::withoutGlobalScopes(),
                'total' => Service::withoutGlobalScopes()->count(),
                'without_organization' => Service::withoutGlobalScopes()->whereNull('organization_id')->count(),
            ],
            'service_requests' => [
                'label' => 'Demandes',
                'description' => 'Demandes de services importées depuis la production.',
                'query' => fn () => ServiceRequest::withoutGlobalScopes(),
                'total' => ServiceRequest::withoutGlobalScopes()->count(),
                'without_organization' => ServiceRequest::withoutGlobalScopes()->whereNull('organization_id')->count(),
            ],
            'transactions' => [
                'label' => 'Transactions',
                'description' => 'Échanges entre membres liés aux services ou demandes.',
                'query' => fn () => Transaction::withoutGlobalScopes(),
                'total' => Transaction::withoutGlobalScopes()->count(),
                'without_organization' => Transaction::withoutGlobalScopes()->whereNull('organization_id')->count(),
            ],
            'messages' => [
                'label' => 'Messages',
                'description' => 'Messages liés aux transactions.',
                'query' => fn () => Message::query(),
                'total' => Message::count(),
                'without_organization' => Message::whereNull('organization_id')->count(),
            ],
            'loops' => [
                'label' => 'Boucles',
                'description' => 'Espaces collaboratifs internes à une organisation.',
                'query' => fn () => Loop::query(),
                'total' => Loop::count(),
                'without_organization' => Loop::whereNull('organization_id')->count(),
            ],
            'loop_members' => [
                'label' => 'Membres de boucles',
                'description' => 'Rattachements utilisateurs ↔ boucles.',
                'query' => fn () => LoopMember::query(),
                'total' => LoopMember::count(),
                'without_organization' => LoopMember::whereNull('organization_id')->count(),
            ],
            'loop_messages' => [
                'label' => 'Messages de boucles',
                'description' => 'Messages ChatLoop.',
                'query' => fn () => LoopMessage::query(),
                'total' => LoopMessage::count(),
                'without_organization' => LoopMessage::whereNull('organization_id')->count(),
            ],
            'blog_posts' => [
                'label' => 'Articles de blog',
                'description' => 'Articles, brouillons et contenus publiés.',
                'query' => fn () => BlogPost::withTrashed(),
                'total' => BlogPost::withTrashed()->count(),
                'without_organization' => BlogPost::withTrashed()->whereNull('organization_id')->count(),
            ],
            'blog_comments' => [
                'label' => 'Commentaires blog',
                'description' => 'Commentaires rattachés aux articles.',
                'query' => fn () => DB::table('blog_comments'),
                'total' => DB::table('blog_comments')->count(),
                'without_organization' => DB::table('blog_comments')->whereNull('organization_id')->count(),
            ],
            'blog_post_tag' => [
                'label' => 'Tags des articles',
                'description' => 'Table pivot articles ↔ tags.',
                'query' => fn () => DB::table('blog_post_tag'),
                'total' => DB::table('blog_post_tag')->count(),
                'without_organization' => DB::table('blog_post_tag')->whereNull('organization_id')->count(),
            ],
            'reports' => [
                'label' => 'Signalements',
                'description' => 'Signalements utilisateurs.',
                'query' => fn () => Report::query(),
                'total' => Report::count(),
                'without_organization' => Report::whereNull('organization_id')->count(),
            ],
            'bug_reports' => [
                'label' => 'Bugs',
                'description' => 'Retours bugs envoyés depuis l’interface.',
                'query' => fn () => DB::table('bug_reports'),
                'total' => DB::table('bug_reports')->count(),
                'without_organization' => DB::table('bug_reports')->whereNull('organization_id')->count(),
            ],
            'referrals' => [
                'label' => 'Invitations',
                'description' => 'Parrainages et invitations.',
                'query' => fn () => Referral::withoutGlobalScopes(),
                'total' => Referral::withoutGlobalScopes()->count(),
                'without_organization' => Referral::withoutGlobalScopes()->whereNull('organization_id')->count(),
            ],
            'referral_rewards' => [
                'label' => 'Récompenses invitations',
                'description' => 'Points distribués via invitations.',
                'query' => fn () => ReferralReward::withoutGlobalScopes(),
                'total' => ReferralReward::withoutGlobalScopes()->count(),
                'without_organization' => ReferralReward::withoutGlobalScopes()->whereNull('organization_id')->count(),
            ],
            'point_ledger' => [
                'label' => 'Historique points',
                'description' => 'Lignes comptables de points.',
                'query' => fn () => PointLedger::query(),
                'total' => PointLedger::count(),
                'without_organization' => PointLedger::whereNull('organization_id')->count(),
            ],
            'categories' => [
                'label' => 'Catégories',
                'description' => 'Catégories B2C/B2B.',
                'query' => fn () => Category::query(),
                'total' => Category::count(),
                'without_organization' => Category::whereNull('organization_id')->count(),
            ],
            'skills' => [
                'label' => 'Compétences',
                'description' => 'Compétences rattachées aux catégories.',
                'query' => fn () => Skill::query(),
                'total' => Skill::count(),
                'without_organization' => Skill::whereNull('organization_id')->count(),
            ],
            'tags' => [
                'label' => 'Tags',
                'description' => 'Tags de services et d’articles.',
                'query' => fn () => DB::table('tags'),
                'total' => DB::table('tags')->count(),
                'without_organization' => DB::table('tags')->whereNull('organization_id')->count(),
            ],
            'service_skill' => [
                'label' => 'Services ↔ compétences',
                'description' => 'Table pivot services ↔ compétences.',
                'query' => fn () => DB::table('service_skill'),
                'total' => DB::table('service_skill')->count(),
                'without_organization' => DB::table('service_skill')->whereNull('organization_id')->count(),
            ],
            'service_tag' => [
                'label' => 'Services ↔ tags',
                'description' => 'Table pivot services ↔ tags.',
                'query' => fn () => DB::table('service_tag'),
                'total' => DB::table('service_tag')->count(),
                'without_organization' => DB::table('service_tag')->whereNull('organization_id')->count(),
            ],
            'service_images' => [
                'label' => 'Images services',
                'description' => 'Images rattachées aux services.',
                'query' => fn () => DB::table('service_images'),
                'total' => DB::table('service_images')->count(),
                'without_organization' => DB::table('service_images')->whereNull('organization_id')->count(),
            ],
            'request_attachments' => [
                'label' => 'Pièces jointes demandes',
                'description' => 'Fichiers rattachés aux demandes.',
                'query' => fn () => DB::table('request_attachments'),
                'total' => DB::table('request_attachments')->count(),
                'without_organization' => DB::table('request_attachments')->whereNull('organization_id')->count(),
            ],
            'reviews' => [
                'label' => 'Avis',
                'description' => 'Avis liés aux transactions.',
                'query' => fn () => DB::table('reviews'),
                'total' => DB::table('reviews')->count(),
                'without_organization' => DB::table('reviews')->whereNull('organization_id')->count(),
            ],
            'favorites' => [
                'label' => 'Favoris',
                'description' => 'Favoris utilisateurs sur services.',
                'query' => fn () => DB::table('favorites'),
                'total' => DB::table('favorites')->count(),
                'without_organization' => DB::table('favorites')->whereNull('organization_id')->count(),
            ],
            'likes' => [
                'label' => 'Likes',
                'description' => 'Likes sur contenus.',
                'query' => fn () => DB::table('likes'),
                'total' => DB::table('likes')->count(),
                'without_organization' => DB::table('likes')->whereNull('organization_id')->count(),
            ],
            'email_templates' => [
                'label' => 'Templates email',
                'description' => 'Templates transactionnels.',
                'query' => fn () => DB::table('email_templates'),
                'total' => DB::table('email_templates')->count(),
                'without_organization' => DB::table('email_templates')->whereNull('organization_id')->count(),
            ],
            'email_logs' => [
                'label' => 'Logs email',
                'description' => 'Historique des emails envoyés.',
                'query' => fn () => DB::table('email_logs'),
                'total' => DB::table('email_logs')->count(),
                'without_organization' => DB::table('email_logs')->whereNull('organization_id')->count(),
            ],
        ];
    }

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
