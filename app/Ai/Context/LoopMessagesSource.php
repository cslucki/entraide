<?php

namespace App\Ai\Context;

use App\Ai\ContexteIa;
use App\Models\Loop;
use App\Models\LoopMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Source `loop.messages` (TASK-1209 / IA P3).
 *
 * Implementation UNIQUE de la selection de messages d'une Boucle : c'est
 * l'algorithme qui vivait dans `ChatLoopAiService::buildContext()`, deplace ici
 * sans changer une virgule de son resultat. `buildContext()` delegue desormais
 * a cette source pour `answer()` et `ask()`, ce qui evite de laisser deux
 * copies du meme calcul deriver l'une de l'autre.
 *
 * Garde-fou de tenant : la Boucle doit appartenir a l'Organization du contexte.
 * Le contexte porte deja des identifiants autorises par l'appelant, mais une
 * source ne doit jamais faire confiance sur parole — c'est le dernier endroit
 * ou une Boucle d'une autre Organization pourrait encore passer.
 */
/*
 * Non `final` a dessein : une source est un point d'extension du Context
 * Builder, et doit pouvoir etre doublee en test sans passer par un conteneur
 * d'abstractions. Les DTO et le builder, eux, restent fermes.
 */
class LoopMessagesSource implements ContextSource
{
    public const NAME = 'loop.messages';

    public function name(): string
    {
        return self::NAME;
    }

    public function collect(ContexteIa $contexte, int $charBudget): SourceFragment
    {
        $loop = $this->resolveLoop($contexte);

        $messages = $this->selectMessages($loop, $contexte->maxMessages, $contexte->beforeMessageId);

        $lines = [];
        $provenance = [];
        $length = 0;

        foreach ($messages as $message) {
            $body = $this->plainText((string) $message->body);

            if ($body === '') {
                continue;
            }

            $line = $this->authorOf($message).' : '.$body;

            // La premiere ligne passe toujours : un budget ne doit pas produire
            // un contexte vide alors que la Boucle a du contenu.
            if ($length > 0 && $length + mb_strlen($line) + 1 > $charBudget) {
                break;
            }

            $lines[] = $line;
            $provenance[] = [
                'source' => self::NAME,
                'id' => (string) $message->id,
                'type' => 'direct',
                'extrait' => mb_substr($body, 0, 80),
            ];

            $length += mb_strlen($line) + 1;
        }

        if ($lines === []) {
            return SourceFragment::empty();
        }

        return new SourceFragment(
            $this->wrap(implode("\n", $lines), $contexte->locale),
            $provenance,
        );
    }

