<?php

namespace App\Support\ScenarioManager;

use App\Models\Organization;
use App\Models\ScenarioManifestVersion;
use App\Models\ScenarioPackLoad;
use App\Models\User;
use App\Support\ScenarioPacks\Manifest\ManifestNotLoadableException;
use App\Support\ScenarioPacks\Manifest\ManifestSandboxLoadResult;
use App\Support\ScenarioPacks\Manifest\ManifestSandboxLoadService;
use App\Support\ScenarioPacks\Manifest\ManifestScenarioPack;
use App\Support\ScenarioPacks\Manifest\ScenarioManifest;
use App\Support\ScenarioPacks\Exceptions\ScenarioPackNotLoadedException;
use App\Support\ScenarioPacks\Exceptions\ScenarioPackOrganizationNotAllowedException;
use App\Support\ScenarioPacks\Exceptions\ScenarioPackOwnershipUnknownException;
use App\Support\ScenarioPacks\ScenarioPackOrganizationGuard;
use App\Support\ScenarioPacks\ScenarioPackRemover;
use App\Support\AssignData\DatasetClassification;
use App\Support\AssignData\DatasetRegistry;
use App\Support\ScenarioPacks\ScenarioPackResetter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1650 — approuver, charger, reinitialiser, retirer.
 *
 * ## Cette classe CABLE, elle ne recharge pas
 *
 * Le moteur existe depuis T1642 a T1644 : {@see ManifestSandboxLoadService}
 * valide, compare au digest approuve, cree une Organization NEUVE, charge par
 * le `ScenarioPackLoader`, inscrit la provenance, et nettoie derriere lui si
 * quoi que ce soit echoue. Il rend meme le chargement EXISTANT sur un rejeu,
 * ce que le CDC 13.3 demande.
 *
 * Ecrire ici un second chemin de chargement serait la pire decision possible :
 * deux chemins divergeraient, et la divergence ferait naitre un monde faux —
 * un monde dont personne ne saurait dire duquel des deux il vient. Cette
 * classe traduit donc un ETAT ADMINISTRATIF en appel moteur, et rien d'autre.
 *
 * ## Ce qu'elle garde, que le moteur ne peut pas garder
 *
 * Le moteur ne connait que du JSON et un digest. Il ignore qu'il existe des
 * versions, des etats, des approbations. Les preconditions du CDC 13.1 sont
 * donc ici :
 *
 * - etat VALID ;
 * - digest inchange depuis l'approbation ;
 * - approbation humaine presente ;
 * - revalidation serveur — assuree par le moteur, qui revalide TOUJOURS ;
 * - aucun ciblage d'un tenant existant — assure par construction, aucune
 *   methode ne prend d'Organization en parametre.
 *
 * ## Fail-closed, sur DEUX gardes qui ne protegent pas la meme chose
 *
 * 1. **Le moteur refuse de TOUCHER** une Organization dont la provenance
 *    sandbox n'est pas prouvee : {@see ScenarioPackOrganizationGuard} leve des
 *    qu'elle n'est ni dans l'allowlist commitee, ni nee comme sandbox. C'est
 *    la garde principale, et elle protege le VIDAGE du monde. Une Organization
 *    cliente ne peut donc pas etre videe, quel que soit l'appelant.
 * 2. **Cette classe refuse de SUPPRIMER** une Organization dont
 *    `organization_created_by_pack` et `scenario_sandbox_created_at` ne
 *    temoignent pas tous les deux. Le moteur ne supprime jamais d'Organization
 *    (CDC 14.2) : la decision est ici, donc la preuve aussi.
 *
 * Les deux sont necessaires. Sans la premiere, on viderait un tenant client ;
 * sans la seconde, on supprimerait une Organization que le pack a seulement
 * remplie.
 */
class ScenarioLifecycleService
{
    public function __construct(
        private readonly ManifestSandboxLoadService $loadService,
        private readonly ScenarioPackResetter $resetter,
        private readonly ScenarioPackRemover $remover,
        private readonly ScenarioSandboxPreflight $preflight,
    ) {}

