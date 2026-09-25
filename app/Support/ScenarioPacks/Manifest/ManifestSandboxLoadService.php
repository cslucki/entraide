<?php

namespace App\Support\ScenarioPacks\Manifest;

use App\Support\ScenarioPacks\Contracts\ScenarioPackDefinition;
use App\Support\ScenarioPacks\ScenarioPackLoader;
use Throwable;

/**
 * TASK-1642 — l'unique porte d'entree du chargement d'un manifeste.
 *
 * Elle enchaine, dans cet ordre et sans raccourci possible :
 *
 *     1. validation integrale + egalite au digest APPROUVE  (aucune ecriture)
 *     2. creation d'une NOUVELLE Organization sandbox
 *     3. chargement via le `ScenarioPackLoader` EXISTANT
 *     4. inscription de la provenance du chargement
 *
 * L'ordre n'est pas cosmetique : l'etape 1 ne touche pas la base, donc un
 * document invalide ou mute laisse la base rigoureusement inchangee — zero
 * Organization creee, ce qui est exactement ce que la spec exige d'un Load
 * refuse.
 *
 * ## Pourquoi la sandbox est detruite quand le chargement echoue
 *
 * Le loader enveloppe `apply()` dans une transaction : si le pack leve, tout
 * ce qu'il a ecrit est annule. Mais l'Organization, elle, a ete creee AVANT
 * d'entrer dans cette transaction — c'est necessaire, le loader exige une
 * Organization pour poser son verrou. Sans rattrapage explicite, un echec
 * laisserait derriere lui une sandbox vide que plus rien ne sait relier a un
 * chargement, donc que plus rien ne sait supprimer. Le `catch` ci-dessous la
 * detruit : il defait exactement ce que CETTE invocation a cree, et rien
 * d'autre.
 *
 * ## Ce que cette classe n'est PAS
 *
 * Elle n'est exposee par AUCUNE route. T1642 ne construit ni le stockage
 * administratif DRAFT/VALID/LOADED, ni son ecran : l'appelant fournit
 * explicitement le JSON et le digest approuve, et c'est pour l'instant du code
 * (tests, futur ecran SuperAdmin) — jamais une requete HTTP.
 */
class ManifestSandboxLoadService
{
    public function __construct(
        private readonly ScenarioSandboxProvisioner $provisioner,
        private readonly ScenarioPackLoader $loader,
    ) {}

    /**
     * Couture de test : l'adaptateur applique a ce manifeste.
     *
     * `protected` et non injectee, deliberement. Le service doit rester
     * impossible a devier en production — aucun appelant ne peut lui passer un
     * autre pack — tout en laissant un test prouver que l'echec d'un `apply()`
     * ne laisse derriere lui ni monde partiel, ni sandbox orpheline. Cette
     * garantie ne se demontre pas autrement : il faut un `apply()` qui echoue
     * APRES avoir deja ecrit.
     */
    protected function makePack(ScenarioManifest $manifest): ScenarioPackDefinition
    {
        return new ManifestScenarioPack($manifest);
    }

    /**
     * Charge un manifeste dans une sandbox NEUVE.
     *
     * @param  string  $json  le document exact qui a ete approuve
     * @param  string  $approvedDigest  le digest affiche a l'humain au moment de l'approbation
     *
     * @throws ManifestNotLoadableException si le document est invalide ou mute
     */
    public function load(string $json, string $approvedDigest): ManifestSandboxLoadResult
    {
        // ETAPE 1 — aucune ecriture. Un document invalide, ou mute depuis
        // l'approbation, s'arrete ICI : la base n'a pas ete touchee.
        $manifest = ScenarioManifest::fromApprovedJson($json, $approvedDigest);

        $pack = $this->makePack($manifest);

        // ETAPE 2 — une Organization NEUVE, toujours. Le slug propose n'est
        // qu'une suggestion : une collision produit une autre sandbox, jamais
        // l'adoption d'une ligne existante.
        $organization = $this->provisioner->provision($manifest);

        try {
            // ETAPE 3 — le moteur existant, inchange : garde Organization,
            // transaction, verrou, registrar, idempotence.
            $result = $this->loader->load($pack, $organization);
        } catch (Throwable $exception) {
            // Pas de sandbox orpheline silencieuse.
            $organization->forceDelete();

            throw $exception;
        }

        // ETAPE 4 — provenance du CHARGEMENT, ecrite apres un succes : une
        // Organization creee pour un chargement qui echoue ensuite ne doit pas
        // se declarer supprimable par un futur retrait.
        $result->load->forceFill(['organization_created_by_pack' => true])->save();

        return new ManifestSandboxLoadResult($manifest, $organization, $result);
    }
}
