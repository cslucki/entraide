<?php

namespace App\Services\Knowledge;

use App\Ai\Agents\LoopClaimPatchAgent;
use App\Ai\CapabilityDefinition;
use App\Ai\CapabilityRegistry;
use App\Ai\ContexteIa;
use App\Ai\PromptRepository;
use App\Ai\ProviderResolver;
use App\Ai\ResolvedModel;
use App\Models\AdminAiPrompt;
use App\Models\DerivedKnowledgeNote;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Services\Ai\AiProviderInvocationLedger;
use App\Services\Ai\JsonResponseParser;
use App\Services\Dossiers\DerivedChunkEligibility;
use App\Support\Ai\AiCorrelation;
use App\Support\Ai\AiCost;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiUsage;
use DomainException;

/**
 * TASK-1540 — compiler une conversation en ENONCES ADRESSABLES.
 *
 * ## Ce que ce service change par rapport au digest
 *
 * `LoopConversationKnowledgeDeriver` demande au modele de reecrire un
 * paragraphe. Chaque tour rejoue donc l'integralite de la memoire, et la
 * mesure de T1537 a montre ce que cela coute : un fait pouvait disparaitre,
 * un etat errone survivre a cote de sa correction, et le vecteur du paragraphe
 * — moyenne de huit sujets — se faisait devancer par un Article generique.
 *
 * Ici le modele ne reecrit rien. Il compare la memoire existante aux echanges
 * recents et propose des OPERATIONS, que le serveur valide une a une. Corriger
 * un budget ne touche plus au fournisseur ; retirer une date ne fabrique pas
 * une date de remplacement ; et chaque enonce a son propre vecteur.
 *
 * ## L'ordre des gardes, et pourquoi il ne change pas
 *
 * Empreinte de source AVANT resolution de provider (T1534), verdict economique
 * avant l'appel, prompt actif en base, ledger canonique apres. Un tour qui
 * n'apprend rien ne coute rien, et cette propriete est ce qui rend le
 * declenchement automatique de T1539 tenable.
 */
final class LoopClaimCompiler
{
    public const FEATURE = 'loop_claim_patch';

    private const MAX_SOURCE_MESSAGES = 60;

    private const MAX_SOURCE_CHARS = 12000;

    public function __construct(
        private readonly CapabilityRegistry $capabilities,
        private readonly PromptRepository $prompts,
        private readonly ProviderResolver $providers,
        private readonly AiEconomicGuard $economicGuard,
        private readonly AiProviderInvocationLedger $ledger,
        private readonly DerivedChunkEligibility $eligibility,
        private readonly ClaimMemory $memory,
    ) {}