    /**
     * L'etape HUMAINE (CDC 12.2).
     *
     * « VALID techniquement ne signifie pas encore autorise a Load. Le
     * SuperAdmin doit confirmer le contenu EXACT. » L'approbation fige donc le
     * digest qu'on a montre a l'humain : si le document change ensuite,
     * T1649 efface cette approbation, et `approvalMatchesCurrentDigest()`
     * redevient faux.
     */
    public function approve(ScenarioManifestVersion $version, User $approver): ScenarioManifestVersion
    {
        $this->refuserSiPasValide($version);

        if ($version->digest === null) {
            throw ScenarioVersionRefused::notValidated();
        }

        // Deja approuve : on ne REECRIT pas la trace.
        //
        // `approved_by` et `approved_at` sont la seule trace d'audit de la
        // porte humaine. Sans cette garde, un POST direct — ou un formulaire
        // reste ouvert dans un onglet — permettait a un autre SuperAdmin de
        // devenir, des semaines apres le chargement, l'approbateur enregistre
        // d'un contenu qu'il n'a jamais vu.
        if ($version->approvalMatchesCurrentDigest()) {
            return $version;
        }

        $version->forceFill([
            'approved_digest' => $version->digest,
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ])->save();

        return $version;
    }

    /**
     * Load (CDC 13).
     *
     * Rend le resultat du moteur, y compris quand il s'agit d'un REJEU : meme
     * `(pack_id, manifest_digest)` rend le chargement existant, et l'ecran dit
     * « deja charge », pas une erreur (CDC 13.3).
     */
    public function load(ScenarioManifestVersion $version): ManifestSandboxLoadResult
    {
        $this->refuserSiPasValide($version);

        // L'approbation est la SEULE porte. `approvalMatchesCurrentDigest()`
        // compare l'approbation au digest COURANT : une version approuvee puis
        // modifiee ne passe pas, meme si elle a ete revalidee depuis.
        if (! $version->approvalMatchesCurrentDigest()) {
            throw ScenarioVersionRefused::notApproved();
        }

        $this->refuserSiLeMondeAppartientADejaAUneAutreVersion($version);

        // La revalidation serveur (CDC 13.1) peut echouer sur un document
        // pourtant approuve : le Validator lit la banque d'avatars SUR
        // DISQUE, et un index absent rend INVALID un document dont le digest
        // n'a pas bouge. Le refus doit alors arriver comme une phrase — c'est
        // exactement le cas ou le produit a raison de dire non.
        $resultat = $this->enTraduisantLeRefusDuMoteur(
            fn (): ManifestSandboxLoadResult => $this->loadService->load(
                $version->json_source,
                (string) $version->approved_digest
            )
        );

        // Le lien administratif : c'est LUI qui fait passer la version en
        // LOADED, etat derive que T1646 a volontairement laisse sans colonne.
        //
        // Il s'ecrit sous VERROU, et la version est RELUE juste avant.
        // Mesure : sans cela, supprimer la version pendant que le monde se
        // charge — ce que le CRUD autorise, puisqu'elle n'est pas encore
        // chargee — faisait porter l'UPDATE sur zero ligne, SANS erreur. Il
        // restait alors une sandbox, ses 22 comptes, et plus aucun ecran
        // capable de les retirer : Remove exige une version.
        $versionDisparue = DB::transaction(function () use ($version, $resultat): bool {
            // Le CHARGEMENT est verrouille AVANT qu'on decide s'il a un
            // proprietaire.
            //
            // Exigence MASTER : « deux requetes concurrentes ne doivent pas
            // pouvoir adopter le meme Load. » Le controle fait plus haut, hors
            // transaction, sert a refuser vite et a bien parler ; c'est CELUI
            // d'ici qui fait autorite, parce qu'il est pris sous verrou et
            // dans la meme transaction que l'ecriture du lien.
            $chargement = ScenarioPackLoad::query()
                ->lockForUpdate()
                ->find($resultat->packLoad->load->id);

            if ($chargement === null) {
                throw ScenarioVersionRefused::versionDisparue();
            }

            $this->refuserSiLeChargementAppartientADejaAUneAutreVersion($version, $chargement);

            $courante = ScenarioManifestVersion::query()->lockForUpdate()->find($version->getKey());

            if ($courante === null) {
                return true;
            }

            $courante->forceFill(['scenario_pack_load_id' => $chargement->getKey()])->save();
            $version->forceFill(['scenario_pack_load_id' => $chargement->getKey()])->syncOriginal();

            return false;
        });

        // Le rattrapage est DEHORS de la transaction ci-dessus, et c'est tout
        // le sujet.
        //
        // Trouve en revue : nettoyage et `throw` etaient dans le MEME
        // `DB::transaction()`. Or `transaction()` attrape le Throwable,
        // ROLLBACK, puis relance — le nettoyage etait donc systematiquement
        // defait par le refus qui le suivait. La sandbox, elle, avait ete
        // committee plus tot par le moteur et survivait. On obtenait
        // exactement l'etat que le commentaire ci-dessus dit vouloir eviter :
        // une sandbox, ses comptes, et plus aucun ecran capable de les
        // retirer.
        if ($versionDisparue) {
            // On ne defait QUE ce que CETTE invocation a cree.
            //
            // Trouve en revue, et c'etait grave : sur un REJEU — donc sur le
            // chemin d'adoption d'un chargement orphelin — ce bloc detruisait
            // un monde qui pouvait vivre depuis des semaines, sans preflight,
            // hors transaction et hors traducteur de refus. Le rattrapage
            // devenait pire que le mal qu'il repare.
            //
            // `wasReplay` dit exactement ce qu'il faut savoir : si le moteur a
            // RENDU un chargement existant, cette invocation n'a rien cree, et
            // il n'y a donc rien a defaire.
            if (! $resultat->wasReplay) {
                $this->remover->remove($resultat->packLoad->load->pack_id, $resultat->organization);

                if ($resultat->organization->scenario_sandbox_created_at !== null) {
                    $resultat->organization->forceDelete();
                }
            }

            throw ScenarioVersionRefused::versionDisparue();
        }

        return $resultat;
    }

