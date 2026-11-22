<?php

namespace App\Jobs;

use App\Models\FeedPost;
use App\Models\Organization;
use App\Models\User;
use App\Support\Feed\FeedMentions;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationEmailDeliverer;
use App\Support\Notifications\NotificationEmitter;
use App\Support\Notifications\NotificationPreferenceResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Ramsey\Uuid\Uuid;

/**
 * TASK-1880 (demande Cyril, 22/11) — notifier les personnes mentionnees dans
 * une annonce du Flux (`@Nom`, `@tous`, `@membres`).
 *
 * `@membres` peut viser toute une Organization : l'eventail des destinataires
 * se calcule donc DANS un job, sur la meme file que les livraisons (Managed
 * Queue quand `notifications_managed_queue_connection` est pose, meme routage
 * que `SendNotificationEmail`), et non dans la requete HTTP de publication.
 *
 * La charge utile est l'identifiant de l'annonce : le worker relit le texte,
 * les Boucles et les droits de l'auteur au moment ou il travaille.
 *
 * Idempotent : un seul identifiant d'evenement par annonce, et l'emetteur ne
 * cree pas de seconde notification pour la meme personne. Modifier une annonce
 * ne notifie donc QUE les personnes nouvellement mentionnees.
 */
class NotifyFeedPostMentions implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly string $feedPostId)
    {
        $this->onQueue(NotificationEmailDeliverer::QUEUE);
        $this->afterCommit();

        if ($connexion = config('queue.notifications_managed_queue_connection')) {
            $this->onConnection($connexion);
            $this->onQueue(config('queue.notifications_managed_queue_name', NotificationEmailDeliverer::QUEUE));
        }
    }

    public static function identifiantEvenement(string $feedPostId): string
    {
        return (string) Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            'bouclepro:notification:'.NotificationCatalogue::FEED_POST_MENTION_CREATED.':'.$feedPostId,
        );
    }

    public function handle(FeedMentions $mentions, NotificationEmitter $emetteur): void
    {
        $post = FeedPost::query()->whereKey($this->feedPostId)->first();

        if ($post === null
            || $post->type !== FeedPost::TYPE_ANNOUNCEMENT
            || $post->status !== FeedPost::STATUS_PUBLISHED) {
            return;
        }

        $auteur = User::query()->withoutGlobalScopes()->whereKey($post->user_id)->first();
        $org = Organization::query()->whereKey($post->organization_id)->first();

        if ($auteur === null || $org === null || $auteur->isDeactivated()) {
            return;
        }

        $analyse = $mentions->analyserAnnonce($post, $auteur);

        if ($analyse['erreur'] !== null || $analyse['mode'] === FeedMentions::MODE_AUCUNE) {
            return;
        }

        $destinataires = $mentions->destinataires($analyse, $org, $auteur);

        if ($destinataires->isEmpty()) {
            return;
        }

        $evenement = self::identifiantEvenement((string) $post->id);

        // Meme optimisation que les mentions du ChatLoop : les verdicts des
        // trois canaux en MASSE (3 requetes au total, quel que soit N).
        foreach ($destinataires->chunk(200) as $lot) {
            $verdicts = app(NotificationPreferenceResolver::class)->allowsMany(
                $lot,
                NotificationCatalogue::FEED_POST_MENTION_CREATED,
                [
                    NotificationCatalogue::CHANNEL_IN_APP,
                    NotificationCatalogue::CHANNEL_EMAIL,
                    NotificationCatalogue::CHANNEL_WEB_PUSH,
                ],
            );

            foreach ($lot as $destinataire) {
                $emetteur->emit(
                    notificationKey: NotificationCatalogue::FEED_POST_MENTION_CREATED,
                    organization: (string) $org->id,
                    recipient: $destinataire,
                    eventId: $evenement,
                    objectType: NotificationCatalogue::OBJECT_FEED_POST,
                    objectId: (string) $post->id,
                    actor: $auteur,
                    canauxAutorises: $verdicts[(string) $destinataire->id] ?? null,
                );
            }
        }
    }
}
