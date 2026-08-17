<?php

namespace App\Http\Controllers;

use App\Services\Ai\AiProviderInvocationConsole;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * « Mes usages IA » (TASK-1223) — transparence, pas FinOps.
 *
 * Scope STRICT : l'utilisateur COURANT dans son Organization COURANTE. Un
 * membre ne voit jamais les usages d'un autre — appartenir a la meme
 * Organization ne suffit pas. La page rend les lignes du ledger canonique
 * telles quelles : « — » pour l'absent, jamais un zero invente, jamais un
 * prompt/une reponse/un document/une cle.
 */
class UserAiUsageController extends Controller
{
    public function index(Request $request, AiProviderInvocationConsole $console): View
    {
        $user = $request->user();
        $organization = currentOrganization() ?? $user->organization;

        abort_unless($organization !== null, 404);

        return view('profile.ai-usage', [
            'rows' => $console->forUser((string) $organization->id, (string) $user->id),
            'organization' => $organization,
        ]);
    }
}
