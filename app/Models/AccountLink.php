<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-1731 (A5b) — un lien explicite entre DEUX comptes distincts.
 *
 * Ce n'est ni une fusion d'identites, ni un partage de donnees, ni un
 * privilege. Le lien autorise UNE SEULE chose : basculer vers l'autre compte
 * sans redonner son mot de passe a chaque fois, et seulement apres une
 * confirmation recente du mot de passe du compte SOURCE.
 *
 * La paire est ORDONNEE — `user_a_id` porte toujours le plus petit
 * identifiant — ce qui rend la relation bidirectionnelle par construction :
 * il n'existe jamais deux lignes pour un meme couple.
 */
class AccountLink extends Model
{
    use HasUuids;

    protected $fillable = ['user_a_id', 'user_b_id', 'created_by_user_id'];

    public function userA(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_a_id');
    }

    public function userB(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_b_id');
    }

    /**
     * L'AUTRE compte du lien, vu depuis celui-ci.
     *
     * Pose ici et nulle part ailleurs : deriver « l'autre » a la main dans
     * chaque ecran aurait donne autant de versions que d'appelants, et c'est
     * la copie qu'on oublie de corriger.
     */
    public function autreQue(User $compte): ?User
    {
        if ((string) $this->user_a_id === (string) $compte->getKey()) {
            return $this->userB;
        }

        if ((string) $this->user_b_id === (string) $compte->getKey()) {
            return $this->userA;
        }

        // Ce compte n'appartient pas au lien. On ne devine pas : rendre l'un
        // des deux au hasard ferait fuir une identite a un tiers.
        return null;
    }

    public function concerne(User $compte): bool
    {
        return in_array((string) $compte->getKey(), [
            (string) $this->user_a_id,
            (string) $this->user_b_id,
        ], true);
    }
}
