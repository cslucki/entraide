<?php

namespace App\Support\ScenarioPacks;

use App\Models\ScenarioPackEntity;
use App\Models\ScenarioPackLoad;
use App\Support\ScenarioPacks\Exceptions\ScenarioPackCrossTenantException;
use App\Support\ScenarioPacks\Exceptions\ScenarioPackReusedEntityMutatedException;
use App\Support\ScenarioPacks\Exceptions\ScenarioPackStoragePathCollisionException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Collaborateur fourni a `ScenarioPackDefinition::apply()` (TASK-1240).
 *
 * Traduit les declarations d'un pack ("cette entite avec cette cle interne
 * existe") en lignes de registre `scenario_pack_entities`, qui portent
 * ensuite l'idempotence, le reset et la suppression bornee. Une seule
 * instance sert tout un passage `apply()` : `trackedKeys()` en fin de
 * passage donne exactement ce que CE passage a produit, ce qui permet a
 * `ScenarioPackResetter` de detecter les orphelins d'une version anterieure.
 *
 * TASK-1245 — ownership. Le registre trace la PARTICIPATION ; l'`ownership`
 * dit si ce chargement a le DROIT DE DETRUIRE l'entite :
 *  - fixe a la PREMIERE inscription d'une (`entityType`, `internalKey`) dans
 *    ce `ScenarioPackLoad`, a partir du signal Eloquent `wasRecentlyCreated`
 *    (vrai uniquement pour une instance que ce processus vient d'inserer) ;
 *  - IMMUABLE ensuite : rejouer (`load()`, `reset()`) retrouve la ligne et
 *    n'y touche pas — `wasRecentlyCreated` est alors false pour tout, ce
 *    n'est pas une raison de degrader `created` en `reused` ;
 *  - `reused` = reference seulement : si le pack a MODIFIE une entite qu'il
 *    n'a pas creee (`wasChanged()` apres son `updateOrCreate`), le
 *    chargement est refuse (`ScenarioPackReusedEntityMutatedException`) —
 *    la transaction du loader annule la mutation. Pas de snapshot/restore.
 *
 * Piege documente : `wasRecentlyCreated` est un drapeau d'INSTANCE. Un pack
 * qui cree une entite puis la re-lit (`Model::find()`) et declare la
 * seconde instance la ferait passer pour `reused`. Declarer l'instance
 * rendue par `create()`/`updateOrCreate()`/`firstOrCreate()`.
 */
class ScenarioPackEntityRegistrar
{
    /** @var array<string, true> cle "type|internal_key" -> true, pour ce passage */
    private array $trackedThisRun = [];

    /**
     * Chemins de storage que ce passage a reclames alors qu'ils etaient
     * libres (ni inscrits dans ce chargement, ni occupes) : tout ce qui s'y
     * trouve ensuite a ete ecrit par ce passage. Sert a effacer ces
     * fichiers si le passage echoue (la transaction DB s'annule, le storage
     * non), sans quoi la garde de collision refuserait a jamais un nouvel
     * essai.
     *
     * @var list<array{0: string, 1: string}> [disk, path]
     */
    private array $storagePathsClaimedThisRun = [];

    public function __construct(private readonly ScenarioPackLoad $load) {}

    /**
     * Declare qu'une entite du pack correspond a `$entity`. Idempotent :
     * rejouer avec la meme (`entityType`, `internalKey`) retrouve et met a
     * jour la meme ligne de registre plutot que d'en creer une seconde.
     *
     * Refuse (sans rien ecrire) si `$entity` porte un `organization_id`
     * different de celui du chargement en cours : un pack ne doit jamais
     * pouvoir, meme par bug, ecrire hors de son Organization cible.
     */
    public function track(string $entityType, string $internalKey, Model $entity): Model
    {
        $entityOrganizationId = (string) ($entity->organization_id ?? '');

        if ($entityOrganizationId === '' || $entityOrganizationId !== (string) $this->load->organization_id) {
            throw ScenarioPackCrossTenantException::forEntity($entityType, $internalKey, (string) $this->load->organization_id, $entityOrganizationId);
        }

        $existing = $this->findRegistryRow($entityType, $internalKey);

        if ($existing) {
            // Une entite reutilisee ne doit etre mutee a AUCUN passage : une
            // version ulterieure du pack qui changerait ses valeurs au reset
            // est refusee comme au premier chargement.
            $this->assertReusedEntityUntouched($existing->ownership, $entityType, $internalKey, $entity);

            // Ownership deliberement absent de cet update : fixe une fois
            // pour toutes a la premiere inscription (invariant TASK-1245).
            $existing->update([
                'entity_model' => $entity::class,
                'entity_id' => $entity->getKey(),
            ]);
        } else {
            $ownership = $entity->wasRecentlyCreated
                ? ScenarioPackEntity::OWNERSHIP_CREATED
                : ScenarioPackEntity::OWNERSHIP_REUSED;

            $this->assertReusedEntityUntouched($ownership, $entityType, $internalKey, $entity);

            $nextSequence = ((int) ScenarioPackEntity::query()
                ->where('scenario_pack_load_id', $this->load->id)
                ->max('sequence')) + 1;

            ScenarioPackEntity::query()->create([
                'scenario_pack_load_id' => $this->load->id,
                'organization_id' => $this->load->organization_id,
                'entity_type' => $entityType,
                'internal_key' => $internalKey,
                'entity_model' => $entity::class,
                'entity_id' => $entity->getKey(),
                'sequence' => $nextSequence,
                'ownership' => $ownership,
            ]);
        }

        $this->trackedThisRun[$entityType.'|'.$internalKey] = true;

        return $entity;
    }

    /**
     * A appeler AVANT d'ecrire un fichier de storage pour l'entite
     * (`entityType`, `internalKey`). Un chemin deja occupe n'est acceptable
     * que s'il est celui d'une entite deja inscrite dans CE chargement (le
     * pack reecrit son propre fichier lors d'un rejeu) ; sinon c'est un
     * fichier preexistant, ownership non prouvable -> refus explicite,
     * jamais d'overwrite silencieux (TASK-1245).
     */
    public function assertStoragePathAvailable(string $entityType, string $internalKey, string $disk, string $path): void
    {
        if ($this->findRegistryRow($entityType, $internalKey) !== null) {
            return;
        }

        if (Storage::disk($disk)->exists($path)) {
            throw ScenarioPackStoragePathCollisionException::forPath($entityType, $internalKey, $disk, $path);
        }

        $this->storagePathsClaimedThisRun[] = [$disk, $path];
    }

    /**
     * A appeler par le loader/resetter si `apply()` echoue : efface les
     * fichiers que CE passage a ecrits sur des chemins qui etaient libres
     * (voir `$storagePathsClaimedThisRun`). Ne touche jamais un chemin deja
     * inscrit dans le chargement ni un chemin qui etait occupe avant.
     */
    public function discardStoragePathsClaimedThisRun(): void
    {
        foreach ($this->storagePathsClaimedThisRun as [$disk, $path]) {
            Storage::disk($disk)->delete($path);
        }

        $this->storagePathsClaimedThisRun = [];
    }

    /**
     * @return array<string, true> cle "type|internal_key" -> true, pour tout
     *                             ce qui a ete declare durant ce passage.
     */
    public function trackedKeys(): array
    {
        return $this->trackedThisRun;
    }

    /**
     * `reused` = reference seulement : `wasChanged()` reflete ce que le
     * dernier `save()` de cette instance a reellement ecrit (vide si
     * `updateOrCreate` a retrouve des valeurs identiques, non vide si le
     * pack a modifie ou restaure l'entite). Ne concerne pas `created` (le
     * pack a le droit d'ecrire ce qu'il possede) ni NULL (jamais produit
     * par ce code).
     */
    private function assertReusedEntityUntouched(?string $ownership, string $entityType, string $internalKey, Model $entity): void
    {
        if ($ownership === ScenarioPackEntity::OWNERSHIP_REUSED && $entity->wasChanged()) {
            throw ScenarioPackReusedEntityMutatedException::forEntity(
                $entityType,
                $internalKey,
                $entity::class,
                array_keys($entity->getChanges()),
            );
        }
    }

    private function findRegistryRow(string $entityType, string $internalKey): ?ScenarioPackEntity
    {
        return ScenarioPackEntity::query()
            ->where('scenario_pack_load_id', $this->load->id)
            ->where('entity_type', $entityType)
            ->where('internal_key', $internalKey)
            ->first();
    }
}
