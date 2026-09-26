<?php

namespace App\Support\ScenarioManager;

/**
 * TASK-1649 — un refus METIER, pas une panne.
 *
 * Le Scenario Manager refuse des gestes parfaitement legitimes a demander :
 * modifier une version chargee, dupliquer un document qu'on ne sait pas
 * relire, importer un fichier qui n'est pas du texte. Ces refus doivent
 * arriver a l'ecran comme une PHRASE, pas comme une erreur 500 ni comme un
 * silence.
 *
 * ## La raison porte la traduction
 *
 * Le message n'est pas fige dans l'exception : {@see reason()} nomme le cas, et
 * l'appelant traduit. Sans cela un SuperAdmin en locale `en` recevrait du
 * francais sur un ecran anglais — ce qui etait le cas avant que ce commentaire
 * ne dise vrai.
 *
 * Le message par defaut existe quand meme, pour les journaux et pour un
 * appelant qui n'a pas d'interface : une exception muette ne rend service a
 * personne.
 */
final class ScenarioVersionRefused extends \RuntimeException
{
    /** Le document est charge : il decrit une sandbox vivante (CDC 8.6, 14.3). */
    public const LOADED_VERSION = 'loaded_version';

    /** Au-dela de `ScenarioManifestVersion::MAX_JSON_BYTES`. */
    public const DOCUMENT_TOO_LARGE = 'document_too_large';

    /** On ne peut pas reecrire l'identite d'un document qu'on ne sait pas lire. */
    public const UNPARSABLE_SOURCE = 'unparsable_source';

    /** `(scenario_key, version)` est unique en base. */
    public const KEY_ALREADY_USED = 'key_already_used';

    /**
     * Le texte recu n'est pas de l'UTF-8 exploitable.
     *
     * PostgreSQL refuse net un octet NUL ou une sequence UTF-8 invalide dans
     * une colonne `text` : sans cette garde, importer une image produirait une
     * 500 en production et un enregistrement silencieux en SQLite.
     */
    public const BINARY_CONTENT = 'binary_content';

    /**
     * @param  array<string, string|int>  $parametres
     */
    private function __construct(
        public readonly string $reason,
        string $message,
        public readonly array $parametres = []
    ) {
        parent::__construct($message);
    }

    public static function loadedVersion(): self
    {
        return new self(self::LOADED_VERSION, 'Version chargee : son document ne se modifie pas.');
    }

    public static function documentTooLarge(int $octets, int $maximum): self
    {
        return new self(
            self::DOCUMENT_TOO_LARGE,
            sprintf('Document de %d octets, au-dela des %d admis.', $octets, $maximum),
            ['octets' => $octets, 'maximum' => $maximum]
        );
    }

    public static function unparsableSource(): self
    {
        return new self(self::UNPARSABLE_SOURCE, 'Document illisible : son identite ne peut pas etre reecrite.');
    }

    public static function keyAlreadyUsed(string $scenarioKey, string $version): self
    {
        return new self(
            self::KEY_ALREADY_USED,
            sprintf('La version %s du scenario %s existe deja.', $version, $scenarioKey),
            ['scenario' => $scenarioKey, 'version' => $version]
        );
    }

    public static function binaryContent(): self
    {
        return new self(self::BINARY_CONTENT, 'Le contenu recu n est pas du texte UTF-8 exploitable.');
    }

    /**
     * La cle de langue qui dit ce refus a un humain, dans SA langue.
     */
    public function translationKey(): string
    {
        return 'admin.scenario_manager.refus_'.$this->reason;
    }
}
