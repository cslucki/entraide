<?php

namespace App\Services\People;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\User;
use App\Services\People\DTO\EligiblePeopleResult;
use App\Services\People\DTO\EligiblePerson;
use Illuminate\Support\Facades\Gate;

/**
 * TASK-1323 (People-1) — la primitive UNIQUE de l'ensemble eligible.
 *
 * Retourne les personnes que l'application a le DROIT de considerer pour
 * une demande et une Loop. ELIGIBILITE seulement : la pertinence
 * (People-2) et la mise en relation (People-3) se construisent STRICTEMENT
 * au-dessus de cet ensemble — jamais a cote. Aucun LLM ici, et regle
 * absolue de la verticale : le modele ne cree jamais la liste des
 * personnes autorisees.
 *
 * ## Criteres V1 (spec fille WOW People, arbitrage Cyril 28/08, §4.5)
 *
 * - meme Organization (Organization = Tenant, Loop != Tenant) ;
 * - membre ACTIF de la Loop cible ;
 * - `MemberAiProfile::STATUS_PUBLISHED` — le profil publie vaut
 *   VISIBILITE, jamais action automatique ;
 * - demandeur exclu (pas de flag d'inclusion en V1) ;
 * - donnees exposees limitees a ce qui est deja visible/autorise.
 *
 * ## Consentements et permissions REUTILISES, aucun invente
 *
 * La primitive ne pose aucune nouvelle regle de visibilite : elle compose
 * celles qui gouvernent deja l'affichage d'un profil publie ailleurs
 * (`ProfileController::show()`/`aiAgentChat()`) —
 * `LoopPolicy::viewWorkspace` pour le droit du demandeur (membre actif,
 * meme Organization, aucun bypass admin), `User::isDisplayableIn()` (non
 * banni + meme Organization), le statut publie du profil scope a CETTE
 * Organization, et le gate `Organization->ai_profiles_enabled` : quand il
 * est OFF, aucune surface n'affiche de profil publie — cette primitive ne
 * doit donc rien retourner non plus.
 *
 * La Boucle doit etre ACTIVE : c'est le perimetre deja retenu par
 * `UserLoopsSource` pour le parcours d'aide (une Boucle archivee n'est pas
 * une destination de demande), volontairement plus strict que
 * `viewWorkspace` seul.
 *
 * ## Discipline de provenance (TASK-1321, Core-1)
 *
 * Chaque personne retournee porte des faits VERIFIES reconstruits ici
 * meme, a partir de l'etat reel confirme par cette requete (appartenance
 * active, profil publie). C'est le seul materiau que People-2/People-3
 * auront le droit de presenter comme des faits.
 *
 * ## TASK-1546 — deux questions, un seul jeu de regles
 *
 * `eligibleFor()` repond a « qui pourrait les aider ? » et exclut toujours
 * le demandeur. `requesterInLoop()` repond a « et moi ? » et ne rend QUE le
 * demandeur. Les deux partagent les memes gardes de contexte et la meme
 * construction de personne : ce qui rend quelqu'un lisible dans une Boucle
 * s'ecrit ici, une fois. Aucune des deux n'elargit l'autre.
 */
class EligiblePeopleService
{
    public function eligibleFor(Organization $organization, Loop $loop, User $requester): EligiblePeopleResult
    {
        $refusal = $this->contextRefusal($organization, $loop, $requester);

        if ($refusal instanceof EligiblePeopleResult) {
            return $refusal;
        }

        // CANDIDATS — nombre de requetes constant quel que soit N :
        // une pour les appartenances actives (+ eager load users), une pour
        // les profils publies du lot. Jamais une requete par personne.
        $memberships = LoopMember::query()
            ->where('loop_id', $loop->id)
            ->where('status', 'active')
            ->where('user_id', '!=', $requester->id)
            ->with('user')
            ->get()
            ->filter(
                // isDisplayableIn = non banni + meme Organization : la regle
                // d'affichage existante, appliquee telle quelle.
                fn (LoopMember $membership): bool => $membership->user instanceof User
                    && $membership->user->isDisplayableIn($organization)
            );

        $profiles = $memberships->isEmpty()
            ? collect()
            : MemberAiProfile::query()
                ->forOrganization($organization)
                ->published()
                ->whereIn('user_id', $memberships->pluck('user_id'))
                ->get()
                ->keyBy('user_id');

        $people = [];

        foreach ($memberships as $membership) {
            $profile = $profiles->get($membership->user_id);

            if (! $profile instanceof MemberAiProfile) {
                continue;
            }

            $people[] = $this->person($loop, $membership, $profile);
        }

        // Ordre deterministe (contrat stable/testable), aucun classement de
        // pertinence — ce serait People-2, et jamais un score opaque.
        usort(
            $people,
            static fn (EligiblePerson $a, EligiblePerson $b): int => [mb_strtolower($a->displayName), $a->userId]
                <=> [mb_strtolower($b->displayName), $b->userId],
        );

        return EligiblePeopleResult::authorized($people);
    }

