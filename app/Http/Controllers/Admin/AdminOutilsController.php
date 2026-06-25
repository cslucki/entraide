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
    private const SENSITIVE_COLUMNS = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'access_token',
        'api_token',
        'refresh_token',
        'auth_token',
        'session_token',
        'verification_token',
        'reset_token',
        'private_key',
        'secret',
    ];
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

    public function assignData(Request $request): View
    {
        $organizations = Organization::orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $datasets = $this->assignableDatasets();

        $selectedOrgId = $request->input('organization_id');
        if ($selectedOrgId && ! Organization::where('id', $selectedOrgId)->exists()) {
            $selectedOrgId = null;
        }

        $isGlobalView = is_null($selectedOrgId);

        $enriched = [];
        foreach ($datasets as $key => $ds) {
            $query = $ds['query']();
            $total = (clone $query)->count();
            $withoutOrg = (clone $query)->whereNull('organization_id')->count();

            $inOrg = 0;
            $otherOrg = 0;
            $withOrg = 0;
            if ($selectedOrgId) {
                $inOrg = (clone $query)->where('organization_id', $selectedOrgId)->count();
                $otherOrg = (clone $query)
                    ->whereNotNull('organization_id')
                    ->where('organization_id', '!=', $selectedOrgId)
                    ->count();
            } else {
                $withOrg = $total - $withoutOrg;
            }

            $enriched[$key] = [
                'label' => $ds['label'],
                'description' => $ds['description'],
                'mode' => $ds['mode'],
                'critical' => $ds['critical'],
                'total' => $total,
                'without_organization' => $withoutOrg,
                'in_org' => $inOrg,
                'other_orgs' => $otherOrg,
                'with_org' => $withOrg,
                'already_in_org' => $selectedOrgId ? ($inOrg === $total) : false,
            ];
        }

        return view('admin.outils.assign-data', compact('enriched', 'organizations', 'selectedOrgId', 'isGlobalView'));
    }

    public function doAssignData(Request $request): RedirectResponse
    {
        $datasets = $this->assignableDatasets();

        $assignableKeys = array_keys(array_filter($datasets, fn ($ds) => $ds['mode'] === 'assignable'));

        $rules = [
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'datasets' => ['required', 'array', 'min:1'],
            'datasets.*' => ['required', 'string', Rule::in($assignableKeys)],
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
            'filter' => ['nullable', 'string', 'in:in_org,other_orgs,without_org,with_org,all'],
        ]);

        $organizationId = $data['organization_id'] ?? null;
        $orgName = $organizationId ? Organization::find($organizationId)?->name : null;
        $filter = $data['filter'] ?? 'all';

        $previews = [];
        foreach ($data['datasets'] as $key) {
            $query = $datasets[$key]['query']();
            $total = (clone $query)->count();

            switch ($filter) {
                case 'in_org':
                    $query->where('organization_id', $organizationId);
                    break;
                case 'other_orgs':
                    $query->whereNotNull('organization_id')->where('organization_id', '!=', $organizationId);
                    break;
                case 'with_org':
                    $query->whereNotNull('organization_id');
                    break;
                case 'without_org':
                    $query->whereNull('organization_id');
                    break;
            }

            $count = (clone $query)->count();
            $rows = (clone $query)->limit(10)->get()->map(fn ($row) => $this->sanitizeRow($row));

            $inOrg = $organizationId ? (clone $datasets[$key]['query']())->where('organization_id', $organizationId)->count() : 0;
            $otherOrg = $organizationId ? (clone $datasets[$key]['query']())->whereNotNull('organization_id')->where('organization_id', '!=', $organizationId)->count() : 0;
            $withoutOrg = (clone $datasets[$key]['query']())->whereNull('organization_id')->count();

            $previews[$key] = [
                'label' => $datasets[$key]['label'],
                'mode' => $datasets[$key]['mode'],
                'critical' => $datasets[$key]['critical'],
                'total' => $total,
                'displayed' => $count,
                'filter' => $filter,
                'in_org' => $inOrg,
                'other_orgs' => $otherOrg,
                'without_organization' => $withoutOrg,
                'rows' => $rows,
            ];
        }

        return view('admin.outils.assign-data-detail', compact('previews', 'orgName', 'organizationId', 'filter'));
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

    private function sanitizeRow(mixed $row): array
    {
        if ($row instanceof \Illuminate\Database\Eloquent\Model) {
            $data = $row->toArray();
        } else {
            $data = (array) $row;
        }

        foreach (self::SENSITIVE_COLUMNS as $col) {
            unset($data[$col]);
        }

        return $data;
    }

    private function assignableDatasets(): array
    {
        return [
            'users' => [
                'label' => __('admin.outils.users_label'),
                'description' => __('admin.outils.assign_data_desc_users'),
                'query' => fn () => User::query(),
                'mode' => 'assignable',
                'critical' => true,
            ],
            'services' => [
                'label' => __('admin.outils.services_label'),
                'description' => __('admin.outils.assign_data_desc_services'),
                'query' => fn () => Service::withoutGlobalScopes(),
                'mode' => 'assignable',
                'critical' => false,
            ],
            'service_requests' => [
                'label' => __('admin.outils.demandes'),
                'description' => __('admin.outils.assign_data_desc_service_requests'),
                'query' => fn () => ServiceRequest::withoutGlobalScopes(),
                'mode' => 'assignable',
                'critical' => false,
            ],
            'transactions' => [
                'label' => __('admin.outils.transactions'),
                'description' => __('admin.outils.assign_data_desc_transactions'),
                'query' => fn () => Transaction::withoutGlobalScopes(),
                'mode' => 'assignable',
                'critical' => false,
            ],
            'messages' => [
                'label' => __('admin.outils.messages'),
                'description' => __('admin.outils.assign_data_desc_messages'),
                'query' => fn () => Message::query(),
                'mode' => 'assignable',
                'critical' => false,
            ],
            'loops' => [
                'label' => __('admin.outils.loops'),
                'description' => __('admin.outils.assign_data_desc_loops'),
                'query' => fn () => Loop::query(),
                'mode' => 'assignable',
                'critical' => false,
            ],
            'loop_members' => [
                'label' => __('admin.outils.loop_members'),
                'description' => __('admin.outils.assign_data_desc_loop_members'),
                'query' => fn () => LoopMember::query(),
                'mode' => 'assignable',
                'critical' => false,
            ],
            'loop_messages' => [
                'label' => __('admin.outils.loop_messages'),
                'description' => __('admin.outils.assign_data_desc_loop_messages'),
                'query' => fn () => LoopMessage::query(),
                'mode' => 'assignable',
                'critical' => false,
            ],
            'blog_posts' => [
                'label' => __('admin.outils.blog_posts'),
                'description' => __('admin.outils.assign_data_desc_blog_posts'),
                'query' => fn () => BlogPost::withTrashed(),
                'mode' => 'assignable',
                'critical' => false,
            ],
            'blog_comments' => [
                'label' => __('admin.outils.blog_comments'),
                'description' => __('admin.outils.assign_data_desc_blog_comments'),
                'query' => fn () => DB::table('blog_comments'),
                'mode' => 'assignable',
                'critical' => false,
            ],
            'blog_post_tag' => [
                'label' => __('admin.outils.blog_post_tags'),
                'description' => __('admin.outils.assign_data_desc_blog_post_tags'),
                'query' => fn () => DB::table('blog_post_tag'),
                'mode' => 'assignable',
                'critical' => false,
            ],
            'feed_posts' => [
                'label' => __('admin.outils.feed_posts'),
                'description' => __('admin.outils.assign_data_desc_feed_posts'),
                'query' => fn () => DB::table('feed_posts'),
                'mode' => 'assignable',
                'critical' => false,
            ],
            'feed_post_comments' => [
                'label' => __('admin.outils.feed_post_comments'),
                'description' => __('admin.outils.assign_data_desc_feed_post_comments'),
                'query' => fn () => DB::table('feed_post_comments'),
                'mode' => 'assignable',
                'critical' => false,
            ],
            'reports' => [
                'label' => __('admin.outils.reports'),
                'description' => __('admin.outils.assign_data_desc_reports'),
                'query' => fn () => Report::query(),
                'mode' => 'assignable',
                'critical' => false,
            ],
            'bug_reports' => [
                'label' => __('admin.outils.bug_reports'),
                'description' => __('admin.outils.assign_data_desc_bug_reports'),
                'query' => fn () => DB::table('bug_reports'),
                'mode' => 'assignable',
                'critical' => false,
            ],
            'referrals' => [
                'label' => __('admin.outils.referrals'),
                'description' => __('admin.outils.assign_data_desc_referrals'),
                'query' => fn () => Referral::withoutGlobalScopes(),
                'mode' => 'assignable',
                'critical' => false,
            ],
            'referral_rewards' => [
                'label' => __('admin.outils.referral_rewards'),
                'description' => __('admin.outils.assign_data_desc_referral_rewards'),
                'query' => fn () => ReferralReward::withoutGlobalScopes(),
                'mode' => 'assignable',
                'critical' => false,
            ],
            'point_ledger' => [
                'label' => __('admin.outils.point_ledger'),
                'description' => __('admin.outils.assign_data_desc_point_ledger'),
                'query' => fn () => PointLedger::query(),
                'mode' => 'assignable',
                'critical' => false,
            ],
            'point_guidelines' => [
                'label' => __('admin.outils.point_guidelines'),
                'description' => __('admin.outils.assign_data_desc_point_guidelines'),
                'query' => fn () => DB::table('point_guidelines'),
                'mode' => 'diagnostic',
                'critical' => false,
            ],
            'categories' => [
                'label' => __('admin.outils.categories'),
                'description' => __('admin.outils.assign_data_desc_categories'),
                'query' => fn () => Category::query(),
                'mode' => 'assignable',
                'critical' => false,
            ],
            'skills' => [
                'label' => __('admin.outils.skills'),
                'description' => __('admin.outils.assign_data_desc_skills'),
                'query' => fn () => Skill::query(),
                'mode' => 'assignable',
                'critical' => false,
            ],
            'tags' => [
                'label' => __('admin.outils.tags'),
                'description' => __('admin.outils.assign_data_desc_tags'),
                'query' => fn () => DB::table('tags'),
                'mode' => 'assignable',
                'critical' => false,
            ],
            'service_skill' => [
                'label' => __('admin.outils.services_skills'),
                'description' => __('admin.outils.assign_data_desc_services_skills'),
                'query' => fn () => DB::table('service_skill'),
                'mode' => 'assignable',
                'critical' => false,
            ],
            'service_tag' => [
                'label' => __('admin.outils.services_tags'),
                'description' => __('admin.outils.assign_data_desc_services_tags'),
                'query' => fn () => DB::table('service_tag'),
                'mode' => 'assignable',
                'critical' => false,
            ],
            'service_images' => [
                'label' => __('admin.outils.service_images'),
                'description' => __('admin.outils.assign_data_desc_service_images'),
                'query' => fn () => DB::table('service_images'),
                'mode' => 'assignable',
                'critical' => false,
            ],
            'request_attachments' => [
                'label' => __('admin.outils.request_attachments'),
                'description' => __('admin.outils.assign_data_desc_request_attachments'),
                'query' => fn () => DB::table('request_attachments'),
                'mode' => 'assignable',
                'critical' => false,
            ],
            'reviews' => [
                'label' => __('admin.outils.reviews'),
                'description' => __('admin.outils.assign_data_desc_reviews'),
                'query' => fn () => DB::table('reviews'),
                'mode' => 'assignable',
                'critical' => false,
            ],
            'favorites' => [
                'label' => __('admin.outils.favorites'),
                'description' => __('admin.outils.assign_data_desc_favorites'),
                'query' => fn () => DB::table('favorites'),
                'mode' => 'assignable',
                'critical' => false,
            ],
            'likes' => [
                'label' => __('admin.outils.likes'),
                'description' => __('admin.outils.assign_data_desc_likes'),
                'query' => fn () => DB::table('likes'),
                'mode' => 'assignable',
                'critical' => false,
            ],
            'badges' => [
                'label' => __('admin.outils.badges'),
                'description' => __('admin.outils.assign_data_desc_badges'),
                'query' => fn () => DB::table('badges'),
                'mode' => 'assignable',
                'critical' => false,
            ],
            'badge_user' => [
                'label' => __('admin.outils.badge_user'),
                'description' => __('admin.outils.assign_data_desc_badge_user'),
                'query' => fn () => DB::table('badge_user'),
                'mode' => 'assignable',
                'critical' => false,
            ],
            'reactions' => [
                'label' => __('admin.outils.reactions'),
                'description' => __('admin.outils.assign_data_desc_reactions'),
                'query' => fn () => DB::table('reactions'),
                'mode' => 'assignable',
                'critical' => false,
            ],
            'email_templates' => [
                'label' => __('admin.outils.email_templates'),
                'description' => __('admin.outils.assign_data_desc_email_templates'),
                'query' => fn () => DB::table('email_templates'),
                'mode' => 'assignable',
                'critical' => false,
            ],
            'email_logs' => [
                'label' => __('admin.outils.email_logs'),
                'description' => __('admin.outils.assign_data_desc_email_logs'),
                'query' => fn () => DB::table('email_logs'),
                'mode' => 'assignable',
                'critical' => false,
            ],
            'translation_overrides' => [
                'label' => __('admin.outils.translation_overrides'),
                'description' => __('admin.outils.assign_data_desc_translation_overrides'),
                'query' => fn () => DB::table('translation_overrides'),
                'mode' => 'diagnostic',
                'critical' => false,
            ],
            'admin_ai_interactions' => [
                'label' => __('admin.outils.admin_ai_interactions'),
                'description' => __('admin.outils.assign_data_desc_admin_ai_interactions'),
                'query' => fn () => DB::table('admin_ai_interactions'),
                'mode' => 'assignable',
                'critical' => false,
            ],
            'ai_interactions' => [
                'label' => __('admin.outils.ai_interactions'),
                'description' => __('admin.outils.assign_data_desc_ai_interactions'),
                'query' => fn () => DB::table('ai_interactions'),
                'mode' => 'assignable',
                'critical' => false,
            ],
            'blog_ai_configs' => [
                'label' => __('admin.outils.blog_ai_configs'),
                'description' => __('admin.outils.assign_data_desc_blog_ai_configs'),
                'query' => fn () => DB::table('blog_ai_configs'),
                'mode' => 'assignable',
                'critical' => false,
            ],
            'member_ai_profiles' => [
                'label' => __('admin.outils.member_ai_profiles'),
                'description' => __('admin.outils.assign_data_desc_member_ai_profiles'),
                'query' => fn () => DB::table('member_ai_profiles'),
                'mode' => 'assignable',
                'critical' => false,
            ],
            'member_ai_profile_interactions' => [
                'label' => __('admin.outils.member_ai_profile_interactions'),
                'description' => __('admin.outils.assign_data_desc_member_ai_profile_interactions'),
                'query' => fn () => DB::table('member_ai_profile_interactions'),
                'mode' => 'assignable',
                'critical' => false,
            ],
        ];
    }
}
