<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ScenarioManifestVersion;
use App\Models\User;
use App\Support\ScenarioManager\Persona\ScenarioPersonaAccess;
use App\Support\ScenarioManager\Persona\ScenarioPersonaRefused;
use App\Support\ScenarioManager\Persona\ScenarioPersonaSwitch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * TASK-1654 — « Voir en tant que persona ».
 *
 * ## Les trois gestes, et pourquoi leurs methodes HTTP diffferent
 *
 * La liste est un GET : elle n'ecrit rien, et doit pouvoir etre rechargee,
 * mise en favori, partagee entre deux administrateurs.
 *
 * L'entree est un POST. Ce n'est pas une convention : c'est une bascule
 * d'identite. En GET, elle serait declenchable par un lien, une image, une
 * redirection — et un administrateur pourrait changer d'identite sans jamais
 * l'avoir demande. Le jeton CSRF est ce qui exige que le geste vienne du
 * formulaire de cette application.
 *
 * La sortie est un POST pour la meme raison, et elle vit HORS du groupe
 * `admin` : pendant le mode, `Auth::user()` EST le persona, donc
 * `AdminMiddleware` rendrait 403 — l'operateur serait enferme dans le mode par
 * la garde censee le proteger. Elle ne depend donc pas du privilege courant,
 * mais de l'existence d'un mode persona valide, ce qui est une autorisation
 * d'une autre nature.
 *
 * ## Rien n'est cru de ce qui arrive
 *
 * L'identifiant de persona recu est relu en base, a l'interieur de la
 * frontiere de la sandbox prouvee depuis la version. Aucune donnee du
 * formulaire ne participe a la preuve : elle ne sert qu'a DESIGNER un candidat
 * que le serveur ira verifier lui-meme.
 */
class AdminScenarioPersonaController extends Controller
{
    public function __construct(
        private readonly ScenarioPersonaAccess $acces,
        private readonly ScenarioPersonaSwitch $bascule,
    ) {}

    /**
     * La liste des personas empruntables de cette version.
     */
    public function index(ScenarioManifestVersion $version): View|RedirectResponse
    {
        try {
            $sandbox = $this->acces->sandboxVivante($version);
            $personas = $this->acces->personasEligibles($version);
        } catch (ScenarioPersonaRefused $refus) {
            return redirect()
                ->route('admin.outils.scenarios.show', $version)
                ->with('error', $refus->getMessage());
        }

        return view('admin.outils.scenario-personas', [
            'version' => $version,
            'sandbox' => $sandbox,
            'personas' => $personas,
        ]);
    }

    /**
     * Entre dans le monde, sous l'identite de ce persona.
     */
    public function enter(Request $request, ScenarioManifestVersion $version): RedirectResponse
    {
        $data = $request->validate([
            'persona_id' => ['required', 'uuid'],
        ]);

        $acteur = $request->user();

        try {
            $persona = $this->acces->exigerUnPersonaEligible($version, $data['persona_id']);
            $this->bascule->entrer($acteur, $persona, $version);
        } catch (ScenarioPersonaRefused $refus) {
            return back()->with('error', $refus->getMessage());
        }

        // La racine, et non une page du Manager : le but est de voir le produit
        // comme ce persona le voit, depuis son point d'entree ordinaire.
        return redirect('/')->with('success', sprintf(
            'Vous agissez desormais en tant que %s.',
            $persona->full_name ?: $persona->name
        ));
    }

    /**
     * Revient au SuperAdmin d'origine.
     */
    public function exit(): RedirectResponse
    {
        try {
            $restaure = $this->bascule->sortir();
        } catch (ScenarioPersonaRefused $refus) {
            return redirect('/')->with('error', $refus->getMessage());
        }

        if (! $restaure) {
            // L'acteur d'origine n'est plus administrateur, ou n'existe plus.
            // On ne lui rend pas un privilege qu'il n'a plus : on ferme tout.
            return redirect()->route('login')->with(
                'error',
                "Le compte d'origine n'est plus administrateur : la session a ete fermee."
            );
        }

        return redirect()
            ->route('admin.outils.scenarios')
            ->with('success', 'Vous etes revenu a votre compte administrateur.');
    }

    /**
     * Le persona actuellement emprunte, pour le bandeau.
     *
     * Lu par la vue partagee ; rend `null` hors mode persona.
     */
    public static function personaCourant(): ?User
    {
        $contexte = ScenarioPersonaAccess::contexte();

        return $contexte === null
            ? null
            : User::query()->find($contexte['persona_id']);
    }
}