    /**
     * @return array{applique: bool, raison: ?string, ajoutes: int, modifies: int, retractes: int, conserves: int, rejetees: list<array{operation: array<string, mixed>, raison: string}>}
     */
    public function compile(Loop $loop): array
    {
        $vide = fn (?string $raison): array => [
            'applique' => false, 'raison' => $raison,
            'ajoutes' => 0, 'modifies' => 0, 'retractes' => 0, 'conserves' => 0,
            'resurrections' => 0, 'rejetees' => [],
        ];

        $organization = $loop->organization;

        if (! $organization instanceof Organization) {
            return $vide('organization_absente');
        }

        $messages = $this->sourceMessages($loop);

        if ($messages === []) {
            return $vide('aucune_source');
        }

        $dossierId = $this->eligibility->rootDossierIdFor($loop);

        if ($dossierId === null) {
            return $vide('aucun_dossier_racine');
        }

        // TASK-1541 — LA garde de cout, et elle vient AVANT le provider.
        //
        // Le conteneur de la Boucle porte l'empreinte de la source deja
        // compilee. Si la source n'a pas bouge, il n'y a rien a apprendre et
        // rien a depenser : c'est ce qui permet au declencheur automatique de
        // balayer toutes les dix minutes sans facturer le silence.
        // `empreinteCompilee` et non l'empreinte de n'importe quel digest : un
        // paragraphe historique porte la MEME empreinte sur les MEMES messages,
        // et la lire ici empecherait toute Boucle deja derivee de basculer.
        $empreinteSource = $this->empreinteSource($messages);
        $dejaCompilee = $this->memory->empreinteCompilee($organization, $loop);

        if ($dejaCompilee !== null && hash_equals($dejaCompilee, $empreinteSource)) {
            return ['applique' => true, 'raison' => 'source_inchangee',
                'ajoutes' => 0, 'modifies' => 0, 'retractes' => 0, 'conserves' => 0,
                'resurrections' => 0, 'rejetees' => []];
        }

        // L'etat de depart : ce que la memoire sait AVANT l'appel. Son
        // empreinte servira d'arbitre si un autre tour ecrit entre-temps.
        $claims = $this->memory->actifs($organization, $loop);
        $empreinteDeDepart = $this->memory->empreinte($claims);

        $capability = CapabilityRegistry::LOOP_CLAIM_PATCH;
        $definition = $this->capabilities->get($capability);

        $contexte = new ContexteIa(
            organizationId: (string) $organization->id,
            userId: null,
            loopId: (string) $loop->id,
            locale: str_starts_with((string) app()->getLocale(), 'en') ? 'en' : 'fr',
            capability: $capability,
            correlationId: AiCorrelation::id(),
            source: self::FEATURE,
            feature: self::FEATURE,
        );

        try {
            $resolved = $this->providers->resolve($capability, $contexte);
        } catch (DomainException) {
            return $vide('aucun_credential');
        }

        $verdict = $this->economicGuard->authorize(
            $organization,
            $definition->process,
            $resolved->provider,
            $resolved->model,
            (float) config('ai.knowledge.economic_guard.monthly_budget_usd', 2.00),
            (int) config('ai.knowledge.economic_guard.monthly_unknown_limit', 10),
        );

        if (! $verdict->allowed) {
            return $vide('budget_refuse');
        }

        $base = $this->activeInstructions($definition->promptKey);

        if ($base === null) {
            return $vide('aucun_prompt_actif');
        }

        $instructions = $this->prompts->compose($capability, $base, (string) $organization->id);
        $startedAt = microtime(true);

        try {
            $agent = new LoopClaimPatchAgent(
                $instructions,
                $definition->maxOutput,
                (float) config('ai.knowledge.temperature', 0.2),
            );

            $response = $agent->prompt($this->tour($claims, $messages),
                provider: $resolved->instance, model: $resolved->model);

            $usage = AiUsage::fromSdkTextTokens($response->usage->promptTokens, $response->usage->completionTokens);
            $operations = $this->lireOperations((string) $response->text);
        } catch (\Throwable $exception) {
            $this->recordLedger($organization, $contexte, $definition, $resolved,
                AiUsage::notObserved(), null, 'failed', $startedAt, $exception::class);

            return $vide('appel_echoue');
        }

        $cost = $this->economicGuard->finalize($resolved->provider, $resolved->model, $usage);
        $this->recordLedger($organization, $contexte, $definition, $resolved, $usage, $cost, 'success', $startedAt, null);

        $patch = ClaimPatch::valider(
            $operations,
            array_map(static fn (DerivedKnowledgeNote $c): string => (string) $c->subject_key, $claims),
            array_map(static fn (LoopMessage $m): string => (string) $m->id, $messages),
        );

        if (! $patch->aTravaille()) {
            // Rien ne change, mais la SOURCE a bouge : le conteneur enregistre
            // qu'elle a ete lue, sinon le prochain balayage rappellerait le
            // modele pour la meme absence de nouveaute.
            $this->memory->rafraichirConteneur($organization, $loop, $dossierId,
                $this->memory->actifs($organization, $loop), $messages, $empreinteSource);

            return ['applique' => true, 'raison' => 'rien_a_changer',
                'ajoutes' => 0, 'modifies' => 0, 'retractes' => 0,
                'conserves' => count($patch->operationsDe(ClaimPatch::OP_KEEP)),
                'resurrections' => 0, 'rejetees' => $patch->rejetees];
        }

        // L'origine est EXPLICITE des les deux cotes (TASK-1548) : c'est elle
        // qui arme la garde anti-resurrection, et un defaut implicite la
        // rendrait dependante de l'ordre des parametres.
        $bilan = $this->memory->appliquer($organization, $loop, $dossierId, $patch, $messages,
            $empreinteDeDepart, $contexte->correlationId, ClaimWriteOrigin::compiler());

        if ($bilan['applique']) {
            $this->memory->rafraichirConteneur($organization, $loop, $dossierId,
                $this->memory->actifs($organization, $loop), $messages, $empreinteSource);
        }

        return $bilan + ['rejetees' => $patch->rejetees];
    }

