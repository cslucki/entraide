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

        // TASK-1688 (P0-3) — ECRITURE CROSS-TENANT, prouvee puis fermee.
        //
        // ## Ce qui a ete mesure, en base isolee
        //
        // Un membre de l'Organization A postait sur `/org/B/bugs` et la table
        // `bug_reports` passait de 0 a 1, avec `organization_id = B` et
        // `reporter_id = <membre de A>`. HTTP 302, aucun refus.
        //
        // ## La cause
        //
        // L'Organization destinataire est prise du CONTEXTE — donc de l'URL,
        // que `ResolveOrganization` lie sans jamais regarder l'appartenance —
        // et ecrite telle quelle. Rien ne confrontait l'auteur a sa cible.
        // Exactement le motif du P0-1 favoris, ferme en T1687.
        //
        // ## Pourquoi ce defaut pesait plus lourd que les precedents
        //
        // Le contenu est ENTIEREMENT controle par l'appelant (`details`, 2000
        // caracteres libres, `reason`, `page_url`) et atterrit sur l'ecran
        // d'ADMINISTRATION du tenant vise. Ce n'etait pas seulement une ligne
        // de trop : c'etait une injection de contenu dans la surface d'autrui.
        //
        // ## La garde, en deux temps
        //
        // 1. **Le destinataire doit etre l'Organization de l'auteur.** Invariant
        //    de fond, independant de toute URL : il ferme A -> B comme B -> A.
        // 2. **Quand l'URL nomme une Organization, elle doit etre la meme.**
        //    Ceinture : si la resolution du contexte venait un jour a diverger
        //    du chemin, un signalement ne pourrait toujours pas atterrir
        //    ailleurs que la ou l'URL le dit.
        //
        // La forme NON prefixee (`POST /bugs`) n'est pas touchee : mesure faite,
        // elle rattache deja le signalement a l'Organization de l'auteur, donc
        // le premier predicat y passe. Elle ne porte pas de parametre
        // d'Organization, donc le second ne s'y applique pas.
        //
        // AUCUNE exemption `is_admin` : doctrine TASK-1658, « inspecter n'est
        // pas participer ». Signaler un bug est un geste de MEMBRE.
        //
        // 404 et pas 403 : le refus ne confirme rien de l'existence de
        // l'Organization visee (doctrine T1479, T1601-T1606).
        $auteur = $request->user();

        abort_unless($auteur !== null && $auteur->organization_id === $organization->id, 404);

        $slugDemande = $request->route('organization') ?? $request->route('community');

        if ($slugDemande !== null) {
            $slugDemande = $slugDemande instanceof Organization ? $slugDemande->slug : (string) $slugDemande;

            abort_unless($slugDemande === $organization->slug, 404);
        }

        BugReport::create([
            'organization_id' => $organization->id,
            'reporter_id' => $auteur->id,
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
