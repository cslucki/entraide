<?php

namespace App\Services\People\DTO;

/**
 * TASK-1546 (People-Self) — « Et moi ? », mesure sur la personne qui demande.
 *
 * Meme discipline que {@see EligiblePeopleResult} et
 * {@see RelevantPeopleResult}, avec un etat de plus — et c'est lui qui porte
 * tout le sujet.
 *
 * ## Trois etats, jamais deux
 *
 * - REFUS de contexte (`authorized: false`) : le triplet (Organization,
 *   Loop, demandeur) n'autorise meme pas a calculer. Propage tel quel depuis
 *   People-1, jamais reinterprete.
 * - NON MESURABLE (`authorized: true`, `assessable: false`) : le serveur n'a
 *   AUCUN signal autorise sur cette personne — son profil IA n'est pas
 *   publie dans cette Organization. Ce n'est pas « vous ne correspondez
 *   pas » : c'est « je ne peux pas le dire ».
 * - MESURE (`assessable: true`) : `reasons` peut etre vide. Un zero franc est
 *   un resultat propre — rien de ce que cette personne declare n'apparie ce
 *   projet.
 *
 * Confondre les deux derniers serait fabriquer une certitude que personne
 * n'a : « rien ne correspond » et « je n'ai rien a lire » se ressemblent a
 * l'ecran et n'ont pas le meme sens. C'est l'invariant 10 du MASTER — *no
 * silent certainty under ambiguity* — applique a soi-meme.
 *
 * ## Pourquoi le profil doit etre PUBLIE, meme pour soi
 *
 * La publication est le consentement de VISIBILITE (doctrine People-1). Lire
 * un brouillon pour repondre a son proprietaire ne serait pas une fuite,
 * mais ce serait une SECONDE regle de visibilite — et la personne
 * s'entendrait dire qu'elle est un bon candidat tout en restant invisible a
 * « Qui pourrait les aider ? ». La meme regle des deux cotes garde la
 * symetrie WRITE/READ, et la limite se DIT.
 */
final class SelfFitResult
{
    public const NOT_ASSESSABLE_PROFILE_NOT_PUBLISHED = 'profile_not_published';

    /**
     * @param  list<array{type: string, label: string, source: array<string, string>, matched_terms: list<string>, verified: true}>  $reasons
     */
    private function __construct(
        public readonly bool $authorized,
        public readonly ?string $refusalReason,
        public readonly bool $assessable,
        public readonly ?string $notAssessableReason,
        public readonly ?EligiblePerson $person,
        public readonly array $reasons,
    ) {}

    public static function refused(string $reason): self
    {
        return new self(false, $reason, false, null, null, []);
    }

    public static function notAssessable(string $reason): self
    {
        return new self(true, null, false, $reason, null, []);
    }

    /**
     * @param  list<array{type: string, label: string, source: array<string, string>, matched_terms: list<string>, verified: true}>  $reasons
     */
    public static function assessed(EligiblePerson $person, array $reasons): self
    {
        return new self(true, null, true, null, $person, $reasons);
    }

    /** Au moins un fait serveur apparie la demande. */
    public function fits(): bool
    {
        return $this->assessable && $this->reasons !== [];
    }

    /**
     * @return array{authorized: bool, refusal_reason: ?string, assessable: bool, not_assessable_reason: ?string, person: ?array<string, mixed>, reasons: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'authorized' => $this->authorized,
            'refusal_reason' => $this->refusalReason,
            'assessable' => $this->assessable,
            'not_assessable_reason' => $this->notAssessableReason,
            'person' => $this->person?->toArray(),
            'reasons' => $this->reasons,
        ];
    }
}
