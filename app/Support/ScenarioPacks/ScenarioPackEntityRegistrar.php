<?php

namespace App\Support\ScenarioPacks;

use App\Models\ScenarioPackEntity;
use App\Models\ScenarioPackLoad;
use App\Support\ScenarioPacks\Exceptions\ScenarioPackCrossTenantException;
use Illuminate\Database\Eloquent\Model;

/**
 * Collaborateur fourni a `ScenarioPackDefinition::apply()` (TASK-1240).
 *
 * Traduit les declarations d'un pack ("cette entite avec cette cle interne
 * existe") en lignes de registre `scenario_pack_entities`, qui portent
 * ensuite l'idempotence, le reset et la suppression bornee. Une seule
 * instance sert tout un passage `apply()` : `trackedKeys()` en fin de
 * passage donne exactement ce que CE passage a produit, ce qui permet a
 * `ScenarioPackResetter` de detecter les orphelins d'une version anterieure.
 */
class ScenarioPackEntityRegistrar
{
    /** @var array<string, true> cle "type|internal_key" -> true, pour ce passage */
    private array $trackedThisRun = [];

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

        $existing = ScenarioPackEntity::query()
            ->where('scenario_pack_load_id', $this->load->id)
            ->where('entity_type', $entityType)
            ->where('internal_key', $internalKey)
            ->first();

        if ($existing) {
            $existing->update([
                'entity_model' => $entity::class,
                'entity_id' => $entity->getKey(),
            ]);
        } else {
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
            ]);
        }

        $this->trackedThisRun[$entityType.'|'.$internalKey] = true;

        return $entity;
    }

    /**
     * @return array<string, true> cle "type|internal_key" -> true, pour tout
     *                             ce qui a ete declare durant ce passage.
     */
    public function trackedKeys(): array
    {
        return $this->trackedThisRun;
    }
}