    /**
     * Reset (CDC 14.1).
     *
     * « Reset restaure le monde au contenu du digest EFFECTIVEMENT charge,
     * enregistre dans `scenario_pack_loads.manifest_digest`. » Ce n'est pas le
     * digest courant de la version : les deux peuvent differer, et c'est le
     * digest charge qui decrit le monde qu'on restaure.
     *
     * La sandbox reste la MEME, et c'est tout l'objet du geste : reinitialiser
     * sert a retrouver un monde propre SANS perdre son adresse.
     */
    public function reset(ScenarioManifestVersion $version): Organization
    {
        $chargement = $this->chargementDeLaVersion($version);

        // Le chemin que le CDC 14.1 decrit, mot pour mot : « retrouver la
        // version, verifier le chargement vivant et le digest, reconstruire le
        // ScenarioManifest APPROUVE puis REUTILISER le Resetter moteur ».
        //
        // La tentation etait d'enchainer un Remove puis un Load. Elle est
        // FAUSSE, et le test l'a montre : le Remove supprime la ligne de
        // chargement, donc le Load qui suit ne reconnait plus
        // `(pack_id, manifest_digest)` et provisionne une SECONDE sandbox en
        // abandonnant la premiere. Reset doit conserver la meme sandbox.
        //
        // Le digest utilise est celui EFFECTIVEMENT charge, pas le digest
        // courant de la version : les deux peuvent differer, et c'est le monde
        // charge qu'on restaure, pas celui qu'on aimerait avoir.
        // AVANT toute mutation, et le meme controle que Remove : un Reset
        // exact est destructif vis-a-vis de tout ce qui a ete fait apres le
        // Load. Proteger le seul Remove laisserait cette porte ouverte.
        $this->preflight->refuserSiContenuEtranger($chargement, $chargement->organization);

        return $this->enTraduisantLaGardeDuMoteur(function () use ($version, $chargement): Organization {
            // DANS le traducteur, et c'est le point : `fromApprovedJson()`
            // REVALIDE, et peut refuser un document dont le digest n'a pas
            // bouge — le Validator lit la banque d'avatars SUR DISQUE, et un
            // index absent suffit. Trouve en revue : `load()` traduisait ce
            // refus en phrase, `reset()` le laissait remonter en 500.
            $manifeste = ScenarioManifest::fromApprovedJson(
                $version->json_source,
                (string) $chargement->manifest_digest
            );

            // `exact: true` : le CDC 14.1 promet une RESTAURATION, pas une
            // reapplication idempotente. Sans ce mode, un renommage survit au
            // Reset — mesure faite en revue.
            $this->resetter->reset(new ManifestScenarioPack($manifeste), $chargement->organization, exact: true);

            return $chargement->organization;
        });
    }

