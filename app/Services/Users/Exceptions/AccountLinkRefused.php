<?php

namespace App\Services\Users\Exceptions;

use RuntimeException;

/**
 * TASK-1731 (A5b) — les refus de la liaison entre comptes.
 *
 * Chaque fabrique porte un motif PRECIS, destine au journal et aux tests. Les
 * ecrans, eux, ne rendent pas forcement ce motif : la creation d'un lien ne
 * doit pas devenir un moyen d'eprouver le mot de passe d'un compte qu'on ne
 * controle pas.
 */
class AccountLinkRefused extends RuntimeException
{
    public readonly string $motif;

    public static function autoLiaison(): self
    {
        return self::avec('auto_liaison', 'Un compte ne peut pas etre lie a lui-meme.');
    }

    public static function preuveInsuffisante(): self
    {
        return self::avec('preuve_insuffisante', 'Le controle des deux comptes n\'est pas prouve.');
    }

    public static function dejaLie(): self
    {
        return self::avec('deja_lie', 'Ces deux comptes sont deja lies.');
    }

    public static function etranger(): self
    {
        return self::avec('etranger', 'Ce lien ne concerne pas ce compte.');
    }

    public static function emailsDifferents(): self
    {
        return self::avec('emails_differents', 'La preuve par la boite exige que les deux comptes partagent la meme adresse.');
    }

    public static function emailNonVerifie(): self
    {
        return self::avec('email_non_verifie', 'La preuve par la boite exige que les deux adresses soient verifiees.');
    }

    private static function avec(string $motif, string $message): self
    {
        $exception = new self($message);
        $exception->motif = $motif;

        return $exception;
    }
}
