<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\PointLedger;
use App\Models\Report;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Skill;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
        $query = User::withCount(['services', 'buyerTransactions', 'sellerTransactions', 'reviewsReceived']);

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