    /**
     * Remove (CDC 14.2).
     *
     * Le Remover moteur vide le monde mais ne supprime PAS l'Organization —
     * c'est ici que la decision se prend, et seulement sur PREUVE :
     *
     * - `organization_created_by_pack` etait vrai sur ce chargement ;
     * - `scenario_sandbox_created_at` est pose, et le provisionneur est le
     *   SEUL endroit du depot qui le pose.
     *
     * Sans ces deux preuves, le monde est vide et l'Organization RESTE. C'est
     * un resultat moins propre qu'une suppression, et infiniment preferable a
     * la suppression d'une Organization cliente.
     *
     * La version administrative reste VALID : son digest approuve n'a pas
     * change, et elle redeviendra chargeable.
     */
    public function remove(ScenarioManifestVersion $version): void
    {
        $chargement = $this->chargementDeLaVersion($version);

        // Une AUTRE version declare-t-elle ce meme chargement ?
        //
        // C'est possible sans rien forcer : le couple (pack_id, digest) se
        // calcule sur le DOCUMENT, pas sur `scenario_key`. Coller deux fois le
        // meme JSON sous deux cles differentes produit donc deux versions qui
        // pointent le meme monde. Mesure : retirer l'une detruisait la sandbox
        // que l'autre continuait d'afficher, et son lien passait a NULL en
        // silence par la FK. On refuse, en NOMMANT l'autre version.
        $autre = ScenarioManifestVersion::query()
            ->where('scenario_pack_load_id', $chargement->getKey())
            ->whereKeyNot($version->getKey())
            ->first();

        if ($autre !== null) {
            throw ScenarioVersionRefused::loadSharedWith($autre->name, $autre->version);
        }

        $organization = $chargement->organization;

        // AVANT toute mutation. Les deux preuves de provenance disent d'ou
        // vient l'Organization ; celle-ci dit ce qu'elle CONTIENT.
        $this->preflight->refuserSiContenuEtranger($chargement, $organization);

        // Lues AVANT, parce que le moteur supprime la ligne de chargement.
        $creeeParLePack = (bool) $chargement->organization_created_by_pack;
        $estUneSandbox = $organization->scenario_sandbox_created_at !== null;

        $this->enTraduisantLaGardeDuMoteur(function () use ($chargement, $organization, $creeeParLePack, $estUneSandbox, $version): void {
            DB::transaction(function () use ($chargement, $organization, $creeeParLePack, $estUneSandbox, $version): void {
                $this->remover->remove($chargement->pack_id, $organization);

                if ($creeeParLePack && $estUneSandbox) {
                    // AVANT de supprimer : tout ce que la FK ferait REMONTER
                    // au niveau PLATEFORME.
                    //
                    // Dans ce produit, `organization_id IS NULL` ne veut pas
                    // dire « orpheline » sur toutes les tables : sur certaines
                    // il veut dire PLATEFORME — la ligne s'applique alors en
                    // repli a TOUTES les Organizations. Une FK en
                    // `ON DELETE SET NULL` transforme donc un `forceDelete()`
                    // de sandbox en PROMOTION silencieuse du contenu d'un
                    // monde de demonstration vers le produit entier.
                    //
                    // La premiere ecriture ne traitait que
                    // `translation_overrides`. Une relecture a montre qu'il y
                    // en a SIX, et surtout que le depot tient deja le registre
                    // canonique de cette semantique. On le LIT plutot que de
                    // recopier une liste qui se perimerait en silence — la
                    // meme lecon que le preflight a coutee.
                    $this->supprimerCeQuiDeviendraitPlateforme($organization);

                    // `Organization` est en SoftDeletes : une suppression douce
                    // laisserait une ligne que le slug garde occupe et qu'aucun
                    // ecran ne montre plus. Une sandbox dont la provenance est
                    // prouvee se supprime REELLEMENT.
                    $organization->forceDelete();
                }

                // La version perd son chargement et redevient simplement VALID.
                $version->forceFill(['scenario_pack_load_id' => null])->save();
            });
        });
    }

