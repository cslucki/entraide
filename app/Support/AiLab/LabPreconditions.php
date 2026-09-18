<?php

namespace App\Support\AiLab;

use App\Ai\ProviderResolver;
use App\Models\Dossier;
use App\Models\DossierChunk;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\ScenarioPackLoad;
use App\Models\User;
use App\Services\Dossiers\DerivedChunkEligibility;
use App\Support\Ai\AiEconomicGuard;
use App\Support\ScenarioPacks\Packs\AiLabPack;

/**
 * TASK-1591 / CDC-NIGHT L-C_CORE — les PRECONDITIONS d'un scenario, etablies
 * par rapport a leur valeur ATTENDUE (CDC-03 §6.3, CDC-NIGHT §8.2-8.3).
 *
 *   YES          toutes les preconditions verifiables correspondent  -> le pipeline est juge
 *   NO           une precondition verifiee ne correspond pas          -> first_failed_component = data
 *   UNAVAILABLE  une precondition necessaire ne peut pas etre etablie -> pipeline NON juge
 *
 * Une precondition NEGATIVE attendue peut etre satisfaite : `gold_access`
 * attendu `denied` et observe `denied` = YES.
 *
 * READ ONLY : des comptes et des existences, jamais une execution. Le quota
 * est le verdict de la VRAIE garde (`AiEconomicGuard::authorizeEmbeddings`,
 * lecture pure) : YES | NO | UNKNOWN (exception) — K7.
 */
final class LabPreconditions
{
    public const YES = 'YES';

    public const NO = 'NO';

    public const UNAVAILABLE = 'UNAVAILABLE';

    public function __construct(private readonly AiEconomicGuard $guard, private readonly DerivedChunkEligibility $eligibility, private readonly ProviderResolver $providers) {}

