<?php

namespace App\Events;

use App\Models\LoopMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

class LoopMessageCreated implements ShouldBroadcast, ShouldRescue
{
    use Dispatchable, InteractsWithSockets;

    /**
     * TASK-1839 — `sendUserMessage()` emet cet event DEPUIS l'interieur de
     * son `DB::transaction()` (meme configuration que la leçon T1815). Avec
     * `ShouldBroadcastNow`, la diffusion partait AVANT le commit : un client
     * connecte pouvait recevoir un message qui finissait par ne jamais
     * exister si la transaction echouait plus loin. `ShouldBroadcast` met
     * la diffusion en file (`BroadcastEvent`), et cette propriete publique
     * est lue par `BroadcastEvent::__construct()` pour la reporter apres le
     * commit — sous le pilote `sync` local, honore comme pour T1815.
     */
    public bool $afterCommit = true;

    /**
     * TASK-1839 — `ShouldRescue` (natif Laravel) fait passer l'appel qui
     * ENREGISTRE la diffusion par `rescue()` : une panne de Reverb est
     * journalisee (`report()`, visible), jamais une exception qui remonte.
     *
     * PORTEE REELLE, mesuree, pas supposee : protege les call sites QUI NE
     * SONT PAS dans une transaction ouverte (6 des 7 emetteurs de cet
     * event), et le call site de `sendUserMessage()` des que
     * `QUEUE_CONNECTION` n'est PAS `sync` (le defaut projet `.env.example`
     * et la PROD, qui mettent le job en file reellement async — l'echec
     * Reverb reste alors isole dans `failed_jobs`, zero impact sur la
     * requete HTTP ni sur les autres listeners de cet event).
     *
     * NE PROTEGE PAS : `sendUserMessage()` sous `QUEUE_CONNECTION=sync`
     * (reglage LOCAL de confort, pas le defaut projet). Sous `sync`,
     * `SyncQueue::push()` avec `afterCommit` DIFFERE l'execution du job
     * dans un callback de la transaction (`DatabaseTransactionRecord::
     * executeCallbacks()`), hors de portee de ce `rescue()` qui n'enveloppe
     * que l'ENREGISTREMENT du callback, pas son execution differee. Et ce
     * foreach n'a AUCUNE isolation entre callbacks : une exception y stoppe
     * aussi tout callback enregistre APRES (ex. le `dispatch(
     * GenerateAiAgentResponse::class)->afterCommit()` de `AppServiceProvider`
     * sur une Boucle avec agent IA). Verifie empiriquement les deux cotes
     * (TASK-1839) : exception propagee sous `sync`, job proprement place en
     * `failed_jobs` sans aucun effet de bord sous `database`. Caracteristique
     * de `Dispatcher::invokeListeners()` (broadcastEvent() avant les autres
     * listeners, pas de try/catch), anterieure a cette TASK mais invisible
     * tant que `BROADCAST_CONNECTION` valait `log` (jamais d'echec reel).
     *
     * Recommandation pour ce pilote : `QUEUE_CONNECTION=database` en local
     * (le defaut `.env.example`, pas `sync`) + un `queue:work` pendant la
     * recette — reproduit fidelement le comportement PROD (Managed Queue
     * T1829) plutot que de contourner une limite d'architecture Laravel.
     */
    // implements ShouldRescue ci-dessus, aucune methode requise (marqueur).

    public string $id;

    public string $loopId;

    public ?string $senderId;

    public string $body;

    public string $type;

    public string $createdAt;

    public function __construct(LoopMessage $message)
    {
        $this->id = $message->id;
        $this->loopId = $message->loop_id;
        $this->senderId = $message->sender_id;
        $this->body = $message->body;
        $this->type = $message->type;
        $this->createdAt = $message->created_at->toIso8601String();
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("loop.{$this->loopId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'loop.message.created';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->id,
            'loop_id' => $this->loopId,
            'sender_id' => $this->senderId,
            'body' => $this->body,
            'type' => $this->type,
            'created_at' => $this->createdAt,
        ];
    }
}