    /**
     * La garde du moteur parle en exception technique ; l'ecran a besoin d'une
     * phrase.
     *
     * Sans cette traduction, tenter de vider une Organization dont la
     * provenance n'est pas prouvee donnerait une 500 — alors que c'est
     * exactement le cas ou le produit a raison de dire non.
     *
     * @template T
     *
     * @param  \Closure():T  $geste
     * @return T
     */
    private function enTraduisantLaGardeDuMoteur(\Closure $geste): mixed
    {
        try {
            return $geste();
        } catch (ScenarioPackOrganizationNotAllowedException) {
            throw ScenarioVersionRefused::notASandbox();
        } catch (ScenarioPackNotLoadedException $refus) {
            // Le Resetter exige un chargement vivant pour CETTE Organization.
            throw ScenarioVersionRefused::engineRefused($refus->getMessage());
        } catch (ScenarioPackOwnershipUnknownException $refus) {
            // Le moteur refuse AVANT toute suppression quand il ne sait pas a
            // qui appartient une entite. C'est un fail-closed exemplaire, et
            // il doit se lire comme tel.
            throw ScenarioVersionRefused::engineRefused($refus->getMessage());
        } catch (ManifestNotLoadableException $refus) {
            throw ScenarioVersionRefused::revalidationFailed($refus->getMessage());
        } catch (QueryException $refus) {
            // La CEINTURE des contraintes RESTRICT (arbitrage MASTER du 27/09,
            // MASTER_DECISION_RESTRICT = A).
            //
            // T1635/T1636 ont mis en `ON DELETE RESTRICT` les ecritures de
            // points, les votes, les soumissions et les transactions. Le Reset
            // EXACT purge les personas, ce que le reset idempotent ne faisait
            // pas : des qu'une sandbox a reellement SERVI, la purge peut donc
            // echouer. Sans ce bras, l'echec sortait en 500 pour ce qui est un
            // refus METIER.
            //
            // Trois proprietes tenues ici, et testees :
            //
            // 1. la transaction du moteur est DEJA entierement annulee quand
            //    on arrive ici — le catch est a l'exterieur, et PostgreSQL
            //    n'est donc plus en etat `25P02` : on peut parler ;
            // 2. rien ne tente de contourner la contrainte, et aucune
            //    suppression ne suit l'echec ;
            // 3. le message rendu est GENERIQUE ; le detail technique, qui
            //    nomme tables et colonnes, part dans les journaux.
            Log::warning('Scenario Manager : suppression bloquee par une contrainte de la base.', [
                'raison' => $refus->getMessage(),
            ]);

            throw ScenarioVersionRefused::protectedData();
        } catch (\LogicException $refus) {
            // La ceinture du purger : une entite du registre qui appartient a
            // une AUTRE Organization que la cible. Le moteur s'arrete AVANT de
            // toucher quoi que ce soit, et c'est exactement ce qu'il faut.
            //
            // Le catch est large par le TYPE, etroit par le CHEMIN : il
            // n'enveloppe que l'appel moteur, et il ne transforme pas l'echec
            // en succes — il le rend comme un REFUS, en conservant le message
            // d'origine pour que l'operateur lise ce que le moteur a dit.
            throw ScenarioVersionRefused::engineRefused($refus->getMessage());
        }
    }

