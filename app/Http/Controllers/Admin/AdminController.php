<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Community;
use App\Models\EmailLog;
use App\Models\PointLedger;
use App\Models\Report;
use App\Models\RequestAttachment;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Skill;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function dashboard(): View
    {
        $stats = [
            'users'        => User::count(),
            'banned'       => User::whereNotNull('banned_at')->count(),
            'services'     => Service::where('status', 'active')->count(),
            'transactions' => Transaction::count(),
            'completed'    => Transaction::where('status', 'completed')->count(),
            'points'       => User::sum('points_balance'),
            'reports'      => Report::where('status', 'pending')->count(),
        ];

        $recentUsers = User::latest()->limit(5)->get();
        $pendingReports = Report::with('reporter')->where('status', 'pending')->latest('created_at')->limit(10)->get();

        return view('admin.dashboard', compact('stats', 'recentUsers', 'pendingReports'));
    }

    // ── Users ────────────────────────────────────────────────────────────────

    public function users(Request $request): View
    {
        $query = User::with(['community'])->withCount(['services', 'buyerTransactions', 'sellerTransactions', 'reviewsReceived']);

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%'.$request->search.'%')
                  ->orWhere('email', 'like', '%'.$request->search.'%');
            });
        }

        if ($request->filled('status')) {
            match ($request->status) {
                'banned'    => $query->whereNotNull('banned_at'),
                'admin'     => $query->where('is_admin', true),
                'available' => $query->where('is_available', true)->whereNull('banned_at'),
                default     => null,
            };
        }

        $users = $query->latest()->paginate(20)->withQueryString();

        return view('admin.users', compact('users'));
    }

    public function editUser(User $user): View
    {
        $communities = Community::where('is_active', true)->orderBy('name')->get();
        return view('admin.users.edit', compact('user', 'communities'));
    }

    public function updateUser(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name'         => 'required|string|max:255',
            'email'        => 'required|email|max:255|unique:users,email,'.$user->id,
            'bio'          => 'nullable|string|max:500',
            'location'     => 'nullable|string|max:100',
            'website'      => 'nullable|url|max:255',
            'linkedin_url' => 'nullable|url|max:255',
            'community_id' => 'nullable|uuid|exists:communities,id',
            'is_available' => 'boolean',
            'is_admin'     => 'boolean',
            'banned'       => 'boolean',
        ]);

        $update = [
            'name'         => $data['name'],
            'email'        => $data['email'],
            'bio'          => $data['bio'] ?? null,
            'location'     => $data['location'] ?? null,
            'website'      => $data['website'] ?? null,
            'linkedin_url' => $data['linkedin_url'] ?? null,
            'community_id' => $data['community_id'] ?? null,
            'is_available' => $request->boolean('is_available'),
        ];

        if ($user->id !== auth()->id()) {
            $update['is_admin']  = $request->boolean('is_admin');
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
        $user->update(['is_available' => !$user->is_available]);
        return back()->with('success', 'Disponibilité modifiée.');
    }

    public function toggleUserAdmin(User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Vous ne pouvez pas modifier vos propres droits admin.');
        }
        $user->update(['is_admin' => !$user->is_admin]);
        return back()->with('success', 'Droits admin modifiés.');
    }

    public function adjustPoints(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'delta'  => 'required|integer|not_in:0',
            'reason' => 'nullable|string|max:255',
        ]);

        DB::transaction(function () use ($user, $data) {
            PointLedger::create([
                'user_id' => $user->id,
                'delta'   => $data['delta'],
                'reason'  => 'adjustment',
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
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'is_admin' => 'boolean',
            'points'   => 'required|integer|min:0',
        ]);

        $user = User::create([
            'name'           => $data['name'],
            'email'          => $data['email'],
            'password'       => Hash::make($data['password']),
            'is_admin'       => $data['is_admin'] ?? false,
            'points_balance' => $data['points'],
        ]);

        if ($data['points'] > 0) {
            PointLedger::create([
                'user_id' => $user->id,
                'delta'   => $data['points'],
                'reason'  => 'welcome_bonus',
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

    public function assignCommunity(Request $request, User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Vous ne pouvez pas vous affecter vous-même.');
        }

        $data = $request->validate([
            'community_id' => ['nullable', 'uuid', 'exists:communities,id'],
        ]);

        $community = $data['community_id']
            ? Community::withTrashed()->find($data['community_id'])
            : null;

        $user->update([
            'community_id' => $data['community_id'],
            'organization_id' => $data['community_id'],
        ]);

        if ($community) {
            return back()->with('success', "{$user->name} affecté à la communauté {$community->name}.");
        }

        return back()->with('success', "{$user->name} retiré de sa communauté (retour communauté globale).");
    }

    // ── Services ─────────────────────────────────────────────────────────────

    public function services(Request $request): View
    {
        $query = Service::withTrashed()->with(['user', 'category']);

        if ($request->filled('search')) {
            $query->where('title', 'like', '%'.$request->search.'%');
        }

        if ($request->filled('status')) {
            match ($request->status) {
                'active'   => $query->where('status', 'active')->whereNull('deleted_at'),
                'paused'   => $query->where('status', 'paused')->whereNull('deleted_at'),
                'deleted'  => $query->onlyTrashed(),
                default    => null,
            };
        }

        $services = $query->latest()->paginate(25)->withQueryString();

        return view('admin.services', compact('services'));
    }

    public function editService(Service $service): View
    {
        $this->authorizeServiceEdit($service);

        $service->load(['category', 'skills', 'tags']);
        $categories = Category::orderBy('name')->get();
        $skills = Skill::with('category')->orderBy('name')->get();

        return view('admin.services.edit', compact('service', 'categories', 'skills'));
    }

    public function updateService(Request $request, Service $service): RedirectResponse
    {
        $this->authorizeServiceEdit($service);

        $data = $request->validate([
            'title'         => 'required|string|max:255',
            'description'   => 'required|string',
            'category_id'   => 'required|uuid|exists:categories,id',
            'delivery_mode' => 'required|in:remote,onsite,both',
            'points_cost'   => 'required|integer|min:1',
            'status'        => 'required|in:active,paused',
            'skills'        => 'nullable|array',
            'skills.*'      => 'uuid|exists:skills,id',
            'tags'          => 'nullable|string',
        ]);

        $service->update([
            'title'         => $data['title'],
            'description'   => $data['description'],
            'category_id'   => $data['category_id'],
            'delivery_mode' => $data['delivery_mode'],
            'points_cost'   => $data['points_cost'],
            'status'        => $data['status'],
        ]);

        $service->skills()->sync($data['skills'] ?? []);

        if (isset($data['tags'])) {
            $tagIds = [];
            foreach (array_slice(array_filter(array_map('trim', explode(',', $data['tags']))), 0, 5) as $name) {
                $slug = Str::slug($name);
                if ($slug) {
                    $tagIds[] = Tag::firstOrCreate(['slug' => $slug], ['name' => $name, 'slug' => $slug])->id;
                }
            }
            $service->tags()->sync($tagIds);
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
        $community = Community::where('admin_id', $user->id)->first();
        if (! $community || $service->community_id !== $community->id) {
            abort(403);
        }
    }

    public function forceDeleteService(string $id): RedirectResponse
    {
        $service = Service::withTrashed()->findOrFail($id);
        $service->forceDelete();
        return back()->with('success', 'Service définitivement supprimé.');
    }

    public function restoreService(string $id): RedirectResponse
    {
        $service = Service::withTrashed()->findOrFail($id);
        $service->restore();
        $service->update(['status' => 'active']);
        return back()->with('success', 'Service restauré.');
    }

    // ── Transactions ──────────────────────────────────────────────────────────

    public function transactions(Request $request): View
    {
        $query = Transaction::with(['buyer', 'seller', 'service', 'serviceRequest']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->whereHas('buyer', fn($u) => $u->where('name', 'like', '%'.$request->search.'%'))
                  ->orWhereHas('seller', fn($u) => $u->where('name', 'like', '%'.$request->search.'%'));
            });
        }

        $transactions = $query->latest()->paginate(25)->withQueryString();

        return view('admin.transactions', compact('transactions'));
    }

    // ── Requests ──────────────────────────────────────────────────────────────

    public function requests(Request $request): View
    {
        $query = ServiceRequest::with(['user', 'category']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where('title', 'like', '%'.$request->search.'%');
        }

        $requests = $query->latest()->paginate(25)->withQueryString();

        return view('admin.requests', compact('requests'));
    }

    public function editRequest(ServiceRequest $serviceRequest): View
    {
        $this->authorizeRequestEdit($serviceRequest);

        $serviceRequest->load('attachments');
        $categories = Category::orderBy('name')->get();

        return view('admin.requests.edit', compact('serviceRequest', 'categories'));
    }

    public function updateRequest(Request $request, ServiceRequest $serviceRequest): RedirectResponse
    {
        $this->authorizeRequestEdit($serviceRequest);

        $data = $request->validate([
            'title'         => 'required|string|max:255',
            'description'   => 'required|string',
            'category_id'   => 'required|uuid|exists:categories,id',
            'delivery_mode' => 'required|in:remote,onsite,both',
            'budget_min'    => 'required|integer|min:1',
            'budget_max'    => 'nullable|integer|gte:budget_min',
            'deadline'      => 'nullable|date',
            'status'        => 'required|in:open,in_progress,closed',
            'attachments'   => 'nullable|array|max:5',
            'attachments.*' => 'file|mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx|max:10240',
            'delete_attachments'   => 'nullable|array',
            'delete_attachments.*' => 'uuid|exists:request_attachments,id',
        ]);

        $serviceRequest->update([
            'title'         => $data['title'],
            'description'   => $data['description'],
            'category_id'   => $data['category_id'],
            'delivery_mode' => $data['delivery_mode'],
            'budget_min'    => $data['budget_min'],
            'budget_max'    => $data['budget_max'] ?? null,
            'deadline'      => $data['deadline'] ?? null,
            'status'        => $data['status'],
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
                    'path'          => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type'     => $file->getMimeType(),
                    'order'         => $currentCount + $index,
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
        $community = Community::where('admin_id', $user->id)->first();
        if (! $community || $serviceRequest->community_id !== $community->id) {
            abort(403);
        }
    }

    public function closeRequest(ServiceRequest $serviceRequest): RedirectResponse
    {
        $serviceRequest->update(['status' => 'closed']);
        return back()->with('success', 'Demande clôturée.');
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
            'name'  => 'required|string|max:100',
            'color' => 'required|string|regex:/^#[0-9a-fA-F]{6}$/',
        ]);
        $data['slug'] = Str::slug($data['name']);
        Category::create($data);
        return back()->with('success', 'Catégorie créée.');
    }

    public function updateCategory(Request $request, Category $category): RedirectResponse
    {
        $data = $request->validate([
            'name'  => 'required|string|max:100',
            'color' => 'required|string|regex:/^#[0-9a-fA-F]{6}$/',
        ]);
        $data['slug'] = Str::slug($data['name']);
        $category->update($data);
        return back()->with('success', 'Catégorie mise à jour.');
    }

    public function destroyCategory(Category $category): RedirectResponse
    {
        if ($category->services()->count() > 0 || $category->serviceRequests()->count() > 0) {
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
            'name'        => $data['name'],
            'slug'        => Str::slug($data['name']),
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
}
