<?php

namespace App\Support\Ai;

use App\Models\AiShellMessage;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\AiShellResponder;
use App\Services\People\DTO\EligiblePerson;
use App\Services\People\EligiblePeopleService;
use App\Services\People\RelevantPeopleService;
use Illuminate\Support\Facades\Route;

/**
 * TASK-1325 (Shell-1) — les cartes structurees d'entites d'un tour IA du
 * Shell « BouclePro IA ». Trois types, pas un framework : Boucle, Personne,
 * Document (Dossier/Article).
 *
 * ## Deux temps, deux statuts — la regle qui gouverne ce fichier
 *
 * 1. AU TOUR (`forAnsweredTurn()`) : on inscrit dans la metadata du message
 *    des REFERENCES — identifiants et faits verifies a cet instant par une
 *    autorite serveur. Jamais un libelle a re-afficher tel quel, jamais une
 *    URL, jamais un droit. Les seules matieres admises :
 *    - la Boucle suggeree DEJA revalidee par la clarification contre les
 *      Boucles reellement offertes au contexte (T1210/T1321) ;
 *    - les personnes retenues par `RelevantPeopleService` (People-2),
 *      strictement DANS l'ensemble eligible de People-1 — le modele ne cree
 *      jamais un candidat, et leurs raisons sont des faits serveur apparies ;
 *    - l'objet du contexte de page du tour, que la page venait de revalider.
 *
 * 2. AU RENDU (`forDisplay()`) : chaque reference est REJOUEE contre
 *    l'autorite qui gouverne deja son objet — `AiShellPageContext::resolve()`
 *    pour Boucle et Document (les gardes des pages elles-memes),
 *    `EligiblePeopleService::eligibleFor()` pour une Personne (People-1
 *    entier : tenant, droit du demandeur, Boucle active, profil PUBLIE, gate
 *    `ai_profiles_enabled`). Ce qui ne passe plus n'existe plus : la carte
 *    disparait, comme la Boucle suggeree de T1315. Nom et URL sont toujours
 *    relus a l'instant, jamais depuis le fil.
 *
 * ## La whitelist est structurelle
 *
 * Un type de carte inconnu n'est pas rendu (le `match` retombe a null), et
 * une carte ne porte jamais de route : les liens sont construits ICI, par les
 * memes helpers que le reste du Shell, et le clic aboutit sur un controleur
 * qui rejoue sa propre garde. Le LLM ne peut donc ni inventer une action, ni
 * fournir une destination.
 *
 * ## Perimetre assume
 *
 * La PersonCard n'offre que « Voir le profil » : la mise en relation est
 * People-3, DEFERRED par decision humaine (28/08) — aucune brique ici.
 * Les raisons d'une personne sont le SNAPSHOT verifie au tour (avec sources
 * et termes apparies) : elles se relisent comme l'historique du tour, et ne
 * sont montrees que tant que la personne est TOUJOURS eligible a l'instant
 * du rendu.
 *
 * Une instance ne sert qu'un couple (Organization, User) : le memo
 * d'eligibilite par Boucle en depend.
 */
final class AiShellTurnCards
{
    public const TYPE_LOOP = 'loop';

    public const TYPE_PERSON = 'person';

    public const TYPE_DOCUMENT = 'document';

    /**
     * Ensemble eligible par Boucle, calcule AU PLUS une fois par rendu —
     * People-1 est a nombre de requetes constant, ce memo le garde ainsi
     * quel que soit le nombre de cartes affichees.
     *
     * @var array<string, array<string, EligiblePerson>>
     */
    private array $eligibleByLoop = [];

    public function __construct(
        private readonly AiShellPageContext $pageContext,
        private readonly RelevantPeopleService $relevantPeople,
        private readonly EligiblePeopleService $eligiblePeople,
    ) {}

