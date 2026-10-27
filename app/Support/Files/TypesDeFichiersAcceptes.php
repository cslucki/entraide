<?php

namespace App\Support\Files;

/**
 * TASK-1704 — LES TYPES DE FICHIERS QUE LE PRODUIT ACCEPTE, une seule fois.
 *
 * ## Pourquoi cette classe existe
 *
 * La liste vivait en clair dans `StoreDossierFileRequest`, ou elle porte une
 * histoire que personne ne doit avoir a redecouvrir : `mimetypes:` compare le
 * type devine par finfo SUR LE CONTENU, et un meme fichier sort sous plusieurs
 * noms selon la version de libmagic. Un `.xls` ancien se presente en
 * `application/vnd.ms-excel`, en `application/x-ole-storage` ou en
 * `application/CDFV2` ; un `.wav` en `audio/wav` ou `audio/x-wav` ; un `.m4a`
 * en `audio/mp4` ou `audio/x-m4a`. **Omettre un alias fait echouer ce seul
 * fichier, au milieu d'un envoi, sans que rien n'explique pourquoi.**
 *
 * La messagerie accepte desormais des fichiers (TASK-1704). En recopier la
 * liste aurait donne deux regles pour une idee — et c'est la copie qu'on
 * oublie de completer le jour ou un alias manque. Meme raisonnement que
 * `DossierFileTransport`, extrait en TASK-1695 pour exactement ce motif.
 *
 * ## Ce que cette classe ne decide PAS
 *
 * Ni la taille maximale, ni le nombre de fichiers, ni le droit d'envoyer : ce
 * sont des decisions de surface, et elles different legitimement d'un ecran a
 * l'autre. Elle ne dit que ceci : « de quoi ce produit sait-il faire quelque
 * chose ? »
 */
final class TypesDeFichiersAcceptes
{
    /** @var list<string> */
    public const IMAGES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    /** @var list<string> */
    public const DOCUMENTS = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'text/plain',
        'text/markdown',
        'text/csv',
        'application/vnd.ms-excel',
        'application/x-ole-storage',
        'application/CDFV2',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',
        'application/x-zip-compressed',
    ];

    /** @var list<string> */
    public const AUDIO = [
        'audio/mpeg',
        'audio/mp3',
        'audio/ogg',
        'audio/vorbis',
        'audio/wav',
        'audio/x-wav',
        'audio/vnd.wave',
        'audio/webm',
        'audio/mp4',
        'audio/x-m4a',
        'audio/aac',
        'audio/flac',
        'audio/x-flac',
    ];

    /** @var list<string> */
    public const VIDEO = [
        'video/mp4',
        'video/quicktime',
        'video/webm',
        'video/ogg',
        'video/x-msvideo',
        'video/x-matroska',
        'video/mpeg',
        'video/3gpp',
    ];

    /**
     * Tous les types, dans l'ordre historique de la liste d'origine.
     *
     * L'ordre n'a aucune importance pour `mimetypes:`, mais le conserver rend
     * le diff de l'extraction lisible : on voit qu'aucun type n'a ete perdu.
     *
     * @return list<string>
     */
    public static function tous(): array
    {
        return array_merge(self::IMAGES, self::DOCUMENTS, self::AUDIO, self::VIDEO);
    }

    /** La regle de validation Laravel, prete a l'emploi. */
    public static function regleMimetypes(): string
    {
        return 'mimetypes:'.implode(',', self::tous());
    }

    /**
     * Ce que l'attribut `accept` d'un champ de fichier doit proposer.
     *
     * Le navigateur filtre sur ce qu'il croit, le serveur verifie sur le
     * CONTENU : les deux listes servent deux choses differentes et ne sont pas
     * interchangeables. Celle-ci est volontairement la meme, pour qu'aucun
     * fichier propose par le selecteur ne soit refuse a l'arrivee.
     */
    public static function pourAttributAccept(): string
    {
        return implode(',', self::tous());
    }
}
