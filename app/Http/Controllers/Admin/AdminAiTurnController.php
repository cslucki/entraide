<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiInteraction;
use App\Models\AiShellMessage;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Services\Ai\AiRerankSettings;
use App\Support\Ai\AiConversationTrace;
use App\Support\Ai\AiTurnDoctrineProjection;
use App\Support\Ai\AiTurnInspection;
use App\Support\Ai\AiTurnProjection;
use App\Support\Ai\AiTurnTrace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * TASK-1581 — Inspector UI V0 : la VUE LECTEUR d'un tour IA (CDC-02 T1-E).
 *
 * Une page admin read-only qui rend ce que `ai:inspect-turn` rend : le bloc
 * `turn` persiste (V0), sa projection (T1580) et, quand une bulle existe, la
 * chaine de conversation (T1-A). Rien d'autre.
 *
 *  - ZERO execution : aucun provider, aucun moteur, aucune ecriture ; la page
 *    ne porte ni bouton RUN ni builder (hors perimetre, CDC-02 §9 DO NOT
 *    BUILD) — elle LIT.
 *  - Memes lecteurs que la CLI, meme vocabulaire : `AiTurnInspection`
 *    (query-free), `AiTurnProjection` (2 requetes, tenant-bound),
 *    `AiConversationTrace`. La page n'a pas de second vocabulaire.
 *  - Perimetre : `is_admin` plateforme (`AdminMiddleware`, groupe `admin`) —
 *    le tenant de chaque tour est affiche en clair, jamais implicite.
 *  - Ce qui n'est PAS rendu : `ai_interactions.prompt`, le texte des chunks,
 *    les secrets (I9) ; la reponse l'est, comme dans la CLI.
 */
class AdminAiTurnController extends Controller
{
    private const RECENTS = 25;

    public function index(Request $request): View|RedirectResponse
    {
        $cle = trim((string) $request->query('interaction', ''));

        if ($cle !== '') {
            // Un lookup par id — GET, sans effet. Non-uuid : refus propre.
            if (! Str::isUuid($cle)) {
                return redirect()->route('admin.ai-turns')->with('inspector_error', 'L\'identifiant doit etre un uuid.');
            }

            if (AiInteraction::query()->whereKey($cle)->exists()) {
                return redirect()->route('admin.ai-turns.show', ['interaction' => $cle]);
            }

            if (AiShellMessage::query()->whereKey($cle)->where('role', AiShellMessage::ROLE_ASSISTANT)->exists()) {
                return redirect()->route('admin.ai-turns.shell', ['shellMessage' => $cle]);
            }

            return redirect()->route('admin.ai-turns')->with('inspector_error', 'Aucun tour persiste ne porte cet identifiant.');
        }

        // Les derniers tours qui portent un bloc `turn` (V0) — toutes
        // Organizations : c'est la console plateforme. `id` departage
        // l'horodatage (pagination stable, lecon TASK-1117).
        $recents = AiInteraction::query()
            ->with('organization:id,slug,name')
            ->whereNotNull('metadata->'.AiTurnTrace::TURN_METADATA_KEY.'->id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::RECENTS)
            ->get();

        return view('admin.ai-turns.index', ['recents' => $recents]);
    }

    public function show(string $interaction, AiRerankSettings $rerank): View
    {
        abort_unless(Str::isUuid($interaction), 404);

        $ligne = AiInteraction::query()->with('organization:id,slug,name')->find($interaction);
        abort_if($ligne === null, 404);

        $trace = AiTurnInspection::fromPersistedTurn($ligne);
        $trace['projection'] = AiTurnProjection::project($ligne);

        // La chaine de conversation, quand la bulle de ce tour existe (une
        // requete, tenant du tour) — sinon la section est absente, pas vide.
        $bulle = $trace['projection']['loop_message_id'] === null
            ? null
            : LoopMessage::query()->whereKey($trace['projection']['loop_message_id'])->where('organization_id', (string) $ligne->organization_id)->first();
        $conversation = $bulle instanceof LoopMessage
            ? AiConversationTrace::fromLoopMessage($bulle, (string) $ligne->organization_id)
            : null;

        return view('admin.ai-turns.show', [
            'ligne' => $ligne,
            'organization' => $ligne->organization,
            'trace' => $trace,
            // TASK-1582 — les deux regles observables : mesure du tour vs
            // configuration d'aujourd'hui, Organization = celle du TOUR.
            'doctrine' => $ligne->organization instanceof Organization ? AiTurnDoctrineProjection::project($trace, $ligne->organization, $rerank) : null,
            'conversation' => $conversation,
            'support' => 'ai_interactions',
        ]);
    }

    public function showShell(string $shellMessage, AiRerankSettings $rerank): View
    {
        abort_unless(Str::isUuid($shellMessage), 404);

        $message = AiShellMessage::query()->with('organization:id,slug,name')
            ->whereKey($shellMessage)
            ->where('role', AiShellMessage::ROLE_ASSISTANT)
            ->first();
        abort_if($message === null, 404);

        $organizationId = (string) $message->organization_id;
        $interactionId = is_array($message->metadata) ? ($message->metadata['ai_interaction_id'] ?? null) : null;
        $interaction = is_string($interactionId) && Str::isUuid($interactionId)
            ? AiInteraction::query()->where('organization_id', $organizationId)->whereKey($interactionId)->first()
            : null;

        $trace = $interaction instanceof AiInteraction
            ? AiTurnInspection::fromPersistedTurn($interaction, $message)
            : AiTurnInspection::fromPersistedTurn($message);
        $trace['projection'] = $interaction instanceof AiInteraction ? AiTurnProjection::project($interaction) : null;

        return view('admin.ai-turns.show', [
            'ligne' => $interaction ?? $message,
            'organization' => $message->organization,
            'trace' => $trace,
            'doctrine' => $message->organization instanceof Organization ? AiTurnDoctrineProjection::project($trace, $message->organization, $rerank) : null,
            'conversation' => $message->conversation_id !== null
                ? AiConversationTrace::fromShellConversation((string) $message->conversation_id, $organizationId)
                : null,
            'support' => 'ai_shell_messages',
        ]);
    }
}
