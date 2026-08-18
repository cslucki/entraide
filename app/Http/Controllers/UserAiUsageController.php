<?php

namespace App\Http\Controllers;

use App\Services\Ai\AiProviderInvocationConsole;
use App\Services\Ai\DTO\AiConsumptionFilters;
use App\Services\Ai\OrganizationAiEconomicUsage;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * « Mes usages IA » (TASK-1223, TASK-1228) — transparence, pas FinOps.
 *
 * Scope STRICT : l'utilisateur COURANT dans son Organization COURANTE. Un
 * membre ne voit jamais les usages d'un autre — appartenir a la meme
 * Organization ne suffit pas. Il ne voit AUCUN chiffre a l'echelle de
 * l'Organization (ni budget, ni consomme, ni classement) : ses utilisations
 * et son cout mesure, rien d'autre (arbitrage MASTER TASK-1228).
 *
 * TASK-1228 — AUTORITE : le resume du mois vient de
 * `OrganizationAiEconomicUsage::summary(..., userId)` — la MEME autorite
 * 1222 que le releve de l'Organization, bornee a cet utilisateur ; la somme
 * des membres (+ non attribuable) y redonne le total de l'Organization.
 * L'historique recent lit les deux registres de cette autorite (generation
 * `ai_interactions`, embeddings ledger), sans overlap. « — » = non mesure,
 * jamais un zero invente ; aucun prompt, reponse, document, cle.
 */
class UserAiUsageController extends Controller
{
    public function index(
        Request $request,
        AiProviderInvocationConsole $console,
        OrganizationAiEconomicUsage $usage,
    ): View {
        $user = $request->user();
        // L'Organization de l'utilisateur d'abord : sur la route non prefixee,
        // l'Organization « courante » est celle par defaut de la plateforme,
        // pas la sienne (revue PASS A) — le releve doit rester le sien.
        $organization = $user->organization ?? currentOrganization();

        abort_unless($organization !== null, 404);

        $period = AiConsumptionFilters::currentMonth();

        return view('profile.ai-usage', [
            'organization' => $organization,
            'period' => $period,
            'usage' => $usage->summary((string) $organization->id, $period->from, $period->to, (string) $user->id),
            'activity' => $console->recentActivityForUser((string) $organization->id, (string) $user->id, 20),
        ]);
    }
}
