<?php

namespace App\Http\Controllers;

use App\Models\BugReport;
use App\Models\Organization;
use App\Support\Tenancy\DefaultOrganizationResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class BugReportController extends Controller
{
    /**
     * TASK-1493 — la page publique de suivi des bugs, bornee a la publicite de
     * l'Organization.
     *
     * ## L'intention publique est REELLE, et c'est ce qui distingue ce cas
     *
     * Contrairement au commentaire « Public organization-scoped detail routes »
     * de TASK-1488, qui s'est revele faux sur ses trois affirmations, la
     * publicite de cette page est ECRITE et coherente :
     *
     * - `bugs.empty` dit « Aucun bug **public** pour le moment. » ;
     * - `bugs.subtitle_org` parle des « corrections **publiees** » ;
     * - la vue rend un bouton **Connexion** a l'invite, donc elle le prevoit.
     *
     * Cette page n'est donc PAS fermee. Elle reste ce qu'elle a ete concue pour
     * etre : une page de transparence produit.
     *
     * ## Ce qui manquait
     *
     * Cette intention n'avait jamais ete confrontee a `is_public`. Le contenu
     * affiche est du texte LIBRE ecrit par des membres — `details` accepte
     * 2000 caracteres — et sur une Organization privee, le publier au Web
     * revient a publier ce que ses membres ecrivent en interne.
     *
     * Mesure au HEAD : 200 a un anonyme. Aucune fuite constatee pour une seule
     * raison — la table `bug_reports` est VIDE. Une table vide n'est pas une
     * frontiere, et TASK-1489 l'avait deja inscrit comme defaut LATENT.
     *
     * ## La regle appliquee n'est pas neuve
     *
     * C'est exactement celle de TASK-1492 : la confidentialite d'une
     * Organization s'etend a ce qu'elle publie. Organization publique -> page
     * publique inchangee ; Organization privee -> reservee a ses membres.
     */
    public function index(): View
    {
        $organization = app()->bound('current_organization')
            ? app('current_organization')
            : DefaultOrganizationResolver::resolve();

        if ($organization instanceof Organization) {
            $this->assertOrganizationBugListIsReadable($organization);
        }

        $bugReports = BugReport::with('organization')
            ->when($organization, fn ($query) => $query->where('organization_id', $organization->id))
            ->whereIn('status', ['pending', 'fixed'])
            ->latest()
            ->paginate(20);

        return view('bug-reports.index', compact('bugReports', 'organization'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'details' => ['required', 'string', 'max:2000'],
            'page_url' => ['nullable', 'url', 'max:2048'],
        ]);

        $organization = app()->bound('current_organization')
            ? app('current_organization')
            : DefaultOrganizationResolver::resolve();

        if (! $organization) {
            return back()->with('error', 'Impossible de rattacher ce bug à une organisation.');
        }

        BugReport::create([
            'organization_id' => $organization->id,
            'reporter_id' => $request->user()->id,
            'reason' => $data['reason'],
            'details' => $data['details'],
            'page_url' => $data['page_url'] ?? url()->previous(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
        ]);

        return back()->with('success', 'Bug signalé. Merci pour votre aide !');
    }

    /**
     * Meme regle et meme forme que
     * `BlogController::assertOrganizationBlogIsReadable()` (TASK-1492), et pour
     * la meme raison : la confidentialite d'une Organization s'etend a ce
     * qu'elle publie.
     *
     * Refus en 404 comme les gardes voisines — un 403 confirmerait a un tiers
     * que cette Organization existe. Le SuperAdmin garde l'acces transverse,
     * avec le meme predicat `is_admin` que `EnsureOrganizationMember` et
     * `OrgAdminMiddleware`.
     */
    private function assertOrganizationBugListIsReadable(Organization $organization): void
    {
        if ($organization->is_public) {
            return;
        }

        $user = auth()->user();

        if ($user?->is_admin) {
            return;
        }

        abort_unless($user && $user->organization_id === $organization->id, 404);
    }
}
