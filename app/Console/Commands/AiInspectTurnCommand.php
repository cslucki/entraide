<?php

namespace App\Console\Commands;

use App\Ai\Context\DossierAccessScope;
use App\Models\AiInteraction;
use App\Models\AiShellMessage;
use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\LoopKnowledgeAnswerService;
use App\Services\ChatLoop\ChatLoopAiService;
use App\Support\Ai\AiExecutionPath;
use App\Support\Ai\AiTruthLabel;
use App\Support\Ai\AiTurnInspection;
use App\Support\Ai\AiTurnTrace;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * TASK-1558 — AI Inspector CLI, Full Shell v0.
 *
 * Execute et observe le VRAI chemin produit derriere une question. Pas un
 * moteur, pas un sandbox bis, pas une imitation du retrieval : cette commande
 * appelle le service que l'ecran appelle, et se contente de LIRE ce qu'il a
 * ecrit.
 *
 * ## Le chemin reproduit, exactement
 *
 * `surface=loop` + `mode=dossiers` reproduit ce que fait un humain qui ouvre une
 * Boucle, choisit « Consulter les Dossiers » et pose sa question :
 *
 *     LoopChat::respondWithDossiers()
 *         -> LoopKnowledgeAnswerService::answer()
 *
 * C'est la MEME methode, avec les memes gardes : appartenance, idempotence,
 * verrou de tour, Context Builder, garde economique, validation des citations,
 * ledger, `AiInteraction`. La commande n'en court-circuite aucune.
 *
 * ## La seule difference, et pourquoi elle est acceptable
 *
 * `publish: false` (TASK-1558) empeche l'ecriture des DEUX `loop_messages` —
 * la question et la reponse. Observer ne doit pas parler dans le fil de
 * quelqu'un d'autre.
 *
 * Tout le reste s'execute : l'appel provider est REEL, il coute, et la
 * telemetrie canonique (`AiInteraction`, ledger) est ecrite parce qu'elle
 * appartient au chemin. Une observation qui n'aurait rien coute n'aurait rien
 * observe.
 *
 * ## Ce qu'elle ne fait pas
 *
 * Aucune publication, aucun message externe, aucune ecriture metier, aucune
 * option destructive. Et surtout : aucune reconstruction de retrieval. Le jour
 * ou cette commande appellerait `DossierSemanticSearchService` ou
 * `ContextBuilder` directement pour « aller plus vite », elle cesserait
 * d'observer le produit pour observer sa propre idee du produit.
 */
