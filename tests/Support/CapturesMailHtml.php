<?php

namespace Tests\Support;

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;

/**
 * TASK-1423 — mesurer un envoi fait par `Mail::html()`.
 *
 * `Mail::fake()` ne double PAS `Mail::html()` : `MailFake` n'a pas cette
 * methode et son `__call` retransmet au vrai mailer. Sous `Mail::fake()`,
 * `assertSentCount()` reste a 0 et `assertNothingOutgoing()` est vert quoi
 * qu'il arrive — un faux oracle (constate en T1421).
 *
 * Ce trait intercepte `Mail::html()` et conserve le `Message` construit par
 * le callback : destinataires, objet, reply-to et corps sont MESURES, et
 * rien n'atteint un transport.
 */
trait CapturesMailHtml
{
    /** @var array<int, array{html: string, message: Message}> */
    protected array $capturedMailHtml = [];

    protected function captureMailHtml(): void
    {
        $this->capturedMailHtml = [];

        Mail::shouldReceive('html')->andReturnUsing(function (string $html, callable $callback) {
            $message = new Message(new Email);
            $callback($message);
            $this->capturedMailHtml[] = ['html' => $html, 'message' => $message];

            return null;
        });
    }

    protected function capturedMailCount(): int
    {
        return count($this->capturedMailHtml);
    }

    /** @return array<int, string> adresses des destinataires du n-ieme envoi capture */
    protected function capturedMailTo(int $index = 0): array
    {
        return array_map(
            fn ($address) => $address->getAddress(),
            $this->capturedMailHtml[$index]['message']->getSymfonyMessage()->getTo()
        );
    }

    protected function capturedMailSubject(int $index = 0): ?string
    {
        return $this->capturedMailHtml[$index]['message']->getSymfonyMessage()->getSubject();
    }
}