    /**
     * TASK-1546 — le DEMANDEUR lui-meme, sous les MEMES regles.
     *
     * « Et moi ? » n'est pas la meme question que « Qui pourrait les aider ? ».
     * L'ensemble recommandable exclut le demandeur, et {@see eligibleFor()}
     * n'est pas touche : rien de ce que cette methode rend ne peut apparaitre
     * dans une liste proposee a quelqu'un d'autre.
     *
     * Mais les REGLES de lecture doivent etre les memes des deux cotes, sans
     * quoi le produit porterait deux visibilites : les quatre gardes de
     * contexte sont donc celles de {@see contextRefusal()}, et la personne est
     * construite par {@see person()} — appartenance ACTIVE, `isDisplayableIn`,
     * profil PUBLIE dans CETTE Organization. C'est pourquoi cette methode vit
     * ici et pas dans People-2 : une seule classe repond a « qu'est-ce qui
     * rend une personne lisible dans cette Boucle ».
     *
     * Un demandeur sans profil publie rend un ensemble VIDE et autorise.
     * L'appelant a le devoir de ne pas le lire comme « ne correspond pas » —
     * voir {@see \App\Services\People\DTO\SelfFitResult}.
     */
    public function requesterInLoop(Organization $organization, Loop $loop, User $requester): EligiblePeopleResult
    {
        $refusal = $this->contextRefusal($organization, $loop, $requester);

        if ($refusal instanceof EligiblePeopleResult) {
            return $refusal;
        }

        if (! $requester->isDisplayableIn($organization)) {
            return EligiblePeopleResult::authorized([]);
        }

        $membership = LoopMember::query()
            ->where('loop_id', $loop->id)
            ->where('user_id', $requester->id)
            ->where('status', 'active')
            ->first();

        if (! $membership instanceof LoopMember) {
            return EligiblePeopleResult::authorized([]);
        }

        $profile = MemberAiProfile::query()
            ->forOrganization($organization)
            ->published()
            ->where('user_id', $requester->id)
            ->first();

        if (! $profile instanceof MemberAiProfile) {
            return EligiblePeopleResult::authorized([]);
        }

        $membership->setRelation('user', $requester);

        return EligiblePeopleResult::authorized([$this->person($loop, $membership, $profile)]);
    }

    /**
     * Les quatre gardes de CONTEXTE, dans l'ordre : tenant d'abord, puis le
     * droit du demandeur, puis l'etat de la Boucle, puis le gate produit.
     * Chaque refus est explicite — jamais un vide silencieux.
     *
     * `null` = le contexte autorise a CALCULER (ce qui ne dit rien de ce que
     * le calcul trouvera).
     */
    private function contextRefusal(Organization $organization, Loop $loop, User $requester): ?EligiblePeopleResult
    {
        if ($loop->organization_id !== $organization->id) {
            return EligiblePeopleResult::refused(EligiblePeopleResult::REFUSAL_CROSS_ORGANIZATION);
        }

        if (! Gate::forUser($requester)->allows('viewWorkspace', $loop)) {
            return EligiblePeopleResult::refused(EligiblePeopleResult::REFUSAL_REQUESTER_NOT_AUTHORIZED);
        }

        if (! $loop->isActive()) {
            return EligiblePeopleResult::refused(EligiblePeopleResult::REFUSAL_LOOP_NOT_ACTIVE);
        }

        if (! $organization->ai_profiles_enabled) {
            return EligiblePeopleResult::refused(EligiblePeopleResult::REFUSAL_AI_PROFILES_DISABLED);
        }

        return null;
    }

    /**
     * Une personne lisible, avec les faits VERIFIES reconstruits depuis l'etat
     * reel que les requetes viennent de confirmer (discipline TASK-1321).
     */
    private function person(Loop $loop, LoopMember $membership, MemberAiProfile $profile): EligiblePerson
    {
        return new EligiblePerson(
            userId: (string) $membership->user_id,
            displayName: $membership->user->publicDisplayName(),
            avatarUrl: $membership->user->publicAvatarUrl(),
            memberAiProfileId: (string) $profile->id,
            verifiedFacts: [
                [
                    'type' => 'active_loop_membership',
                    'loop_id' => (string) $loop->id,
                    'joined_at' => $membership->joined_at?->toIso8601String(),
                ],
                [
                    'type' => 'member_ai_profile_published',
                    'member_ai_profile_id' => (string) $profile->id,
                    'published_at' => $profile->published_at?->toIso8601String(),
                ],
            ],
        );
    }
}