class AiInspectTurnCommand extends Command
{
    protected $signature = 'ai:inspect-turn
        {--organization= : Slug ou UUID de l\'Organization}
        {--user= : Email ou UUID d\'un utilisateur autorise de cette Organization}
        {--surface=loop : Surface observee (loop)}
        {--loop= : UUID de la Boucle}
        {--mode=dossiers : dossiers | ia_dossiers | ia}
        {--question= : La question posee}
        {--trigger-message= : EXECUTE --mode=ia — uuid du loop_message auquel l\'IA repond (obligatoire : le chemin produit repond toujours a un message)}
        {--interaction= : EXPLAIN — uuid d\'une ligne ai_interactions deja persistee (aucune execution)}
        {--turn= : EXPLAIN — turn_id canonique (turn.id, repli metadata.turn_id pour les anciens tours)}
        {--message= : EXPLAIN — uuid d\'un loop_message, remonte a son ai_interaction_id}
        {--shell-message= : EXPLAIN — uuid d\'un ai_shell_message (ligne assistant) : suit ai_interaction_id, sinon lit le bloc turn zero-provider}
        {--json : Sortie JSON machine-readable}';

    protected $description = 'Execute et observe le vrai chemin BouclePro derriere une question (EXECUTE), ou lit un tour deja persiste sans le rejouer (EXPLAIN : --interaction, --turn, --message, --shell-message).';

    /** Les surfaces et modes REELLEMENT reproductibles en v0. */
    private const SURFACES = ['loop'];

    private const MODES = ['dossiers', 'ia_dossiers', 'ia'];

    public function handle(
        LoopKnowledgeAnswerService $knowledge,
        ChatLoopAiService $chatLoop,
        DossierAccessScope $scope,
    ): int {
        // TASK-1569 / CDC-01 V0-H0 — mode EXPLAIN : une cle de lookup suffit,
        // et elle exclut toute execution. Le tenant reste OBLIGATOIRE : une
        // ligne d'une autre Organization est « introuvable », jamais rendue.
        if ($this->option('interaction') !== null || $this->option('turn') !== null || $this->option('message') !== null || $this->option('shell-message') !== null) {
            return $this->expliquer();
        }

        $surface = (string) $this->option('surface');
        $mode = (string) $this->option('mode');
        $question = trim((string) $this->option('question'));

        // Un mode non reproductible est REFUSE, jamais approxime. Rendre une
        // trace pour un chemin qu'on n'a pas execute serait le defaut que cet
        // outil existe pour empecher.
        // TASK-1576 / V0-I — le Shell est EXPLAIN-ONLY en V0 (CDC-01 §9.2, repli
        // assume) : un seam de non-persistance du fil traverserait `respond()`
        // de bout en bout. Ses tours se produisent par la surface reelle et
        // s'expliquent ensuite par `--shell-message`.
        if ($surface === 'shell') {
            return $this->refuser('Surface « shell » : explain-only en v0. Produisez le tour par la surface reelle, puis --shell-message=<uuid>.');
        }

        if (! in_array($surface, self::SURFACES, true)) {
            return $this->refuser("Surface non reproductible en v0 : « {$surface} ». Disponible : ".implode(', ', self::SURFACES));
        }

        if (! in_array($mode, self::MODES, true)) {
            return $this->refuser("Mode non reproductible en v0 : « {$mode} ». Disponible : ".implode(', ', self::MODES));
        }

        if ($question === '') {
            return $this->refuser('Aucune question : --question est obligatoire.');
        }

        $organization = $this->resoudreOrganization();

        if ($organization === null) {
            return $this->refuser('Organization introuvable.');
        }

        $user = $this->resoudreUser($organization);

        if ($user === null) {
            return $this->refuser('Utilisateur introuvable dans cette Organization.');
        }

        $loop = Loop::query()->find((string) $this->option('loop'));

        // Le tenant est revalide ICI en plus du service : un identifiant passe
        // en ligne de commande n'est pas une autorisation, exactement comme un
        // `PageContext` n'en est pas une.
        if (! $loop instanceof Loop || (string) $loop->organization_id !== (string) $organization->id) {
            return $this->refuser('Boucle introuvable dans cette Organization.');
        }

        if ($mode === 'ia') {
            return $this->executerIa($chatLoop, $organization, $user, $loop, $question);
        }

        // TASK-1558 — SANS cette liaison, l'observation ment.
        //
        // `DossierPolicy::view` lit `current_organization` dans le conteneur.
        // Le middleware HTTP le lie ; une commande Artisan, non. Mesure faite
        // sur le banc reel : sans liaison, `accessibleDossierIds()` rend
        // **0 dossier** pour le proprietaire meme de la Boucle, le retrieval
        // n'a plus aucun perimetre, et le CLI aurait accuse le classement
        // d'un defaut qui n'existait que dans son propre harnais.
        //
        // Ce n'est pas un contournement de garde : la policy s'execute, avec le
        // MEME contexte qu'en production. Idiome repris tel quel de
        // `DossierFileIndexer` — poser, puis restaurer.
        return $this->dansLeTenant($organization, function () use (
            $knowledge, $scope, $organization, $user, $loop, $question, $surface, $mode
        ): int {
            $perimetre = $this->perimetre($scope, $organization, $user, $loop);

            try {
                // TASK-1568 / V0-G — un tour observe par la CLI EST un tour
                // `loop_chat.dossiers` : c'est le meme chemin produit, avec les
                // memes gardes (docblock ci-dessus). Lui donner un pseudo-chemin
                // « cli » serait la faute inverse de celle que C15 corrige.
                $reponse = $mode === 'ia_dossiers'
                    ? $knowledge->answerHybrid($loop, $user, $question, null, publish: false, executionPath: AiExecutionPath::LOOP_CHAT_IA_DOSSIERS)
                    : $knowledge->answer($loop, $user, $question, null, publish: false, executionPath: AiExecutionPath::LOOP_CHAT_DOSSIERS);
            } catch (\RuntimeException $exception) {
                // Un refus du service — ACL, economie, panne — est un RESULTAT
                // d'observation, pas un plantage de l'outil.
                return $this->refuser($exception->getMessage(), $exception::class);
            }

            $interaction = $reponse->interactionId === null
                ? null
                : AiInteraction::query()->find($reponse->interactionId);

            return $this->rendre(AiTurnInspection::build(
                identity: [
                    'organization' => $organization->slug,
                    'organization_id' => (string) $organization->id,
                    'user' => $user->email,
                    'user_id' => (string) $user->id,
                    'surface' => $surface,
                    'mode' => $mode,
                    'loop_id' => (string) $loop->id,
                    'loop_name' => $loop->name,
                ],
                scope: $perimetre,
                question: $question,
                answer: $reponse,
                interaction: $interaction,
            ));
        });
    }

    /**
     * TASK-1575 / CDC-01 V0-H (§9.2) — EXECUTE `--mode=ia` : le VRAI
     * `ChatLoopAiService::respondInThread()`, avec le seam `publish: false`
     * equivalent a celui du RAG (T1558) : verrou, idempotence, garde
     * economique, provider, ledger, `AiInteraction` — tout s'execute ; seule la
     * bulle `loop_messages` n'est pas ecrite.
     *
     * Le declencheur est OBLIGATOIRE parce que le chemin produit repond
     * toujours a un message : l'historique `reply_chain` (P0.12) et
     * l'idempotence du tour en dependent. Un pseudo-declencheur fabrique par la
     * CLI observerait un tour qui n'existe pas dans le produit. Un declencheur
     * deja repondu est REFUSE par le service (`AiTurnIdempotency`) — c'est un
     * resultat d'observation ; EXPLAIN `--message` lit alors la reponse
     * existante.
     */
    private function executerIa(ChatLoopAiService $chatLoop, Organization $organization, User $user, Loop $loop, string $question): int
    {
        $triggerId = trim((string) $this->option('trigger-message'));

        if ($triggerId === '') {
            return $this->refuser('--mode=ia exige --trigger-message : le chemin IA repond toujours a un message du fil.');
        }

        if (! Str::isUuid($triggerId)) {
            return $this->refuser('--trigger-message doit etre un uuid.');
        }

        $trigger = LoopMessage::query()
            ->whereKey($triggerId)
            ->where('organization_id', (string) $organization->id)
            ->where('loop_id', (string) $loop->id)
            ->first();

        if (! $trigger instanceof LoopMessage) {
            return $this->refuser('Message declencheur introuvable dans cette Boucle.');
        }

        return $this->dansLeTenant($organization, function () use ($chatLoop, $user, $loop, $question, $trigger): int {
            try {
                $interaction = $chatLoop->respondInThread($loop, $user, $question, $trigger, publish: false);
            } catch (\RuntimeException $exception) {
                return $this->refuser($exception->getMessage(), $exception::class);
            }

            if (! $interaction instanceof AiInteraction) {
                return $this->refuser('Le seam publish:false devait rendre l\'AiInteraction du tour.');
            }

            // Le tour vient d'etre ecrit : il se LIT comme n'importe quel tour
            // persiste — meme lecteur, memes labels. Aucune section « vivante »
            // n'est fabriquee pour ce chemin (il n'a pas de KnowledgeAnswer).
            return $this->rendreExplain(AiTurnInspection::fromPersistedTurn($interaction->refresh()));
        });
    }

    /**
     * TASK-1569 / CDC-01 V0-H0 — EXPLAIN : lire un tour persiste, sans le
     * rejouer.
     *
     * READ ONLY absolu : aucun provider, aucun moteur, aucune ecriture, aucune
     * liaison de tenant dans le conteneur (rien ici n'appelle une policy). Le
     * perimetre de lecture est la clause `organization_id` de chaque requete —
     * la MEME garde que `AiInteraction` porte partout ailleurs (I10).
     *
     * Trois cles, une seule ligne rendue :
     *   --interaction  ai_interactions.id
     *   --turn         turn.id canonique (V0-A) ; repli metadata.turn_id,
     *                  la cle historique du seul chemin RAG avant V0-A (C19)
     *   --message      loop_messages.id -> metadata.ai_interaction_id (FACT :
     *                  la bulle IA porte cet id depuis TASK-1233)
     *
     * Une cle qui ne resout rien dans CE tenant est refusee, sans dire si la
     * ligne existe ailleurs.
     */
    private function expliquer(): int
    {
        $cles = array_filter([
            'interaction' => $this->option('interaction'),
            'turn' => $this->option('turn'),
            'message' => $this->option('message'),
            'shell-message' => $this->option('shell-message'),
        ], static fn ($v): bool => $v !== null && trim((string) $v) !== '');

        if (count($cles) !== 1) {
            return $this->refuser('EXPLAIN : exactement UNE cle parmi --interaction, --turn, --message, --shell-message.');
        }

        if ($this->option('question') !== null || $this->option('loop') !== null) {
            return $this->refuser('EXPLAIN ne prend ni --question ni --loop : il lit un tour, il n\'en joue pas.');
        }

        $organization = $this->resoudreOrganization();

        if ($organization === null) {
            return $this->refuser('Organization introuvable.');
        }

        $cle = (string) array_key_first($cles);
        $valeur = trim((string) reset($cles));

        // Minor H0 : un id qui n'est pas un uuid ne peut correspondre a rien —
        // le dire proprement plutot que laisser PostgreSQL lever une
        // `QueryException` sur un cast de colonne uuid.
        if (in_array($cle, ['interaction', 'message', 'shell-message'], true) && ! Str::isUuid($valeur)) {
            return $this->refuser("--{$cle} doit etre un uuid.");
        }

        if ($cle === 'shell-message') {
            return $this->expliquerLigneShell($organization, $valeur);
        }

        $interaction = $this->interactionPersistee($organization, $cle, $valeur);

        if ($interaction === null) {
            return $this->refuser('Aucun tour persiste ne correspond a cette cle dans cette Organization.');
        }

        return $this->rendreExplain(AiTurnInspection::fromPersistedTurn($interaction));
    }

    /**
     * TASK-1576 / V0-I — une ligne assistant du Shell. Deux cas, un seul
     * lecteur : la branche a moteur a pose `ai_interaction_id` (on lit
     * l'interaction, la ligne Shell en second pour ses declins) ; la branche
     * zero-provider a compose son bloc `turn` dans la ligne meme. Un
     * `ai_interaction_id` qui ne resout rien dans CE tenant est rendu tel quel
     * dans `shell.ai_interaction_id`, et la ligne se lit seule — jamais une
     * interaction d'ailleurs.
     */
    private function expliquerLigneShell(Organization $organization, string $messageId): int
    {
        $message = AiShellMessage::query()
            ->whereKey($messageId)
            ->where('organization_id', (string) $organization->id)
            ->where('role', AiShellMessage::ROLE_ASSISTANT)
            ->first();

        if (! $message instanceof AiShellMessage) {
            return $this->refuser('Aucune ligne assistant du Shell ne correspond a cette cle dans cette Organization.');
        }

        $interactionId = is_array($message->metadata) ? ($message->metadata['ai_interaction_id'] ?? null) : null;
        $interaction = is_string($interactionId) && Str::isUuid($interactionId)
            ? AiInteraction::query()->where('organization_id', (string) $organization->id)->whereKey($interactionId)->first()
            : null;

        return $this->rendreExplain($interaction instanceof AiInteraction
            ? AiTurnInspection::fromPersistedTurn($interaction, $message)
            : AiTurnInspection::fromPersistedTurn($message));
    }

    private function interactionPersistee(Organization $organization, string $cle, string $valeur): ?AiInteraction
    {
        $tenant = AiInteraction::query()->where('organization_id', (string) $organization->id);

        return match ($cle) {
            'interaction' => $tenant->whereKey($valeur)->first(),
            // C19 : le bloc canonique d'abord ; la cle historique seulement si
            // rien ne repond — jamais l'inverse, jamais les deux melangees.
            'turn' => $tenant->clone()->where('metadata->'.AiTurnTrace::TURN_METADATA_KEY.'->id', $valeur)->first()
                ?? $tenant->clone()->where('metadata->turn_id', $valeur)->first(),
            'message' => $this->interactionDuMessage($organization, $valeur),
            default => null,
        };
    }

    private function interactionDuMessage(Organization $organization, string $messageId): ?AiInteraction
    {
        $message = LoopMessage::query()
            ->whereKey($messageId)
            ->where('organization_id', (string) $organization->id)
            ->first();

        $interactionId = is_array($message?->metadata) ? ($message->metadata['ai_interaction_id'] ?? null) : null;

        if (! is_string($interactionId) || $interactionId === '') {
            return null;
        }

        return AiInteraction::query()
            ->where('organization_id', (string) $organization->id)
            ->whereKey($interactionId)
            ->first();
    }

    /** @param  array<string, mixed>  $trace */
    private function rendreExplain(array $trace): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('');
        $this->info('── RUN (explain — tour persiste, rien n\'est rejoue)');
        foreach ($trace['run'] as $cle => $valeur) {
            $this->line(sprintf('  %-20s %s', $cle, $this->afficher($valeur)));
        }

        $this->info('── IDENTITY');
        if ($trace['identity'] === null) {
            $this->line('  null  (ce tour n\'a pas depose son identite : anterieur a V0-A, ou collecte coupee)');
        } else {
            foreach ($trace['identity'] as $cle => $valeur) {
                $this->line(sprintf('  %-20s %s', $cle, $this->afficher(is_scalar($valeur) || $valeur === null ? $valeur : json_encode($valeur))));
            }
        }

        $this->info('── DECISION');
        foreach ($trace['decision'] as $cle => $valeur) {
            $this->line(sprintf('  %-20s %s', $cle, $this->afficher($valeur)));
        }

        $this->info('── STEPS');
        if ($trace['steps'] === null) {
            $this->line('  null');
        } else {
            foreach ($trace['steps'] as $etape) {
                $this->line(sprintf('  %-22s %-15s %-34s %s',
                    $this->afficher($etape['name'] ?? null),
                    $this->afficher($etape['status'] ?? null),
                    $this->afficher($etape['reason_code'] ?? null),
                    isset($etape['metrics']) ? json_encode($etape['metrics']) : ''));
            }
        }

        $this->info('── HISTORY');
        if ($trace['history'] === null) {
            $this->line('  null');
        } else {
            foreach ($trace['history'] as $cle => $valeur) {
                $this->line(sprintf('  %-20s %s', $cle, $this->afficher(is_array($valeur) ? json_encode($valeur) : $valeur)));
            }
        }

        $this->info('── RETRIEVAL TRACE');
        $rt = $trace['retrieval_trace'];
        if ($rt === null) {
            $this->line('  null');
        } else {
            foreach (['dense_candidates_count', 'after_distance_filter_count', 'max_distance', 'rerank_attempted', 'reason_not_attempted', 'final_context_count'] as $cle) {
                $this->line(sprintf('  %-28s %s', $cle, $this->afficher($rt[$cle])));
            }
        }

        $this->info('── STATE');
        foreach ($trace['state'] as $cle => $valeur) {
            $this->line(sprintf('  %-20s %s', $cle, $this->afficher($valeur)));
        }

        $this->info('── OUTPUT');
        foreach (['failure', 'grounded'] as $cle) {
            $this->line(sprintf('  %-20s %s', $cle, $this->afficher($trace['output'][$cle])));
        }
        $this->line(sprintf('  %-20s %s', 'consulted_chunk_ids', $trace['output']['consulted_chunk_ids'] === null ? 'null' : count($trace['output']['consulted_chunk_ids'])));
        $this->line(sprintf('  %-20s %s', 'cited_chunk_ids', $trace['output']['cited_chunk_ids'] === null ? 'null' : count($trace['output']['cited_chunk_ids'])));
        $this->line('');
        $this->line($this->afficher($trace['output']['response']));

        $this->info('── PROVIDER');
        foreach (['provider', 'model', 'generation_sdk_invocation_id', 'latency_ms', 'cost_usd', 'input_tokens', 'output_tokens'] as $cle) {
            $this->line(sprintf('  %-30s %s', $cle, $this->afficher($trace['provider'][$cle])));
        }

        $this->info('── SHELL');
        if ($trace['shell'] === null) {
            $this->line('  null  (tour hors Shell)');
        } else {
            foreach (['message_id', 'producer', 'status', 'ai_interaction_id'] as $cle) {
                $this->line(sprintf('  %-20s %s', $cle, $this->afficher($trace['shell'][$cle])));
            }
            $declins = $trace['shell']['fallthroughs'];
            $this->line(sprintf('  %-20s %s', 'fallthroughs', $declins === null ? 'null' : ($declins === [] ? '(aucun)' : '')));
            foreach ($declins ?? [] as $declin) {
                $this->line(sprintf('    %-22s %-10s %s', $declin['branch'] ?? '?', $declin['status'] ?? '?', $declin['reason_code'] ?? '?'));
            }
        }

        // TASK-1575 / V0-H — d'ou vient chaque valeur. Le detail complet est
        // dans `--json` ; ici, le compte par label et la liste de ce qui MANQUE.
        $this->info('── TRUTH');
        $comptes = array_count_values($trace['truth']);
        foreach (AiTruthLabel::all() as $label) {
            $this->line(sprintf('  %-20s %d', $label, $comptes[$label] ?? 0));
        }
        $indisponibles = array_keys(array_filter($trace['truth'], static fn (string $l): bool => $l === AiTruthLabel::UNAVAILABLE));
        $this->line(sprintf('  %-20s %s', 'unavailable', $indisponibles === [] ? '(aucun)' : implode(', ', $indisponibles)));
        $this->line('');

        return self::SUCCESS;
    }

    /**
     * Pose l'Organization courante le temps de l'observation, puis la restaure.
     *
     * @param  callable(): int  $callback
     */
    private function dansLeTenant(Organization $organization, callable $callback): int
    {
        $avait = app()->bound('current_organization');
        $precedent = $avait ? app('current_organization') : null;

        app()->instance('current_organization', $organization);

        try {
            return $callback();
        } finally {
            if ($avait) {
                app()->instance('current_organization', $precedent);
            } else {
                app()->forgetInstance('current_organization');
            }
        }
    }

    /**
     * Le perimetre REEL, lu a l'autorite qui le calcule pour le retrieval
     * (`DossierAccessScope`) — jamais une seconde regle locale qui pourrait
     * diverger et faire croire a un acces qui n'existe pas.
     *
     * @return array<string, mixed>
     */
    private function perimetre(DossierAccessScope $scope, Organization $organization, User $user, Loop $loop): array
    {
        $dossierIds = $scope->accessibleDossierIds((string) $organization->id, $user, (string) $loop->id);

        $dossiers = Dossier::query()->whereIn('id', $dossierIds)->get(['id', 'name']);

        $fichiers = DossierFile::query()
            ->whereIn('dossier_id', $dossierIds)
            ->whereNull('deleted_at')
            ->get(['id', 'dossier_id', 'display_name', 'mime_type']);

        return [
            'authorized_dossier_ids' => array_values($dossierIds),
            'authorized_dossiers' => $dossiers->map(fn (Dossier $d): array => [
                'id' => (string) $d->id,
                'name' => $d->name,
            ])->all(),
            'eligible_files' => $fichiers->map(fn (DossierFile $f): array => [
                'id' => (string) $f->id,
                'dossier_id' => (string) $f->dossier_id,
                'name' => $f->display_name,
                'mime_type' => $f->mime_type,
            ])->all(),
        ];
    }

    private function resoudreOrganization(): ?Organization
    {
        $cle = trim((string) $this->option('organization'));

        if ($cle === '') {
            return null;
        }

        return Organization::query()->where('slug', $cle)->first()
            ?? Organization::query()->find($cle);
    }

    private function resoudreUser(Organization $organization): ?User
    {
        $cle = trim((string) $this->option('user'));

        if ($cle === '') {
            return null;
        }

        $user = User::query()->where('email', $cle)->first() ?? User::query()->find($cle);

        // Un utilisateur d'une autre Organization n'est pas « introuvable par
        // hasard » : il est hors tenant, et le dire ici evite de decouvrir le
        // probleme trois couches plus bas.
        if ($user === null || (string) $user->organization_id !== (string) $organization->id) {
            return null;
        }

        return $user;
    }

    /** @param  array<string, mixed>  $trace */
    private function rendre(array $trace): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $run = $trace['run'];
        $out = $trace['output'];
        $prov = $trace['provider'];

        $this->line('');
        $this->info('── RUN');
        $this->line("  turn_id            {$this->afficher($run['turn_id'])}");
        $this->line("  correlation_id     {$this->afficher($run['correlation_id'])}");
        $this->line("  ai_interaction_id  {$this->afficher($run['ai_interaction_id'])}");
        $this->line("  capability         {$this->afficher($run['capability'])}");

        $this->info('── IDENTITY');
        foreach ($trace['identity'] as $cle => $valeur) {
            $this->line(sprintf('  %-18s %s', $cle, $this->afficher($valeur)));
        }

        $this->info('── SCOPE');
        $this->line('  dossiers autorises '.count($trace['scope']['authorized_dossier_ids']));
        $this->line('  fichiers eligibles '.count($trace['scope']['eligible_files']));

        $this->info('── RETRIEVAL');
        $this->line("  query              « {$trace['retrieval']['query']} »");
        $this->line("  candidates_found   {$this->afficher($trace['retrieval']['candidates_found'])}");
        $this->line("  consulted          {$trace['retrieval']['consulted_count']}");

        foreach ($trace['retrieval']['entries'] as $e) {
            $this->line(sprintf('   #%-2d %-4s dist=%-8s %-10s %s',
                $e['rank'], $this->afficher($e['ref']), $this->afficher($e['distance']),
                $this->afficher($e['selection']), $this->afficher($e['title'] ?? null)));
        }

        // TASK-1565 — les etages que le retrieval a traverses, tels que le
        // pipeline les a ecrits. Rien n'est recalcule ici.
        $this->info('── RETRIEVAL TRACE');
        $rt = $trace['retrieval_trace'];

        if ($rt === null) {
            $this->line('  (cette interaction ne porte pas de trace de retrieval)');
        } else {
            $this->line(sprintf('  %-28s %s', 'dense_candidates_count', $this->afficher($rt['dense_candidates_count'])));
            $this->line(sprintf('  %-28s %s   (max_distance=%s)', 'after_distance_filter_count',
                $this->afficher($rt['after_distance_filter_count']), $this->afficher($rt['max_distance'])));
            $this->line(sprintf('  %-28s %s', 'rerank_attempted', $this->afficher($rt['rerank_attempted'])));
            $this->line(sprintf('  %-28s %s', 'reason_not_attempted', $this->afficher($rt['reason_not_attempted'])));
            $this->line(sprintf('  %-28s %s', 'candidates_sent_to_rerank', $this->afficher($rt['candidates_sent_to_rerank_count'])));
            $this->line(sprintf('  %-28s %s', 'rerank_result_count', $this->afficher($rt['rerank_result_count'])));
            $this->line(sprintf('  %-28s %s', 'final_context_count', $this->afficher($rt['final_context_count'])));
            $this->line(sprintf('  %-28s %s', 'sources_denied', $rt['sources_denied'] === null
                ? 'null'
                : ($rt['sources_denied'] === [] ? '(aucune)' : json_encode($rt['sources_denied'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))));

            foreach ($rt['candidates'] as $c) {
                $this->line(sprintf('   #%-2s dist=%-8s pass=%-5s rerank=%-4s final=%-5s %s',
                    $this->afficher($c['dense_rank'] ?? null),
                    $this->afficher($c['dense_distance'] ?? null),
                    $this->afficher($c['passed_distance_filter'] ?? null),
                    $this->afficher($c['rerank_rank'] ?? null),
                    $this->afficher($c['selected_final'] ?? null),
                    $this->afficher($c['chunk_id'] ?? null)));
            }
        }

        $this->info('── LLM INPUT');
        $this->line("  chunks envoyes     {$trace['llm_input']['chunks_sent']}");

        foreach ($trace['llm_input']['chunks'] as $c) {
            $this->line(sprintf('   %-4s %s', $this->afficher($c['ref']), $this->afficher($c['preview'])));
        }

        $this->info('── OUTPUT');
        $this->line("  grounded           {$this->afficher($out['grounded'])}");
        $this->line("  verification       {$this->afficher($out['verification_status'])}");
        $this->line("  degraded_reason    {$this->afficher($out['degraded_reason'])}");
        $this->line("  rule               {$this->afficher($out['rule'])}");
        $this->line('  citations          '.count($out['citations']));
        $this->line('');
        $this->line($out['answer']);

        $this->info('── PROVIDER');
        foreach (['provider', 'model', 'generation_sdk_invocation_id', 'latency_ms', 'cost_usd', 'input_tokens', 'output_tokens'] as $cle) {
            $this->line(sprintf('  %-30s %s', $cle, $this->afficher($prov[$cle])));
        }
        $this->line(sprintf('  %-30s %s', 'embedding_sdk_invocation_ids',
            $prov['embedding_sdk_invocation_ids'] === null ? 'null' : count($prov['embedding_sdk_invocation_ids'])));
        $this->line('');

        return self::SUCCESS;
    }

    private function refuser(string $message, ?string $classe = null): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode([
                'refused' => true,
                'message' => $message,
                'exception' => $classe,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }

    /** `null` s'affiche « null », jamais 0 ni vide — la regle du read model. */
    private function afficher(mixed $valeur): string
    {
        if ($valeur === null) {
            return 'null';
        }

        if (is_bool($valeur)) {
            return $valeur ? 'true' : 'false';
        }

        return (string) $valeur;
    }
}