    /**
     * Le tour : la memoire d'abord, les echanges ensuite.
     *
     * Les identifiants sont donnes EXPLICITEMENT des deux cotes. C'est ce qui
     * permet au serveur de rejeter ensuite tout ce qui n'en vient pas — une
     * identite inventee, une preuve hors perimetre.
     *
     * @param  list<DerivedKnowledgeNote>  $claims
     * @param  list<LoopMessage>  $messages
     */
    private function tour(array $claims, array $messages): string
    {
        $lignes = ['--- ENONCES DEJA EN MEMOIRE ---'];

        if ($claims === []) {
            $lignes[] = '(aucun : cette memoire est vide)';
        }

        foreach ($claims as $claim) {
            $lignes[] = '['.$claim->subject_key.'] '.trim((string) $claim->content);
        }

        $lignes[] = '';
        $lignes[] = '--- ECHANGES RECENTS ---';

        $used = 0;

        foreach ($messages as $message) {
            $auteur = trim((string) ($message->sender?->name ?? '')) ?: '?';
            $ligne = '['.$message->id.'] '.$auteur.' : '.trim((string) $message->body);

            if ($used + mb_strlen($ligne) > self::MAX_SOURCE_CHARS) {
                break;
            }

            $lignes[] = $ligne;
            $used += mb_strlen($ligne);
        }

        return implode("\n", $lignes);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lireOperations(string $texte): array
    {
        $decoded = json_decode(JsonResponseParser::extractJsonFromText($texte), true);

        if (! is_array($decoded)) {
            return [];
        }

        $operations = $decoded['operations'] ?? $decoded;

        return is_array($operations) ? array_values($operations) : [];
    }

    /**
     * L'empreinte de l'etat SOURCE — identifiants et dernieres editions.
     *
     * Meme idiome que `LoopConversationKnowledgeDeriver` : un message corrige
     * change l'empreinte, donc redonne lieu a compilation.
     *
     * @param  list<LoopMessage>  $messages
     */
    private function empreinteSource(array $messages): string
    {
        $parts = array_map(
            static fn (LoopMessage $m): string => $m->id.':'.($m->edited_at?->toIso8601String() ?? $m->created_at?->toIso8601String() ?? ''),
            $messages,
        );

        return hash('sha256', implode('|', $parts));
    }

    /**
     * @return list<LoopMessage>
     */
    private function sourceMessages(Loop $loop): array
    {
        return LoopMessage::query()
            ->with('sender:id,name')
            ->where('loop_id', $loop->id)
            ->where('organization_id', $loop->organization_id)
            ->where('type', 'user')
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->limit(self::MAX_SOURCE_MESSAGES)
            ->get()
            ->filter(static fn (LoopMessage $m): bool => mb_strlen(trim((string) $m->body)) >= LoopConversationKnowledgeDeriver::MIN_MESSAGE_CHARS)
            ->reverse()
            ->values()
            ->all();
    }

    private function activeInstructions(string $promptKey): ?string
    {
        $prompt = AdminAiPrompt::query()
            ->where('scenario_id', $promptKey)
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();

        return $prompt === null || trim((string) $prompt->prompt_text) === ''
            ? null
            : (string) $prompt->prompt_text;
    }

    private function recordLedger(
        Organization $organization,
        ContexteIa $contexte,
        CapabilityDefinition $definition,
        ResolvedModel $resolved,
        AiUsage $usage,
        ?AiCost $cost,
        string $status,
        float $startedAt,
        ?string $failure,
    ): void {
        $this->ledger->recordGeneration(
            organizationId: (string) $organization->id,
            userId: null,
            capability: $definition->id,
            process: $definition->process,
            resolved: $resolved,
            usage: $usage,
            cost: $cost,
            status: $status,
            correlationId: $contexte->correlationId,
            sdkInvocationId: null,
            failureReason: $failure,
            startedAtMicrotime: $startedAt,
            feature: self::FEATURE,
        );
    }
}
