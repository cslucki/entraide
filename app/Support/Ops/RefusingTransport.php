<?php

namespace App\Support\Ops;

use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * TASK-1724 — le transport qui REFUSE, pose a la place d'un transport distant
 * quand la base visee est un jumeau de PROD.
 *
 * ## Pourquoi remplacer le TRANSPORT, et pas ecouter `MessageSending`
 *
 * `Illuminate\Mail\Events\MessageSending` ne porte que le message et ses
 * donnees — **pas le nom du mailer**. Un garde pose sur cet evenement ne
 * saurait donc pas si l'envoi part par le mailer par defaut ou par un
 * `Mail::mailer('ses')` explicite, et MASTER exige precisement que la
 * selection explicite d'un mailer different du defaut soit couverte.
 *
 * On remplace donc le transport lui-meme. Quel que soit le chemin — defaut,
 * mailer nomme, file d'attente — l'envoi finit ici, et ici il s'arrete. C'est
 * aussi « avant transport reel » au sens litteral : aucune connexion n'est
 * ouverte.
 */
final class RefusingTransport implements TransportInterface
{
    public function __construct(private readonly string $mailer, private readonly string $base) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        throw new RuntimeException(
            "EMISSION REFUSEE — le mailer « {$this->mailer} » vise une destination qui n'est pas locale, "
            ."et la base configuree est « {$this->base} », un jumeau de PRODUCTION.\n\n"
            .'Un jumeau porte de vraies adresses et de vraies conversations : un envoi reel atteindrait '
            ."des personnes.\n\n"
            ."Seules les destinations locales sont admises — un collecteur sur l'adresse de bouclage, "
            ."ou les transports `log` et `array`.\n\n"
            .'Contexte : TASK-1724.'
        );
    }

    public function __toString(): string
    {
        return 'bouclepro-refused';
    }
}
