<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\ScenarioManifestVersion;
use App\Models\User;
use App\Support\ScenarioManager\Persona\ScenarioPersonaAccess;
use App\Support\ScenarioManager\Persona\ScenarioPersonaRefused;
use App\Support\ScenarioManager\Persona\ScenarioPersonaSwitch;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * TASK-1654 — le mode persona se reverifie a CHAQUE requete, et se ferme seul.
 *
 * ## Pourquoi une verification par requete, et pas seulement a l'entree
 *
 * Parce que l'entree prouve un etat a un INSTANT, et que le mode persona dure.
 * Entre deux pages, la sandbox peut etre retiree, le persona supprime, deplace
 * vers un vrai tenant, promu administrateur de plateforme, ou voir son adresse
 * devenir reelle. Chacun de ces evenements transforme un emprunt legitime en
 * emprunt qui ne le serait plus — et aucun d'eux ne passe par l'entree.
 *
 * Une garde posee seulement a la porte protege la porte, pas le sejour.
 *
 * ## Fail-closed, et la sortie est la MEME que la sortie explicite
 *
 * Quand une condition tombe, on ne « continue prudemment » pas : on quitte le
 * mode par le meme chemin que l'operateur aurait pris, avec la meme
 * revalidation du privilege d'origine. Si l'acteur n'est plus administrateur,
 * la seule issue sure est la deconnexion complete.
 *
 * ## Le cout quand le mode est inactif
 *
 * Une lecture de session et un retour. Aucune requete SQL. C'est la condition
 * pour qu'une garde puisse etre POSEE PARTOUT sans qu'on hesite a le faire —
 * et une garde qu'on hesite a poser partout finit par manquer la page qui
 * comptait.
 */
class EnsureScenarioPersonaContextIsValid
{
    public function handle(Request $request, Closure $next)
    {
        $contexte = ScenarioPersonaAccess::contexte();

        if ($contexte === null) {
            // Cas de loin le plus fréquent : aucun coût.
            return $next($request);
        }

        if (($raison = $this->raisonDeFermer($contexte)) === null) {
            // Le bandeau est alimente ICI, pas par la vue. Le contexte vient
            // d'etre VALIDE : l'ecran ne peut donc pas annoncer un mode que la
            // garde aurait ferme, ni interroger la base pour le redecouvrir.
            view()->share('scenarioPersonaBanner', [
                'persona' => Auth::user(),
                'sandbox' => Organization::query()->find($contexte['sandbox_organization_id']),
            ]);

            return $next($request);
        }

        $restaure = app(ScenarioPersonaSwitch::class)->sortir();

        // La phrase dit CE QUI s'est passe dans le monde, pas « une erreur est
        // survenue » : l'operateur doit pouvoir comprendre pourquoi il a ete
        // sorti sans aller lire un journal.
        $message = $raison->getMessage();

        return $restaure
            ? redirect()->route('admin.outils.scenarios')->with('error', $message)
            : redirect()->route('login')->with('error', $message);
    }

    /**
     * La raison de fermer le mode, ou `null` s'il tient toujours.
     *
     * L'ordre va du plus structurel au plus individuel : inutile de juger un
     * persona si le monde auquel il appartient a disparu.
     *
     * @param  array<string, string>  $contexte
     */
    private function raisonDeFermer(array $contexte): ?ScenarioPersonaRefused
    {
        $version = ScenarioManifestVersion::query()->find($contexte['scenario_version_id']);

        if ($version === null) {
            return ScenarioPersonaRefused::versionNotLoaded();
        }

        try {
            $sandbox = app(ScenarioPersonaAccess::class)->sandboxVivante($version);
        } catch (ScenarioPersonaRefused $refus) {
            return $refus;
        }

        // Le chargement doit etre CELUI de l'entree. Un Reset suivi d'un
        // rechargement fabrique un monde neuf : l'identite du persona d'hier
        // n'y signifie plus rien, meme si les identifiants se ressemblent.
        if ((string) $version->scenario_pack_load_id !== $contexte['scenario_pack_load_id']) {
            return ScenarioPersonaRefused::loadGone();
        }

        if ((string) $sandbox->getKey() !== $contexte['sandbox_organization_id']) {
            return ScenarioPersonaRefused::sandboxGone();
        }

        $persona = Auth::user();

        // La session dit un persona, l'authentification en dit un autre : on ne
        // choisit pas lequel a raison, on ferme.
        if (! $persona instanceof User || (string) $persona->getKey() !== $contexte['persona_id']) {
            return ScenarioPersonaRefused::noActivePersonaMode();
        }

        // Relu en base, jamais depuis l'instance de session : c'est justement le
        // changement survenu ENTRE deux requetes qu'on cherche a voir.
        $frais = User::query()->find($persona->getKey());

        if ($frais === null) {
            return ScenarioPersonaRefused::notInSandbox();
        }

        return ScenarioPersonaAccess::raisonDInegibilite($frais, $sandbox);
    }
}
