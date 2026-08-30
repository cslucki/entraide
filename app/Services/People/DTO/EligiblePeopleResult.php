<?php

namespace App\Services\People\DTO;

/**
 * TASK-1323 (People-1) — resultat de la primitive d'eligibilite.
 *
 * Deux etats, jamais confondus :
 * - REFUS de contexte (`authorized: false`, `refusalReason` renseigne) :
 *   le triplet (Organization, Loop, demandeur) n'autorise meme pas a
 *   CALCULER un ensemble — cross-tenant, demandeur sans droit sur la
 *   Boucle, Boucle non active, profils IA desactives. Le refus est
 *   explicite, jamais un ensemble vide silencieux : un appelant ne doit
 *   pas pouvoir confondre « interdit » et « personne d'eligible ».
 * - ENSEMBLE (`authorized: true`) : zero, une ou N personnes. Zero est un
 *   resultat parfaitement valide.
 */
final class EligiblePeopleResult
{
    public const REFUSAL_CROSS_ORGANIZATION = 'cross_organization';

    public const REFUSAL_REQUESTER_NOT_AUTHORIZED = 'requester_not_authorized';

    public const REFUSAL_LOOP_NOT_ACTIVE = 'loop_not_active';

    public const REFUSAL_AI_PROFILES_DISABLED = 'ai_profiles_disabled';

    /**
     * @param  list<EligiblePerson>  $people
     */
    private function __construct(
        public readonly bool $authorized,
        public readonly ?string $refusalReason,
        public readonly array $people,
    ) {}

    public static function refused(string $reason): self
    {
        return new self(false, $reason, []);
    }

    /**
     * @param  list<EligiblePerson>  $people
     */
    public static function authorized(array $people): self
    {
        return new self(true, null, $people);
    }

    /**
     * @return array{authorized: bool, refusal_reason: ?string, people: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'authorized' => $this->authorized,
            'refusal_reason' => $this->refusalReason,
            'people' => array_map(
                static fn (EligiblePerson $person): array => $person->toArray(),
                $this->people,
            ),
        ];
    }
}
