<?php

namespace App\Support\Images;

/**
 * TASK-1873 — la connexion de queue des images est-elle VRAIMENT asynchrone ?
 *
 * ## Pourquoi cette garde
 *
 * Mesure PROD du 21/11/2026 : `QUEUE_CONNECTION=sync`. Sans
 * `IMAGE_VARIANTS_QUEUE_CONNECTION`, la connexion des images retombe sur la
 * connexion par defaut, donc `sync` : un « lot » de la console s'executerait
 * EN ENTIER dans la requete web, et chaque upload deriverait ses variantes
 * pendant la requete, sur une instance de 512 Mo.
 *
 * La regle est resolue comme Laravel le fera au dispatch : connexion dediee
 * si elle est definie, sinon connexion par defaut, puis son PILOTE.
 *
 * Asynchrones : les pilotes qui confient le job a un autre processus
 * (`database`, `redis`, `sqs`, `beanstalkd`). Tout le reste est refuse :
 * `sync` (dans la requete), `deferred` (apres la reponse, mais dans le meme
 * processus web), `background` (processus ephemere sans supervision ni
 * suivi), `null` (job perdu), connexion inconnue.
 */
final class ImageQueue
{
    public const ASYNC_DRIVERS = ['database', 'redis', 'sqs', 'beanstalkd'];

    public function connection(): string
    {
        return (string) (config('image_variants.queue_connection') ?: config('queue.default'));
    }

    public function driver(): ?string
    {
        $driver = config('queue.connections.'.$this->connection().'.driver');

        return is_string($driver) ? $driver : null;
    }

    public function isAsync(): bool
    {
        return in_array($this->driver(), self::ASYNC_DRIVERS, true);
    }

    /**
     * @throws ImageQueueNotConfigured
     */
    public function ensureAsync(): void
    {
        if (! $this->isAsync()) {
            throw new ImageQueueNotConfigured($this->connection(), $this->driver());
        }
    }
}
