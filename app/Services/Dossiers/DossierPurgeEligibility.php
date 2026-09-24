<?php

namespace App\Services\Dossiers;

use App\Models\Dossier;

/**
 * TASK-1630 — qui peut etre purge par l'outil SuperAdmin, et pourquoi pas.
 *
 * Une seule verite, lue a DEUX moments : quand l'ecran affiche un badge
 * « Protege » et quand le serveur execute la purge. Si l'ecran seul decidait,
 * un POST forge emporterait la racine d'une Boucle vivante — et
 * l'utilisateur perdrait son Drive sans qu'aucun ecran ne l'ait propose.
 *
 * ## Ce qui est protege, et sur quel critere
 *
 * Ni `User` ni `Loop` ne portent SoftDeletes : une racine reellement
 * orpheline au sens des cles etrangeres n'existe pas, la cascade l'aurait
 * deja emportee. La protection se lit donc sur l'ACTIVITE du porteur :
 *
 *   · racine d'une Boucle `active`        -> protegee ;
 *   · racine d'un compte non banni        -> protegee ;
 *   · racine d'une Boucle archivee        -> purgeable ;
 *   · racine d'un compte banni            -> purgeable ;
 *   · racine deja soft-deletee            -> purgeable (la decision est prise) ;
 *   · sous-dossier legacy (`parent_id`)   -> purgeable.
 *
 * Un sous-dossier reste purgeable meme sous une racine protegee : c'est tout
 * l'objet de l'outil. Ce qui est protege, c'est le NOEUD RACINE lui-meme, pas
 * la branche qu'il gouverne.
 */
class DossierPurgeEligibility
{
    public const TYPE_LOOP_ROOT = 'loop_root';

    public const TYPE_USER_ROOT = 'user_root';

    public const TYPE_LEGACY_CHILD = 'legacy_child';

    public const REASON_ACTIVE_LOOP = 'active_loop';

    public const REASON_ACTIVE_USER = 'active_user';

    /**
     * Le type affiche d'un Dossier — celui que l'operateur doit reconnaitre
     * d'un coup d'oeil.
     */
    public function type(Dossier $dossier): string
    {
        if ($dossier->parent_id !== null) {
            return self::TYPE_LEGACY_CHILD;
        }

        return $dossier->loop_id !== null ? self::TYPE_LOOP_ROOT : self::TYPE_USER_ROOT;
    }

    /**
     * `null` si le Dossier est purgeable ; sinon la raison de la protection.
     *
     * Le Dossier doit arriver avec ses relations `loop` et `owner` chargees
     * lorsqu'elles existent — l'appelant les eager-load, sinon la liste
     * produit une requete par ligne.
     */
    public function protectionReason(Dossier $dossier): ?string
    {
        // Une decision de suppression deja prise ne se re-protege pas.
        if ($dossier->trashed()) {
            return null;
        }

        if ($this->type($dossier) === self::TYPE_LEGACY_CHILD) {
            return null;
        }

        if ($dossier->loop_id !== null) {
            return $dossier->loop?->status === 'active' ? self::REASON_ACTIVE_LOOP : null;
        }

        if ($dossier->owner_id !== null) {
            return $dossier->owner !== null && $dossier->owner->banned_at === null
                ? self::REASON_ACTIVE_USER
                : null;
        }

        // Ni parent, ni Boucle, ni proprietaire : la ligne viole deja
        // `dossiers_holder_xor`. C'est precisement le residu que cet outil
        // existe pour enlever.
        return null;
    }

    public function isPurgeable(Dossier $dossier): bool
    {
        return $this->protectionReason($dossier) === null;
    }
}
