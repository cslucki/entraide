<?php

namespace App\Support\ScenarioPacks;

use App\Models\Organization;
use App\Models\ScenarioPackEntity;
use App\Models\ScenarioPackLoad;
use App\Support\ScenarioPacks\Contracts\ScenarioPackDefinition;
use App\Support\ScenarioPacks\Exceptions\ScenarioPackNotLoadedException;
use App\Support\ScenarioPacks\Exceptions\ScenarioPackOwnershipUnknownException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reset d'un scenario pack (TASK-1240), conforme au contrat TASK-1239 S11 :
 * "retour a l'etat obtenu immediatement apres un chargement propre", pas une
 * suppression totale.
 *
 * Mecanisme : reapplique le pack (comme un chargement idempotent), puis
 * retire les entites qui existaient dans le registre AVANT ce passage mais
 * que le passage n'a pas re-declarees — des orphelins issus d'une version
 * anterieure du pack. Le reset ne touche jamais une entite qui n'est pas
 * dans le registre de CE chargement.
 *
 * TASK-1245 : la reapplication ne modifie JAMAIS l'ownership des lignes deja
 * inscrites (fixe a la premiere inscription, cf. registrar) ; un orphelin
 * n'est purge physiquement que s'il est `created` (via la meme primitive que
 * le remover), un orphelin `reused` est seulement desinscrit, un orphelin a
 * ownership inconnu fait refuser le reset AVANT toute suppression.
 */
class ScenarioPackResetter
{
    public function __construct(private readonly ScenarioPackEntityPurger $purger = new ScenarioPackEntityPurger) {}

    /**
     * TASK-1650 — `$exact` restaure aussi les ATTRIBUTS, et il est OPTIONNEL.
     *
     * Le reset historique reapplique le pack de maniere idempotente : il
     * recree ce qui MANQUE, puis retire les orphelins. Il ne defait donc pas
     * ce qui a ete MODIFIE. Mesure : renommer une Boucle dans une sandbox, la
     * reinitialiser, et elle garde son nouveau nom.
     *
     * Le CDC 14.1 du Scenario Manager promet pourtant de « restaurer le monde
     * au contenu du digest effectivement charge ». D'ou ce mode, qui PURGE
     * d'abord les entites que le pack a creees, puis reapplique : la
     * restauration est alors exacte par construction, dans la MEME sandbox.
     *
     * Pourquoi un parametre plutot qu'un changement de comportement : ce
     * Resetter sert aussi `scenario-pack:reset`, la commande des packs
     * historiques, qui vise de VRAIES Organizations de l'allowlist. Changer sa
     * semantique par defaut aurait modifie ce que fait cette commande sur des
     * donnees reelles — bien au-dela du perimetre autorise. Les appelants
     * existants gardent donc exactement leur comportement.
     */
    public function reset(ScenarioPackDefinition $pack, Organization $organization, bool $exact = false): ScenarioPackLoadResult
    {
        ScenarioPackOrganizationGuard::assertAllowed($organization);

        return DB::transaction(function () use ($pack, $organization, $exact) {
            // Verrou pose AUSSI sur une sandbox en corbeille. Voir la raison
            // detaillee dans ScenarioPackRemover::verrouiller() : sous la
            // portee par defaut, un modele SoftDeletes mis a la corbeille ne
            // verrouille RIEN et le retour jete le cachait.
            $verrouillee = Organization::query()
                ->withTrashed()
                ->whereKey($organization->id)
                ->lockForUpdate()
                ->first();

            if ($verrouillee === null) {
                throw new \LogicException(
                    'Organization introuvable au moment de verrouiller : '.$organization->id
                );
            }

            $load = ScenarioPackLoad::query()
                ->where('organization_id', $organization->id)
                ->where('pack_id', $pack->packId())
                ->first();

            if ($load === null) {
                throw ScenarioPackNotLoadedException::forPack($pack->packId(), $organization->slug);
            }

            $before = ScenarioPackEntity::query()
                ->where('scenario_pack_load_id', $load->id)
                ->get();

            if ($exact) {
                // Purge AVANT reapplication, dans l'ordre inverse d'inscription
                // — le meme ordre que le Remover, pour les memes raisons de
                // dependances.
                //
                // Une propriete INCONNUE fait refuser AVANT toute suppression :
                // on ne detruit pas ce dont on ignore a qui il appartient.
                $inconnus = $before->filter(fn (ScenarioPackEntity $e) => $e->hasUnknownOwnership());

                if ($inconnus->isNotEmpty()) {
                    throw ScenarioPackOwnershipUnknownException::forLoad(
                        $pack->packId(),
                        $organization->slug,
                        'reset exact (purge prealable)',
                        $inconnus->countBy('entity_type')->all(),
                    );
                }

                foreach ($before->sortByDesc('sequence') as $entite) {
                    if ($entite->isOwnedByPack()) {
                        $this->purger->purge($entite, $organization);
                    }

                    $entite->delete();
                }

                // Le registre de CE chargement est vide : la reapplication qui
                // suit recree tout, et la boucle d'orphelins ci-dessous n'a
                // plus rien a balayer.
                $before = $before->take(0);
            }

            $registrar = new ScenarioPackEntityRegistrar($load);
            try {
                $pack->apply($organization, $registrar);
            } catch (Throwable $e) {
                // Meme raison que dans ScenarioPackLoader : le storage ne
                // suit pas le rollback de la transaction.
                $registrar->discardStoragePathsClaimedThisRun();

                throw $e;
            }

            $trackedThisRun = $registrar->trackedKeys();

            $orphans = $before->reject(
                fn (ScenarioPackEntity $entity) => isset($trackedThisRun[$entity->entity_type.'|'.$entity->internal_key])
            )->sortByDesc('sequence');

            $unknown = $orphans->filter(fn (ScenarioPackEntity $entity) => $entity->hasUnknownOwnership());
            if ($unknown->isNotEmpty()) {
                throw ScenarioPackOwnershipUnknownException::forLoad(
                    $pack->packId(),
                    $organization->slug,
                    'reset (retrait des orphelins)',
                    $unknown->countBy('entity_type')->all(),
                );
            }

            $removedOrphans = [];
            foreach ($orphans as $orphan) {
                if ($orphan->isOwnedByPack()) {
                    $this->purger->purge($orphan, $organization);
                }
                $removedOrphans[] = $orphan->entity_type.'|'.$orphan->internal_key;
                $orphan->delete();
            }

            $load->pack_version = $pack->packVersion();
            $load->reset_at = now();
            $load->save();

            $counts = ScenarioPackEntity::query()
                ->where('scenario_pack_load_id', $load->id)
                ->selectRaw('entity_type, count(*) as aggregate')
                ->groupBy('entity_type')
                ->pluck('aggregate', 'entity_type')
                ->map(fn ($count) => (int) $count)
                ->all();

            return new ScenarioPackLoadResult($load, false, $counts, $removedOrphans);
        });
    }
}
