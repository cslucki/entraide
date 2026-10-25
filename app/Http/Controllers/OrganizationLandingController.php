<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\GuestShell\GuestShellSurface;
use App\Services\Workshops\PublicWorkshopListing;
use App\Support\GuestShell\GuestShellDisplayMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrganizationLandingController extends Controller
{
    public function __invoke(Request $request, string $organization): View|RedirectResponse
    {
        $organization = Organization::findBySlug($organization);
        abort_if(! $organization || ! $organization->is_active, 404);

        if (! $organization->is_public && ! auth()->check()) {
            return redirect()->route('organization.login', ['organization' => $organization->slug]);
        }

        $actor = $request->user();
        $showInternalHomepageContent = $actor !== null
            && ($actor->organization_id === $organization->id || $actor->is_admin);

        // TASK-1692 — l'accueil reste public, pas les donnees ni les portes
        // internes qu'il presentait historiquement. Un visiteur anonyme ou un
        // membre d'une autre Organization recoit le gabarit marketing, mais
        // aucune lecture de membres, atelier ou Shell IA n'est declenchee.
        // La garde de route reste l'autorite d'acces ; ce booleen ne fait que
        // borner le rendu de l'unique page publique.
        // TASK-1442 — SW-8a : lecture PURE du Shell Welcome (aucun cookie, aucune identite, aucun appel) ; l'overlay se monte selon la decision.
        $guestShell = $showInternalHomepageContent && $organization->is_public
            ? app(GuestShellSurface::class)->read($organization, $request)
            : null;

        // TASK-1463 (audit OPUS final P1-1) : les ateliers publies a session publiee a venir — la meme selection que
        // workshops.runtime ; lecture pure (aucun cookie), seulement pour une Organization active et publique.
        //
        // TASK-1692, correctif post-CI : cette selection reste PUBLIQUE. Elle ne
        // porte que des ateliers PUBLIES, a session publiee a venir, d'une
        // Organization publique ET active — c'est-a-dire des evenements que
        // l'Organization a elle-meme rendus publics. La borner a
        // `$showInternalHomepageContent` etait une regression produit, pas un
        // gain de confidentialite : elle vidait l'affiche publique des ateliers
        // sans rien proteger. Les trois conditions historiques sont l'autorite.
        $publicWorkshops = $organization->is_public && $organization->is_active
            ? app(PublicWorkshopListing::class)->upcoming($organization)
            : collect();

        // TASK-1494 — le mode SHELL FIRST decide AVANT le gabarit de landing.
        //
        // TASK-1443 avait ecrit l'inverse, et l'assumait : « shell_first : le
        // Shell est l'experience principale, inclus en HAUT, le contenu public
        // classique reste ENTIER juste en dessous. » Mesure de Cyril : le grand
        // Shell s'affiche, et la landing marketing complete se deroule derriere.
        // C'etait donc le contrat, pas un accident — et MASTER l'a arbitre
        // autrement : en Shell First, rendre la landing entiere DEDOUBLE
        // l'experience au lieu de la remplacer.
        //
        // La decision est prise ici, une seule fois, plutot que par une
        // conditionnelle dans chacun des trois gabarits (`home`, `hero-v2`,
        // `artscilab-hero`, 518 lignes de balisage marketing) : aucun d'eux
        // n'est modifie, donc les modes `overlay` et OFF ne peuvent pas
        // regresser.
        //
        // On lit le mode EFFECTIF (`display.mode` quand le Shell est visible),
        // jamais la colonne : un Shell degrade ou refuse par la garde
        // economique doit continuer de rendre la landing normale, sans quoi une
        // Organization mal configuree n'aurait plus d'accueil du tout.
        if (($guestShell['display']['visible'] ?? false)
            && GuestShellDisplayMode::isShellFirst($guestShell['display']['mode'] ?? null)) {
            return view('organization.shell-first', compact('organization', 'guestShell'));
        }

        if ($organization->homepage_template === 'bouclepro_hero_v2') {
            $heroAvatars = $showInternalHomepageContent
                ? $organization->users()
                    ->activeAccount()
                    ->whereNotNull('avatar')
                    ->latest()
                    ->limit(12)
                    ->get(['id', 'name', 'avatar'])
                    ->map(fn ($user) => $user->avatar_url)
                    ->values()
                : collect();

            // TASK-1611 — le prochain atelier monte DANS le HERO.
            //
            // Meme selection publique, lue une seule fois : `heroSessions()`
            // aplatit `$publicWorkshops` a la maille de la date, sans aucune
            // requete de plus. Vide (pas d'atelier, ou Organization privee /
            // inactive) => le gabarit garde son visuel d'origine a droite.
            //
            // Ce gabarit ne recoit plus `publicWorkshops` : le bloc « Ateliers
            // ouverts » du bas de page y est remplace par le HERO, qui dit la
            // meme chose plus haut. Les deux autres gabarits le conservent.
            $heroSessions = PublicWorkshopListing::heroSessions($publicWorkshops);

            return view('organization.hero-v2', compact('organization', 'heroAvatars', 'guestShell', 'heroSessions', 'showInternalHomepageContent'));
        }

        // TASK-1699 — le gabarit OFSH.
        //
        // Il est servi AVANT `artscilab_hero` sans que l'ordre ait d'effet :
        // les trois conditions portent sur la meme colonne et s'excluent. Ce
        // qui compte est ce qu'il ne recoit PAS — ni `$heroAvatars`, ni
        // `$heroSessions`, ni `$publicWorkshops`. Cette landing ne lit aucune
        // donnee de tenant : quatre portes, des textes, et c'est tout. La
        // garde `$showInternalHomepageContent` lui est passee quand meme,
        // parce qu'elle y commande la DESTINATION des quatre portes — la
        // surface reelle pour un membre, la connexion bornee sinon.
        if ($organization->homepage_template === 'ofsh_hero') {
            return view('organization.ofsh-hero', compact('organization', 'guestShell', 'showInternalHomepageContent'));
        }

        if ($organization->homepage_template === 'artscilab_hero') {
            $heroAvatars = $showInternalHomepageContent
                ? $organization->users()
                    ->activeAccount()
                    ->whereNotNull('avatar')
                    ->latest()
                    ->limit(16)
                    ->get(['id', 'name', 'avatar'])
                    ->map(fn ($user) => $user->avatar_url)
                    ->values()
                : collect();

            return view('organization.artscilab-hero', compact('organization', 'heroAvatars', 'guestShell', 'publicWorkshops', 'showInternalHomepageContent'));
        }

        $defaultOrganization = $organization;

        return view('organization.home', compact('organization', 'defaultOrganization', 'guestShell', 'publicWorkshops', 'showInternalHomepageContent'));
    }

    /**
     * TASK-1700 — « A propos », et sa frontiere d'acces.
     *
     * ## La frontiere, d'abord
     *
     * Elle est celle de la landing ci-dessus, reprise mot pour mot, comme
     * `OrganizationFlowchartController` l'a fait avant : Organization inactive
     * -> 404 ; Organization non publique et visiteur anonyme -> la page de
     * connexion BORNEE de cette Organization.
     *
     * Elle est posee ICI parce que la route sort desormais de la garde du
     * groupe (TASK-1700, voir `routes/web.php`). Et elle est plus FORTE que ce
     * qui existait : cette methode ne regardait pas `is_public` du tout. Tant
     * que le groupe exigeait `auth` + `organization.member`, personne ne
     * pouvait atteindre la page d'une Organization privee sans en etre membre ;
     * rouvrir la route sans ce test aurait fait de « A propos » un oracle
     * d'existence sur les Organizations privees.
     *
     * ## Le gabarit, ensuite
     *
     * Cette methode rendait `about-launchpals` a TOUTE Organization, sans
     * jamais regarder laquelle. Ce n'etait pas un defaut de resolution de
     * tenant — `$organization` etait juste — mais une vue codee en dur : `ofsh`
     * et `main` recevaient la page anglaise de LaunchPals.
     *
     * Le discriminant est `homepage_template`, la colonne qui dit DEJA quelle
     * identite visuelle une Organization a choisie. Aucune structure de donnees
     * n'est creee pour ca, et le choix reste fait a un seul endroit :
     * `/admin/homepages`. Une Organization qui prend le gabarit OFSH prend sa
     * page « A propos » avec, ce qui est le comportement attendu.
     *
     * Toute autre valeur garde `about-launchpals`, a l'octet : `main` et
     * `launchpals` ne bougent pas.
     */
    public function about(string $organization): View|RedirectResponse
    {
        $organization = Organization::findBySlug($organization);
        abort_if(! $organization || ! $organization->is_active, 404);

        if (! $organization->is_public && ! auth()->check()) {
            return redirect()->route('organization.login', ['organization' => $organization->slug]);
        }

        if ($organization->homepage_template === 'ofsh_hero') {
            return view('organization.about-ofsh', compact('organization'));
        }

        return view('organization.about-launchpals', compact('organization'));
    }
}
