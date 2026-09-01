<?php

namespace App\Services\People;

use App\Models\Loop;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\Service;
use App\Models\User;
use App\Services\People\DTO\EligiblePerson;
use App\Services\People\DTO\RelevantPeopleResult;
use App\Services\People\DTO\RelevantPerson;
use Illuminate\Support\Str;

/**
 * TASK-1324 (People-2) — pertinence EXPLICABLE, strictement DANS l'ensemble
 * eligible de People-1.
 *
 * Doctrine WOW People, dans cet ordre et jamais un autre :
 * 1. ELIGIBILITE — {@see EligiblePeopleService} (TASK-1323), consommee telle
 *    quelle : ses refus se propagent sans reinterpretation, son ensemble est
 *    l'UNIQUE univers de candidats. Aucune requete de candidats ici.
 * 2. PERTINENCE — deterministe, lexicale, transparente : un candidat n'est
 *    retenu que si au moins un signal AUTORISE de son dossier apparie des
 *    termes de la demande. Zero resultat est un resultat propre.
 * 3. PROVENANCE — chaque raison est un fait serveur relu a l'instant par ce
 *    service (idiome TASK-1321) ; le texte d'un modele, s'il y en a un, est
 *    `ai_wording, verified: false` — jamais un fait.
 *
 * ## Signaux V1 — peu de regles, signaux forts, tous traçables
 *
 * - Contenu STRUCTURE du `MemberAiProfile` PUBLIE du candidat (`skills`,
 *   `help_types`, `problems_helped`). La publication vaut consentement de
 *   visibilite (doctrine People-1) ; les champs qui ne decrivent pas une
 *   competence offerte (`boundaries`, resumes, exemples) ne sont JAMAIS des
 *   signaux — citer les limites de quelqu'un pour le recommander serait un
 *   contresens.
 * - `Skill` structuree (referentiel de l'Organization) portee par un
 *   `Service` ACTIF du candidat dans la MEME Organization : a la fois une
 *   competence declaree dans un referentiel et une contribution observable,
 *   org-visible par construction (Marketplace).
 *
 * Signaux volontairement NON retenus en V1 (« ne pas forcer un signal peu
 * fiable ») : messages libres (non structures), Dossiers (visibilite
 * multi-niveaux — exigerait de rejouer la policy d'acces du demandeur pour
 * chaque citation), participations (pas de source structuree univoque).
 *
 * ## Ce que ce service ne fait JAMAIS
 *
 * Pas de score numerique, pas de pourcentage, pas de classement universel :
 * l'ordre de sortie est explicable (nombre de faits apparies, puis nom) et
 * ne pretend a rien d'autre. Aucun appel provider ici — le role du LLM,
 * optionnel, passe exclusivement par {@see validatedProviderSelection()},
 * qui ne sait que RESTREINDRE un ensemble deja construit par le serveur.
 */
class RelevantPeopleService
{
    /**
     * « Zero a quelques » (spec fille People-2) : le plafond fait partie du
     * contrat — une longue liste redeviendrait un classement.
     */
    public const MAX_RESULTS = 3;

    /**
     * Vocabulaire d'emballage (articles, pronoms, formulation de demande)
     * retire avant appariement : ces mots relient toutes les demandes a tous
     * les profils sans rien dire du sujet. Liste V1 volontairement courte,
     * appliquee APRES normalisation (minuscules, sans accents) et apres le
     * singulier naif — la qualite d'appariement n'est pas le contrat,
     * la traçabilite l'est.
     */
    private const STOPWORDS = [
        'les', 'des', 'une', 'mon', 'mes', 'ton', 'tes', 'son', 'ses', 'nos', 'vos',
        'leur', 'notre', 'votre', 'pour', 'avec', 'sans', 'dans', 'sur', 'sous',
        'par', 'pas', 'est', 'sont', 'ete', 'etre', 'avoir', 'fait', 'faire',
        'qui', 'que', 'quoi', 'dont', 'mais', 'donc', 'car', 'tres', 'peu',
        'cette', 'cet', 'ces', 'comme', 'plus', 'moins', 'bien', 'tout', 'tous',
        'toute', 'quelqu', 'chose', 'je', 'tu', 'il', 'elle', 'nous', 'vous',
        'ils', 'elles', 'moi', 'toi', 'lui',
        'the', 'and', 'for', 'with', 'you', 'your', 'our', 'are', 'was', 'has',
        'have', 'how', 'what', 'who', 'can', 'could', 'would', 'will', 'this',
        'that', 'from', 'about', 'someone',
        // Vocabulaire de la demande d'aide elle-meme : present dans presque
        // toutes les phrases ET presque tous les profils — un appariement
        // dessus ne dit rien de la personne.
        'aide', 'aider', 'besoin', 'demande', 'question', 'help', 'need',
    ];

    public function __construct(
        private readonly EligiblePeopleService $eligiblePeople,
    ) {}

