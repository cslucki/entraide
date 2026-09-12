<?php

namespace App\Support\Ai;

use App\Models\AiShellMessage;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\AiShellResponder;
use App\Services\People\DTO\EligiblePerson;
use App\Services\People\EligiblePeopleService;

/**
 * TASK-1546 (audit) — le texte qui NOMME des personnes, et sa revalidation au
 * moment de l'affichage.
 *
 * ## Le defaut ferme ici
 *
 * Un tour People/Self ecrit des noms dans `content`, et `content` est
 * persiste. Entre le calcul et le rendu — une seconde ou trois semaines plus
 * tard — quelqu'un peut quitter la Boucle ou depublier son profil. Le fil
 * continuait a le nommer, avec ses competences, a chaque ouverture du Shell.
 *
 * La revalidation des PersonCards ne pouvait rien y faire, et pour une raison
 * qui merite d'etre ecrite : {@see AiShellTurnCards::forDisplay()} ne rend des
 * cartes que sur un tour `STATUS_ANSWERED`, et les tours People/Self sont
 * `STATUS_NON_INTERACTION`. Leurs cartes ne sont donc jamais affichees — la
 * seule chose qui atteigne l'ecran est le TEXTE.
 *
 * ## Un seul composeur, deux appelants
 *
 * Le corps nominatif est compose ici, et ici seulement : par
 * {@see AiShellResponder} au moment du tour, puis par le rendu a chaque
 * affichage. Deux compositions auraient diverge a la premiere retouche
 * editoriale, et c'est l'affichage qui aurait menti.
 *
 * ## Le nom vient de MAINTENANT, la raison vient du TOUR
 *
 * Meme discipline que {@see AiShellTurnCards::personCard()} : l'identite est
 * relue par l'autorite d'eligibilite a l'instant du rendu, les raisons restent
 * le snapshot de ce que le serveur avait lu ce jour-la. Une raison est datee
 * par nature ; une identite, non.
 */
final class AiShellNominativeTurn
{
    public function __construct(
        private readonly EligiblePeopleService $eligiblePeople,
    ) {}

    /**
     * Le corps de « Qui pourrait les aider ? ».
     *
     * @param  list<array{name: string, reasons: list<array<string, mixed>>}>  $people
     */
    public function peopleBody(string $loopName, array $people, string $locale): string
    {
        $lignes = array_map(
            fn (array $person): string => trans('ai.people_candidate', [
                'name' => $person['name'],
                'reasons' => $this->reasons($person['reasons'], $locale),
            ], $locale),
            $people,
        );

        return trans('ai.people_intro', ['loop' => $loopName], $locale)."\n\n".implode("\n", $lignes);
    }

    /**
     * Le corps de « Et moi ? » quand la mesure retient quelque chose.
     *
     * @param  list<array<string, mixed>>  $reasons
     */
    public function selfBody(string $loopName, string $name, array $reasons, string $locale): string
    {
        return trans('ai.self_fit', ['loop' => $loopName], $locale)
            ."\n\n".trans('ai.people_candidate', [
                'name' => $name,
                'reasons' => $this->reasons($reasons, $locale),
            ], $locale)
            ."\n\n".trans('ai.self_limits', [], $locale);
    }

    /**
     * Le contenu AFFICHABLE d'un message, revalide MAINTENANT.
     *
     * Rend le contenu stocke pour tout ce qui ne nomme personne — l'immense
     * majorite des tours, y compris les tours People/Self bloques, refuses ou
     * a zero resultat. Ce qui nomme est reconstruit depuis l'ensemble eligible
     * de l'instant ; ce qui n'y est plus disparait, et si plus rien ne reste,
     * le texte entier cede la place a une phrase qui ne nomme rien.
     */
    public function displayContent(Organization $organization, User $user, AiShellMessage $message): string
    {
        $stored = (string) $message->content;

        if ($message->role !== AiShellMessage::ROLE_ASSISTANT) {
            return $stored;
        }

        $metadata = is_array($message->metadata) ? $message->metadata : [];
        $producer = $metadata['producer'] ?? null;

        $self = $producer === AiShellResponder::PRODUCER_SELF_MATCHING;

        if (! $self && $producer !== AiShellResponder::PRODUCER_PEOPLE_MATCHING) {
            return $stored;
        }

        $bloc = $metadata[$self ? 'self' : 'people'] ?? null;

        if (! is_array($bloc)) {
            return $stored;
        }

        // Ce que le tour a NOMME. Un tour bloque, refuse ou a zero resultat
        // n'a rien ici — et n'a donc rien a revalider.
        $nomme = $self
            ? (($bloc['fits'] ?? false) === true ? (array) ($bloc['reasons'] ?? []) : [])
            : (array) ($bloc['selected'] ?? []);

        if ($nomme === []) {
            return $stored;
        }

        $locale = str_starts_with((string) app()->getLocale(), 'en') ? 'en' : 'fr';
        $loopId = trim((string) ($bloc['referent_loop_id'] ?? ''));
        $loop = $loopId === '' ? null : Loop::query()->find($loopId);

        if (! $loop instanceof Loop) {
            return trans('ai.nominative_no_longer_shown', [], $locale);
        }

        if ($self) {
            $moi = $this->eligiblePeople->requesterInLoop($organization, $loop, $user);

            return $moi->authorized && $moi->people !== []
                ? $this->selfBody((string) $loop->name, $moi->people[0]->displayName, $nomme, $locale)
                : trans('ai.nominative_no_longer_shown', [], $locale);
        }

        $eligible = $this->eligiblePeople->eligibleFor($organization, $loop, $user);

        if (! $eligible->authorized) {
            return trans('ai.nominative_no_longer_shown', [], $locale);
        }

        $parId = [];

        foreach ($eligible->people as $person) {
            $parId[$person->userId] = $person;
        }

        $retenues = [];

        foreach ($nomme as $entree) {
            $userId = is_array($entree) ? trim((string) ($entree['user_id'] ?? '')) : '';
            $person = $parId[$userId] ?? null;

            if (! $person instanceof EligiblePerson) {
                continue;
            }

            $retenues[] = [
                'name' => $person->displayName,
                'reasons' => (array) ($entree['reasons'] ?? []),
            ];
        }

        return $retenues === []
            ? trans('ai.nominative_no_longer_shown', [], $locale)
            : $this->peopleBody((string) $loop->name, $retenues, $locale);
    }

    /**
     * Les raisons, telles que le serveur les avait lues : le libelle DECLARE,
     * jamais une reformulation. Aucun score, aucun rang.
     *
     * @param  list<array<string, mixed>>|array<mixed>  $reasons
     */
    private function reasons(array $reasons, string $locale): string
    {
        $libelles = [];

        foreach ($reasons as $reason) {
            if (! is_array($reason) || ! is_string($reason['label'] ?? null) || trim($reason['label']) === '') {
                continue;
            }

            $libelles[] = trans('ai.people_reason', ['label' => trim($reason['label'])], $locale);
        }

        return implode(trans('ai.people_reason_separator', [], $locale), $libelles);
    }
}