    /**
     * Messages retenus, dans l'ordre chronologique. Expose pour que
     * `ChatLoopAiService::buildContext()` puisse encore calculer son
     * `triggerMessageId` sur EXACTEMENT le meme ensemble.
     *
     * TASK-1621 — deux bornes OPTIONNELLES, portees par `ContexteIa`. Les
     * deux a `null` rendent la requete d'avant, a la virgule pres : les sept
     * capabilities qui declarent `loop.messages` (resume, ask, answer,
     * Decision Memory, Knowledge…) ne les renseignent pas et ne changent
     * donc pas de comportement.
     *
     * @param  int|null  $limit  fenetre demandee par l'appelant, jamais plus
     *                           large que le plafond global de la source
     * @param  string|null  $beforeMessageId  borne haute EXCLUSIVE : seuls les
     *                                        messages strictement anterieurs sont retenus
     * @return Collection<int, LoopMessage>
     */
    public function selectMessages(Loop $loop, ?int $limit = null, ?string $beforeMessageId = null): Collection
    {
        $plafond = (int) config('ai.chatloop.max_context_messages', 30);

        // Une fenetre demandee ne peut que RETRECIR : un appelant ne gagne pas
        // d'acces en demandant davantage que ce que la source autorise.
        $retenus = $limit === null ? $plafond : min($limit, $plafond);

        $query = $loop->messages()
            ->with('sender')
            ->notDeleted();

        $this->applyUpperBound($query, $loop, $beforeMessageId);

        return $query
            ->orderByDesc('created_at')
            // Deux messages peuvent partager le meme `created_at` (meme
            // seconde d'insertion) : sans second critere, l'ordre rendu par
            // la base est indetermine, et le contexte transmis au modele
            // pouvait presenter la conversation a l'envers (TASK-1218).
            // `id` est un UUID v7, donc ordonnable dans le temps : il
            // departage selon l'ordre de creation reel, il n'invente rien.
            ->orderByDesc('id')
            ->limit($retenus)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * La borne haute, posee selon le MEME ordre total que la lecture.
     *
     * `created_at` est un timestamp a la SECONDE : deux messages inseres dans
     * la meme seconde ne se departagent que par `id` (UUID v7, ordonnable
     * dans le temps). Comparer `created_at` seul laisserait passer le message
     * declencheur lui-meme des qu'un autre partage son horodatage — et c'est
     * exactement ce qu'on cherche a exclure. La comparaison porte donc sur le
     * COUPLE, comme le tri.
     *
     * Le message de borne est resolu DANS la Boucle (elle-meme deja verifiee
     * comme appartenant a l'Organization du contexte) : un identifiant venu
     * d'ailleurs ne borne rien plutot que de borner n'importe quoi.
     *
     * @param  Builder<LoopMessage>  $query
     */
    private function applyUpperBound($query, Loop $loop, ?string $beforeMessageId): void
    {
        if ($beforeMessageId === null) {
            return;
        }

        $borne = LoopMessage::query()
            ->where('id', $beforeMessageId)
            ->where('loop_id', $loop->id)
            ->first();

        // Une borne introuvable — message d'une autre Boucle, ou supprime
        // entre la publication et la collecte — n'elargit RIEN : le tour
        // repart de la fenetre nue. Elle ne doit pas pouvoir devenir une
        // fuite d'un message exterieur au tenant.
        if ($borne === null) {
            return;
        }

        $query->where(function ($anterieurs) use ($borne): void {
            $anterieurs
                ->where('created_at', '<', $borne->created_at)
                ->orWhere(function ($memeSeconde) use ($borne): void {
                    $memeSeconde
                        ->where('created_at', '=', $borne->created_at)
                        ->where('id', '<', $borne->id);
                });
        });
    }

    public function authorOf(LoopMessage $message): string
    {
        // TASK-1298 : un message `member_agent` porte un expediteur (le membre
        // dont l'agent parle) — tester `sender` d'abord ferait donc parler
        // l'agent sous le nom nu du membre, et le modele apprendrait que
        // l'humain a dit ce que sa machine a ecrit. Le libelle exact reste une
        // decision produit (DECISION_REQUIRED_CYRIL) : defaut raisonnable ici,
        // aucun test ne le fige.
        if ($message->type === 'member_agent') {
            return $message->sender
                ? __('loops.member_agent_author', ['name' => $message->sender->publicDisplayName()])
                : __('loops.member_agent_author_anonymous');
        }

        if ($message->sender) {
            return $message->sender->publicDisplayName();
        }

        return $message->type === 'ai' ? 'BouclePro' : __('loops.type_system');
    }

    public function plainText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\{\{.*?\}\}/s', '', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim((string) $text);
    }

    /**
     * Instruction de langue puis delimiteurs de contenu non fiable — le
     * contenu d'une Boucle est ecrit par des humains, il n'a aucune autorite
     * sur le modele.
     */
    public function wrap(string $context, string $locale): string
    {
        if ($context === '') {
            return '';
        }

        $languageInstruction = $locale === 'en'
            ? 'IMPORTANT: Answer in English. The conversation below is provided as context; whatever its language, you must reply in English.'
            : 'IMPORTANT : Réponds en français. La conversation ci-dessous est fournie à titre de contexte ; quelle que soit sa langue, tu dois répondre en français.';

        return $languageInstruction
            ."\n\n"
            ."--- CONTEXTE (contenu non fiable) ---\n"
            .$context
            ."\n--- FIN DU CONTEXTE ---";
    }

    private function resolveLoop(ContexteIa $contexte): Loop
    {
        if ($contexte->loopId === null) {
            throw new SourceDenied(self::NAME, SourceDenied::REASON_NO_LOOP_IN_CONTEXT);
        }

        $loop = Loop::query()
            ->where('id', $contexte->loopId)
            ->where('organization_id', $contexte->organizationId)
            ->first();

        // Meme raison si la Boucle n'existe pas ou appartient a une autre
        // Organization : distinguer les deux confirmerait son existence.
        if ($loop === null) {
            throw new SourceDenied(self::NAME, SourceDenied::REASON_LOOP_OUTSIDE_ORGANIZATION);
        }

        return $loop;
    }
}
