<?php

namespace App\Support\ScenarioPacks\Manifest;

use App\Models\Organization;
use App\Support\ScenarioManifest\ManifestSchema;

/**
 * TASK-1642 — cree la NOUVELLE Organization sandbox d'un manifeste.
 *
 * Toute la securite tenant de cette TASK tient dans une phrase : ce service
 * ne fait que des INSERT. Il n'existe, dans tout le fichier, aucun chemin qui
 * charge une Organization existante pour la rendre a l'appelant. Le seul
 * `SELECT` est un test d'EXISTENCE de slug, dont le resultat ne sert qu'a
 * choisir un AUTRE slug — jamais a recuperer la ligne trouvee.
 *
 * C'est la difference exacte entre le contrat de la spec 10.2 :
 *
 *     "Le slug propose peut devenir `amt-formation-ia-2` ou une autre forme
 *      sure. Il ne provoque JAMAIS l'adoption de `main`, d'un slug client ou
 *      d'une ligne existante."
 *
 * et le piege qu'elle vise : un `firstOrCreate(['slug' => $proposed])`, qui
 * aurait l'air idempotent et qui livrerait le tenant de quelqu'un d'autre au
 * premier manifeste qui devine un slug client.
 *
 * ## Profil de la sandbox
 *
 * Non publique, non defaut, sans credential IA. La spec 7.1 n'expose aucun
 * autre parametre d'Organization : tout ce qui n'est pas ci-dessous est un
 * choix PRODUIT, jamais une cle du manifeste.
 *
 * ## Provenance
 *
 * `scenario_sandbox_created_at` est pose ici, et nulle part ailleurs, sur une
 * ligne qui vient d'etre inseree. C'est la seconde preuve que lit
 * `ScenarioPackOrganizationGuard`. Elle est posee par `forceFill()` parce que
 * la colonne n'est deliberement pas `fillable` : ce qui empeche un mass
 * assignment de l'atteindre doit aussi m'empecher, moi, de la poser par
 * inadvertance depuis un tableau de donnees.
 */
class ScenarioSandboxProvisioner
{
    /** Nombre maximal de suffixes essayes avant d'abandonner. */
    private const MAX_SLUG_ATTEMPTS = 50;

    public function provision(ScenarioManifest $manifest): Organization
    {
        $declared = $manifest->organization();

        $organization = Organization::create([
            'name' => (string) $declared->name,
            'slug' => $this->reserveAvailableSlug($manifest->proposedSlug()),
            'description' => (string) $declared->description,
            'locale' => (string) $declared->locale,
            // Profil SUR et non client : une sandbox n'est pas distribuee
            // publiquement tant que le produit ne l'a pas ouverte (spec 7.1).
            'is_active' => true,
            'is_public' => false,
            'is_default' => false,
            'loops_enabled' => true,
            'ai_profiles_enabled' => true,
            'loop_mode' => 'multi',
        ]);

        // Provenance server-side, sur la ligne qui vient d'etre creee.
        $organization->forceFill(['scenario_sandbox_created_at' => now()])->save();

        return $organization;
    }

    /**
     * Un slug LIBRE, derive de la suggestion du manifeste.
     *
     * Le retour de cette methode est toujours un slug qu'aucune Organization
     * ne porte : le service appelant ne peut donc pas, meme par bug, ecrire
     * dans une ligne existante.
     */
    private function reserveAvailableSlug(string $proposed): string
    {
        $base = $this->safeBase($proposed);

        if (! $this->slugTaken($base)) {
            return $base;
        }

        for ($suffix = 2; $suffix <= self::MAX_SLUG_ATTEMPTS; $suffix++) {
            $candidate = $base.'-'.$suffix;

            if (! $this->slugTaken($candidate)) {
                return $candidate;
            }
        }

        // Dernier recours DETERMINISTE dans sa forme, aleatoire dans sa
        // valeur : mieux vaut un slug moins joli qu'un chargement qui echoue,
        // et surtout mieux qu'un repli silencieux sur une ligne existante.
        return $base.'-'.bin2hex(random_bytes(4));
    }

    /**
     * Seconde ceinture sur les slugs reserves.
     *
     * Le Validator T1641 refuse deja `main`, `admin`, `api`, `app`, `www`,
     * `prod`, `production` et `develop` comme proposition. On ne s'appuie pas
     * dessus ici : un futur appelant pourrait provisionner depuis un document
     * obtenu autrement, et la garde de tenant ne doit pas dependre d'une
     * validation faite ailleurs.
     */
    private function safeBase(string $proposed): string
    {
        $base = trim($proposed);

        if ($base === '' || in_array($base, ManifestSchema::RESERVED_SLUGS, true)) {
            return 'scenario-sandbox';
        }

        return $base;
    }

    /**
     * Existe-t-il DEJA une Organization portant ce slug ?
     *
     * `withoutGlobalScopes()` et `withTrashed()` volontairement : une ligne
     * invisible pour le contexte courant, ou soft-deleted, occupe quand meme
     * le slug. La rater ferait croire le slug libre, et la creation echouerait
     * sur la contrainte d'unicite — ou pire, reveillerait une ligne existante.
     *
     * Cette methode rend un BOOLEEN, jamais le modele trouve. C'est
     * deliberate : il ne doit exister aucun chemin par lequel une Organization
     * preexistante remonte jusqu'a l'appelant.
     */
    private function slugTaken(string $slug): bool
    {
        return Organization::query()
            ->withoutGlobalScopes()
            ->withTrashed()
            ->where('slug', $slug)
            ->exists();
    }
}
