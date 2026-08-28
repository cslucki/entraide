<?php

namespace App\Services\People\DTO;

/**
 * TASK-1324 (People-2) — resultat de la pertinence explicable.
 *
 * Meme discipline que {@see EligiblePeopleResult} (People-1) : un REFUS de
 * contexte (propage tel quel depuis la primitive d'eligibilite, jamais
 * reinterprete) n'est pas un ensemble vide — et zero personne pertinente
 * est un resultat parfaitement propre (`authorized: true`, liste vide).
 *
 * `$rejectedProviderUserIds` : trace des identifiants proposes par un
 * provider et REFUSES par le serveur parce qu'ils ne figurent pas dans
 * l'ensemble qu'il avait lui-meme retenu — l'audit du garde-fou « le modele
 * ne cree jamais de candidats ». Vide tant qu'aucune selection provider n'a
 * ete appliquee.
 *
 * Le contrat n'expose AUCUN score numerique, aucun rang, aucun pourcentage :
 * les raisons (faits apparies) sont l'unique matiere de la pertinence.
 */
final class RelevantPeopleResult
{
    /**
     * @param  list<RelevantPerson>  $people
     * @param  list<string>  $rejectedProviderUserIds
     */
    private function __construct(
        public readonly bool $authorized,
        public readonly ?string $refusalReason,
        public readonly array $people,
        public readonly array $rejectedProviderUserIds,
    ) {}

    public static function refused(string $reason): self
    {
        return new self(false, $reason, [], []);
    }

    /**
     * @param  list<RelevantPerson>  $people
     */
    public static function authorized(array $people): self
    {
        return new self(true, null, $people, []);
    }

    /**
     * @param  list<RelevantPerson>  $people
     * @param  list<string>  $rejectedProviderUserIds
     */
    public function withProviderOutcome(array $people, array $rejectedProviderUserIds): self
    {
        return new self($this->authorized, $this->refusalReason, $people, $rejectedProviderUserIds);
    }

    /**
     * @return array{authorized: bool, refusal_reason: ?string, people: list<array<string, mixed>>, rejected_provider_user_ids: list<string>}
     */
    public function toArray(): array
    {
        return [
            'authorized' => $this->authorized,
            'refusal_reason' => $this->refusalReason,
            'people' => array_map(
                static fn (RelevantPerson $person): array => $person->toArray(),
                $this->people,
            ),
            'rejected_provider_user_ids' => $this->rejectedProviderUserIds,
        ];
    }
}
