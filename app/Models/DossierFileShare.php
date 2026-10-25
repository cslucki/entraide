<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-1695 — une autorisation de LECTURE posee sur un fichier.
 *
 * ## Ce que cette ligne dit, et rien d'autre
 *
 * « Cette personne peut lire ce fichier. » Pas « elle peut le modifier », pas
 * « elle peut le repartager », pas « elle voit le Dossier qui le contient ».
 * Arbitrage de Cyril (chemin « C ») : la surface d'autorisation la plus petite
 * qui reponde a la demande.
 *
 * Il n'y a donc PAS de colonne `role`, et c'est delibere : un role inventerait
 * une question que personne n'a posee, et chaque lecteur du code devrait
 * ensuite deviner ce qu'il vaut.
 *
 * ## `HasOrganizationId` n'est PAS utilise ici
 *
 * Ce trait remplit `organization_id` depuis le CONTEXTE courant quand il est
 * nul. Pour une autorisation, c'est exactement ce qu'il ne faut pas : le tenant
 * doit venir du FICHIER partage, pas de la page ou l'on se trouve. Un contexte
 * mal resolu poserait sinon une autorisation dans la mauvaise Organization —
 * et une frontiere qui se deduit du client n'est pas une frontiere (TASK-1691).
 *
 * Le service qui cree ces lignes le lit donc sur `DossierFile`, explicitement.
 */
class DossierFileShare extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'dossier_file_id',
        'user_id',
        'shared_by',
    ];

    public function file(): BelongsTo
    {
        return $this->belongsTo(DossierFile::class, 'dossier_file_id');
    }

    /** La personne qui RECOIT l'acces. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Celle qui l'a donne. Nullable : la trace survit a son auteur. */
    public function sharedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_by');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
