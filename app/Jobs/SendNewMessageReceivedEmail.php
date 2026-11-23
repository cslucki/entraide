<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\Organization;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\NewMessageReceived;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationEmitter;
use App\Support\Notifications\NotificationPreferenceResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

/**
 * TASK-1858 — livrer `NewMessageReceived` hors du thread HTTP/Livewire,
 * pour l'unique declencheur `MessageThread::notifierApresCommit()`.
 * Audit prealable (TASK-1857) : ce declencheur partage la meme famille de
 * risque que `TransactionStatusChanged` avant TASK-1855/1857 — un
 * `DB::afterCommit()` BRUT reste SYNCHRONE dans le thread HTTP, et
 * bloque sur une conversation SMTP a CHAQUE message prive echange (plus
 * frequent que les transitions de transaction).
 *
 * ## Perimetre
 *
 * Seul `MessageThread::notifierApresCommit()` declenche `NewMessageReceived`
 * aujourd'hui (verifie : un seul appel site dans tout le depot). Rien
 * d'autre a deporter dans la messagerie privee pour cette notification.
 *
 * ## La charge utile est trois IDENTIFIANTS, rien d'autre
 *
 * `transactionId`, `messageId`, `recipientId` — meme motif que
 * `SendTransactionStatusChangedEmail` (TASK-1855/1857). Le worker relit
 * la transaction, le message ET le destinataire FRAIS : aucun objet
 * Eloquent serialise dans la charge utile.
 *
 * ## Autorisation TENANT — meme idiome que TASK-1854/1855/1857
 *
 * `Transaction` porte `BelongsToOrganizationScope` (contrairement a
 * `Message`, qui n'a aucun scope global propre — verifie) : la PREMIERE
 * lecture de la transaction doit explicitement l'ecarter
 * (`withoutGlobalScope`) avant de pouvoir lier le tenant.
 * `withOrganizationContext()` reprend A L'IDENTIQUE l'idiome deja en
 * place dans tous les jobs de cette famille : etat precedent sauvegarde,
 * restaure dans un `finally` meme si la notification leve.
 *
 * ## Isolation explicite du destinataire (meme arbitrage que PR #862)
 *
 * Le destinataire doit etre exactement `buyer_id` ou `seller_id` de LA
 * MEME transaction, dans la MEME Organization — jamais presume depuis la
 * seule charge utile. Un refus est journalise SANS email ni identifiant
 * de destinataire.
 *
 * ## Arbitrage MASTER (revue PR #867) — coherence du MESSAGE lui-meme
 *
 * L'isolation ci-dessus protege le DESTINATAIRE, pas la lecture du
 * MESSAGE : `Message` n'a aucun scope global tenant (contrairement a
 * `Transaction`), donc `Message::find($this->messageId)` rendrait,
 * SANS garde supplementaire, n'importe quel message portant cet
 * identifiant — y compris un message d'une AUTRE transaction ou d'une
 * AUTRE Organization si la charge utile etait un jour corrompue ou
 * rejouee. `handle()` verifie donc, avant toute resolution du
 * destinataire : `message.transaction_id` ET `message.organization_id`
 * correspondent EXACTEMENT a la transaction relue, ET
 * `message.sender_id` est lui-meme l'un des deux participants legitimes
 * (buyer ou seller) — un expediteur qui n'est ni l'un ni l'autre ne peut
 * provenir d'aucun parcours reel de cette conversation. Enfin, le
 * destinataire ne peut jamais etre l'expediteur lui-meme (on ne notifie
 * personne de son propre message). Chaque refus est journalise SANS
 * contenu de message ni identifiant de destinataire.
 *
 * ## Preferences et journalisation
 *
 * TASK-1885 C5 — pour une DISCUSSION PRIVEE seulement, ce job emet aussi la
 * notification `conversation.private.received` (application + Web Push) et
 * ne part en email que si le membre n'a pas coupe ce canal. Le paragraphe
 * ci-dessous reste vrai pour les messages d'un echange d'entraide.
 *
 * `NewMessageReceived` ne porte aujourd'hui AUCUNE preference de
 * destinataire (verifie : `via()` rend toujours `['mail']`, aucune garde
 * dans la classe) — ce job ne l'ajoute pas, il deplace l'envoi existant
 * SANS en changer la condition. La journalisation `email_logs` reste
 * AUTOMATIQUE via l'ecouteur global `NotificationSent`
 * (`AppServiceProvider`, deja verifie pour `TransactionStatusChanged`) :
 * aucune ecriture propre a ce job.
 *
 * ## `$tries = 1` — meme doctrine que toute la famille
 *
 * Aucune reprise automatique : `NewMessageReceived` n'a pas de garde
 * d'idempotence propre, la rejouer aveuglement risquerait un second
 * envoi sur une panne transitoire du transport.
 *
 * ## Integrite de la messagerie si la mise en file echoue
 *
 * Le message est DEJA ecrit et commis avant que ce job ne soit meme
 * construit (`MessageThread::sendMessage()` dispatche depuis
 * `DB::afterCommit()`, APRES la transaction qui cree le message). Un
 * echec de mise en file (verifie par l'appelant, sous `rescue()`) ne
 * peut donc jamais faire disparaitre un message deja envoye dans la
 * conversation.
 */
