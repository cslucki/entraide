<?php

namespace App\Support\ScenarioPacks\Manifest;

use App\Models\Organization;
use App\Models\ScenarioPackEntity;
use App\Models\ScenarioPackLoad;
use App\Support\ScenarioPacks\Contracts\ScenarioPackDefinition;
use App\Support\ScenarioPacks\ScenarioPackLoader;
use App\Support\ScenarioPacks\ScenarioPackLoadResult;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
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

        // ETAPE 2 — ce monde approuve a-t-il DEJA ete charge ? Spec 5.2 : un
        // double clic ou un rejeu reseau rend le chargement existant, il n'en
        // cree pas un second. L'identite est le couple (pack, digest) : ni le
        // slug propose, qui n'est qu'une suggestion, ni le seul `pack_id`, que
        // deux versions d'un meme pack partagent.
        $existing = $this->findExistingLoad($pack, $manifest);

        if ($existing !== null) {
            return $existing;
        }

        // ETAPE 3 — une Organization NEUVE, toujours. Le slug propose n'est
        // qu'une suggestion : une collision produit une autre sandbox, jamais
        // l'adoption d'une ligne existante.
        $organization = $this->provisioner->provision($manifest);

        try {
            // ETAPE 4 — le moteur existant, inchange : garde Organization,
            // transaction, verrou, registrar, idempotence.
            $result = $this->loader->load($pack, $organization);

            // ETAPE 5 — inscription de l'identite approuvee. C'est ICI que la
            // contrainte unique (pack_id, manifest_digest) tranche une course :
            // deux appels concurrents ont pu passer l'etape 2 sans rien
            // trouver, un seul peut ecrire ce couple.
            //
            // L'ecriture est enveloppee dans une transaction, et ce n'est pas
            // un ornement : sous PostgreSQL, une violation de contrainte
            // AVORTE la transaction en cours, et toute instruction suivante
            // echoue en `25P02 — current transaction is aborted`. Le nettoyage
            // du perdant serait alors impossible des qu'un appelant — ou le
            // harnais de test — a ouvert une transaction autour du
            // chargement. Imbriquee, `DB::transaction()` pose un SAVEPOINT :
            // l'echec ne defait que cette ecriture et rend la connexion
            // utilisable pour defaire le reste. SQLite se comporte de meme.
            DB::transaction(function () use ($result, $manifest): void {
                $result->load->forceFill([
                    'manifest_digest' => $manifest->digest(),
                    'organization_created_by_pack' => true,
                ])->save();
            });
        } catch (UniqueConstraintViolationException) {
            // Course perdue : un autre appel a charge le MEME monde approuve
            // pendant que celui-ci travaillait. On defait exactement ce que
            // CETTE invocation a cree, puis on rend le chargement gagnant —
            // le rejeu doit retourner un resultat, pas une erreur.
            $this->discard($organization);

            $winner = $this->findExistingLoad($pack, $manifest);

            if ($winner !== null) {
                return $winner;
            }

            throw ManifestNotLoadableException::concurrentLoadLost($manifest->digest());
        } catch (Throwable $exception) {
            // Pas de sandbox orpheline silencieuse.
            $this->discard($organization);

            throw $exception;
        }

        return new ManifestSandboxLoadResult($manifest, $organization, $result);
    }

    /**
     * Le chargement deja en place pour ce monde approuve, ou `null`.
     *
     * Rend le resultat SANS rejouer le pack : un rejeu ne doit provoquer
     * aucune ecriture. Les compteurs viennent du registre, qui est la memoire
     * de ce que le chargement d'origine a reellement produit.
     */
    private function findExistingLoad(ScenarioPackDefinition $pack, ScenarioManifest $manifest): ?ManifestSandboxLoadResult
    {
        $load = ScenarioPackLoad::query()
            ->where('pack_id', $pack->packId())
            ->where('manifest_digest', $manifest->digest())
            ->first();

        if ($load === null) {
            return null;
        }

        $organization = Organization::query()->withoutGlobalScopes()->find($load->organization_id);

        if ($organization === null) {
            // La sandbox a ete supprimee sous la ligne de chargement : ce
            // n'est plus un rejeu, c'est un etat incoherent. On laisse le
            // chargement normal reprendre la main plutot que de rendre une
            // sandbox absente.
            return null;
        }

        $counts = ScenarioPackEntity::query()
            ->where('scenario_pack_load_id', $load->id)
            ->selectRaw('entity_type, count(*) as aggregate')
            ->groupBy('entity_type')
            ->pluck('aggregate', 'entity_type')
            ->map(fn ($count) => (int) $count)
            ->all();

        // Le manifeste rendu est celui de l'appel courant : son digest est,
        // par construction, celui du chargement retrouve — c'est la condition
        // meme qui a fait de cet appel un rejeu. Le document n'est pas relu
        // depuis la base : il n'y est pas stocke, et T1642 ne livre pas ce
        // stockage administratif.
        return new ManifestSandboxLoadResult(
            $manifest,
            $organization,
            new ScenarioPackLoadResult($load, false, $counts),
            wasReplay: true,
        );
    }

    /**
     * Defait exactement ce que CETTE invocation a cree, et rien d'autre.
     */
    private function discard(Organization $organization): void
    {
        ScenarioPackLoad::query()->where('organization_id', $organization->id)->delete();
        $organization->forceDelete();
    }
}
