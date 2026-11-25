<?php

namespace App\Http\Controllers;

use App\Models\BugReport;
use App\Models\Organization;
use App\Support\Tenancy\DefaultOrganizationResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BugReportController extends Controller
{
    /**
     * TASK-1904 — page « Signalement ». CONFIDENTIELLE (decision MASTER D6 :
     * fin de la transparence publique de TASK-1493).
     *
     * - Invite : invitation a se connecter, AUCUNE liste.
     * - Connecte : le formulaire, et SES seuls signalements dans l'organisation
     *   courante. Jamais ceux d'un autre membre, jamais d'une autre organisation.
     */
    public function index(Request $request): View
    {
        $organization = app()->bound('current_organization')
            ? app('current_organization')
            : DefaultOrganizationResolver::resolve();

        $user = $request->user();

        $bugReports = $user !== null && $organization instanceof Organization
            ? BugReport::where('organization_id', $organization->id)
                ->where('reporter_id', $user->id)
                ->latest()
                ->paginate(20)
            : null;

        // Le « contenu concerne » : la page d'ou vient le membre, si elle est
        // sur ce site et n'est pas la page Signalement elle-meme.
        $referer = (string) $request->headers->get('referer', '');
        $pageUrl = BugReport::isInternalUrl($referer) && ! preg_match('#/(report|bugs)/?$#', (string) parse_url($referer, PHP_URL_PATH))
            ? $referer
            : null;

        return view('bug-reports.index', compact('bugReports', 'organization', 'pageUrl'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', Rule::in(BugReport::CATEGORIES)],
            'details' => ['required', 'string', 'max:2000'],
            'page_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $organization = app()->bound('current_organization')
            ? app('current_organization')
            : DefaultOrganizationResolver::resolve();

        if (! $organization) {
            return back()->with('error', __('bugs.no_organization'));
        }

        // TASK-1688 (P0-3) — ECRITURE CROSS-TENANT, prouvee puis fermee.
        //
        // ## Ce qui a ete mesure, en base isolee
        //
        // Un membre de l'Organization A postait sur `/org/B/bugs` (desormais `/report`) et la table
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
        // La forme NON prefixee (`POST /report`, ex-`/bugs`) n'est pas touchee : mesure faite,
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
            'category' => $data['category'],
            // `reason` (NOT NULL, historique) porte la cle de categorie.
            'reason' => $data['category'],
            'details' => $data['details'],
            // Une URL externe n'est jamais conservee (pas de lien piege dans le back-office).
            'page_url' => BugReport::isInternalUrl($data['page_url'] ?? null) ? $data['page_url'] : null,
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
        ]);

        return back()->with('success', __('bugs.sent'));
    }
}
