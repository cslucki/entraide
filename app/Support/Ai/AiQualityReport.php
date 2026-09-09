<?php

namespace App\Support\Ai;

use App\Models\AiInteraction;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1487 (AI Quality Q2) — « est-ce que l'IA aide ? », et surtout : « le
 * sait-on ? ».
 *
 * ## Ce que ce rapport refuse de faire
 *
 * Afficher un zero qui ment. Le CDC l'interdit, et c'est le seul risque serieux
 * d'un cockpit de qualite construit sur zero retour : « 0 % utile » se lit
 * comme « l'IA n'aide personne », alors que la verite est « personne n'a jamais
 * ete interroge ».
 *
 * Trois etats distincts sont donc calcules, jamais confondus :
 *
 *  - **non instrumentee** : aucun moyen de recueillir un avis. Fait structurel.
 *  - **pas encore evaluable** : la fonction sait recueillir un avis, mais aucun
 *    tour de la periode n'est posterieur a son instrumentation.
 *  - **pas encore de retour** : des tours evaluables existent, personne n'a
 *    repondu.
 *
 * Le quatrieme, **mesuree**, n'apparait qu'a partir du premier verdict reel.
 *
 * ## Ce qu'il n'agrege JAMAIS
 *
 * **Par utilisateur.** `ai_interaction_feedbacks.user_id` rend un verdict
 * attribuable a une personne ; grouper par personne transformerait
 * mecaniquement ce cockpit en notation d'utilisateurs, ce que le CDC interdit.
 * L'agregation se fait par FONCTION, et la colonne `user_id` n'est jamais lue
 * ici — un test le verifie sur la source.
 *
 * ## Tenant
 *
 * `ai_interactions.organization_id` et `ai_interaction_feedbacks.organization_id`
 * bornent tout. Un OrgAdmin ne voit que la sienne ; le SuperAdmin agrege la
 * plateforme et peut filtrer sur une Organization — jamais lire une
 * conversation.
 */
final class AiQualityReport
{
    /** @return array<string, mixed> */
    public function forOrganization(Organization $organization, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->build($from, $to, (string) $organization->id);
    }

    /** @return array<string, mixed> */
    public function forPlatform(CarbonImmutable $from, CarbonImmutable $to, ?Organization $only = null): array
    {
        return $this->build($from, $to, $only instanceof Organization ? (string) $only->id : null);
    }

    /**
     * @return array{
     *     from: CarbonImmutable, to: CarbonImmutable,
     *     interactions: int, evaluable: int, evaluated: int, helpful: int, improve: int,
     *     has_any_feedback: bool, rows: list<array<string, mixed>>
     * }
     */
    private function build(CarbonImmutable $from, CarbonImmutable $to, ?string $organizationId): array
    {
        $interactions = $this->interactionCounts($from, $to, $organizationId);
        $verdicts = $this->verdictCounts($from, $to, $organizationId);

        $rows = [];
        $totalEvaluable = 0;
        $totalEvaluated = 0;
        $totalHelpful = 0;
        $totalImprove = 0;

        // Les fonctions VUES sur la periode, plus celles qu'on sait
        // instrumenter : une fonction instrumentee mais inutilisee doit
        // apparaitre, sinon le cockpit tait ce qu'il sait le mieux.
        $features = collect($interactions->keys())
            ->merge(AiQualityInstrumentation::features())
            ->unique()
            ->sort()
            ->values();

        foreach ($features as $feature) {
            $counts = $interactions->get($feature, ['total' => 0, 'evaluable' => 0]);
            $verdict = $verdicts->get($feature, ['evaluated' => 0, 'helpful' => 0, 'improve' => 0]);

            $totalEvaluable += $counts['evaluable'];
            $totalEvaluated += $verdict['evaluated'];
            $totalHelpful += $verdict['helpful'];
            $totalImprove += $verdict['improve'];

            $rows[] = [
                'feature' => $feature,
                'interactions' => $counts['total'],
                'evaluable' => $counts['evaluable'],
                'evaluated' => $verdict['evaluated'],
                'helpful' => $verdict['helpful'],
                'improve' => $verdict['improve'],
                // La couverture n'existe QUE s'il y a un denominateur. `null`
                // n'est pas zero : il se rend « — », jamais « 0 % ».
                'coverage' => $counts['evaluable'] > 0
                    ? $verdict['evaluated'] / $counts['evaluable']
                    : null,
                'status' => AiQualityInstrumentation::status($feature, $counts['evaluable'], $verdict['evaluated']),
                'since' => AiQualityInstrumentation::since($feature),
            ];
        }

        return [
            'from' => $from,
            'to' => $to,
            'interactions' => (int) $interactions->sum('total'),
            'evaluable' => $totalEvaluable,
            'evaluated' => $totalEvaluated,
            'helpful' => $totalHelpful,
            'improve' => $totalImprove,
            // Le drapeau qui gouverne tout l'ecran : sans un seul verdict, on
            // ne montre AUCUN pourcentage de qualite.
            'has_any_feedback' => $totalEvaluated > 0,
            'rows' => $rows,
        ];
    }

    /**
     * Par fonction : le total, et la part reellement EVALUABLE — celle qui est
     * posterieure a l'instrumentation de cette fonction.
     */
    private function interactionCounts(CarbonImmutable $from, CarbonImmutable $to, ?string $organizationId): Collection
    {
        $rows = AiInteraction::query()
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('feature, count(*) as total')
            ->groupBy('feature')
            ->pluck('total', 'feature');

        return $rows->map(function (int $total, string $feature) use ($from, $to, $organizationId): array {
            $since = AiQualityInstrumentation::since($feature);

            if ($since === null) {
                return ['total' => $total, 'evaluable' => 0];
            }

            $evaluableFrom = $since->greaterThan($from) ? $since : $from;

            $evaluable = $evaluableFrom->greaterThanOrEqualTo($to) ? 0 : AiInteraction::query()
                ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
                ->where('feature', $feature)
                ->whereBetween('created_at', [$evaluableFrom, $to])
                ->count();

            return ['total' => $total, 'evaluable' => $evaluable];
        });
    }

    /**
     * Par fonction : combien de tours portent un verdict, et lesquels.
     *
     * La jointure part de la trace, jamais de l'acteur : `user_id` n'est ni lu,
     * ni groupe, ni rendu.
     */
    private function verdictCounts(CarbonImmutable $from, CarbonImmutable $to, ?string $organizationId): Collection
    {
        return collect(DB::table('ai_interaction_feedbacks as f')
            ->join('ai_interactions as i', 'i.id', '=', 'f.ai_interaction_id')
            ->when($organizationId !== null, fn ($q) => $q->where('i.organization_id', $organizationId))
            ->whereBetween('i.created_at', [$from, $to])
            ->groupBy('i.feature')
            ->selectRaw('i.feature as feature')
            ->selectRaw('count(distinct f.ai_interaction_id) as evaluated')
            ->selectRaw("sum(case when f.verdict = 'helpful' then 1 else 0 end) as helpful")
            ->selectRaw("sum(case when f.verdict = 'improve' then 1 else 0 end) as improve")
            ->get())
            ->keyBy('feature')
            ->map(fn ($r): array => [
                'evaluated' => (int) $r->evaluated,
                'helpful' => (int) $r->helpful,
                'improve' => (int) $r->improve,
            ]);
    }
}