    /**
     * @return array{verdict: string, checks: array<string, array{expected: string, actual: mixed, status: string, detail?: string}>}
     */
    public function establish(LabScenario $scenario, Organization $organization, ?User $user, ?Loop $loop): array
    {
        $declared = $scenario->preconditions();
        $checks = [];

        // pack_loaded — observe via scenario_pack_loads (ce que `scenario-pack:status` lit).
        if (($declared['pack_loaded'] ?? 'required') === 'required') {
            $loaded = ScenarioPackLoad::query()->where('pack_id', AiLabPack::PACK_ID)->where('organization_id', (string) $organization->id)->exists();
            $checks['pack_loaded'] = ['expected' => 'loaded', 'actual' => $loaded ? 'loaded' : 'absent', 'status' => $loaded ? self::YES : self::UNAVAILABLE];
        }

        $dossierIds = $loop instanceof Loop
            ? Dossier::query()->withoutGlobalScopes()->where('organization_id', (string) $organization->id)->where('loop_id', (string) $loop->id)->pluck('id')->map(static fn ($id): string => (string) $id)->all()
            : [];
        $golds = AiLabPack::CORPUS[$scenario->loop()] ?? [];

        // gold_exists — les fichiers DECLARES du corpus de cette Loop sont presents dans son Dossier.
        if (($declared['gold_exists'] ?? 'required') === 'required') {
            $presents = $dossierIds === [] ? [] : DossierFile::query()->where('organization_id', (string) $organization->id)->whereIn('dossier_id', $dossierIds)->whereIn('original_name', $golds)->pluck('original_name')->all();
            $manquants = array_values(array_diff($golds, $presents));
            $checks['gold_exists'] = ['expected' => implode(',', $golds), 'actual' => implode(',', $presents), 'status' => $loop === null ? self::UNAVAILABLE : ($manquants === [] ? self::YES : self::UNAVAILABLE), 'detail' => $manquants === [] ? 'tous presents' : 'manquants : '.implode(',', $manquants)];
        }

        // gold_indexed — dossier_chunks pour chaque fichier gold (alreadyIndexed, FACT DossierFileIndexer).
        if (($declared['gold_indexed'] ?? 'required') === 'required') {
            $files = $dossierIds === [] ? collect() : DossierFile::query()->where('organization_id', (string) $organization->id)->whereIn('dossier_id', $dossierIds)->whereIn('original_name', $golds)->get(['id', 'original_name']);
            $nonIndexes = [];
            foreach ($files as $file) {
                if (DossierChunk::query()->where('dossier_file_id', $file->id)->count() === 0) {
                    $nonIndexes[] = $file->original_name;
                }
            }
            $ok = $loop !== null && $files->count() === count($golds) && $nonIndexes === [];
            $checks['gold_indexed'] = ['expected' => 'indexed', 'actual' => $ok ? 'indexed' : 'partial', 'status' => $ok ? self::YES : self::UNAVAILABLE, 'detail' => $nonIndexes === [] ? 'tous indexes' : 'sans chunk : '.implode(',', $nonIndexes)];
        }

        // gold_access — l'ACL REELLE (Organization + membre actif de la Loop), comparee a l'attendu.
        $expectedAccess = $declared['gold_access']['expected'] ?? 'not_applicable';
        if ($expectedAccess !== 'not_applicable') {
            $allowed = $user instanceof User && $loop instanceof Loop
                && (string) $user->organization_id === (string) $organization->id
                && LoopMember::query()->where('loop_id', $loop->id)->where('user_id', $user->id)->where('status', 'active')->exists();
            $actual = $allowed ? 'allowed' : 'denied';
            $checks['gold_access'] = ['expected' => $expectedAccess, 'actual' => $actual, 'status' => $actual === $expectedAccess ? self::YES : self::NO];
        }

        // derived_chunks_absent — aucun chunk derive (knowledge:derive-due) dans
        // les Dossiers du scenario ; le COMPTE vient de l'autorite (T1539).
        if (($declared['derived_chunks_absent'] ?? 'required') === 'required') {
            $derives = $this->eligibility->derivedChunkCount((string) $organization->id, $dossierIds);
            $checks['derived_chunks_absent'] = ['expected' => 0, 'actual' => $derives, 'status' => $loop === null ? self::UNAVAILABLE : ($derives === 0 ? self::YES : self::NO), 'detail' => $derives === 0 ? 'aucun chunk derive' : "{$derives} chunk(s) derive(s) : le Lab n'est pas propre (reset requis)"];
        }

        // quota — le verdict de la VRAIE garde, en lecture ; YES | NO | UNKNOWN (K7).
        if (($declared['quota'] ?? 'required') === 'required') {
            try {
                // Une cle TENANT est requise : sans cle propre, aucun embedding
                // ne part (doctrine T1214/T1225 : aucun repli plateforme, voir
                // `ProviderResolver::tenantEmbeddingKey`). Le pack ne pose jamais
                // de cle ; l'operateur du Lab la pose (T1587 D1) — revue Opus #1.
                // MEME predicat que l'autorite (cle non vide, reglage utilisable,
                // famille = celle de l'index) : ni plus large, ni plus etroit.
                $configured = $this->providers->resolveEmbeddingInstance((string) $organization->id) !== null;
                if (! $configured) {
                    $checks['quota'] = ['expected' => 'YES', 'actual' => 'UNKNOWN', 'status' => self::UNAVAILABLE, 'detail' => 'aucune instance d\'embedding TENANT resolvable (cle vide, reglage inutilisable ou famille ≠ index ; T1214/T1225, pas de repli plateforme) — l\'operateur du Lab pose la cle'];
                } else {
                    $verdict = $this->guard->authorizeEmbeddings($organization, $user instanceof User && (string) $user->organization_id === (string) $organization->id ? $user : null);
                    $checks['quota'] = ['expected' => 'YES', 'actual' => $verdict->allowed ? 'YES' : 'NO', 'status' => $verdict->allowed ? self::YES : self::NO, 'detail' => $verdict->reason ?? 'ok'];
                }
            } catch (\Throwable $e) {
                $checks['quota'] = ['expected' => 'YES', 'actual' => 'UNKNOWN', 'status' => self::UNAVAILABLE, 'detail' => $e::class];
            }
        }

        // embedding_config — informatif, jamais un verdict.
        $setting = OrganizationAiSetting::query()->where('organization_id', (string) $organization->id)->first(['provider', 'model']);
        $checks['embedding_config'] = ['expected' => 'informatif', 'actual' => ($setting?->provider ?? 'UNAVAILABLE').' / '.config('ai.default_for_embeddings', 'UNAVAILABLE'), 'status' => self::YES];

        $statuts = array_column($checks, 'status');
        $verdict = in_array(self::UNAVAILABLE, $statuts, true) ? self::UNAVAILABLE : (in_array(self::NO, $statuts, true) ? self::NO : self::YES);

        return ['verdict' => $verdict, 'checks' => $checks];
    }
}
