<?php

namespace App\Support\Ai;

use App\Models\AiShellMessage;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\AiShellResponder;
use App\Services\People\DTO\EligiblePeopleResult;
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
     * TASK-1360 — l'ETAT VIDE des personnes.
     *
     * Jusqu'ici, un tour situe dans une Boucle qui ne rendait AUCUNE personne
     * n'affichait rien du tout : ni resultat, ni explication. Or la doctrine
     * maison dit qu'un refus n'est jamais un vide silencieux
     * ({@see EligiblePeopleResult}) — et People-1 /
     * People-2 sont livres et cables depuis T1323/T1324, mais restent invisibles
     * faute de profils publies (7 % des membres a l'audit du 2026-09-01).
     *
     * Cette carte dit donc simplement qu'il n'y a personne a proposer ICI, et
     * ouvre le seul chemin qui change cela : publier son propre profil.
     *
     * Ce qu'elle ne dit JAMAIS, et c'est deliberé : combien de membres compte
     * la Boucle, et pourquoi telle personne n'est pas proposee. Une raison
     * individuelle d'ineligibilite serait un fait sur QUELQU'UN D'AUTRE ; un
     * decompte serait un fait sur la population de la Boucle. Ni l'un ni
     * l'autre n'appartient a celui qui pose la question.
     */
    public const TYPE_PEOPLE_EMPTY = 'people_empty';

    /**
     * TASK-1350 — l'appel a l'action d'une LoopCard.
     *
     * `prepare_request` est l'historique : le membre DEMANDE, on lui prepare un
     * brouillon de demande. `offer_help` est son symetrique : le membre PROPOSE
     * son aide, et « Preparer ma demande » serait alors un contresens — on
     * l'envoie au parcours canonique « Proposer de l'aide », tenant-aware et
     * SANS preremplissage (V1 : aucune reprise du texte du tour).
     *
     * L'absence de la cle sur une reference deja ecrite vaut `prepare_request` :
     * les tours anterieurs a TASK-1350 se relisent donc a l'identique.
     */
    public const CTA_PREPARE_REQUEST = 'prepare_request';

    public const CTA_OFFER_HELP = 'offer_help';

    /** Intention de tour telle que la clarification la qualifie pour une offre. */
    public const INTENT_OFFER = 'offer';

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
     * @param  string|null  $intent  TASK-1350 — intention qualifiee par la
     *                               clarification (`offer` / `help_request`).
     *                               Une OFFRE renverse la question : le membre
     *                               apporte quelque chose, il ne cherche pas
     *                               « qui peut m'aider ». Elle change donc deux
     *                               choses, et rien d'autre : l'appel a
     *                               l'action de la LoopCard, et l'absence de
     *                               toute PersonCard.
     * @return list<array<string, mixed>>
     */
    public function forAnsweredTurn(
        Organization $organization,
        User $user,
        ?array $suggestedLoop,
        array $pageContext,
        string $need,
        ?string $intent = null,
    ): array {
        $cards = [];
        $isOffer = $intent === self::INTENT_OFFER;

        $suggestedLoopId = is_array($suggestedLoop) ? trim((string) ($suggestedLoop['id'] ?? '')) : '';

        if ($suggestedLoopId !== '') {
            // Seul le texte du modele est conserve avec la reference, et il
            // reste ce qu'il est : `ai_wording`, jamais un fait (T1321).
            $wording = $suggestedLoop['provenance']['ai_wording']['text'] ?? null;

            $cards[] = [
                'type' => self::TYPE_LOOP,
                'id' => $suggestedLoopId,
                'ai_wording' => is_string($wording) && trim($wording) !== '' ? trim($wording) : null,
                'cta' => $isOffer ? self::CTA_OFFER_HELP : self::CTA_PREPARE_REQUEST,
            ];
        }

        // La Boucle des personnes : celle que le tour suggere, sinon celle de
        // la page. Quel que soit ce choix, People-1 rejoue TOUTES ses gardes —
        // l'identifiant n'est qu'un candidat, jamais un droit.
        $object = is_array($pageContext['object'] ?? null) ? $pageContext['object'] : null;
        $peopleLoopId = $suggestedLoopId !== ''
            ? $suggestedLoopId
            : ((($object['type'] ?? null) === AiShellPageContext::KIND_LOOP) ? (string) $object['id'] : '');

        // TASK-1350 : sur une OFFRE, aucune PersonCard. « Qui peut m'aider ? »
        // n'a pas de sens quand c'est le membre qui propose son aide — et
        // afficher des personnes la transformerait en demande deguisee. La
        // coupe est faite AU TOUR : rien n'est ecrit, donc rien ne peut etre
        // re-resolu au rendu, meme apres un changement de code.
        if ($isOffer) {
            $peopleLoopId = '';
        }

        if ($peopleLoopId !== '') {
            $loop = Loop::query()->find($peopleLoopId);

            if ($loop instanceof Loop) {
                $relevant = $this->relevantPeople->relevantFor($organization, $loop, $user, $need);
                $suggested = 0;

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
                        $suggested++;
                    }
                }

                // TASK-1360 : personne a proposer ici. On l'ECRIT, au lieu de
                // ne rien rendre. La carte ne porte que l'identifiant de la
                // Boucle : aucune raison, aucun decompte, rien sur les autres.
                //
                // Un seul bon resultat vaut mieux qu'un resultat cache : cet
                // etat vide ne s'ecrit donc QUE lorsque le tour n'a suggere
                // personne.
                if ($suggested === 0) {
                    $cards[] = [
                        'type' => self::TYPE_PEOPLE_EMPTY,
                        'loop_id' => (string) $loop->id,
                    ];
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
                self::TYPE_PEOPLE_EMPTY => $this->peopleEmptyCard($organization, $user, $reference),
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

        // TASK-1350 : whitelist stricte, et defaut historique. Une valeur
        // inconnue — ou absente, cas de tous les tours anterieurs — retombe
        // sur `prepare_request`.
        $cta = ($reference['cta'] ?? null) === self::CTA_OFFER_HELP
            ? self::CTA_OFFER_HELP
            : self::CTA_PREPARE_REQUEST;

        return [
            'type' => self::TYPE_LOOP,
            'title' => (string) $object['label'],
            'url' => (string) $object['url'],
            'ai_wording' => is_string($wording) && $wording !== '' ? $wording : null,
            'cta' => $cta,
            // Construite ICI, comme toutes les URL de cartes : le LLM ne
            // fournit jamais une destination. Le controleur cible rejoue sa
            // garde au clic.
            'cta_url' => $cta === self::CTA_OFFER_HELP ? $this->offerHelpUrl($organization) : null,
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
     * TASK-1360 — l'etat vide, RE-EVALUE au rendu comme toute autre carte.
     *
     * Deux raisons de ne rien rendre, et elles sont de nature differente :
     *
     *  - la Boucle n'est plus visible par cette personne : la carte disparait,
     *    exactement comme une PersonCard dont l'objet n'est plus autorise ;
     *  - quelqu'un est devenu eligible depuis le tour : l'etat vide serait
     *    alors un MENSONGE. Il ne se contente donc pas d'etre autorise, il doit
     *    rester VRAI. C'est la meme discipline anti-TOCTOU que les autres
     *    cartes, appliquee a une affirmation plutot qu'a un droit.
     *
     * `eligibleNow()` porte People-1 entier : l'etat vide s'efface des qu'un
     * membre publie son profil, sans qu'aucun tour ancien ait a etre reecrit.
     *
     * @param  array<string, mixed>  $reference
     * @return array<string, mixed>|null
     */
    private function peopleEmptyCard(Organization $organization, User $user, array $reference): ?array
    {
        $loopId = (string) ($reference['loop_id'] ?? '');

        if ($loopId === '') {
            return null;
        }

        $resolved = $this->pageContext->resolve($user, $organization, AiShellPageContext::KIND_LOOP, $loopId);

        if (! is_array($resolved['object'] ?? null)) {
            return null;
        }

        if ($this->eligibleNow($organization, $user, $loopId) !== []) {
            return null;
        }

        return [
            'type' => self::TYPE_PEOPLE_EMPTY,
            'loop_id' => $loopId,
            'label' => __('ai.shell_people_empty'),
            'cta_label' => __('ai.shell_people_empty_cta'),
            'cta_url' => $this->aiProfileUrl($organization),
        ];
    }

    /**
     * Le parcours canonique du profil IA — celui que l'onboarding utilise deja.
     * Le controleur cible rejoue sa propre garde au clic, comme toute page.
     */
    private function aiProfileUrl(Organization $organization): string
    {
        if (Route::has('organization.agent-ia.wizard')) {
            return route('organization.agent-ia.wizard', ['organization' => $organization->slug]);
        }

        return Route::has('agent-ia.wizard') ? route('agent-ia.wizard') : '';
    }

    /**
     * TASK-1350 — le parcours canonique « Proposer de l'aide », tenant-aware.
     *
     * Meme cascade que `AiShell::requestsCreateUrl()` : la route org-scopee
     * quand elle existe, sinon la route globale. Aucun preremplissage en V1 —
     * on ouvre le formulaire, la personne ecrit et valide.
     */
    private function offerHelpUrl(Organization $organization): string
    {
        if (Route::has('organization.services.create')) {
            return route('organization.services.create', ['organization' => $organization->slug]);
        }

        return Route::has('services.create') ? route('services.create') : '';
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
