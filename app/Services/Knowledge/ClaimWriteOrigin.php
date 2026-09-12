<?php

namespace App\Services\Knowledge;

use App\Models\LoopMessage;
use App\Models\User;

/**
 * TASK-1548 — QUI ecrit dans la memoire.
 *
 * Jusqu'ici la question ne se posait pas : `ClaimMemory::appliquer()` n'avait
 * qu'un seul appelant, le compiler, et `preuves()` ecrivait son nom en dur.
 * Une correction humaine ne pouvait donc pas se DIRE : elle serait entree en
 * base sous la signature du deriver, indistinguable d'une phrase de modele.
 *
 * Cette distinction n'est pas documentaire. Elle porte deux regles executables :
 *
 *  - la provenance d'un enonce corrige a la main nomme la personne et l'instant
 *    (ce que W1.5-C devra afficher, et ce qu'aucun horodatage seul ne dit) ;
 *  - la garde anti-resurrection ne s'applique QU'AUX ecritures du compiler.
 *    Un humain doit pouvoir re-affirmer ce qu'un humain a corrige ; c'est la
 *    machine, relisant un corpus inchange, qu'on empeche de defaire une
 *    correction.
 *
 * Une origine humaine porte OBLIGATOIREMENT son message : sans lui la
 * correction n'aurait pas de preuve, et `ClaimPatch::valider()` la refuserait —
 * ce qui est exactement le comportement voulu, jamais un assouplissement.
 */
final class ClaimWriteOrigin
{
    /** La derivation automatique : un modele a propose, le serveur a valide. */
    public const COMPILER = 'compiler';

    /** Une personne a explicitement corrige un enonce. */
    public const HUMAN_CORRECTION = 'human_correction';

    private function __construct(
        public readonly string $kind,
        public readonly ?User $user,
        public readonly ?LoopMessage $message,
        public readonly string $derivedBy,
    ) {}

    public static function compiler(): self
    {
        return new self(self::COMPILER, null, null, LoopConversationKnowledgeDeriver::FEATURE);
    }

    /**
     * @param  LoopMessage  $message  le message HUMAIN qui porte la correction —
     *                                sa preuve, et le point de reference a partir
     *                                duquel « posterieur » se mesure.
     */
    public static function humanCorrection(User $user, LoopMessage $message): self
    {
        return new self(self::HUMAN_CORRECTION, $user, $message, self::HUMAN_CORRECTION);
    }

    public function isHuman(): bool
    {
        return $this->kind === self::HUMAN_CORRECTION;
    }

    /**
     * Les champs d'origine ajoutes a la provenance de chaque enonce ecrit.
     *
     * @return array<string, mixed>
     */
    public function provenanceFields(): array
    {
        if (! $this->isHuman()) {
            return ['derived_by' => $this->derivedBy];
        }

        return [
            'derived_by' => $this->derivedBy,
            'corrected_by_user_id' => (string) $this->user?->id,
            'corrected_at' => now()->toIso8601String(),
            'correction_message_id' => (string) $this->message?->id,
        ];
    }
}
