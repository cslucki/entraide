<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiInteraction;
use App\Models\BlogComment;
use App\Models\BlogPost;
use App\Models\BugReport;
use App\Models\Category;
use App\Models\Country;
use App\Models\EmailLog;
use App\Models\Favorite;
use App\Models\FeedPost;
use App\Models\FeedPostComment;
use App\Models\LoginLog;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\MemberAiProfile;
use App\Models\Message;
use App\Models\Organization;
use App\Models\PointLedger;
use App\Models\Referral;
use App\Models\Report;
use App\Models\RequestAttachment;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Skill;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Tenancy\DefaultOrganizationResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function dashboard(): View
    {
        $stats = [
            'users' => User::count(),
            'banned' => User::whereNotNull('banned_at')->count(),
            'services' => Service::withoutGlobalScope(BelongsToOrganizationScope::class)->where('status', 'active')->count(),
            'transactions' => Transaction::withoutGlobalScope(BelongsToOrganizationScope::class)->count(),
            'completed' => Transaction::withoutGlobalScope(BelongsToOrganizationScope::class)->where('status', 'completed')->count(),
            'points' => User::sum('points_balance'),
            'reports' => Report::where('status', 'pending')->count(),
        ];

        $recentUsers = User::latest()->limit(5)->get();
        $pendingReports = Report::with('reporter')->where('status', 'pending')->latest('created_at')->limit(10)->get();

        return view('admin.dashboard', compact('stats', 'recentUsers', 'pendingReports'));
    }

    // ── Users ────────────────────────────────────────────────────────────────

    public function users(Request $request): View
    {
        $query = User::with(['organization'])->withCount(['services', 'buyerTransactions', 'sellerTransactions', 'reviewsReceived']);

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%'.$request->search.'%')
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
            'name' => $query->orderBy('name', $direction),
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

        return view('admin.users', compact('users', 'organizations'));
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
        $categories = Category::orderBy('name_b2c')->get();
        $skills = Skill::with('category')->orderBy('name')->get();

        return view('admin.services.edit', compact('service', 'categories', 'skills'));
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
            'skills' => 'nullable|array',
            'skills.*' => 'uuid|exists:skills,id',
            'tags' => 'nullable|string',
        ]);

        $service->update([
            'title' => $data['title'],
            'description' => $data['description'],
            'category_id' => $data['category_id'],
            'delivery_mode' => $data['delivery_mode'],
            'points_cost' => $data['points_cost'],
            'status' => $data['status'],
        ]);

        $service->skills()->syncWithPivotValues($data['skills'] ?? [], ['organization_id' => $service->organization_id]);

        if (isset($data['tags'])) {
            $tagIds = [];
            foreach (array_slice(array_filter(array_map('trim', explode(',', $data['tags']))), 0, 5) as $name) {
                $slug = Str::slug($name);
                if ($slug) {
                    $tagIds[] = Tag::firstOrCreate(['slug' => $slug, 'organization_id' => $service->organization_id], ['name' => $name, 'slug' => $slug])->id;
                }
            }
            $service->tags()->syncWithPivotValues($tagIds, ['organization_id' => $service->organization_id]);
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
            ->where('id', '!=', $user->id)
            ->orderBy('name')
            ->get();

        return view('admin.users.delete-preview', compact('user', 'counts', 'sameOrgUsers'));
    }

    public function deleteUser(Request $request, User $user): View
    {
        $data = $request->validate([
            'confirmation' => 'required|string',
            'transfer_to' => 'nullable|uuid|exists:users,id',
        ]);

        if ($data['confirmation'] !== $user->name) {
            return $this->deletePreview($user);
        }

        $counts = $this->countUserRelations($user);

        if (! empty($data['transfer_to'])) {
            $counts['transfer'] = $this->estimateTransferCounts($user, $data['transfer_to']);
        }

        $counts['preview_only'] = true;

        $sameOrgUsers = User::where('organization_id', $user->organization_id)
            ->where('id', '!=', $user->id)
            ->orderBy('name')
            ->get();

        return view('admin.users.delete-preview', compact('user', 'counts', 'sameOrgUsers'));
    }

    private function countUserRelations(User $user): array
    {
        $own = [
            'services' => Service::withoutGlobalScope(BelongsToOrganizationScope::class)
                ->where('user_id', $user->id)->count(),
            'service_requests' => ServiceRequest::withoutGlobalScope(BelongsToOrganizationScope::class)
                ->where('user_id', $user->id)->count(),
            'transactions_as_buyer' => Transaction::where('buyer_id', $user->id)->count(),
            'transactions_as_seller' => Transaction::where('seller_id', $user->id)->count(),
            'blog_posts' => BlogPost::withoutGlobalScope(BelongsToOrganizationScope::class)
                ->where('user_id', $user->id)->count(),
            'blog_comments' => BlogComment::withoutGlobalScope(BelongsToOrganizationScope::class)
                ->where('user_id', $user->id)->count(),
            'favorites' => Favorite::where('user_id', $user->id)->count(),
            'feed_posts' => FeedPost::withoutGlobalScope(BelongsToOrganizationScope::class)
                ->where('user_id', $user->id)->count(),
            'feed_post_comments' => FeedPostComment::withoutGlobalScope(BelongsToOrganizationScope::class)
                ->where('user_id', $user->id)->count(),
            'point_ledger' => PointLedger::where('user_id', $user->id)->count(),
            'member_ai_profile' => MemberAiProfile::where('user_id', $user->id)->count(),
        ];

        $part = [
            'loop_memberships' => LoopMember::where('user_id', $user->id)->count(),
            'orgs_as_admin' => Organization::where('admin_id', $user->id)->count(),
            'loops_created' => Loop::where('created_by', $user->id)->count(),
        ];

        $audit = [
            'messages_sent' => Message::where('sender_id', $user->id)->count(),
            'loop_messages_sent' => LoopMessage::where('sender_id', $user->id)->count(),
            'reports_filed' => Report::where('reporter_id', $user->id)->count(),
            'email_logs' => EmailLog::where('user_id', $user->id)->count(),
            'bug_reports' => BugReport::where('reporter_id', $user->id)->count(),
            'login_logs' => LoginLog::where('user_id', $user->id)->count(),
            'ai_interactions' => AiInteraction::where('user_id', $user->id)->count(),
            'referrals_made' => Referral::where('referrer_user_id', $user->id)->count(),
        ];

        return compact('own', 'part', 'audit');
    }

    private function estimateTransferCounts(User $user, string $transferToId): array
    {
        $transferTo = User::find($transferToId);
        if (! $transferTo || $transferTo->organization_id !== $user->organization_id) {
            return [];
        }

        return [
            'services' => Service::withoutGlobalScope(BelongsToOrganizationScope::class)
                ->where('user_id', $user->id)->count(),
            'service_requests' => ServiceRequest::withoutGlobalScope(BelongsToOrganizationScope::class)
                ->where('user_id', $user->id)->count(),
            'blog_posts' => BlogPost::withoutGlobalScope(BelongsToOrganizationScope::class)
                ->where('user_id', $user->id)->count(),
            'blog_comments' => BlogComment::withoutGlobalScope(BelongsToOrganizationScope::class)
                ->where('user_id', $user->id)->count(),
            'favorites' => Favorite::where('user_id', $user->id)->count(),
            'feed_posts' => FeedPost::withoutGlobalScope(BelongsToOrganizationScope::class)
                ->where('user_id', $user->id)->count(),
            'feed_post_comments' => FeedPostComment::withoutGlobalScope(BelongsToOrganizationScope::class)
                ->where('user_id', $user->id)->count(),
        ];
    }
}
