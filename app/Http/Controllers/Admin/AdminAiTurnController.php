<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiInteraction;
use App\Models\AiShellMessage;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\AiRerankSettings;
use App\Support\Ai\AiConversationTrace;
use App\Support\Ai\AiTurnDoctrineProjection;
use App\Support\Ai\AiTurnExecutor;
use App\Support\Ai\AiTurnInspection;
use App\Support\Ai\AiTurnProjection;
use App\Support\Ai\AiTurnTrace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * TASK-1581 — Inspector UI V0 : la VUE LECTEUR d'un tour IA (CDC-02 T1-E).
 *
 * Une page admin read-only qui rend ce que `ai:inspect-turn` rend : le bloc
 * `turn` persiste (V0), sa projection (T1580) et, quand une bulle existe, la
 * chaine de conversation (T1-A). Rien d'autre.
 *
 *  - Observer = ZERO execution : aucun provider, aucun moteur, aucune
 *    ecriture — la page LIT.
 *  - TASK-1585 — « Tester une requete » : la SEULE entree qui execute, en
 *    POST, par `AiTurnExecutor` (le meme que `ai:inspect-turn`) : vrais
 *    services, vrai `AiEconomicGuard`, vraie cle de l'Organization, vrai
 *    ledger, `publish: false` — aucune bulle dans la Boucle. Le GET du
 *    formulaire n'execute rien. Le tour produit se lit ensuite par `show()`.
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

    // ────────────────────────────── TASK-1585 — Tester une requete

    /**
     * Le formulaire, en GET : selections dependantes HONNETES (Organization ->
     * ses utilisateurs -> les Boucles ou l'utilisateur est membre actif ->
     * pour `ia`, un declencheur du fil), rendues cote serveur — aucun script,
     * aucune decouverte hors de l'Organization choisie. Aucune execution,
     * aucune ecriture.
     */
    public function testForm(Request $request): View
    {
        return view('admin.ai-turns.test', $this->contexteDuTest($request->query()));
    }

    /**
     * L'execution, en POST : un VRAI tour par `AiTurnExecutor`, puis redirect
     * vers sa fiche. Un refus du service (economie, ACL, idempotence, panne)
     * est un resultat : si un tour a ete ecrit malgre tout (arret anticipe),
     * on l'inspecte ; sinon le refus est affiche sur le formulaire.
     */
    public function runTest(Request $request, AiTurnExecutor $executor): RedirectResponse
    {
        $donnees = $request->validate([
            'organization' => ['required', 'uuid'],
            'user' => ['required', 'uuid'],
            'loop' => ['required', 'uuid'],
            'mode' => ['required', 'in:'.implode(',', AiTurnExecutor::MODES)],
            // Review Opus F3 — les MEMES bornes d'entree que le produit : 500
            // sur les chemins documentaires (`LoopController`,
            // `DossierAnswerController`), 5000 sur le composeur ChatLoop. Un
            // tour qu'aucun membre ne pourrait emettre n'a pas a etre facture.
            'question' => ['required', 'string', 'min:3', $request->input('mode') === 'ia' ? 'max:5000' : 'max:500'],
            'trigger' => ['nullable', 'uuid'],
        ]);

        $contexte = $this->contexteDuTest($donnees);
        $organization = $contexte['organization'];
        $user = $contexte['user'];
        $loop = $contexte['loopChoisie'];
        $trigger = $contexte['trigger'];

        // Chaque terme est resolu DANS le precedent (tenant) ; un id qui ne
        // resout rien ici est refuse — sans dire s'il existe ailleurs.
        if (! $organization instanceof Organization || ! $user instanceof User || ! $loop instanceof Loop) {
            return $this->retourAuFormulaire($donnees, 'Organization, utilisateur et Boucle doivent se resoudre dans la meme Organization.');
        }

        if ($donnees['mode'] === 'ia' && ! $trigger instanceof LoopMessage) {
            return $this->retourAuFormulaire($donnees, 'Le mode ia exige un message declencheur de cette Boucle.');
        }

        try {
            $execution = $executor->execute($organization, $user, $loop, $donnees['mode'], $donnees['question'], $trigger, null, AiTurnTrace::RUN_KIND_INSPECTOR);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            return $this->retourAuFormulaire($donnees, $exception->getMessage());
        }

        if ($execution->interaction instanceof AiInteraction) {
            return redirect()
                ->route('admin.ai-turns.show', ['interaction' => (string) $execution->interaction->id])
                ->with('inspector_test', [
                    'run_id' => $execution->runId,
                    'refused' => $execution->refused(),
                    'message' => $execution->refusalMessage,
                    'manifest_failure' => $execution->manifestFailure,
                ]);
        }

        return $this->retourAuFormulaire($donnees, $execution->refused()
            ? 'Refuse avant tout tour : '.$execution->refusalMessage
            : 'Le service n\'a ecrit aucun tour.');
    }

    /**
     * Resout, DANS l'ordre et DANS le tenant, ce que le formulaire a choisi ;
     * chaque liste dependante n'est calculee qu'une fois son parent resolu.
     *
     * @param  array<string, mixed>  $entree
     * @return array<string, mixed>
     */
    private function contexteDuTest(array $entree): array
    {
        $uuid = static fn (string $cle) => is_string($entree[$cle] ?? null) && Str::isUuid($entree[$cle]) ? $entree[$cle] : null;

        $organizations = Organization::query()->orderBy('slug')->get(['id', 'slug', 'name']);
        $organization = $uuid('organization') !== null ? $organizations->firstWhere('id', $uuid('organization')) : null;

        $users = $organization instanceof Organization
            ? User::query()->where('organization_id', (string) $organization->id)->orderBy('email')->get(['id', 'email', 'name', 'organization_id'])
            : collect();
        $user = $uuid('user') !== null ? $users->firstWhere('id', $uuid('user')) : null;

        $loops = $organization instanceof Organization && $user instanceof User
            ? AiTurnExecutor::accessibleLoops($organization, $user)
            : collect();
        $loop = $uuid('loop') !== null ? $loops->firstWhere('id', $uuid('loop')) : null;

        // Declencheurs possibles pour `ia` : les messages humains recents du
        // fil, dans cette Boucle (tenant du fil). Review Opus F1 : AUCUN
        // contenu de conversation n'est rendu (I9) — date, id court, auteur
        // (id) ; F5 : ceux qui ont deja leur reponse IA sont marques, parce que
        // l'idempotence les refusera.
        $triggers = $loop instanceof Loop
            ? LoopMessage::query()
                ->where('organization_id', (string) $organization->id)
                ->where('loop_id', (string) $loop->id)
                ->where('type', 'user')
                ->orderByDesc('created_at')->orderByDesc('id')
                ->limit(20)
                ->get(['id', 'sender_id', 'created_at', 'loop_id', 'organization_id'])
                ->each(function (LoopMessage $m): void {
                    $m->setAttribute('already_answered', LoopMessage::query()->where('loop_id', $m->loop_id)->where('reply_to_id', $m->id)->where('type', 'ai')->exists());
                })
            : collect();
        $trigger = $uuid('trigger') !== null ? $triggers->firstWhere('id', $uuid('trigger')) : null;

        return [
            'organizations' => $organizations, 'organization' => $organization,
            'users' => $users, 'user' => $user,
            // `loopChoisie` : dans un @foreach, `$loop` est la variable de Blade.
            'loops' => $loops, 'loopChoisie' => $loop,
            'triggers' => $triggers, 'trigger' => $trigger,
            'mode' => in_array($entree['mode'] ?? null, AiTurnExecutor::MODES, true) ? $entree['mode'] : 'dossiers',
            // Review Opus F2 — la question ne transite jamais par l'URL : elle
            // revient par la session (flash) apres un refus.
            'question' => is_string(session('inspector_test_question')) ? session('inspector_test_question') : '',
            'modes' => AiTurnExecutor::MODES,
        ];
    }

    /** @param  array<string, mixed>  $donnees */
    private function retourAuFormulaire(array $donnees, string $message): RedirectResponse
    {
        return redirect()
            ->route('admin.ai-turns.test', Collection::make($donnees)->only(['organization', 'user', 'loop', 'mode', 'trigger'])->filter()->all())
            ->with('inspector_test_error', $message)
            ->with('inspector_test_question', (string) ($donnees['question'] ?? ''));
    }
}