    /**
     * Les references de cartes d'un tour ANSWERED — des identifiants et des
     * faits verifies a CET instant, jamais un droit ni un libelle de
     * confiance.
     *
     * @param  array<string, mixed>|null  $suggestedLoop  la suggestion DEJA
     *                                                    validee par la clarification
     * @param  array<string, mixed>  $pageContext  contexte resolu par CETTE requete
     * @param  string  $need  matiere d'appariement People-2 (question + besoin clarifie)
     * @return list<array<string, mixed>>
     */
    public function forAnsweredTurn(
        Organization $organization,
        User $user,
        ?array $suggestedLoop,
        array $pageContext,
        string $need,
    ): array {
        $cards = [];

        $suggestedLoopId = is_array($suggestedLoop) ? trim((string) ($suggestedLoop['id'] ?? '')) : '';

        if ($suggestedLoopId !== '') {
            // Seul le texte du modele est conserve avec la reference, et il
            // reste ce qu'il est : `ai_wording`, jamais un fait (T1321).
            $wording = $suggestedLoop['provenance']['ai_wording']['text'] ?? null;

            $cards[] = [
                'type' => self::TYPE_LOOP,
                'id' => $suggestedLoopId,
                'ai_wording' => is_string($wording) && trim($wording) !== '' ? trim($wording) : null,
            ];
        }

        // La Boucle des personnes : celle que le tour suggere, sinon celle de
        // la page. Quel que soit ce choix, People-1 rejoue TOUTES ses gardes —
        // l'identifiant n'est qu'un candidat, jamais un droit.
        $object = is_array($pageContext['object'] ?? null) ? $pageContext['object'] : null;
        $peopleLoopId = $suggestedLoopId !== ''
            ? $suggestedLoopId
            : ((($object['type'] ?? null) === AiShellPageContext::KIND_LOOP) ? (string) $object['id'] : '');

        if ($peopleLoopId !== '') {
            $loop = Loop::query()->find($peopleLoopId);

            if ($loop instanceof Loop) {
                $relevant = $this->relevantPeople->relevantFor($organization, $loop, $user, $need);

                // Un refus de contexte ou zero pertinent = zero carte,
                // proprement. Le refus n'est pas reinterprete ici.
                if ($relevant->authorized) {
                    foreach ($relevant->people as $person) {
                        $cards[] = [
                            'type' => self::TYPE_PERSON,
                            'user_id' => $person->person->userId,
                            'loop_id' => (string) $loop->id,
                            // Faits serveur apparies (label, source,
                            // matched_terms, verified: true) — le snapshot du
                            // tour, relisible comme son historique.
                            'reasons' => $person->reasons,
                        ];
                    }
                }
            }
        }

        if (in_array($object['type'] ?? null, [AiShellPageContext::KIND_DOSSIER, AiShellPageContext::KIND_ARTICLE], true)) {
            $cards[] = [
                'type' => self::TYPE_DOCUMENT,
                'kind' => (string) $object['type'],
                'id' => (string) $object['id'],
            ];
        }

        return $cards;
    }

    /**
     * Les cartes AFFICHABLES d'un message : chaque reference stockee est
     * re-resolue MAINTENANT par l'autorite qui gouverne son objet. Ce qui ne
     * passe plus n'existe plus ; un type inconnu n'est jamais rendu.
     *
     * @return list<array<string, mixed>>
     */
    public function forDisplay(Organization $organization, User $user, AiShellMessage $message): array
    {
        $metadata = is_array($message->metadata) ? $message->metadata : [];

        if ($message->role !== AiShellMessage::ROLE_ASSISTANT
            || ($metadata['status'] ?? null) !== AiShellResponder::STATUS_ANSWERED) {
            return [];
        }

        $stored = $metadata['cards'] ?? null;

        if (! is_array($stored)) {
            // Tours anterieurs a Shell-1 : la suggestion de Boucle existante
            // EST deja une reference de carte — meme donnee, meme revalidation.
            $legacy = $metadata['suggested_loop_id'] ?? null;
            $stored = is_string($legacy) && $legacy !== ''
                ? [['type' => self::TYPE_LOOP, 'id' => $legacy, 'ai_wording' => null]]
                : [];
        }

        $cards = [];

        foreach ($stored as $reference) {
            if (! is_array($reference)) {
                continue;
            }

            $card = match ($reference['type'] ?? null) {
                self::TYPE_LOOP => $this->loopCard($organization, $user, $reference),
                self::TYPE_PERSON => $this->personCard($organization, $user, $reference),
                self::TYPE_DOCUMENT => $this->documentCard($organization, $user, $reference),
                // La whitelist : tout le reste n'existe pas a l'ecran.
                default => null,
            };

            if ($card !== null) {
                $cards[] = $card + ['turn_id' => (string) $message->id];
            }
        }

        return $cards;
    }

