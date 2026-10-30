<?php

namespace App\Support\ScenarioPacks\Manifest;

use App\Models\Organization;
use App\Models\ScenarioPackEntity;
use App\Models\ScenarioPackLoad;
use App\Support\ScenarioPacks\Contracts\ScenarioPackDefinition;
use App\Support\ScenarioPacks\Exceptions\ScenarioPackOwnershipUnknownException;
use App\Support\ScenarioPacks\ScenarioPackLoader;
use App\Support\ScenarioPacks\ScenarioPackLoadResult;
use App\Support\ScenarioPacks\ScenarioPackRemover;
use App\Support\Tenancy\OrganizationDetachGuard;
use App\Support\Tenancy\OrganizationDetachRefused;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        // TASK-1729 phase 2 — valeur par defaut, et ce n'est pas de la
        // commodite : la couture de test de ce service construit des
        // sous-classes anonymes avec DEUX arguments. Un troisieme parametre
        // requis aurait casse ces tests, et la correction aurait porte sur
        // eux plutot que sur le defaut. Meme forme que `ScenarioPackRemover`,
        // qui se donne lui aussi son purgeur par defaut.
        private readonly ScenarioPackRemover $remover = new ScenarioPackRemover,
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

        // ETAPE 4 — le moteur existant, inchange : garde Organization,
        // transaction, verrou, registrar, idempotence.
        //
        // Ce bloc n'attrape QUE `Throwable`, et il rend TOUJOURS l'exception
        // d'origine. C'est structurel, pas cosmetique : `apply()` ecrit des
        // objets metier, et ces ecritures ont leurs propres contraintes
        // d'unicite — une adresse e-mail, un slug de Boucle. Une violation qui
        // vient de la n'a rien a voir avec une course sur le digest. L'englober
        // dans le traitement de course reviendrait a nettoyer la sandbox puis
        // a rendre un chargement gagnant comme s'il s'agissait d'un succes,
        // c'est-a-dire a MASQUER un defaut metier derriere un faux rejeu.
        try {
            $result = $this->loader->load($pack, $organization);
        } catch (Throwable $exception) {
            // Pas de sandbox orpheline silencieuse.
            $this->discard($organization);

            throw $exception;
        }

        // ETAPE 5 — inscription de l'identite approuvee, ISOLEE dans son
        // propre bloc. C'est la seule ecriture dont une violation d'unicite
        // signifie "un autre appel a charge le meme monde approuve pendant que
        // celui-ci travaillait", parce que c'est la seule qui touche l'index
        // unique (pack_id, manifest_digest).
        //
        // L'ecriture est enveloppee dans une transaction, et ce n'est pas un
        // ornement : sous PostgreSQL, une violation de contrainte AVORTE la
        // transaction en cours, et toute instruction suivante echoue en
        // `25P02 — current transaction is aborted`. Le nettoyage du perdant
        // serait alors impossible des qu'un appelant — ou le harnais de test —
        // a ouvert une transaction autour du chargement. Imbriquee,
        // `DB::transaction()` pose un SAVEPOINT : l'echec ne defait que cette
        // ecriture et rend la connexion utilisable pour defaire le reste.
        // SQLite se comporte de meme.
        try {
            DB::transaction(function () use ($result, $manifest): void {
                $result->load->forceFill([
                    'manifest_digest' => $manifest->digest(),
                    'organization_created_by_pack' => true,
                ])->save();
            });
        } catch (UniqueConstraintViolationException) {
            // Course perdue. On defait exactement ce que CETTE invocation a
            // cree, puis on rend le chargement gagnant — un rejeu doit
            // retourner un resultat, pas une erreur.
            $this->discard($organization);

            $winner = $this->findExistingLoad($pack, $manifest);

            if ($winner !== null) {
                return $winner;
            }

            throw ManifestNotLoadableException::concurrentLoadLost($manifest->digest());
        } catch (Throwable $exception) {
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
     *
     * `protected` : un test doit pouvoir simuler l'apparition d'un gagnant
     * ENTRE la recherche de rejeu et l'echec d'`apply()`. Cette fenetre ne se
     * reproduit pas autrement — tout ce qu'`apply()` ecrit vit dans la
     * transaction du loader et disparait avec elle quand il leve.
     */
    protected function findExistingLoad(ScenarioPackDefinition $pack, ScenarioManifest $manifest): ?ManifestSandboxLoadResult
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
     *
     * ## Le defaut corrige — TASK-1729 phase 2, arbitrage MASTER
     *
     * Ce nettoyage etait le plus dangereux des cinq `forceDelete()`
     * d'Organization du depot, et le seul dont l'atteignabilite soit PROUVEE :
     * deux de ses trois appelants (la course perdue sur le digest, et l'echec
     * d'inscription de la provenance) tournent APRES un
     * `loader->load()` reussi ET committe. Les 22 personas du monde existent
     * donc, rattaches a cette Organization.
     *
     * Or l'ancienne ecriture ne supprimait que la ligne de chargement, jamais
     * les comptes. La FK `users.organization_id ON DELETE SET NULL` les
     * DETACHAIT — et depuis A4, un compte sans Organization dont l'adresse est
     * portee ailleurs est un compte MUET : ni login, ni reinitialisation.
     *
     * Et cela cassait l'invariant SANS aucune concurrence. `sandboxEmail()`
     * prefixe l'adresse du slug de la sandbox, et le provisionneur ne reserve
     * les slugs que parmi les Organizations VIVANTES : un `discard()` libere
     * le slug, le chargement suivant du meme manifeste le reprend, et ses
     * membres portent EXACTEMENT les adresses des orphelins laisses par le
     * premier. Au second `discard()`, le `SET NULL` poussait ces lignes dans
     * `users_email_orphan_unique` ou les adresses existaient deja : violation
     * 23505 non rattrapee, et sous PostgreSQL une transaction avortee.
     *
     * ## Ce que fait la correction, et ce qu'elle refuse de faire
     *
     * Elle ne purge RIEN elle-meme. Elle appelle le `ScenarioPackRemover`,
     * c'est-a-dire l'autorite d'ownership du moteur — deja ecrite, deja
     * testee : il purge les entites dont le registre prouve l'ownership
     * `created`, laisse les `reused`, et REFUSE tout quand l'ownership est
     * inconnu. Un compte reel, un compte etranger, un compte seulement
     * reference ne peut donc pas etre supprime ici. Recopier une boucle de
     * purge aurait produit une seconde regle d'ownership — et c'est la copie
     * qu'on oublie de corriger.
     *
     * Puis la garde D1, sous verrou, mesure ce qui est ENCORE rattache : s'il
     * reste un compte dont le detachement rendrait une adresse ambigue, tout
     * est annule.
     *
     * ## Pourquoi un refus ne remonte PAS
     *
     * Les trois appelants sont des chemins d'echec qui doivent rendre
     * l'exception D'ORIGINE — c'est une propriete de ce fichier, ecrite et
     * testee : masquer un defaut metier derriere un incident de nettoyage
     * ferait disparaitre le defaut. Un refus de nettoyage laisse donc la
     * sandbox ENTIERE, sa ligne de chargement incluse, ce qui la laisse
     * retirable par l'ecran Remove — strictement mieux que l'ancien etat, qui
     * la detruisait en orphelinant ses comptes. Le fait est journalise, jamais
     * avale en silence.
     *
     * La transaction est ce qui rend ce « tout ou rien » vrai : si la garde
     * refuse, la purge que le remover vient de faire est annulee avec elle.
     * Aucune suppression partielle n'est possible.
     */
    private function discard(Organization $organization): void
    {
        try {
            DB::transaction(function () use ($organization): void {
                $chargements = ScenarioPackLoad::query()
                    ->where('organization_id', $organization->id)
                    ->pluck('pack_id');

                foreach ($chargements as $packId) {
                    $this->remover->remove((string) $packId, $organization);
                }

                // Ceinture : `remove()` supprime la ligne de chargement de
                // chaque pack qu'il traite, mais une ligne sans `pack_id`
                // exploitable ne doit pas survivre a la sandbox.
                ScenarioPackLoad::query()->where('organization_id', $organization->id)->delete();

                OrganizationDetachGuard::verrouillerEtRefuserSiAmbigu($organization);

                $organization->forceDelete();
            });
        } catch (OrganizationDetachRefused|ScenarioPackOwnershipUnknownException|QueryException $refus) {
            // `QueryException` est dans la liste pour une raison precise, et
            // c'est la meme que les deux autres.
            //
            // La purge du remover peut echouer sur une contrainte `RESTRICT` :
            // T1635/T1636 en ont pose sur les ecritures de points, les votes,
            // les soumissions et les transactions, et une sandbox qui a
            // REELLEMENT servi en porte. `ScenarioLifecycleService` traite deja
            // ce cas comme un refus metier, pas comme un incident.
            //
            // Le laisser remonter d'ici aurait casse l'invariant de ce
            // fichier — « rendre TOUJOURS l'exception d'origine » — en
            // remplacant le defaut qu'on essayait de signaler par un incident
            // de nettoyage. Le `rollback` de la transaction ci-dessus garantit
            // par ailleurs qu'aucune purge partielle ne subsiste.
            Log::warning('TASK-1729 — nettoyage de sandbox refuse, la sandbox est conservee ENTIERE.', [
                'organization_id' => $organization->id,
                'organization_slug' => $organization->slug,
                'raison' => $refus->getMessage(),
            ]);
        }
    }
}
