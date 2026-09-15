<?php

namespace App\Console\Commands;

use App\Ai\Context\DossierAccessScope;
use App\Models\AiInteraction;
use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\LoopKnowledgeAnswerService;
use App\Support\Ai\AiTurnInspection;
use Illuminate\Console\Command;

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
        {--mode=dossiers : dossiers | ia_dossiers}
        {--question= : La question posee}
        {--json : Sortie JSON machine-readable}';

    protected $description = 'Execute et observe le vrai chemin BouclePro derriere une question, sans publier dans le fil.';

    /** Les surfaces et modes REELLEMENT reproductibles en v0. */
    private const SURFACES = ['loop'];

    private const MODES = ['dossiers', 'ia_dossiers'];

    public function handle(
        LoopKnowledgeAnswerService $knowledge,
        DossierAccessScope $scope,
    ): int {
        $surface = (string) $this->option('surface');
        $mode = (string) $this->option('mode');
        $question = trim((string) $this->option('question'));

        // Un mode non reproductible est REFUSE, jamais approxime. Rendre une
        // trace pour un chemin qu'on n'a pas execute serait le defaut que cet
        // outil existe pour empecher.
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
                $reponse = $mode === 'ia_dossiers'
                    ? $knowledge->answerHybrid($loop, $user, $question, null, publish: false)
                    : $knowledge->answer($loop, $user, $question, null, publish: false);
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
