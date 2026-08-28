<?php

namespace App\Services\People\DTO;

/**
 * TASK-1323 (People-1) — une personne que l'application a le droit de
 * considerer pour une demande et une Loop.
 *
 * EXPOSITION VOLONTAIREMENT MINIMALE. Ce DTO ne porte que ce qui est deja
 * public a l'echelle de l'Organization (nom d'affichage public, avatar
 * public) plus une REFERENCE au profil IA publie — jamais son contenu.
 * Le contenu du profil (skills, help_types, ...) est le materiau de la
 * PERTINENCE (People-2), qui le consommera par ses propres chemins
 * autorises. Aucun email, telephone, bio ni champ prive ici, par
 * construction : ajouter un champ a ce DTO est une decision d'exposition,
 * pas un detail.
 *
 * `verifiedFacts` : faits reconstruits cote serveur par la primitive
 * elle-meme (discipline de provenance TASK-1321) — le seul materiau que
 * People-2/People-3 auront le droit de presenter comme des faits.
 */
final class EligiblePerson
{
    /**
     * @param  list<array<string, mixed>>  $verifiedFacts
     */
    public function __construct(
        public readonly string $userId,
        public readonly string $displayName,
        public readonly ?string $avatarUrl,
        public readonly string $memberAiProfileId,
        public readonly array $verifiedFacts,
    ) {}

    /**
     * @return array{user_id: string, display_name: string, avatar_url: ?string, member_ai_profile_id: string, verified_facts: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'display_name' => $this->displayName,
            'avatar_url' => $this->avatarUrl,
            'member_ai_profile_id' => $this->memberAiProfileId,
            'verified_facts' => $this->verifiedFacts,
        ];
    }
}
