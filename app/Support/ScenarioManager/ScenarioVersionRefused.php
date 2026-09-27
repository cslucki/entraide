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
 * Le message n'est pas fige dans l'exception : la propriete `$reason` nomme
 * le cas, et l'appelant traduit via {@see translationKey()}. Sans cela un SuperAdmin en locale `en` recevrait du
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

    /** Approuver ou charger exige l'etat VALID (CDC 13.1). */
    public const NOT_VALID = 'not_valid';

    /** Approuver exige un digest, donc une validation technique prealable. */
    public const NOT_VALIDATED = 'not_validated';

    /**
     * L'approbation manque, ou ne porte plus sur le document courant.
     *
     * Les deux cas sont le MEME fait pour qui regarde l'ecran : ce document-ci
     * n'a pas ete approuve. Les distinguer inviterait a croire qu'une
     * approbation perimee vaut encore quelque chose.
     */
    public const NOT_APPROVED = 'not_approved';

    /** Reset et Remove exigent un chargement vivant. */
    public const NOT_LOADED = 'not_loaded';

    /** Le chargement designe n'appartient pas a cette version, ou a disparu. */
    public const LOAD_MISMATCH = 'load_mismatch';

    /**
     * La cible n'est pas une sandbox PROUVEE.
     *
     * C'est la garde du moteur, traduite : `ScenarioPackOrganizationGuard`
     * refuse de toucher une Organization qui n'est ni dans l'allowlist
     * commitee, ni nee comme sandbox. Une Organization cliente ne peut donc
     * pas etre videe — et ce refus doit arriver comme une phrase.
     */
    public const NOT_A_SANDBOX = 'not_a_sandbox';

    /**
     * Le moteur a refuse de charger a la REVALIDATION (CDC 13.1).
     *
     * Le Validator lit la banque d'avatars sur DISQUE : un index absent rend
     * invalide un document dont le digest n'a pourtant pas bouge. S'y ajoutent
     * le digest qui ne correspond plus et la course perdue sur un chargement
     * concurrent. Trois refus metier, aucune panne.
     */
    public const REVALIDATION_FAILED = 'revalidation_failed';

    /** Une AUTRE version administrative declare le meme chargement. */
    public const LOAD_SHARED = 'load_shared';

    /**
     * Le monde que cette version veut charger appartient DEJA a une autre.
     *
     * Verdict MASTER du 27/09 : « une sandbox vivante doit avoir une seule
     * version administrative proprietaire. Sinon Remove, Reset, Capture,
     * affichage LOADED et provenance deviennent ambigus. »
     *
     * A ne pas confondre avec {@see LOAD_SHARED}, qui constate le partage au
     * moment de RETIRER. Celui-ci l'empeche de naitre, au moment de CHARGER.
     * Le premier est un pansement, le second la garde.
     */
    public const LOAD_ALREADY_OWNED = 'load_already_owned';

    /** La version a disparu pendant que son monde se chargeait. */
    public const VERSION_GONE = 'version_gone';

    /**
     * Le monde tient a des donnees que la base PROTEGE.
     *
     * Arbitrage MASTER du 27/09 (MASTER_DECISION_RESTRICT = A) : prevoir ces
     * blocages demanderait de suivre les cascades depuis le registre — un
     * moteur de graphe, hors scope. MESURE a l'appui : un inventaire naif des
     * contraintes RESTRICT refuse sur une sandbox SAINE, parce que le pack
     * cree des votes de sondage qu'il n'inscrit pas et qui partent en cascade
     * avant leur auteur.
     *
     * La contrainte de la base reste donc la CEINTURE. Ce qui est exige, et
     * qui est tenu : la transaction est entierement annulee, aucun
     * contournement n'est tente, aucune suppression ne suit l'echec, et
     * l'etat d'avant est bit pour bit celui d'apres.
     *
     * Le message rendu est GENERIQUE. Le detail technique de la contrainte
     * part dans les journaux : il nomme des tables et des colonnes, ce qui
     * n'aide pas l'operateur et renseigne qui n'a pas a l'etre.
     *
     * Dette ouverte apres T1650 : « Scenario sandbox destructive preflight —
     * RESTRICT/cascade analysis ».
     */
    public const PROTECTED_DATA = 'protected_data';

    /**
     * Le moteur a refuse, et il a raison.
     *
     * Chargement absent pour cette Organization, propriete d'une entite
     * inconnue : le moteur s'arrete AVANT de detruire quoi que ce soit. Ce
     * fail-closed doit se lire comme une decision, pas comme une panne.
     */
    public const ENGINE_REFUSED = 'engine_refused';

    /**
     * La sandbox contient du contenu que ce chargement n'a pas mis.
     *
     * Un vrai compte deplace la depuis `/admin/users`, du contenu redige par
     * une personne : ni Reset ni Remove ne doivent l'emporter. Les deux
     * refusent AVANT toute mutation.
     */
    public const FOREIGN_CONTENT = 'foreign_content';

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

    public static function notValid(): self
    {
        return new self(self::NOT_VALID, 'Cette version n est pas a l etat VALID.');
    }

    public static function notValidated(): self
    {
        return new self(self::NOT_VALIDATED, 'Cette version n a pas encore ete validee techniquement.');
    }

    public static function notApproved(): self
    {
        return new self(self::NOT_APPROVED, 'Ce document n a pas ete approuve.');
    }

    public static function notLoaded(): self
    {
        return new self(self::NOT_LOADED, 'Cette version n est reliee a aucun chargement vivant.');
    }

    public static function loadMismatch(): self
    {
        return new self(self::LOAD_MISMATCH, 'Le chargement designe n appartient pas a cette version.');
    }

    public static function notASandbox(): self
    {
        return new self(self::NOT_A_SANDBOX, 'La cible n est pas une sandbox de scenario prouvee.');
    }

    public static function loadSharedWith(string $autreNom, string $autreVersion): self
    {
        return new self(
            self::LOAD_SHARED,
            sprintf('Le chargement est aussi declare par %s %s.', $autreNom, $autreVersion),
            ['autre' => $autreNom, 'version' => $autreVersion]
        );
    }

    public static function loadAlreadyOwnedBy(string $autreNom, string $autreVersion): self
    {
        return new self(
            self::LOAD_ALREADY_OWNED,
            sprintf('Ce monde est deja charge par %s %s.', $autreNom, $autreVersion),
            ['scenario' => $autreNom, 'version' => $autreVersion]
        );
    }

    public static function protectedData(): self
    {
        return new self(
            self::PROTECTED_DATA,
            'Des donnees protegees empechent la suppression de cette sandbox.'
        );
    }

    public static function versionDisparue(): self
    {
        return new self(self::VERSION_GONE, 'La version a disparu pendant le chargement.');
    }

    /**
     * @param  array<string, int>  $inventaire
     */
    public static function foreignContent(array $inventaire): self
    {
        $resume = implode(', ', array_map(
            static fn (string $table, int $nombre): string => $nombre.' '.$table,
            array_keys($inventaire),
            $inventaire
        ));

        return new self(
            self::FOREIGN_CONTENT,
            'Contenu etranger dans la sandbox : '.$resume,
            ['inventaire' => $resume]
        );
    }

    /**
     * Le `:detail` des deux refus ci-dessous est PORTE, pas seulement affiche
     * dans le message d'exception.
     *
     * Trouve en revue : les deux cles de langue attendaient `:detail` et
     * personne ne le leur donnait, donc le SuperAdmin lisait « Detail :
     * :detail ». Un refus dont la cause reste dans les logs oblige a ouvrir
     * les logs — c'est-a-dire a ne pas la lire.
     */
    public static function engineRefused(string $detail): self
    {
        return new self(self::ENGINE_REFUSED, 'Le moteur a refuse : '.$detail, ['detail' => $detail]);
    }

    public static function revalidationFailed(string $detail): self
    {
        return new self(self::REVALIDATION_FAILED, 'Revalidation serveur refusee : '.$detail, ['detail' => $detail]);
    }

    /**
     * La cle de langue qui dit ce refus a un humain, dans SA langue.
     */
    public function translationKey(): string
    {
        return 'admin.scenario_manager.refus_'.$this->reason;
    }
}
