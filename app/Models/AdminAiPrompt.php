<?php

namespace App\Models;

use Database\Factories\AdminAiPromptFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class AdminAiPrompt extends Model
{
    /** @use HasFactory<AdminAiPromptFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'scenario_id',
        'name',
        'description',
        'prompt_text',
        'version',
        'is_active',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByScenario($query, string $scenarioId)
    {
        return $query->where('scenario_id', $scenarioId);
    }

    /**
     * TASK-1371 — ACTIVER CETTE VERSION, ET ELLE SEULE, POUR SON SCENARIO.
     *
     * ## Le defaut que cette methode ferme
     *
     * `AdminAiPromptController::update()` traitait `is_active` comme un booleen
     * ordinaire : cocher la case activait une version SANS toucher aux soeurs.
     * Et `store()` ne validait meme pas ce champ — or la colonne vaut
     * `->default(true)` en base, donc **toute version creee par l'interface
     * naissait active**, silencieusement.
     *
     * Resultat mesure le 2026-09-02 : `clarify_help_request` portait v3 ET v1
     * actives. Le service reste correct — il retient la version active la plus
     * haute — mais l'ecran d'administration affichait deux versions « actives »
     * pour un meme scenario, sans dire laquelle s'appliquait. La migration v3
     * l'avait ecrit d'elle-meme : « laisser deux lignes actives rendrait l'ecran
     * d'administration mensonger ». Elle s'en gardait ; le chemin humain, non.
     *
     * ## Pourquoi une transaction, et pas une contrainte en base
     *
     * Deux ecritures — desactiver les soeurs, activer celle-ci — doivent valoir
     * comme une seule : un echec entre les deux laisserait le scenario SANS
     * aucune version active, c'est-a-dire une capability muette.
     *
     * Une contrainte `UNIQUE(scenario_id) WHERE is_active` aurait fait echouer
     * des migrations et des packs de scenario existants au lieu de corriger
     * l'ecran. Le defaut est un defaut de CHEMIN D'ECRITURE : il se repare la ou
     * l'on ecrit.
     *
     * ## Idempotente, sans garde explicite
     *
     * Rejouee sur une version deja seule active, elle ne reecrit rien : Eloquent
     * n'emet pas d'UPDATE pour un attribut non modifie. Une garde
     * `if (! $this->is_active)` avait ete ecrite ici puis RETIREE — aucun
     * sabotage ne pouvait la faire rougir, ce qui prouvait qu'elle ne servait a
     * rien. Un garde intestable est soit non teste, soit superflu ; celui-la
     * etait superflu.
     *
     * C'est ce qui permet d'appeler cette methode sans condition depuis
     * `store()` comme depuis `update()`.
     */
    public function activate(): void
    {
        DB::transaction(function (): void {
            static::query()
                ->where('scenario_id', $this->scenario_id)
                ->whereKeyNot($this->getKey())
                ->where('is_active', true)
                ->update(['is_active' => false]);

            $this->forceFill(['is_active' => true])->save();
        });
    }
}
