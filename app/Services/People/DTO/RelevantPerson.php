<?php

namespace App\Services\People\DTO;

use App\Services\People\RelevantPeopleService;

/**
 * TASK-1324 (People-2) — une personne de l'ensemble eligible retenue comme
 * pertinente pour UNE demande, avec ses raisons.
 *
 * Deux matieres, jamais fusionnees (idiome de provenance TASK-1321) :
 *
 * - `$reasons` : des FAITS serveur. Chaque raison est un signal autorise,
 *   verifie par la requete qui vient de le lire (contenu structure du profil
 *   PUBLIE, Skill d'un Service ACTIF de la meme Organization), avec sa
 *   source (`member_ai_profile_id`, `service_id`/`skill_id`) et les termes
 *   de la demande qui l'ont apparie (`matched_terms`). Toujours
 *   `verified: true` — c'est le serveur qui parle. Le libelle (`label`) est
 *   la valeur declaree elle-meme (« Erasmus+ », « relire_document ») : la
 *   raison se lit sans decodeur, « Competence declaree : Erasmus+ ».
 * - `$aiWording` : du TEXTE de modele, optionnel, toujours
 *   `verified: false`. Il peut reformuler, jamais prouver — et il ne peut
 *   etre pose que sur une personne DEJA retenue par le serveur
 *   ({@see RelevantPeopleService::validatedProviderSelection()}).
 *
 * `$person` re-expose la personne People-1 TELLE QUELLE (exposition minimale
 * + `verified_facts` d'eligibilite). People-2 n'y ajoute aucun champ prive.
 */
final class RelevantPerson
{
    /**
     * @param  list<array{type: string, label: string, source: array<string, string>, matched_terms: list<string>, verified: true}>  $reasons
     * @param  array{text: string, verified: false}|null  $aiWording
     */
    public function __construct(
        public readonly EligiblePerson $person,
        public readonly array $reasons,
        public readonly ?array $aiWording = null,
    ) {}

    public function withAiWording(string $text): self
    {
        return new self($this->person, $this->reasons, ['text' => $text, 'verified' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge($this->person->toArray(), [
            'reasons' => $this->reasons,
            'ai_wording' => $this->aiWording,
        ]);
    }
}
