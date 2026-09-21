<?php

namespace App\Services\Loops;

use App\Ai\MultiAssistant\AssistantOutcome;
use App\Ai\MultiAssistant\MultiAssistantRun;
use App\Events\LoopMessageCreated;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ce qui, d'un tour « 3 assistants IA », entre dans le FIL. (TASK-1619)
 *
 * Separe de l'orchestrateur, et ce n'est pas un decoupage de confort :
 * `LoopMultiAiOrchestrator` ne publie RIEN, par contrat (SLICE D). Publier est
 * un acte de l'interface, declenche par un humain qui a clique. Laisser le
 * moteur publier aurait fait de « generer » et « ecrire dans la conversation
 * de tout le monde » un seul geste, que plus rien ensuite n'aurait pu separer.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * Seules les REUSSITES entrent dans le fil
 * ────────────────────────────────────────────────────────────────────────────
 *
 * Arbitrage MASTER du 21/09. Un assistant sature, refuse ou en panne n'ecrit
 * rien : son etat vit dans la zone du composeur, chez le seul demandeur, et
 * disparait au rechargement.
 *
 * La raison est que le fil est LU PAR TOUT LE CERCLE, ET POUR TOUJOURS. Une
 * bulle « Traverse est momentanement indisponible » y resterait des mois apres
 * que la saturation a cesse, pour des gens qui n'ont pas pose la question. Le
 * fil garde ce qui a une valeur pour le groupe ; un incident technique de
 * trente secondes n'en a pas.
 *
 * Consequence assumee : recharger la page perd l'avertissement. C'est le bon
 * arbitrage — ce qui est perdu est une information perissable, ce qui est
 * garde est une reponse.
 */
final class LoopMultiAiPublisher
{
    /** Le discriminant d'identite de bulle de ce moteur (famille `ai_mode`, T1308). */
    public const AI_MODE = 'multi_ai';

    /**
     * Publier la question puis les reponses reussies.
     *
     * `$questionMessage` non nul = la question est DEJA dans le fil (reessai,
     * « demander a une autre IA »). On n'en republie pas une seconde : le
     * membre a pose sa question une fois, le fil ne doit pas laisser croire
     * qu'il l'a posee trois fois.
     *
     * @return array{question: ?LoopMessage, bubbles: list<LoopMessage>}
     */
    public function publish(
        Loop $loop,
        User $requester,
        string $question,
        MultiAssistantRun $run,
        ?LoopMessage $questionMessage = null,
    ): array {
        $reussites = $run->succeeded();

        // Aucune reponse : rien n'entre dans le fil, pas meme la question.
        // Publier une question que personne n'a pu honorer laisserait une
        // interpellation sans suite dans la conversation de tout le monde.
        if ($reussites === [] && $questionMessage === null) {
            return ['question' => null, 'bubbles' => []];
        }

        $bulles = [];

        DB::transaction(function () use ($loop, $requester, $question, $reussites, $run, &$questionMessage, &$bulles): void {
            $questionMessage ??= LoopMessage::create([
                'loop_id' => $loop->id,
                'sender_id' => $requester->id,
                'reply_to_id' => null,
                'body' => $question,
                'image_path' => null,
                'type' => 'user',
                'metadata' => ['asked_knowledge_question' => true],
                'organization_id' => $loop->organization_id,
            ]);

            foreach ($reussites as $outcome) {
                $bulles[] = $this->bulle($loop, $requester, $question, $outcome, $questionMessage, $run->correlationId);
            }

            $loop->touch();
        });

        foreach ($bulles as $bulle) {
            event(new LoopMessageCreated($bulle));
        }

        return ['question' => $questionMessage, 'bubbles' => $bulles];
    }

    private function bulle(
        Loop $loop,
        User $requester,
        string $question,
        AssistantOutcome $outcome,
        LoopMessage $questionMessage,
        string $correlationId,
    ): LoopMessage {
        return LoopMessage::create([
            'loop_id' => $loop->id,
            'sender_id' => null,
            'reply_to_id' => $questionMessage->id,
            'body' => (string) $outcome->answer,
            'image_path' => null,
            'type' => 'ai',
            'metadata' => array_filter([
                'requested_by' => $requester->id,
                // Le discriminant canonique (T1308). Aucun message anterieur ne
                // peut le porter : il n'a donc aucune derivation historique a
                // prevoir, comme `llm_rag` en son temps.
                'ai_mode' => self::AI_MODE,
                'action' => 'multi_ai',
                // CE QUI NOMME LA VOIX. Trois assistants dans un meme fil ne
                // se distinguent que par la : sans cette cle, le membre lit
                // trois bulles identiques qui se contredisent poliment.
                'assistant_key' => $outcome->assistantKey,
                'plugin' => LoopAiAssistants::PLUGIN,
                'question' => $question,
                'grounded' => $outcome->sources !== [],
                // Les messages de la Boucle qui ont servi de matiere. Meme cle
                // que le mode Dossiers (`context_message_ids`) : la provenance
                // de ce moteur EST conversationnelle, elle ne cite pas de
                // documents et n'a donc pas de `sources` a afficher.
                'context_message_ids' => array_values(array_unique(array_map(
                    static fn (array $entree): string => (string) ($entree['id'] ?? ''),
                    $outcome->sources,
                ))),
                // TASK-1595 / TASK-1619 — produites DANS le meme tour provider.
                // Cle ABSENTE quand il n'y en a pas : la forme de la metadata
                // ne change pas pour autant, et le blade la lit deja ainsi.
                ...($outcome->followUps === [] ? [] : ['follow_up_questions' => $outcome->followUps]),
                'model' => $outcome->model,
                'turn_id' => $outcome->turnId,
                // La correlation du tour : c'est par elle que les bulles d'un
                // meme « Demander aux 3 » se reconnaissent entre elles, et donc
                // par elle que la synthese saura QUOI relire.
                'correlation_id' => $correlationId,
            ], static fn ($valeur): bool => $valeur !== null),
            'organization_id' => $loop->organization_id,
        ]);
    }
}