class SendNewMessageReceivedEmail implements ShouldQueue
{
    use Dispatchable, Queueable;

    public const DEDICATED_QUEUE = 'entraide-new-message-email';

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public readonly string $transactionId,
        public readonly string $messageId,
        public readonly string $recipientId,
    ) {
        $this->onQueue(self::DEDICATED_QUEUE);
        $this->afterCommit();

        if ($connexion = config('queue.notifications_managed_queue_connection')) {
            $this->onConnection($connexion);
            $this->onQueue(config('queue.notifications_managed_queue_name', self::DEDICATED_QUEUE));
        }
    }

    public function handle(): void
    {
        $transaction = Transaction::withoutGlobalScope(BelongsToOrganizationScope::class)
            ->find($this->transactionId);

        if ($transaction === null) {
            Log::warning('SendNewMessageReceivedEmail: transaction introuvable au moment du traitement.', [
                'transaction_id' => $this->transactionId,
                'message_id' => $this->messageId,
            ]);

            return;
        }

        $this->withOrganizationContext($transaction->organization_id, function (?Organization $organization) use ($transaction): void {
            if ($organization === null) {
                Log::warning('SendNewMessageReceivedEmail: Organization introuvable, email non envoye.', [
                    'transaction_id' => $this->transactionId,
                    'organization_id' => $transaction->organization_id,
                ]);

                return;
            }

            $message = Message::find($this->messageId);

            if ($message === null) {
                Log::warning('SendNewMessageReceivedEmail: message introuvable au moment du traitement.', [
                    'transaction_id' => $this->transactionId,
                    'message_id' => $this->messageId,
                ]);

                return;
            }

            // Arbitrage MASTER (revue PR #867) — `Message` n'a AUCUN scope
            // global tenant (contrairement a `Transaction`, deja ecarte
            // explicitement ci-dessus) : rien n'empeche, a la seule lecture
            // par identifiant, qu'un message RETROUVE appartienne a une
            // AUTRE transaction ou une AUTRE Organization que celles de la
            // charge utile — l'isolation deja posee sur le DESTINATAIRE ne
            // couvre pas cette lecture-la. Verifie ICI, avant toute
            // resolution du destinataire : appartenance stricte a la
            // transaction ET a l'Organization, puis l'expediteur doit
            // lui-meme etre l'un des deux participants legitimes (buyer ou
            // seller) — un message dont l'expediteur n'est NI l'un NI
            // l'autre ne peut provenir d'aucun parcours reel de cette
            // conversation.
            $messageCoherent = (string) $message->transaction_id === $this->transactionId
                && $message->organization_id === $transaction->organization_id
                && in_array($message->sender_id, [$transaction->buyer_id, $transaction->seller_id], true);

            if (! $messageCoherent) {
                Log::warning('SendNewMessageReceivedEmail: message incoherent avec la transaction, email non envoye.', [
                    'transaction_id' => $this->transactionId,
                    'organization_id' => $transaction->organization_id,
                ]);

                return;
            }

            $recipient = User::find($this->recipientId);

            if ($recipient === null) {
                Log::warning('SendNewMessageReceivedEmail: destinataire introuvable, email non envoye.', [
                    'transaction_id' => $this->transactionId,
                    'recipient_id' => $this->recipientId,
                ]);

                return;
            }

            // Isolation explicite (meme arbitrage que SendTransactionStatusChangedEmail,
            // PR #862) : seuls l'acheteur et le vendeur DE CETTE transaction,
            // DANS la MEME Organization, sont des destinataires legitimes.
            $estDestinataireLegitime = in_array($this->recipientId, [$transaction->buyer_id, $transaction->seller_id], true)
                && $recipient->organization_id === $transaction->organization_id;

            if (! $estDestinataireLegitime) {
                Log::warning('SendNewMessageReceivedEmail: destinataire illegitime, email non envoye.', [
                    'transaction_id' => $this->transactionId,
                    'organization_id' => $transaction->organization_id,
                ]);

                return;
            }

            // Arbitrage MASTER (revue PR #867) — un destinataire identique
            // a l'expediteur du message ne peut jamais etre legitime : on
            // ne notifie personne de son PROPRE message.
            if ($this->recipientId === $message->sender_id) {
                Log::warning('SendNewMessageReceivedEmail: destinataire identique a l expediteur, email non envoye.', [
                    'transaction_id' => $this->transactionId,
                    'organization_id' => $transaction->organization_id,
                ]);

                return;
            }

            // TASK-1885 C5 (demande Cyril, 22/11) — une DISCUSSION PRIVEE
            // notifie aussi dans l'application et en PWA, et son email obeit
            // desormais au reglage « Recevoir un message prive ». Les messages
            // d'un ECHANGE d'entraide gardent leur comportement d'avant (email
            // `new_message`, sans reglage) : hors du perimetre demande.
            if ($transaction->isDirectConversation()) {
                $this->notifierDansLApplication($organization, $transaction, $message, $recipient);

                if (! app(NotificationPreferenceResolver::class)->allows(
                    $recipient,
                    NotificationCatalogue::CONVERSATION_PRIVATE_RECEIVED,
                    NotificationCatalogue::CHANNEL_EMAIL,
                )) {
                    return;
                }
            }

            $recipient->notify(new NewMessageReceived($transaction, $message));
        });
    }

    /**
     * TASK-1885 C5 — notification en application + Web Push (le planificateur
     * du catalogue s'en charge ; l'email, lui, reste a ce job).
     *
     * Un identifiant d'evenement PAR MESSAGE (rejeu idempotent) ; la cle de
     * regroupement PAR CONVERSATION. Un echec ici ne doit jamais empecher
     * l'email : il est journalise, sans contenu de message.
     */
    private function notifierDansLApplication(Organization $organization, Transaction $transaction, Message $message, User $recipient): void
    {
        rescue(
            function () use ($organization, $transaction, $message, $recipient): void {
                $expediteur = User::query()->whereKey($message->sender_id)->first();

                app(NotificationEmitter::class)->emit(
                    notificationKey: NotificationCatalogue::CONVERSATION_PRIVATE_RECEIVED,
                    organization: $organization,
                    recipient: $recipient,
                    eventId: (string) Uuid::uuid5(
                        Uuid::NAMESPACE_URL,
                        'bouclepro:notification:'.NotificationCatalogue::CONVERSATION_PRIVATE_RECEIVED.':'.$message->id,
                    ),
                    objectType: NotificationCatalogue::OBJECT_TRANSACTION,
                    objectId: (string) $transaction->id,
                    actor: $expediteur,
                    collapseKey: 'conversation:'.$transaction->id,
                );
            },
            fn (\Throwable $e) => Log::error('In-app notification failed after successful business action', [
                'event' => 'private_conversation_notification',
                'transaction_id' => $transaction->id,
                'organization_id' => $transaction->organization_id,
                'exception_class' => $e::class,
            ]),
            false,
        );
    }

    /**
     * Idiome repris A L'IDENTIQUE de `SendTransactionStatusChangedEmail` /
     * `SendReviewReceivedEmail` (TASK-1854/1855) — voir le docblock de
     * classe.
     *
     * @template TReturn
     *
     * @param  callable(?Organization): TReturn  $callback
     * @return TReturn
     */
    private function withOrganizationContext(string $organizationId, callable $callback): mixed
    {
        $hadPrevious = app()->bound('current_organization');
        $previous = $hadPrevious ? app('current_organization') : null;
        $organization = Organization::query()->whereKey($organizationId)->first();

        if ($organization) {
            app()->instance('current_organization', $organization);
        } elseif ($hadPrevious) {
            app()->forgetInstance('current_organization');
        }

        try {
            return $callback($organization);
        } finally {
            if ($hadPrevious) {
                app()->instance('current_organization', $previous);
            } else {
                app()->forgetInstance('current_organization');
            }
        }
    }
}
