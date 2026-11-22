<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Loop;
use App\Models\Organization;
use App\Services\AccountCloner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

/**
 * TASK-1882 (demande Cyril, 22/11) — « Clonage de comptes » (Outils du
 * superadmin) : copier un compte et ses billets de blog vers une autre
 * Organization. Voir `AccountCloner` pour ce qui est copie, et pourquoi.
 */
class AdminAccountCloneController extends Controller
{
    public function index(Request $request): View
    {
        $organizations = Organization::query()->orderBy('name')->get(['id', 'name', 'slug', 'is_default']);

        $origine = $organizations->firstWhere('id', $this->uuid($request->query('origine')))
            ?? $organizations->firstWhere('is_default', true)
            ?? $organizations->first();

        $comptes = $origine
            ? AccountCloner::comptesDe((string) $origine->id)->orderBy('first_name')->orderBy('name')->get(['id', 'first_name', 'name', 'email'])
            : collect();

        $compte = $comptes->firstWhere('id', $this->uuid($request->query('compte')));
        $cible = $organizations->firstWhere('id', $this->uuid($request->query('cible')));

        if ($cible && $origine && $cible->id === $origine->id) {
            $cible = null;
        }

        $source = $compte ? AccountCloner::comptesDe((string) $origine->id)->find($compte->id) : null;
        $billets = $source ? BlogPost::query()->where('user_id', $source->id)->with(['loops:id,name', 'category'])->orderBy('created_at')->get() : collect();

        return view('admin.outils.clonage-de-comptes', [
            'organizations' => $organizations,
            'origine' => $origine,
            'comptes' => $comptes,
            'source' => $source,
            'cible' => $cible,
            'billets' => $billets,
            'emailPris' => $source && $cible && AccountCloner::emailPrisDans((string) $source->email, (string) $cible->id),
            'categories' => $cible ? Category::query()->where('organization_id', $cible->id)->orderBy('name_b2c')->get() : collect(),
            'boucles' => $cible ? Loop::query()->where('organization_id', $cible->id)->orderBy('name')->get(['id', 'name']) : collect(),
        ]);
    }

    public function store(Request $request, AccountCloner $cloneur): RedirectResponse
    {
        $data = $request->validate([
            'origine' => ['required', 'uuid', 'exists:organizations,id'],
            'compte' => ['required', 'uuid'],
            'cible' => ['required', 'uuid', 'exists:organizations,id', 'different:origine'],
            'billets' => ['array'],
            'billets.*.category_id' => ['nullable', 'uuid'],
            'billets.*.loop_id' => ['nullable', 'uuid'],
        ]);

        $source = AccountCloner::comptesDe($data['origine'])->findOrFail($data['compte']);
        $cible = Organization::query()->findOrFail($data['cible']);
        $retour = ['origine' => $data['origine'], 'compte' => $data['compte'], 'cible' => $data['cible']];

        try {
            [$clone, $nbBillets] = $cloneur->cloner($source, $cible, $data['billets'] ?? []);
        } catch (RuntimeException $e) {
            return redirect()->route('admin.outils.clonage-de-comptes', $retour)->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.users.edit', $clone)
            ->with('success', "Compte cloné dans « {$cible->name} » avec {$nbBillets} billet(s) de blog. Le compte d'origine est inchangé.");
    }

    private function uuid(mixed $valeur): ?string
    {
        return is_string($valeur) && Str::isUuid($valeur) ? $valeur : null;
    }
}