    /**
     * Les personnes pertinentes pour `$need`, DANS l'ensemble eligible.
     *
     * `$need` est le texte de la demande (brut ou clarifie). Un texte sans
     * terme exploitable produit zero resultat — proprement.
     */
    public function relevantFor(Organization $organization, Loop $loop, User $requester, string $need): RelevantPeopleResult
    {
        $eligible = $this->eligiblePeople->eligibleFor($organization, $loop, $requester);

        if (! $eligible->authorized) {
            // Refus de contexte People-1, propage tel quel : la pertinence
            // n'a pas le droit d'exister la ou l'eligibilite a dit non.
            return RelevantPeopleResult::refused((string) $eligible->refusalReason);
        }

        $needTokens = $this->tokens($need);

        if ($eligible->people === [] || $needTokens === []) {
            return RelevantPeopleResult::authorized([]);
        }

        $signalsByUserId = $this->authorizedSignalsByUserId($organization, $eligible->people);

        $kept = [];

        foreach ($eligible->people as $person) {
            $reasons = $this->matchedReasons($signalsByUserId[$person->userId] ?? [], $needTokens);

            if ($reasons === []) {
                continue;
            }

            $kept[] = new RelevantPerson($person, $reasons);
        }

        // Ordre explicable, jamais un score : plus de faits apparies d'abord,
        // puis l'ordre alphabetique stable de People-1.
        usort(
            $kept,
            static fn (RelevantPerson $a, RelevantPerson $b): int => [count($b->reasons), mb_strtolower($a->person->displayName), $a->person->userId]
                <=> [count($a->reasons), mb_strtolower($b->person->displayName), $b->person->userId],
        );

        return RelevantPeopleResult::authorized(array_slice($kept, 0, self::MAX_RESULTS));
    }

    /**
     * Applique une selection proposee par un provider — le SEUL point de
     * contact prevu entre un LLM et cette verticale, et il ne sait que
     * restreindre.
     *
     * `$proposal` : liste de `{user_id, wording?}` produite par un modele a
     * qui on a montre `$server->people` (des candidats deja eligibles et des
     * faits deja verifies). Regles, par construction :
     *
     * - un `user_id` absent de l'ensemble retenu par le serveur est REJETE et
     *   trace (`rejected_provider_user_ids`) — le modele ne cree jamais un
     *   candidat, ni ne repeche un eligible que le serveur n'a pas retenu ;
     * - le `wording` est attache `verified: false` a une personne deja
     *   validee ; toute autre cle de la proposition (faits, raisons, scores
     *   inventes) est ignoree — un provider ne peut pas ecrire un fait ;
     * - l'ordre SERVEUR est conserve : selectionner n'est pas classer ;
     * - un resultat refuse reste refuse, quoi que propose le provider.
     */
    public function validatedProviderSelection(RelevantPeopleResult $server, array $proposal): RelevantPeopleResult
    {
        if (! $server->authorized) {
            return $server;
        }

        $peopleByUserId = [];

        foreach ($server->people as $person) {
            $peopleByUserId[$person->person->userId] = $person;
        }

        $wordings = [];
        $rejected = [];

        foreach ($proposal as $entry) {
            $userId = trim((string) (is_array($entry) ? ($entry['user_id'] ?? '') : ''));

            if (! array_key_exists($userId, $peopleByUserId)) {
                $rejected[] = $userId;

                continue;
            }

            if (array_key_exists($userId, $wordings)) {
                continue;
            }

            $wordings[$userId] = trim((string) ($entry['wording'] ?? ''));
        }

        $selected = [];

        foreach ($server->people as $person) {
            $userId = $person->person->userId;

            if (! array_key_exists($userId, $wordings)) {
                continue;
            }

            $selected[] = $wordings[$userId] !== ''
                ? $person->withAiWording($wordings[$userId])
                : $person;
        }

        return $server->withProviderOutcome($selected, $rejected);
    }

