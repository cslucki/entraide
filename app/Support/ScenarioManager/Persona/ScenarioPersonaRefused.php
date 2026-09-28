<?php

namespace App\Support\ScenarioManager\Persona;

/**
 * TASK-1654 — les refus de Persona Access, nommes.
 *
 * Un refus porte une RAISON stable, pas seulement une phrase. La phrase change
 * avec la langue et la relecture ; la raison est ce sur quoi un test peut
 * s'appuyer sans se casser au premier reformulage.
 *
 * Toutes ces raisons sont des refus de SECURITE. Aucune n'est un incident
 * technique : chacune dit qu'une precondition d'eligibilite n'est pas prouvee,
 * et le defaut est donc toujours de refuser.
 */
final class ScenarioPersonaRefused extends \RuntimeException
{
    /** La version n'est pas chargee : il n'existe aucun monde ou entrer. */
    public const VERSION_NOT_LOADED = 'version_not_loaded';

    /** Le chargement a disparu : plus rien ne relie cette version a un monde. */
    public const LOAD_GONE = 'load_gone';

    /** La sandbox a disparu. */
    public const SANDBOX_GONE = 'sandbox_gone';

    /** La sandbox est en corbeille : elle n'est plus VIVANTE. */
    public const SANDBOX_NOT_LIVING = 'sandbox_not_living';

    /**
     * L'Organization du chargement n'est pas une sandbox de scenario.
     *
     * Le cas ne devrait pas se produire par les portes du produit. Il est
     * verifie quand meme : c'est LA preuve qui distingue Persona Access d'une
     * impersonation ordinaire, et une preuve qu'on suppose n'est pas une preuve.
     */
    public const NOT_A_SANDBOX = 'not_a_sandbox';

    /** Le compte vise n'appartient pas a cette sandbox. */
    public const NOT_IN_SANDBOX = 'not_in_sandbox';

    /** Le compte vise n'a pas d'adresse fictive `.test`. */
    public const NOT_FICTIONAL = 'not_fictional';

    /** Le compte vise est administrateur de PLATEFORME. */
    public const PLATFORM_ADMIN = 'platform_admin';

    /** Le compte vise est banni : le produit le renverrait aussitot au login. */
    public const BANNED = 'banned';

    /** L'acteur de depart n'est pas administrateur de plateforme. */
    public const ACTOR_NOT_PLATFORM_ADMIN = 'actor_not_platform_admin';

    /** Une identite d'emprunt est deja active : l'imbrication est interdite. */
    public const ALREADY_IMPERSONATING = 'already_impersonating';

    /** Aucun mode persona valide : rien a quitter. */
    public const NO_ACTIVE_PERSONA_MODE = 'no_active_persona_mode';

    private function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function versionNotLoaded(): self
    {
        return new self(self::VERSION_NOT_LOADED, "Cette version n'est pas chargee : il n'existe aucun monde ou entrer.");
    }

    public static function loadGone(): self
    {
        return new self(self::LOAD_GONE, 'Le chargement de cette version a disparu.');
    }

    public static function sandboxGone(): self
    {
        return new self(self::SANDBOX_GONE, 'La sandbox de cette version a disparu.');
    }

    public static function sandboxNotLiving(): self
    {
        return new self(self::SANDBOX_NOT_LIVING, "La sandbox est en corbeille : elle n'est plus vivante.");
    }

    public static function notASandbox(): self
    {
        return new self(self::NOT_A_SANDBOX, "L'organisation de ce chargement n'est pas une sandbox de scenario.");
    }

    public static function notInSandbox(): self
    {
        return new self(self::NOT_IN_SANDBOX, "Ce compte n'appartient pas a la sandbox de cette version.");
    }

    public static function notFictional(): self
    {
        return new self(self::NOT_FICTIONAL, "Ce compte n'a pas d'adresse fictive : on n'entre pas sous une identite reelle.");
    }

    public static function platformAdmin(): self
    {
        return new self(self::PLATFORM_ADMIN, "Ce compte est administrateur de plateforme : on n'entre pas sous ses droits.");
    }

    public static function banned(): self
    {
        return new self(self::BANNED, 'Ce compte est banni.');
    }

    public static function actorNotPlatformAdmin(): self
    {
        return new self(self::ACTOR_NOT_PLATFORM_ADMIN, "Seul un administrateur de plateforme entre dans une sandbox.");
    }

    public static function alreadyImpersonating(): self
    {
        return new self(self::ALREADY_IMPERSONATING, "Une identite d'emprunt est deja active : quittez-la d'abord.");
    }

    public static function noActivePersonaMode(): self
    {
        return new self(self::NO_ACTIVE_PERSONA_MODE, 'Aucun mode persona actif.');
    }
}
