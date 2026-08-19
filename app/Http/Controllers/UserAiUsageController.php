<?php

namespace App\Http\Controllers;

use App\Services\Ai\AiProviderInvocationConsole;
use App\Services\Ai\DTO\AiConsumptionFilters;
use App\Services\Ai\OrganizationAiEconomicUsage;
use App\Support\Ai\AiEconomicGuard;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * « Mes usages IA » (TASK-1223, TASK-1228, TASK-1229) — transparence, pas FinOps.
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
 *
 * TASK-1229 — CREDIT : ce qu'il lui reste vient de
 * `AiEconomicGuard::userCreditStatus()` — l'autorite qui BLOQUE : le chiffre
 * affiche est celui qui refuse. En utilisations, jamais en dollars ; essais
 * de doctrine et indexations hors credit, l'ecran le dit.
 *
 * TASK-1257 — V2 (gap analysis dans le TASK file) : les CATEGORIES D'USAGE
 * du mois (`OrganizationAiEconomicUsage::userGenerationByProcess()`, meme
 * autorite 1219/1222, meme filtre tenant-safe, sous-lignes qui SOMMENT la
 * ligne « Generations ») ; le cout en $ est nomme pour ce qu'il est — cout
 * FOURNISSEUR mesure, a titre d'information, jamais un prix facture au
 * membre (le credit reste la seule unite de son acces) ; le statut d'une
 * ligne d'historique sans parole de l'ecrivain est complete par le ledger
 * de la meme correlation (console) ; les exclusions du credit (indexations,
 * autres traitements, essais) sont chiffrees sous la carte credit.
 */
class UserAiUsageController extends Controller
{
    public function index(
        Request $request,
        AiProviderInvocationConsole $console,
        OrganizationAiEconomicUsage $usage,
        AiEconomicGuard $guard,
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
            'categories' => $usage->userGenerationByProcess((string) $organization->id, $period->from, $period->to, (string) $user->id),
            'activity' => $console->recentActivityForUser((string) $organization->id, (string) $user->id, 20),
            'credit' => $guard->userCreditStatus($organization, $user),
            'offersUrl' => aiOffersUrl($organization),
        ]);
    }

    /**
     * TASK-1229 : « Voir les offres » — page d'INFORMATION, aucun paiement,
     * aucun catalogue reel : ce qu'est le credit, a qui s'adresser, et le
     * lien vers les abonnements de l'Organization s'ils sont actives.
     */
    public function offers(Request $request, AiEconomicGuard $guard): View
    {
        $user = $request->user();
        $organization = $user->organization ?? currentOrganization();

        abort_unless($organization !== null, 404);

        $subscriptionsUrl = null;

        if ($organization->subscriptions_enabled) {
            $subscriptionsUrl = $organization->is_default
                ? route('subscriptions')
                : route('organization.subscriptions', ['organization' => $organization->slug]);
        }

        return view('profile.ai-offers', [
            'organization' => $organization,
            'credit' => $guard->userCreditStatus($organization, $user),
            'subscriptionsUrl' => $subscriptionsUrl,
            'usageUrl' => $organization->is_default || request()->route('organization') === null
                ? route('profile.ai-usage')
                : route('organization.profile.ai-usage', ['organization' => $organization->slug]),
        ]);
    }
}