    /**
     * Le moteur refuse de charger : ce n'est pas une panne.
     *
     * `ManifestNotLoadableException` couvre trois cas, et tous les trois sont
     * des refus METIER : document devenu invalide depuis l'approbation, digest
     * qui ne correspond plus, course perdue sur un chargement concurrent. Sans
     * cette traduction, chacun donnerait une 500.
     *
     * @template T
     *
     * @param  \Closure():T  $geste
     * @return T
     */
    private function enTraduisantLeRefusDuMoteur(\Closure $geste): mixed
    {
        try {
            return $geste();
        } catch (ManifestNotLoadableException $refus) {
            throw ScenarioVersionRefused::revalidationFailed($refus->getMessage());
        } catch (\LogicException $refus) {
            // Le fail-closed du chargeur, notamment quand un document declare
            // plus d'un persona responsable. Le Validator le refuse desormais
            // en amont, mais un document APPROUVE avant cette regle peut
            // encore exister : il doit alors rendre une phrase, pas une 500.
            //
            // `reset()` traduisait deja ce cas et `load()` non : la meme
            // dissymetrie que MASTER avait relevee sur le preflight.
            throw ScenarioVersionRefused::engineRefused($refus->getMessage());
        }
    }

    /**
     * Le chargement de CETTE version, ou un refus.
     *
     * Deux verifications, pas une : que la version soit chargee, et que le
     * chargement lui appartienne VRAIMENT. La seconde parait redondante —
     * c'est la meme colonne — mais elle garde la PROPRIETE : le jour ou
     * quelqu'un pourra relier une version a un chargement autrement, c'est ce
     * test-ci qui dira non.
     */
    private function chargementDeLaVersion(ScenarioManifestVersion $version): ScenarioPackLoad
    {
        if (! $version->isLoaded()) {
            throw ScenarioVersionRefused::notLoaded();
        }

        $chargement = ScenarioPackLoad::query()->find($version->scenario_pack_load_id);

        if ($chargement === null) {
            throw ScenarioVersionRefused::loadMismatch();
        }

        // `withTrashed()` est DELIBERE. `Organization` est en SoftDeletes, et
        // la relation ordinaire rend `null` sur une sandbox mise a la
        // corbeille. On repondait alors « le chargement n'appartient pas a
        // cette version » — une phrase FAUSSE : il lui appartient, c'est
        // l'Organization qui etait masquee. Et comme Reset et Remove
        // refusaient tous les deux, la sandbox et ses comptes restaient en
        // base POUR TOUJOURS, sans aucun geste produit capable de les retirer.
        $organization = Organization::withTrashed()->find($chargement->organization_id);

        if ($organization === null) {
            throw ScenarioVersionRefused::loadMismatch();
        }

        $chargement->setRelation('organization', $organization);

        return $chargement;
    }

    /**
     * Ce qui, en perdant son Organization, deviendrait du contenu PLATEFORME.
     *
     * La liste n'est pas ecrite ici : elle est LUE sur
     * {@see DatasetRegistry}, qui classe deja chaque table du depot et dont la
     * rubrique `GlobalNullValid` dit exactement « NULL signifie Plateforme ».
     * Une table ajoutee demain a cette rubrique sera traitee sans que
     * personne y pense — alors qu'une liste recopiee ici aurait vieilli en
     * silence, ce que cette TASK a deja paye une fois sur le preflight.
     *
     * Bornes, volontairement : seules les lignes qui portent l'identifiant de
     * CETTE sandbox. Rien ne touche a une autre Organization, ni aux lignes
     * plateforme existantes — celles-la portent deja `NULL` et ne viennent pas
     * d'ici.
     *
     * A noter pour qui relira, parce que le depot porte la lecon inverse :
     * on lit souvent ici qu'une FK posee par `Schema::table()` n'existe pas en
     * SQLite. Ce n'est PLUS vrai en Laravel 13, qui reconstruit la table.
     * MESURE : le sabotage de ce nettoyage fait rougir le test en nommant
     * `categories`, dont la FK est justement posee par `Schema::table()`. La
     * promotion est donc demontrable sur les deux moteurs.
     */
    private function supprimerCeQuiDeviendraitPlateforme(Organization $organization): void
    {
        foreach ((new DatasetRegistry)->all() as $dataset) {
            if ($dataset->classification !== DatasetClassification::GlobalNullValid) {
                continue;
            }

            if (! Schema::hasTable($dataset->table) || ! Schema::hasColumn($dataset->table, 'organization_id')) {
                continue;
            }

            DB::table($dataset->table)->where('organization_id', $organization->getKey())->delete();
        }
    }

    private function refuserSiPasValide(ScenarioManifestVersion $version): void
    {
        if (! $version->isValid()) {
            throw ScenarioVersionRefused::notValid();
        }
    }