    /**
     * @param  array<string, mixed>  $reference
     * @return array<string, mixed>|null
     */
    private function loopCard(Organization $organization, User $user, array $reference): ?array
    {
        $resolved = $this->pageContext->resolve(
            $user,
            $organization,
            AiShellPageContext::KIND_LOOP,
            (string) ($reference['id'] ?? ''),
        );

        $object = $resolved['object'] ?? null;

        if (! is_array($object)) {
            return null;
        }

        $wording = $reference['ai_wording'] ?? null;

        return [
            'type' => self::TYPE_LOOP,
            'title' => (string) $object['label'],
            'url' => (string) $object['url'],
            'ai_wording' => is_string($wording) && $wording !== '' ? $wording : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $reference
     * @return array<string, mixed>|null
     */
    private function personCard(Organization $organization, User $user, array $reference): ?array
    {
        $loopId = trim((string) ($reference['loop_id'] ?? ''));
        $userId = trim((string) ($reference['user_id'] ?? ''));

        if ($loopId === '' || $userId === '') {
            return null;
        }

        $person = $this->eligibleNow($organization, $user, $loopId)[$userId] ?? null;

        if (! $person instanceof EligiblePerson) {
            return null;
        }

        // Les raisons du SNAPSHOT du tour, chacune traduite par une whitelist
        // de types People-2 — un type inconnu retombe sur le libelle generique,
        // jamais sur une cle brute a l'ecran.
        $reasons = [];

        foreach ((array) ($reference['reasons'] ?? []) as $reason) {
            if (! is_array($reason) || ! is_string($reason['type'] ?? null) || ! is_string($reason['label'] ?? null)) {
                continue;
            }

            $reasons[] = __(match ($reason['type']) {
                'profile_skill' => 'ai.shell_card_reason_profile_skill',
                'profile_help_type' => 'ai.shell_card_reason_profile_help_type',
                'profile_problem_helped' => 'ai.shell_card_reason_profile_problem_helped',
                'service_skill' => 'ai.shell_card_reason_service_skill',
                default => 'ai.shell_card_reason_generic',
            }, ['label' => $reason['label']]);
        }

        return [
            'type' => self::TYPE_PERSON,
            // Nom et avatar relus a l'instant par People-1 — jamais le
            // snapshot du tour.
            'title' => $person->displayName,
            'avatar' => $person->avatarUrl,
            'reasons' => $reasons,
            'url' => $this->profileUrl($organization, $userId),
        ];
    }

    /**
     * @param  array<string, mixed>  $reference
     * @return array<string, mixed>|null
     */
    private function documentCard(Organization $organization, User $user, array $reference): ?array
    {
        $kind = $reference['kind'] ?? null;

        if (! in_array($kind, [AiShellPageContext::KIND_DOSSIER, AiShellPageContext::KIND_ARTICLE], true)) {
            return null;
        }

        $resolved = $this->pageContext->resolve($user, $organization, $kind, (string) ($reference['id'] ?? ''));

        $object = $resolved['object'] ?? null;

        if (! is_array($object)) {
            return null;
        }

        return [
            'type' => self::TYPE_DOCUMENT,
            'kind' => $kind,
            'title' => (string) $object['label'],
            'url' => (string) $object['url'],
        ];
    }

    /**
     * L'ensemble eligible de la Boucle, indexe par utilisateur — People-1
     * entier, recalcule a l'instant du rendu. Un refus de contexte rend
     * l'ensemble vide : aucune personne ne s'affiche la ou l'eligibilite
     * dit non.
     *
     * @return array<string, EligiblePerson>
     */
    private function eligibleNow(Organization $organization, User $user, string $loopId): array
    {
        if (array_key_exists($loopId, $this->eligibleByLoop)) {
            return $this->eligibleByLoop[$loopId];
        }

        $eligible = [];
        $loop = Loop::query()->find($loopId);

        if ($loop instanceof Loop) {
            $result = $this->eligiblePeople->eligibleFor($organization, $loop, $user);

            if ($result->authorized) {
                foreach ($result->people as $person) {
                    $eligible[$person->userId] = $person;
                }
            }
        }

        return $this->eligibleByLoop[$loopId] = $eligible;
    }

    /**
     * Le lien vers le profil canonique — le controleur cible rejoue sa garde
     * (meme Organization, non banni) au clic, comme toute page.
     */
    private function profileUrl(Organization $organization, string $userId): string
    {
        if (Route::has('organization.profile.show')) {
            return route('organization.profile.show', ['organization' => $organization->slug, 'user' => $userId]);
        }

        return Route::has('profile.show') ? route('profile.show', ['user' => $userId]) : '';
    }
}