    /**
     * Les signaux AUTORISES de chaque personne eligible — et rien d'autre.
     *
     * Nombre de requetes constant quel que soit N (meme discipline que
     * People-1) : une pour les profils du lot, une pour les Services actifs
     * du lot (+ eager load des Skills). Chaque source est re-scopee ici a
     * l'Organization, en defense en profondeur de ce que People-1 garantit
     * deja.
     *
     * @param  list<EligiblePerson>  $people
     * @return array<string, list<array{type: string, label: string, source: array<string, string>}>>
     */
    private function authorizedSignalsByUserId(Organization $organization, array $people): array
    {
        $userIds = array_map(static fn (EligiblePerson $person): string => $person->userId, $people);

        // Le profil est relu par la REFERENCE que People-1 a exposee, re-scope
        // org + publie : si le profil a change d'etat entre les deux lectures,
        // ses champs disparaissent simplement des signaux.
        $profiles = MemberAiProfile::query()
            ->forOrganization($organization)
            ->published()
            ->whereIn('id', array_map(static fn (EligiblePerson $person): string => $person->memberAiProfileId, $people))
            ->get()
            ->keyBy('user_id');

        // `Service` porte `BelongsToOrganizationScope` (tenant AMBIANT) :
        // hors requete HTTP il fermerait tout (0=1) ou suivrait un autre
        // contexte que l'Organization demandee. Idiome documente de
        // `LoopMarketplaceService::linksFor()` : retirer CE scope-la
        // seulement (le SoftDeletingScope reste — un Service a la corbeille
        // n'est pas un signal) et filtrer explicitement sur l'Organization.
        // `Service::active()` = statut actif + auteur au compte actif.
        $servicesByUserId = Service::query()
            ->withoutGlobalScope(BelongsToOrganizationScope::class)
            ->where('organization_id', $organization->id)
            ->active()
            ->whereIn('user_id', $userIds)
            ->with(['skills' => fn ($query) => $query->where('skills.organization_id', $organization->id)])
            ->get()
            ->groupBy('user_id');

        $signals = [];

        foreach ($people as $person) {
            $own = [];

            $profile = $profiles->get($person->userId);

            if ($profile instanceof MemberAiProfile) {
                foreach ([
                    'skills' => 'profile_skill',
                    'help_types' => 'profile_help_type',
                    'problems_helped' => 'profile_problem_helped',
                ] as $field => $type) {
                    foreach ((array) $profile->{$field} as $value) {
                        // JSON libre saisi au wizard : seul un libelle texte
                        // non vide est un signal.
                        if (! is_string($value) || trim($value) === '') {
                            continue;
                        }

                        $own[] = [
                            'type' => $type,
                            'label' => trim($value),
                            'source' => ['member_ai_profile_id' => (string) $profile->id],
                        ];
                    }
                }
            }

            foreach ($servicesByUserId->get($person->userId, collect()) as $service) {
                foreach ($service->skills as $skill) {
                    $own[] = [
                        'type' => 'service_skill',
                        'label' => (string) $skill->name,
                        'source' => [
                            'skill_id' => (string) $skill->id,
                            'service_id' => (string) $service->id,
                            'service_title' => (string) $service->title,
                        ],
                    ];
                }
            }

            $signals[$person->userId] = $own;
        }

        return $signals;
    }

    /**
     * Les signaux de la personne qui apparient la demande — chacun devient
     * une raison portant le fait, sa source et les termes communs.
     *
     * @param  list<array{type: string, label: string, source: array<string, string>}>  $signals
     * @param  list<string>  $needTokens
     * @return list<array{type: string, label: string, source: array<string, string>, matched_terms: list<string>, verified: true}>
     */
    private function matchedReasons(array $signals, array $needTokens): array
    {
        $reasons = [];

        foreach ($signals as $signal) {
            $matched = array_values(array_intersect($this->tokens($signal['label']), $needTokens));

            if ($matched === []) {
                continue;
            }

            // Un meme libelle declare deux fois (profil + deux Services…) ne
            // fabrique pas deux raisons : la premiere source citee suffit.
            $key = $signal['type'].'|'.mb_strtolower($signal['label']);

            if (array_key_exists($key, $reasons)) {
                continue;
            }

            $reasons[$key] = $signal + ['matched_terms' => $matched, 'verified' => true];
        }

        return array_values($reasons);
    }

    /**
     * Normalisation lexicale V1, entierement transparente : minuscules,
     * translitteration des accents, decoupe sur tout ce qui n'est pas
     * alphanumerique (les slugs `relire_document` se decoupent donc aussi),
     * singulier naif (« budgets » → « budget »), puis retrait des mots
     * d'emballage. Tokens de moins de 3 caracteres ignores.
     *
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        $parts = preg_split('/[^a-z0-9]+/', Str::ascii(mb_strtolower($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $tokens = [];

        foreach ($parts as $part) {
            // TASK-1360 — le mot d'emballage est ecarte AVANT toute troncature.
            //
            // L'ordre inverse laissait fuir tous les mots vides de quatre
            // lettres ou plus finissant par « s » : le singulier naif les
            // rendait meconnaissables de la liste. « dans » devenait « dan »,
            // qui n'y figure pas — et se retrouvait donc presente a
            // l'utilisateur comme une raison de mise en relation. Constate en
            // base : une carte justifiait un rapprochement par `matched_terms:
            // ["dan"]`. Meme fuite pour sous, sans, plus, tous, nous, vous,
            // moins et tres.
            //
            // Le second controle, apres troncature, reste necessaire : il
            // attrape les mots vides que le singulier naif REND identiques a
            // une entree de la liste (« elles » -> « elle »).
            if (in_array($part, self::STOPWORDS, true)) {
                continue;
            }

            if (strlen($part) >= 4 && str_ends_with($part, 's')) {
                $part = substr($part, 0, -1);
            }

            if (strlen($part) < 3 || in_array($part, self::STOPWORDS, true)) {
                continue;
            }

            $tokens[$part] = true;
        }

        // Les cles numeriques (« 2024 ») redeviendraient des int : le contrat
        // des `matched_terms` est une liste de chaines.
        return array_map(strval(...), array_keys($tokens));
    }
}