    /**
     * Exclusivite du chargement administratif (verdict MASTER du 27/09).
     *
     * « Le moteur peut techniquement retourner le meme chargement pour un
     * meme `pack_id + manifest_digest`, et cette idempotence doit rester
     * intacte. Mais au niveau Scenario Manager, une sandbox vivante doit avoir
     * une seule version administrative proprietaire. »
     *
     * Consequence stricte : la garde est ICI, et l'unicite moteur n'est pas
     * touchee. Deux versions peuvent porter le meme contenu en DRAFT ou en
     * VALID ; une seule peut etre LOADED sur un chargement vivant donne.
     *
     * Le rejeu de la version PROPRIETAIRE reste idempotent : elle retrouve son
     * propre chargement, et c'est le cas que le CDC 13.3 decrit.
     *
     * Un chargement SANS proprietaire — la version a disparu, l'adoption est
     * le seul chemin de reprise — est laisse passer volontairement. Refuser la
     * rendrait definitivement inaccessible.
     */
    private function refuserSiLeMondeAppartientADejaAUneAutreVersion(ScenarioManifestVersion $version): void
    {
        $document = json_decode((string) $version->json_source, true);

        if (! is_array($document) || ! is_string($document['id'] ?? null)) {
            return;
        }

        $chargement = ScenarioPackLoad::query()
            ->where('pack_id', self::packIdPour($document['id']))
            ->where(ScenarioPackLoad::MANIFEST_DIGEST, (string) $version->approved_digest)
            ->first();

        if ($chargement === null) {
            return;
        }

        $this->refuserSiLeChargementAppartientADejaAUneAutreVersion($version, $chargement);
    }

    /**
     * Le predicat d'exclusivite, PARTAGE par les deux controles.
     *
     * Un seul endroit decide « ce chargement a-t-il deja un proprietaire ? ».
     * T1639 a deja coute une divergence de cette forme — le comptage et le
     * transfert ne portaient pas le meme filtre — et la lecon tient ici :
     * deux formulations du meme predicat finissent par ne plus dire la meme
     * chose, et c'est la version permissive qui gagne.
     *
     * ## L'adoption d'un chargement ORPHELIN
     *
     * Autorisee par MASTER comme mecanisme de RECUPERATION, sous conditions
     * strictes, toutes verifiees ici :
     *
     * - aucune autre version vivante ne possede ce chargement ;
     * - le digest approuve de la version correspond EXACTEMENT au
     *   `manifest_digest` du chargement ;
     * - l'Organization du chargement est bien une sandbox nee comme telle.
     *
     * Sans elles, « adopter » deviendrait un moyen de s'attribuer le monde
     * d'un autre.
     */
    private function refuserSiLeChargementAppartientADejaAUneAutreVersion(
        ScenarioManifestVersion $version,
        ScenarioPackLoad $chargement
    ): void {
        $proprietaire = ScenarioManifestVersion::query()
            ->where('scenario_pack_load_id', $chargement->getKey())
            ->whereKeyNot($version->getKey())
            ->first();

        if ($proprietaire !== null) {
            throw ScenarioVersionRefused::loadAlreadyOwnedBy(
                (string) $proprietaire->name,
                (string) $proprietaire->version
            );
        }

        // Orphelin : l'adoption doit encore PROUVER qu'il s'agit bien du monde
        // de cette version, et d'une sandbox.
        if ((string) $chargement->manifest_digest !== (string) $version->approved_digest) {
            throw ScenarioVersionRefused::loadMismatch();
        }

        $sandbox = Organization::query()
            ->withTrashed()
            ->whereKey($chargement->organization_id)
            ->first();

        if ($sandbox === null || $sandbox->scenario_sandbox_created_at === null) {
            throw ScenarioVersionRefused::notASandbox();
        }
    }

    /**
     * L'identifiant de pack d'un manifeste, tel que le moteur le forge.
     *
     * Expose pour que les tests n'aient pas a le deviner.
     */
    public static function packIdPour(string $scenarioId): string
    {
        return ManifestScenarioPack::PACK_ID_PREFIX.$scenarioId;
    }
}
