<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Country;
use App\Models\EmailLog;
use App\Models\LoginLog;
use App\Models\Organization;
use App\Models\PointLedger;
use App\Models\Report;
use App\Models\RequestAttachment;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Skill;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Admin\PlatformDashboardMetrics;
use App\Services\UserDataLifecycleRegistry;
use App\Services\Users\Exceptions\UserDeletionBlockedException;
use App\Services\Users\UserDeletionExecutor;
use App\Support\Tenancy\DefaultOrganizationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminController extends Controller
{
    /**
     * TASK-1507 — le tableau de bord SuperAdmin, toutes Organizations
     * confondues. Les indicateurs demandes par Cyril (depense IA, Shell
     * Welcome, connexions jour/semaine/mois, comptes qui se connectent le
     * plus, interactions IA) viennent d'un service unique, miroir plateforme
     * de `OrganizationDashboardMetrics` (TASK-1504).
     */
    public function dashboard(PlatformDashboardMetrics $metrics): View
    {
        $recentUsers = User::with('organization')->latest()->limit(5)->get();
        $pendingReports = Report::with('reporter')->where('status', 'pending')->latest('created_at')->limit(10)->get();

        return view('admin.dashboard', [
            'metrics' => $metrics->get(),
            'recentUsers' => $recentUsers,
            'pendingReports' => $pendingReports,
        ]);
    }

    // ── Users ────────────────────────────────────────────────────────────────

    public function users(Request $request): View
    {
        $query = User::with(['organization'])->withCount(['services', 'buyerTransactions', 'sellerTransactions', 'reviewsReceived']);

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('first_name', 'like', '%'.$request->search.'%')
                    ->orWhere('name', 'like', '%'.$request->search.'%')
                    ->orWhere('email', 'like', '%'.$request->search.'%');
            });
        }

        if ($request->filled('status')) {
            match ($request->status) {
                'banned' => $query->whereNotNull('banned_at'),
                'admin' => $query->where('is_admin', true),
                'available' => $query->where('is_available', true)->whereNull('banned_at'),
                default => null,
            };
        }

        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->organization_id);
        }

        $direction = $request->direction === 'asc' ? 'asc' : 'desc';

        match ($request->sort) {
            'name' => $query->orderBy('first_name', $direction)->orderBy('name', $direction),
            'email' => $query->orderBy('email', $direction),
            'created_at' => $query->orderBy('created_at', $direction),
            'points_balance' => $query->orderBy('points_balance', $direction),
            'services_count' => $query->orderBy('services_count', $direction),
            'exchange_count' => $query->orderByRaw(
                '(SELECT COUNT(*) FROM transactions WHERE buyer_id = users.id OR seller_id = users.id) '.$direction
            ),
            'rating' => $query->orderBy('rating', $direction),
            'status' => $query->orderByRaw('banned_at IS NULL '.($direction === 'asc' ? 'ASC' : 'DESC').', banned_at '.$direction),
            'organization_id' => $query->orderBy(
                Organization::select('name')->whereColumn('id', 'users.organization_id')->limit(1),
                $direction
            ),
            default => $query->latest(),
        };

        $users = $query->paginate(20)->withQueryString();
        $organizations = Organization::where('is_active', true)->orderBy('name')->get();

        return view('admin.users', compact('users', 'organizations') + [
            'stats' => $this->userListStats(),
        ]);
    }

    /**
     * TASK-1640 — les compteurs du bandeau de la liste, en UNE requete.
     *
     * Des agregats conditionnels plutot que cinq `count()` : la page est un
     * cockpit, pas un rapport, et le test de cout de `/admin/users` mesure
     * justement que cette page ne grandit pas en requetes quand le nombre de
     * comptes augmente.
     *
     * Les compteurs portent sur la POPULATION ENTIERE, pas sur le filtre courant.
     * Un compteur qui bougerait avec les filtres ne dirait plus « combien de
     * comptes bannis existe-t-il » mais « combien en vois-je », ce qui n'est pas
     * la question posee par un cockpit. Le total filtre, lui, est rendu par le
     * paginateur a cote du tableau.
     *
     * @return array<string, int>
     */
    private function userListStats(): array
    {
        // Deux pieges de moteur evites ici, tous deux deja payes sur ce depot :
        //  - `count(*) FILTER (...)` est du PostgreSQL pur ;
        //  - `is_admin = 1` passe en SQLite (entier) et ECHOUE en PostgreSQL, ou
        //    la colonne est un vrai `boolean`.
        // Le predicat NU (`case when is_admin then ...`) est valide dans les deux.
        $row = User::query()->toBase()->selectRaw(
            'count(*) as total,'
            .' sum(case when banned_at is null and is_available then 1 else 0 end) as disponibles,'
            .' sum(case when banned_at is not null then 1 else 0 end) as bannis,'
            .' sum(case when is_admin then 1 else 0 end) as admins,'
            .' sum(case when created_at >= ? then 1 else 0 end) as nouveaux',
            [now()->startOfMonth()]
        )->first();

        return [
            'total' => (int) $row->total,
            'disponibles' => (int) $row->disponibles,
            'bannis' => (int) $row->bannis,
            'admins' => (int) $row->admins,
            'nouveaux' => (int) $row->nouveaux,
        ];
    }

    public function editUser(User $user): View
    {
        $organizations = Organization::where('is_active', true)->orderBy('name')->get();

        $userOrg = $user->organization;
        $localeColumn = app()->getLocale() === 'en' ? 'name_en' : 'name_fr';

        if ($userOrg) {
            $priorityCodes = $userOrg->priorityCountries()->where('active', true)->pluck('code');
            $priorityCountries = Country::whereIn('code', $priorityCodes)->where('active', true)->get();
            $otherCountries = Country::where('active', true)
                ->whereNotIn('code', $priorityCodes)
                ->orderBy($localeColumn)
                ->get();
            $countries = $priorityCountries->concat($otherCountries);
        } else {
            $countries = Country::where('active', true)->orderBy($localeColumn)->get();
        }

        return view('admin.users.edit', compact('user', 'organizations', 'countries'));
    }

    public function updateUser(Request $request, User $user): RedirectResponse
    {
        $organization = $this->resolveOrganizationFromInput($request->input('organization_id'));

        $data = $request->validate([
            'first_name' => 'nullable|string|max:255',
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,'.$user->id,
            'phone' => 'nullable|string|max:30',
            'bio' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:255',
            'country_code' => ['nullable', 'string', 'size:2', Rule::exists('countries', 'code')->where('active', true)],
            'preferred_locale' => ['nullable', 'string', Rule::in(['fr', 'en'])],
            'address_line1' => 'nullable|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:30',
            'website' => 'nullable|url|max:255',
            'linkedin_url' => 'nullable|url|max:255',
            'organization_id' => 'nullable|uuid|exists:organizations,id',
            'avatar' => 'nullable|image|mimes:jpeg,png,webp|max:2048',
            'is_available' => 'boolean',
            'is_admin' => 'boolean',
            'banned' => 'boolean',
        ]);

        $update = [
            'first_name' => $data['first_name'] ?? null,
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'bio' => $data['bio'] ?? null,
            'city' => $data['city'] ?? null,
            'country_code' => $data['country_code'] ?? null,
            'preferred_locale' => $data['preferred_locale'] ?? null,
            'address_line1' => $data['address_line1'] ?? null,
            'address_line2' => $data['address_line2'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'website' => $data['website'] ?? null,
            'linkedin_url' => $data['linkedin_url'] ?? null,
            'organization_id' => $organization->id,
            'is_available' => $request->boolean('is_available'),
        ];

        if ($organization->membership_enabled) {
            $request->validate(['membership_value' => 'nullable|string|max:255']);
            $update['membership_value'] = $request->input('membership_value');
        }

        if ($request->hasFile('avatar')) {
            if ($user->avatar) {
                Storage::disk('public')->delete($user->avatar);
            }
            $update['avatar'] = $request->file('avatar')->store('avatars', 'public');
        }

        if ($user->id !== auth()->id()) {
            $update['is_admin'] = $request->boolean('is_admin');
            $update['banned_at'] = $request->boolean('banned') ? ($user->banned_at ?? now()) : null;
        }

        $user->update($update);

        return back()->with('success', "Profil de {$user->name} mis à jour.");
    }

    public function banUser(User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Vous ne pouvez pas vous bannir vous-même.');
        }
        $user->update(['banned_at' => now()]);

        return back()->with('success', 'Utilisateur banni.');
    }

    public function unbanUser(User $user): RedirectResponse
    {
        $user->update(['banned_at' => null]);

        return back()->with('success', 'Utilisateur débanni.');
    }

    public function toggleUserAvailability(User $user): RedirectResponse
    {
        $user->update(['is_available' => ! $user->is_available]);

        return back()->with('success', 'Disponibilité modifiée.');
    }

    public function toggleUserAdmin(User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Vous ne pouvez pas modifier vos propres droits admin.');
        }
        $user->update(['is_admin' => ! $user->is_admin]);

        return back()->with('success', 'Droits admin modifiés.');
    }

    public function adjustPoints(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'delta' => 'required|integer|not_in:0',
            'reason' => 'nullable|string|max:255',
        ]);

        DB::transaction(function () use ($user, $data) {
            PointLedger::create([
                'user_id' => $user->id,
                'delta' => $data['delta'],
                'organization_id' => $user->organization_id,
                'reason' => 'adjustment',
            ]);
            $user->increment('points_balance', $data['delta']);
        });

        $sign = $data['delta'] > 0 ? '+' : '';

        return back()->with('success', "Solde ajusté de {$sign}{$data['delta']} pts pour {$user->name}.");
    }

    public function createUser(): View
    {
        return view('admin.users.create');
    }

    public function storeUser(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'is_admin' => 'boolean',
            'points' => 'required|integer|min:0',
        ]);

        $organization = $this->resolveOrganizationFromInput();

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'organization_id' => $organization->id,
            'is_admin' => $data['is_admin'] ?? false,
            'points_balance' => $data['points'],
        ]);

        if ($data['points'] > 0) {
            PointLedger::create([
                'user_id' => $user->id,
                'delta' => $data['points'],
                'organization_id' => $user->organization_id,
                'reason' => 'welcome_bonus',
            ]);
        }

        return redirect()->route('admin.users')->with('success', "Utilisateur {$user->name} créé avec succès.");
    }

    public function changePassword(Request $request, User $user): RedirectResponse
    {
        $request->validate([
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user->update(['password' => Hash::make($request->password)]);

        return back()->with('success', "Mot de passe de {$user->name} modifié.");
    }

    public function sendPasswordResetLink(User $user): RedirectResponse
    {
        $status = Password::broker()->sendResetLink(['email' => $user->email]);

        if ($status === Password::RESET_LINK_SENT) {
            EmailLog::create([
                'template_id' => null,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'to_email' => $user->email,
                'subject' => 'Réinitialisation de votre mot de passe',
                'status' => 'sent',
                'data' => [
                    'source' => 'admin-password-reset',
                    'broker' => 'users',
                    'admin_id' => auth()->id(),
                ],
            ]);
        }

        return match ($status) {
            Password::RESET_LINK_SENT => back()->with('success', 'Lien de réinitialisation envoyé.'),
            Password::RESET_THROTTLED => back()->with('error', 'Un lien a déjà été envoyé récemment. Réessayez dans quelques instants.'),
            default => back()->with('error', 'Impossible d\'envoyer le lien de réinitialisation.'),
        };
    }

    public function assignOrganization(Request $request, User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Vous ne pouvez pas vous affecter vous-même.');
        }

        $data = $request->validate([
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
        ]);

        $organization = $this->resolveOrganizationFromInput($data['organization_id'] ?? null);

        $user->update([
            'organization_id' => $organization->id,
        ]);

        return back()->with('success', "{$user->name} affecté à l'organisation {$organization->name}.");
    }

    public function loginAsUser(User $user): RedirectResponse
    {
        if ($user->banned_at) {
            return back()->with('error', 'Impossible de se connecter sous un utilisateur banni.');
        }

        if ($user->is_admin) {
            return back()->with('error', 'Impossible de se connecter sous un autre administrateur.');
        }

        session()->put('admin_original_id', auth()->id());

        auth()->login($user);
        session()->regenerate();

        return redirect('/');
    }

    public function backToAdmin(): RedirectResponse
    {
        $originalAdminId = session()->pull('admin_original_id');

        if (! $originalAdminId) {
            return redirect('/')->with('error', 'Aucune session admin précédente trouvée.');
        }

        $admin = User::find($originalAdminId);

        if (! $admin) {
            return redirect('/')->with('error', 'Compte admin introuvable.');
        }

        auth()->login($admin);
        session()->regenerate();

        return redirect()->route('admin.users');
    }

    private function resolveOrganizationFromInput(?string $organizationId = null): Organization
    {
        if ($organizationId) {
            $organization = Organization::withTrashed()->find($organizationId);

            if ($organization) {
                return $organization;
            }
        }

        $organization = DefaultOrganizationResolver::resolve();

        if ($organization) {
            return $organization;
        }

        throw ValidationException::withMessages([
            'organization_id' => 'Aucune organisation par défaut active n\'est disponible.',
        ]);
    }

    private function adminOrganizations(): Collection
    {
        return Organization::orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'is_default']);
    }

    private function selectedAdminOrganizationId(Request $request): string
    {
        if ($request->input('organization_id') === 'all') {
            return 'all';
        }

        if ($request->filled('organization_id')) {
            return (string) $request->input('organization_id');
        }

        return (string) (DefaultOrganizationResolver::resolve()?->getKey() ?? 'all');
    }

    private function applyAdminOrganizationFilter($query, string $organizationId): void
    {
        if ($organizationId !== 'all') {
            $query->where('organization_id', $organizationId);
        }
    }

    // ── Services ─────────────────────────────────────────────────────────────

    public function services(Request $request): View
    {
        $query = Service::withTrashed()->withoutGlobalScope(BelongsToOrganizationScope::class)->with(['user', 'category', 'organization']);
        $organizations = $this->adminOrganizations();
        $selectedOrganizationId = $this->selectedAdminOrganizationId($request);

        $this->applyAdminOrganizationFilter($query, $selectedOrganizationId);

        if ($request->filled('search')) {
            $query->where('title', 'like', '%'.$request->search.'%');
        }

        if ($request->filled('status')) {
            match ($request->status) {
                'active' => $query->where('status', 'active')->whereNull('deleted_at'),
                'paused' => $query->where('status', 'paused')->whereNull('deleted_at'),
                'deleted' => $query->onlyTrashed(),
                default => null,
            };
        }

        $services = $query->latest()->paginate(25)->withQueryString();

        return view('admin.services', compact('organizations', 'selectedOrganizationId', 'services'));
    }

    public function editService(string $service): View
    {
        $service = Service::withTrashed()->withoutGlobalScope(BelongsToOrganizationScope::class)->findOrFail($service);
        $this->authorizeServiceEdit($service);

        $service->load(['category', 'skills', 'tags']);
        $organizations = Organization::orderBy('name')->get(['id', 'name', 'slug']);
        $categories = Category::where('organization_id', $service->organization_id)->orderBy('name_b2c')->get();
        $skills = Skill::with('category')->where('organization_id', $service->organization_id)->orderBy('name')->get();
        $users = User::assignable()->orderBy('first_name')->orderBy('name')->get(['id', 'first_name', 'name', 'email']);

        return view('admin.services.edit', compact('service', 'organizations', 'categories', 'skills', 'users'));
    }

    public function updateService(Request $request, string $service): RedirectResponse
    {
        $service = Service::withTrashed()->withoutGlobalScope(BelongsToOrganizationScope::class)->findOrFail($service);
        $this->authorizeServiceEdit($service);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'category_id' => 'required|uuid|exists:categories,id',
            'delivery_mode' => 'required|in:remote,onsite,both',
            'points_cost' => 'required|integer|min:1',
            'status' => 'required|in:active,paused',
            'user_id' => ['required', 'uuid', Rule::exists('users', 'id')->whereNull('banned_at')],
            'organization_id' => 'required|uuid|exists:organizations,id',
            'skills' => 'nullable|array',
            'skills.*' => 'uuid|exists:skills,id',
            'tags' => 'nullable|string',
        ]);

        $serviceOwner = User::assignable()->find($data['user_id']);
        if (! $serviceOwner || $serviceOwner->organization_id !== $data['organization_id']) {
            throw ValidationException::withMessages([
                'user_id' => __('validation.exists', ['attribute' => 'user']),
            ]);
        }

        $service->update([
            'title' => $data['title'],
            'description' => $data['description'],
            'category_id' => $data['category_id'],
            'delivery_mode' => $data['delivery_mode'],
            'points_cost' => $data['points_cost'],
            'status' => $data['status'],
            'user_id' => $data['user_id'],
            'organization_id' => $data['organization_id'],
        ]);

        $service->skills()->syncWithPivotValues($data['skills'] ?? [], ['organization_id' => $data['organization_id']]);

        if (isset($data['tags'])) {
            $tagIds = [];
            foreach (array_slice(array_filter(array_map('trim', explode(',', $data['tags']))), 0, 5) as $name) {
                $slug = Str::slug($name);
                if ($slug) {
                    $tagIds[] = Tag::firstOrCreate(['slug' => $slug, 'organization_id' => $data['organization_id']], ['name' => $name, 'slug' => $slug])->id;
                }
            }
            $service->tags()->syncWithPivotValues($tagIds, ['organization_id' => $data['organization_id']]);
        }

        return redirect()->route('admin.services')->with('success', "Service « {$service->title} » modifié.");
    }

    private function authorizeServiceEdit(Service $service): void
    {
        $user = auth()->user();
        if ($user->is_admin) {
            return; // super-admin : accès total
        }
        // admin d'une communauté : seulement les services de sa communauté
        $organization = Organization::where('admin_id', $user->id)->first();
        if (! $organization || $service->organization_id !== $organization->id) {
            abort(403);
        }
    }

    public function forceDeleteService(string $id): RedirectResponse
    {
        $service = Service::withTrashed()->withoutGlobalScope(BelongsToOrganizationScope::class)->findOrFail($id);
        $service->forceDelete();

        return back()->with('success', 'Service définitivement supprimé.');
    }

    public function restoreService(string $id): RedirectResponse
    {
        $service = Service::withTrashed()->withoutGlobalScope(BelongsToOrganizationScope::class)->findOrFail($id);
        $service->restore();
        $service->update(['status' => 'active']);

        return back()->with('success', 'Service restauré.');
    }

    // ── Transactions ──────────────────────────────────────────────────────────

    public function transactions(Request $request): View
    {
        $query = Transaction::withoutGlobalScope(BelongsToOrganizationScope::class)->with(['buyer', 'seller', 'service', 'serviceRequest', 'organization']);
        $organizations = $this->adminOrganizations();
        $selectedOrganizationId = $this->selectedAdminOrganizationId($request);

        $this->applyAdminOrganizationFilter($query, $selectedOrganizationId);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->whereHas('buyer', fn ($u) => $u->where('name', 'like', '%'.$request->search.'%'))
                    ->orWhereHas('seller', fn ($u) => $u->where('name', 'like', '%'.$request->search.'%'));
            });
        }

        $transactions = $query->latest()->paginate(25)->withQueryString();

        return view('admin.transactions', compact('organizations', 'selectedOrganizationId', 'transactions'));
    }

    // ── Requests ──────────────────────────────────────────────────────────────

    public function requests(Request $request): View
    {
        $query = ServiceRequest::withoutGlobalScope(BelongsToOrganizationScope::class)->with(['user', 'category', 'organization']);
        $organizations = $this->adminOrganizations();
        $selectedOrganizationId = $this->selectedAdminOrganizationId($request);

        $this->applyAdminOrganizationFilter($query, $selectedOrganizationId);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where('title', 'like', '%'.$request->search.'%');
        }

        $requests = $query->latest()->paginate(25)->withQueryString();

        return view('admin.requests', compact('organizations', 'selectedOrganizationId', 'requests'));
    }

    public function editRequest(string $serviceRequest): View
    {
        $serviceRequest = ServiceRequest::withoutGlobalScope(BelongsToOrganizationScope::class)->findOrFail($serviceRequest);
        $this->authorizeRequestEdit($serviceRequest);

        $serviceRequest->load('attachments');
        $categories = Category::orderBy('name_b2c')->get();

        return view('admin.requests.edit', compact('serviceRequest', 'categories'));
    }

    public function updateRequest(Request $request, string $serviceRequest): RedirectResponse
    {
        $serviceRequest = ServiceRequest::withoutGlobalScope(BelongsToOrganizationScope::class)->findOrFail($serviceRequest);
        $this->authorizeRequestEdit($serviceRequest);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'category_id' => 'required|uuid|exists:categories,id',
            'delivery_mode' => 'required|in:remote,onsite,both',
            'budget_min' => 'required|integer|min:1',
            'budget_max' => 'nullable|integer|gte:budget_min',
            'deadline' => 'nullable|date',
            'status' => 'required|in:open,in_progress,closed',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx|max:10240',
            'delete_attachments' => 'nullable|array',
            'delete_attachments.*' => 'uuid|exists:request_attachments,id',
        ]);

        $serviceRequest->update([
            'title' => $data['title'],
            'description' => $data['description'],
            'category_id' => $data['category_id'],
            'delivery_mode' => $data['delivery_mode'],
            'budget_min' => $data['budget_min'],
            'budget_max' => $data['budget_max'] ?? null,
            'deadline' => $data['deadline'] ?? null,
            'status' => $data['status'],
        ]);

        if (! empty($data['delete_attachments'])) {
            $toDelete = RequestAttachment::whereIn('id', $data['delete_attachments'])
                ->where('service_request_id', $serviceRequest->id)
                ->get();
            foreach ($toDelete as $att) {
                Storage::disk('public')->delete($att->path);
                $att->delete();
            }
        }

        if ($request->hasFile('attachments')) {
            $currentCount = $serviceRequest->attachments()->count();
            foreach ($request->file('attachments') as $index => $file) {
                $path = $file->store('request-attachments', 'public');
                $serviceRequest->attachments()->create([
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'order' => $currentCount + $index,
                    'organization_id' => $serviceRequest->organization_id,
                ]);
            }
        }

        return redirect()->route('admin.requests')->with('success', "Demande « {$serviceRequest->title} » modifiée.");
    }

    private function authorizeRequestEdit(ServiceRequest $serviceRequest): void
    {
        $user = auth()->user();
        if ($user->is_admin) {
            return;
        }
        $organization = Organization::where('admin_id', $user->id)->first();
        if (! $organization || $serviceRequest->organization_id !== $organization->id) {
            abort(403);
        }
    }

    public function closeRequest(string $serviceRequest): RedirectResponse
    {
        $serviceRequest = ServiceRequest::withoutGlobalScope(BelongsToOrganizationScope::class)->findOrFail($serviceRequest);
        $serviceRequest->update(['status' => 'closed']);

        return back()->with('success', 'Demande clôturée.');
    }

    public function destroyTransaction(string $transactionId): RedirectResponse
    {
        $transaction = Transaction::withoutGlobalScope(BelongsToOrganizationScope::class)->findOrFail($transactionId);
        PointLedger::where('transaction_id', $transaction->id)->update(['transaction_id' => null]);
        $transaction->delete();

        return back()->with('success', __('admin.transaction_deleted'));
    }

    public function destroyRequest(string $requestId): RedirectResponse
    {
        $serviceRequest = ServiceRequest::withoutGlobalScope(BelongsToOrganizationScope::class)->findOrFail($requestId);
        foreach ($serviceRequest->transactions as $transaction) {
            PointLedger::where('transaction_id', $transaction->id)->update(['transaction_id' => null]);
            $transaction->delete();
        }
        $serviceRequest->delete();

        return back()->with('success', __('admin.request_deleted'));
    }

    // ── Categories ────────────────────────────────────────────────────────────

    public function categories(): View
    {
        $categories = Category::withCount(['services', 'skills', 'serviceRequests'])->with('skills')->get();

        return view('admin.categories', compact('categories'));
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'color' => 'required|string|regex:/^#[0-9a-fA-F]{6}$/',
        ]);
        $data['slug'] = Str::slug($data['name']);
        Category::create($data);

        return back()->with('success', 'Catégorie créée.');
    }

    public function updateCategory(Request $request, Category $category): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'color' => 'required|string|regex:/^#[0-9a-fA-F]{6}$/',
        ]);
        $data['slug'] = Str::slug($data['name']);
        $category->update($data);

        return back()->with('success', 'Catégorie mise à jour.');
    }

    public function destroyCategory(Category $category): RedirectResponse
    {
        if ($category->services()->withoutGlobalScope(BelongsToOrganizationScope::class)->count() > 0 || $category->serviceRequests()->withoutGlobalScope(BelongsToOrganizationScope::class)->count() > 0) {
            return back()->with('error', 'Impossible de supprimer une catégorie utilisée par des services ou demandes.');
        }
        $category->skills()->delete();
        $category->delete();

        return back()->with('success', 'Catégorie supprimée.');
    }

    public function storeSkill(Request $request, Category $category): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:100']);
        Skill::create([
            'category_id' => $category->id,
            'name' => $data['name'],
            'slug' => Str::slug($data['name']),
        ]);

        return back()->with('success', 'Compétence ajoutée.');
    }

    public function destroySkill(Skill $skill): RedirectResponse
    {
        $skill->delete();

        return back()->with('success', 'Compétence supprimée.');
    }

    // ── Reports ───────────────────────────────────────────────────────────────

    public function reports(): View
    {
        $reports = Report::with('reporter')->latest('created_at')->paginate(20);

        return view('admin.reports', compact('reports'));
    }

    public function dismissReport(Report $report): RedirectResponse
    {
        $report->update(['status' => 'dismissed']);

        return back()->with('success', 'Signalement classé.');
    }

    public function reviewReport(Report $report): RedirectResponse
    {
        $report->update(['status' => 'reviewed']);

        return back()->with('success', 'Signalement marqué comme traité.');
    }

    // ── Login history ─────────────────────────────────────────────────────────

    public function loginHistory(Request $request): View
    {
        $query = LoginLog::with(['user', 'organization']);

        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->organization_id);
        }

        if ($request->filled('search')) {
            $query->whereHas('user', function ($q) use ($request) {
                $q->where('name', 'like', '%'.$request->search.'%')
                    ->orWhere('email', 'like', '%'.$request->search.'%');
            });
        }

        $direction = $request->direction === 'asc' ? 'asc' : 'desc';

        match ($request->sort) {
            'user' => $query->orderBy(
                User::select('name')->whereColumn('id', 'login_logs.user_id')->limit(1),
                $direction
            ),
            'organization_id' => $query->orderBy(
                Organization::select('name')->whereColumn('id', 'login_logs.organization_id')->limit(1),
                $direction
            ),
            'ip_address' => $query->orderBy('ip_address', $direction),
            default => $query->latest('created_at'),
        };

        $loginLogs = $query->paginate(25)->withQueryString();
        $organizations = Organization::where('is_active', true)->orderBy('name')->get();

        return view('admin.login-history.index', compact('loginLogs', 'organizations'));
    }

    public function loginHistoryUser(Request $request, User $user): View
    {
        $logs = LoginLog::where('user_id', $user->id)
            ->with('organization')
            ->latest('created_at')
            ->paginate(25)
            ->withQueryString();

        return view('admin.login-history.user', compact('user', 'logs'));
    }

    // ── User deletion dry-run ─────────────────────────────────────────────────
    // ⚠️  Dry-run only — no data is ever deleted by these methods.

    public function deletePreview(User $user): View
    {
        $counts = $this->countUserRelations($user);

        $sameOrgUsers = User::where('organization_id', $user->organization_id)
            ->assignable()
            ->where('id', '!=', $user->id)
            ->orderBy('name')
            ->get();

        // TASK-1636 : ce que l'executeur refuserait, dit AVANT de proposer le
        // bouton definitif.
        $precheck = app(UserDeletionExecutor::class)->precheck($user);
        $previewFingerprint = $this->deletePreviewFingerprint($precheck);

        return view('admin.users.delete-preview', compact(
            'user', 'counts', 'sameOrgUsers', 'precheck', 'previewFingerprint'
        ));
    }

    /**
     * TASK-1636 — la suppression REELLE. SuperAdmin uniquement.
     *
     * Tout est revalide ici : le compte, la cible de transfert, et les refus.
     * L'executeur recontrole ensuite lui-meme sous verrou — ce controleur ne lui
     * fait pas gagner un raccourci.
     *
     * ## TASK-1640 — la recopie du nom a ete RETIREE
     *
     * Elle donnait l'illusion d'une garde : elle ne prouvait ni l'identite de
     * l'admin, ni la fraicheur de ce qu'il avait lu, ni que l'etat du compte
     * permettait la suppression. Elle coutait une frappe et ne protegeait rien
     * que les quatre gardes reelles ne couvrent deja :
     *
     *  1. l'authentification SuperAdmin (`AdminMiddleware`) ;
     *  2. une modal de confirmation explicite ;
     *  3. `preview_fingerprint`, qui refuse une decision prise sur un etat perime ;
     *  4. le recontrole autoritatif sous `lockForUpdate()` dans l'executeur.
     *
     * Le champ n'est pas seulement cache a l'ecran : l'exigence est retiree de la
     * validation. Cacher le champ en laissant la regle cote serveur aurait produit
     * un formulaire que le serveur refuse — le defaut symetrique de celui mesure
     * en TASK-1636, ou l'ecran demandait `fullName` et le serveur comparait `name`.
     */
    public function destroyUser(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'preview_fingerprint' => 'required|string',
            'transfer_to' => [
                'nullable',
                'uuid',
                Rule::exists('users', 'id')
                    ->where('organization_id', $user->organization_id)
                    ->whereNull('banned_at'),
            ],
        ]);

        $executor = app(UserDeletionExecutor::class);

        // Fraicheur : on ne lit AUCUN compteur venu du navigateur. On recalcule
        // l'etat et on compare deux empreintes. Si la situation a bouge depuis
        // l'ecran, l'admin doit revoir ce qu'il signe.
        if ($this->deletePreviewFingerprint($executor->precheck($user)) !== $data['preview_fingerprint']) {
            return redirect()
                ->route('admin.users.delete-preview', $user)
                ->with('error', __('admin.user_delete.stale'));
        }

        $name = $user->fullName;

        try {
            $report = $executor->execute($user, $data['transfer_to'] ?? null);
        } catch (UserDeletionBlockedException $blocked) {
            return redirect()
                ->route('admin.users.delete-preview', $user)
                ->with('error', implode(' ', array_column($blocked->blocks, 'message')));
        }

        $message = [__('admin.user_delete.done', ['name' => $name])];

        if (($transferred = array_sum($report['transferred'])) > 0) {
            $target = User::find($data['transfer_to']);
            $message[] = __('admin.user_delete.done_transferred', [
                'count' => $transferred,
                'target' => $target?->name ?? '',
            ]);
        }

        if (($purged = $report['dossiers']['dossiers'] ?? 0) > 0) {
            $message[] = __('admin.user_delete.done_dossiers', ['count' => $purged]);
        }

        if (($deleted = array_sum($report['deleted'])) > 0) {
            $message[] = __('admin.user_delete.done_deleted', ['count' => $deleted]);
        }

        return redirect()->route('admin.users')->with('success', implode(' ', $message));
    }

    /**
     * Empreinte de l'etat presente a l'admin.
     *
     * Elle ne transporte aucun compteur : le navigateur la rend telle quelle et
     * le serveur la RECALCULE pour comparer. Un simple hash suffit — il n'y a
     * rien a signer, puisque rien de ce qui revient n'est cru sur parole.
     *
     * @param  array{blocks: list<array{key: string, count: int, message: string}>, transferable: array<string, int>, requires_transfer: bool}  $precheck
     */
    /**
     * TASK-1640 — la fiche complete d'un membre, demandee AU CLIC.
     *
     * Lecture seule, bornee a UN compte, et volontairement composee de comptages
     * plutot que de listes : la question posee par cet ecran est « qu'est-ce que
     * cette personne FAIT sur la plateforme », pas « donne-moi ses contenus ».
     * Rendre les lignes elles-memes aurait fait passer du contenu personnel
     * (messages, prompts IA) dans une reponse d'administration.
     *
     * Les tables absentes du schema sont ignorees plutot que de faire echouer la
     * fiche : ce depot a des tables qui apparaissent par TASK, et une fiche qui
     * jette parce qu'une table n'existe pas encore serait un faux defaut.
     */
    public function userProfileSummary(User $user): JsonResponse
    {
        $compte = function (string $table, string $column) use ($user): int {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                return 0;
            }

            return DB::table($table)->where($column, $user->id)->count();
        };

        $ledgerIA = Schema::hasTable('ai_provider_invocations')
            ? DB::table('ai_provider_invocations')->where('user_id', $user->id)->selectRaw(
                'count(*) as appels, coalesce(sum(total_tokens), 0) as tokens, coalesce(sum(provider_cost), 0) as cout'
            )->first()
            : null;

        $derniereConnexion = Schema::hasTable('login_logs')
            ? DB::table('login_logs')->where('user_id', $user->id)->max('created_at')
            : null;

        return response()->json([
            'identite' => [
                'name' => $user->fullName,
                'email' => $user->email,
                'organization' => $user->organization?->name,
                'statut' => $user->banned_at !== null
                    ? __('admin.users_status_banned')
                    : ($user->is_available ? __('admin.users_status_available') : __('admin.users_status_unavailable')),
                'is_admin' => (bool) $user->is_admin,
                'points' => (int) $user->points_balance,
                'inscrit_le' => $user->created_at?->format('d/m/Y'),
                'derniere_connexion' => $derniereConnexion ? Carbon::parse($derniereConnexion)->format('d/m/Y H:i') : null,
                'note' => $user->rating ? number_format((float) $user->rating, 1).'/5' : null,
            ],
            'contributions' => [
                __('admin.users_profile_services') => $compte('services', 'user_id'),
                __('admin.users_profile_requests') => $compte('service_requests', 'user_id'),
                __('admin.users_profile_articles') => $compte('blog_posts', 'user_id'),
                __('admin.users_profile_feed') => $compte('feed_posts', 'user_id'),
                __('admin.users_profile_loops_created') => $compte('loops', 'created_by'),
                __('admin.users_profile_loop_messages') => $compte('loop_messages', 'sender_id'),
            ],
            'interactions' => [
                __('admin.users_profile_purchases') => $compte('transactions', 'buyer_id'),
                __('admin.users_profile_sales') => $compte('transactions', 'seller_id'),
                __('admin.users_profile_reviews_given') => $compte('reviews', 'reviewer_id'),
                __('admin.users_profile_reviews_received') => $compte('reviews', 'reviewed_id'),
                __('admin.users_profile_comments') => $compte('blog_comments', 'user_id') + $compte('feed_post_comments', 'user_id'),
                __('admin.users_profile_memberships') => $compte('loop_members', 'user_id'),
                __('admin.users_profile_votes') => $compte('loop_poll_votes', 'user_id'),
            ],
            'ia' => [
                'appels' => (int) ($ledgerIA->appels ?? 0),
                'tokens' => (int) ($ledgerIA->tokens ?? 0),
                // Le cout est un fait economique : on le rend tel quel, arrondi a
                // 4 decimales, sans le transformer en jugement.
                'cout' => round((float) ($ledgerIA->cout ?? 0), 4),
                'interactions' => $compte('ai_interactions', 'user_id'),
                'shell' => $compte('ai_shell_messages', 'user_id'),
                'retours' => $compte('ai_interaction_feedbacks', 'user_id'),
                'profil_ia' => $compte('member_ai_profiles', 'user_id') > 0,
            ],
        ]);
    }

    /**
     * TASK-1640 — ce que la modal de `/admin/users` demande AU CLIC.
     *
     * Lecture seule et bornee a UN compte. Rien n'est recalcule ici : ce sont
     * `UserDeletionExecutor::precheck()` et `deletePreviewFingerprint()`, deja
     * l'autorite, qui repondent. Aucune regle metier ne vit dans cette methode.
     *
     * Pourquoi une route dediee plutot que la liste : `precheck()` fait une
     * quinzaine de comptages par compte. Les calculer pour les 20 lignes d'une
     * page aurait ajoute ~300 requetes au rendu de `/admin/users` pour un clic
     * qui n'aura lieu qu'une fois.
     */
    public function userDeletePrecheck(User $user): JsonResponse
    {
        $precheck = app(UserDeletionExecutor::class)->precheck($user);

        // Les repreneurs possibles, dans la MEME Organization : c'est la borne
        // que `destroyUser()` revalide de son cote. L'ecran ne propose donc
        // jamais un choix que le serveur refusera.
        $candidats = User::query()
            ->where('organization_id', $user->organization_id)
            ->assignable()
            ->whereKeyNot($user->id)
            ->orderBy('first_name')
            ->orderBy('name')
            ->get(['id', 'first_name', 'name'])
            ->map(fn (User $candidat) => ['id' => $candidat->id, 'name' => $candidat->fullName])
            ->values();

        return response()->json([
            'user' => ['id' => $user->id, 'name' => $user->fullName],
            // Seuls le libelle et le compte sortent : la cle technique du blocage
            // reste cote serveur, l'ecran n'a rien a en faire.
            'blocks' => collect($precheck['blocks'])
                ->map(fn (array $block) => ['message' => $block['message'], 'count' => $block['count']])
                ->values(),
            'requires_transfer' => $precheck['requires_transfer'],
            'transfer_total' => array_sum($precheck['transferable']),
            'transfer_candidates' => $candidats,
            'preview_fingerprint' => $this->deletePreviewFingerprint($precheck),
        ]);
    }

    private function deletePreviewFingerprint(array $precheck): string
    {
        $blocks = collect($precheck['blocks'])
            ->map(fn (array $block) => $block['key'].':'.$block['count'])
            ->sort()
            ->values()
            ->all();

        $transferable = $precheck['transferable'];
        ksort($transferable);

        return hash('sha256', json_encode(['blocks' => $blocks, 'transferable' => $transferable]));
    }

    public function deleteUser(Request $request, User $user): View
    {
        $data = $request->validate([
            'confirmation' => 'required|string',
            'transfer_to' => [
                'nullable',
                'uuid',
                Rule::exists('users', 'id')
                    ->where('organization_id', $user->organization_id)
                    ->whereNull('banned_at'),
            ],
        ]);

        // La vue demande le nom COMPLET (`fullName`) : comparer a `name` seul
        // rendait tout compte portant un prenom impossible a confirmer en
        // suivant l'instruction affichee.
        if ($data['confirmation'] !== $user->fullName) {
            return $this->deletePreview($user);
        }

        $counts = $this->countUserRelations($user);

        if (! empty($data['transfer_to'])) {
            $counts['transfer'] = $this->estimateTransferCounts($user, $data['transfer_to']);
        }

        $counts['preview_only'] = true;

        $sameOrgUsers = User::where('organization_id', $user->organization_id)
            ->assignable()
            ->where('id', '!=', $user->id)
            ->orderBy('name')
            ->get();

        // TASK-1636 : la simulation prepare l'etape definitive — ce qui
        // bloquerait, et l'empreinte de l'etat sur lequel l'admin se prononce.
        $precheck = app(UserDeletionExecutor::class)->precheck($user);
        $previewFingerprint = $this->deletePreviewFingerprint($precheck);
        $transferTo = $data['transfer_to'] ?? null;

        return view('admin.users.delete-preview', compact(
            'user', 'counts', 'sameOrgUsers', 'precheck', 'previewFingerprint', 'transferTo'
        ));
    }

    private function countUserRelations(User $user): array
    {
        return app(UserDataLifecycleRegistry::class)->preview($user);
    }

    private function estimateTransferCounts(User $user, string $transferToId): array
    {
        return app(UserDataLifecycleRegistry::class)->transferEstimate($user, $transferToId);
    }
}
