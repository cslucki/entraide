<?php

namespace App\Services\Ai;

use App\Ai\Context\DossierAccessScope;
use App\Ai\Context\PeopleQuestionShape;
use App\Ai\ProviderResolver;
use App\Models\AiShellMessage;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\DTO\KnowledgeAnswer;
use App\Services\Dossiers\DerivedChunkEligibility;
use App\Services\Dossiers\DossierInsightsService;
use App\Services\Dossiers\DossierSemanticSearchGate;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\Knowledge\ClaimMemory;
use App\Services\Knowledge\LoopReferenceResolver;
use App\Services\People\DTO\EligiblePeopleResult;
use App\Services\People\DTO\RelevantPeopleResult;
use App\Services\People\DTO\SelfFitResult;
use App\Services\People\RelevantPeopleService;
use App\Support\Ai\AiSelfKnowledge;
use App\Support\Ai\AiShellPageContext;
use App\Support\Ai\AiShellThread;
use App\Support\Ai\AiShellTurnCards;
use App\Support\Ai\AiShellUsageReference;
use App\Support\Ai\AiTurnLock;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TASK-1315 — UN tour de conversation du Shell « BouclePro IA ».
 *
 * ## Ce service n'est PAS un moteur de conversation
 *
 * Il n'appelle aucun provider, ne compose aucun prompt, ne resout aucun modele,
 * n'ecrit pas le ledger et ne declare aucune capability. Il route puis DELEGUE
 * aux autorites specialisees : clarification d'entraide, reponse generale
 * membre et moteur documentaire existant. Le fil reste ici et n'est jamais
 * reconstruit dans ces services.
 *
 * Ce que ces delegations apportent, sans qu'on le reconstruise :
 *  - capabilities bornees a leur intention et a leurs sources ;
 *  - contexte borne par `ContextBuilder`, doctrine d'Organization (T1227) ;
 *  - provider / modele / credential resolus par l'Organization (T1212) ;
 *  - `AiEconomicGuard::authorize()` AVANT tout appel, et le ledger
 *    `ai_provider_invocations` apres (T1220) ;
 *  - `suggestedLoop` REVALIDE contre les Boucles reellement offertes au
 *    contexte (T1210) — le Shell ne peut donc pas proposer une Boucle que
 *    l'utilisateur n'aurait pas ;
 *  - repli deterministe, sans appel, quand la clarification est desactivee, le
 *    tenant non configure, ou le verdict economique negatif.
 *
 * ## Ce qu'il ajoute, et rien d'autre
 *
 * La persistance du tour dans le fil du Shell, le verrou de course,
 * l'idempotence par declencheur, et — depuis TASK-1346 — la MEMOIRE du tour.
 * Il ne publie RIEN : aucune Demande, aucun message de Boucle, aucun Article.
 * La validation humaine reste devant toute publication durable.
 *
 * ## La memoire : le fil affiche, et rien de plus (TASK-1346)
 *
 * T1315 avait livre la persistance ET l'affichage du fil sans livrer son
 * INJECTION : la personne lisait son historique a l'ecran et parlait a un
 * interlocuteur qui n'avait rien recu. `conversationMemory()` ferme cet ecart,
 * et strictement lui : elle relit la conversation COURANTE — celle que la
 * surface affiche deja — par la porte unique `AiShellThread::messages()`, la
 * borne, et la donne a `situated()` comme un bloc de plus.
 *
 * Ce n'est donc ni un resume, ni une memoire longue, ni une memoire d'une
 * conversation a l'autre : effacer le fil ouvre une nouvelle conversation, qui
 * n'a aucune memoire. Et ce n'est pas une source : la question de savoir ce
 * que le modele a le DROIT de lire reste entierement chez `ContextBuilder`,
 * sous les `allowedSources` de la capability.
 *
 * ## L'honnetete du tour (TASK-1350)
 *
 * Le Shell transformait TOUT en demande d'aide : un remerciement, une question
 * sur le produit, une offre de competence en ressortaient avec un titre et un
 * brouillon. Deux ajouts ferment cet ecart, et strictement lui.
 *
 * D'abord `interaction_fit` : le clarificateur dit desormais si un autre membre
 * pourrait utilement contribuer. Ce verdict n'a d'autorite que sous un prompt
 * qui l'a instruit (v3+), et il arrive DEJA arbitre — `null` vaut « aucune
 * autorite », et se comporte comme avant. Quand il vaut `false`, le tour
 * devient {@see self::STATUS_NON_INTERACTION} : un message canonique, une
 * metadata minimale, rien a preparer.
 *
 * Quand ce verdict est `false`, le Shell ne se tait plus : le clarificateur
 * rend AUSSI un `direct_reply`, une reponse courte au message courant, que la
 * bulle affiche comme la parole de BouclePro IA. Le message canonique reste le
 * repli quand ce champ manque. Repondre n'est pas preparer une demande : ni
 * titre, ni brouillon, ni carte, ni `prepareRequest()`.
 *
 * Ensuite la self-knowledge : quatre questions sur BouclePro lui-meme sont
 * reconnues par FULL MATCH normalise ({@see AiSelfKnowledge}) et repondues
 * depuis les sources canoniques du produit, AVANT `situated()` et avant tout
 * provider. Une erreur de ce chemin n'est jamais une erreur pour la personne :
 * le tour repart chez le provider legacy.
 *
 * Ce que TASK-1350 n'ajoute pas : aucune nouvelle table, aucune migration de
 * donnees, et aucun changement chez les appelants partages du clarificateur —
 * NON_INTERACTION comme `direct_reply` sont des comportements de SHELL. Le
 * service partage expose les deux champs ; il ne les applique jamais.
 *
 * ## Le verrou : la doctrine T1311, pas une copie
 *
 * La course est arbitree par `AiTurnLock::runOnKey()` — la primitive de T1311
 * elle-meme, sur une cle fournie ici. Le Shell n'a pas de Boucle : il apporte
 * donc sa propre cle, `{organization}:{user}`, et rien d'autre ne change. Le
 * rejeu, lui, est arbitre par la BASE (`ai_shell_messages.reply_to_id` UNIQUE) :
 * le verrou traite la course, l'idempotence traite le rejeu.
 */
final class AiShellResponder
{
    /** Le tour a produit une reponse de l'IA. */
    public const STATUS_ANSWERED = 'answered';

    /** Le garde de securite de la clarification a refuse la demande. */
    public const STATUS_BLOCKED = 'blocked';

    /** Aucune IA n'a repondu (desactivee, tenant non configure, refus economique). */
    public const STATUS_UNAVAILABLE = 'unavailable';

    /**
     * TASK-1350 — le tour n'est pas une Interaction.
     *
     * Deux chemins y menent, et un seul comportement en sort :
     *  - le clarificateur a rendu `interaction_fit = false` sous un prompt
     *    autoritaire (v3+) : l'enonce est clairement hors Interaction ;
     *  - la question portait sur BouclePro lui-meme et a ete repondue sans
     *    provider ({@see AiSelfKnowledge}) — une plateforme qui s'explique
     *    n'attend rien d'un autre membre.
     *
     * Ce statut est un statut de SHELL, pas de clarification : le service
     * partage l'ignore, `RequestController::formulate()` et `LoopController`
     * ne le voient jamais. Sa metadata est MINIMALE par construction
     * (`status`, `producer`, `page_context`, pins) — donc pas de titre, pas de
     * brouillon, pas de Boucle suggeree, pas de cartes, pas d'intention. Rien
     * a preparer, donc `prepareRequest()` refuse, et `forDisplay()` rend `[]`
     * puisqu'il n'accepte que `STATUS_ANSWERED`.
     */
    public const STATUS_NON_INTERACTION = 'non_interaction';

    /**
     * TASK-1530 — le producteur d'un tour documentaire REPRIS apres navigation.
     *
     * Distinct de `dossier.answer` a dessein : le tour n'a pas ete tenu sur la
     * page du Dossier, et un verdict humain comme une mesure doivent pouvoir
     * separer « repondu sur la page » de « repris depuis le fil ».
     */
    public const PRODUCER_DOSSIER_CONTINUATION = 'dossier.answer.continuation';

    /**
     * TASK-1530 — les producteurs dont la reponse contient du CONTENU de
     * document : ce qui a ete lu dans un Dossier ou un Article, soumis a une
     * ACL et a une fraicheur. La liste sert a une seule chose — tenir ce
     * contenu hors de la memoire remise a une capability qui n'a pas droit aux
     * sources documentaires.
     */
    /**
     * TASK-1531 — le producteur d'un tour documentaire DECOUVERT, sans objet
     * courant ni objet deja discute. Distinct des deux autres : ni la page ni
     * le fil ne designaient ce Dossier, c'est la recherche qui l'a trouve.
     */
    public const PRODUCER_DOSSIER_DISCOVERY = 'dossier.answer.discovery';

    /**
     * TASK-1544 — le producteur d'une REFERENCE INDIRECTE resolue.
     *
     * « Le projet dont Marin parlait mardi » : le tour ne lit aucun document
     * et n'appelle aucun modele. Il rend ce que la provenance dit — quel
     * projet, etabli par qui, quand — ou la liste des projets possibles quand
     * la question en designe plusieurs.
     */
    public const PRODUCER_REFERENCE_RESOLUTION = 'reference.resolution';

    /**
     * TASK-1546 — le producteur de « Qui pourrait les aider ? ».
     *
     * Le tour ne lit aucun document et n'appelle aucun modele : il rend
     * l'ensemble que le serveur autorise, apparie au besoin du referent.
     */
    public const PRODUCER_PEOPLE_MATCHING = 'people.matching';

    /**
     * TASK-1546 — le producteur de « Et moi ? ».
     *
     * `moi` = l'utilisateur AUTHENTIFIE du tour, jamais une personne nommee
     * dans la phrase. Un Shell qui accepterait « et Marin ? » par ce chemin
     * rendrait le profil de quelqu'un d'autre sur une question personnelle.
     */
    public const PRODUCER_SELF_MATCHING = 'people.self';

    private const DOCUMENTARY_PRODUCERS = [
        'dossier.answer',
        'article.answer',
        self::PRODUCER_DOSSIER_CONTINUATION,
        self::PRODUCER_DOSSIER_DISCOVERY,
    ];

    /**
     * TASK-1531 — bornes de la decouverte, alignees sur celles du moteur
     * documentaire (`DossierInsightsService::ANSWER_SOURCE_LIMIT` /
     * `ANSWER_CANDIDATE_LIMIT`) : ce qui est CITE, et le bassin de candidats
     * lu en SQL. Un seul embedding de requete est calcule quel que soit le
     * nombre de candidats.
     */
    private const DISCOVERY_SOURCE_LIMIT = 5;

    private const DISCOVERY_CANDIDATE_LIMIT = 12;

    /**
     * TASK-1531 — mots de >= 4 lettres qui ne constituent PAS un sujet. Sans
     * cette courte liste, « comment allez vous ? » porterait « allez » et
     * « vous » comme sujets et declencherait le balayage de perimetre.
     *
     * Volontairement minuscule : elle n'a pas vocation a devenir une liste de
     * stopwords: chaque ajout doit venir d'un enonce reellement observe.
     *
     * @var list<string>
     */
    private const SUBJECTLESS_TOKENS = [
        // Les interrogatifs eux-memes : ils ouvrent la question, ils n'en sont
        // pas le sujet. Sans eux, « comment allez vous ? » porterait
        // « comment » comme sujet et declencherait le balayage.
        'comment', 'pourquoi', 'quand', 'combien', 'quel', 'quelle', 'quels', 'quelles',
        'quoi', 'what', 'which', 'when', 'where', 'whose', 'whom',
        // Pronoms, auxiliaires et mots-outils de >= 4 lettres.
        'allez', 'vous', 'nous', 'elle', 'elles', 'ils', 'cela', 'ceci', 'cette',
        'etes', 'etre', 'avez', 'avoir', 'faire', 'fait', 'peux', 'peut', 'pouvez',
        'this', 'that', 'these', 'those', 'your', 'you', 'they', 'there', 'here',
        'have', 'does', 'doing', 'been', 'being', 'were', 'will', 'would', 'could',
    ];

    /**
     * TASK-1358 — la langue dans laquelle le prompt administrable actif est
     * REDIGE, et donc celle que le modele adopte spontanement.
     *
     * ## Pourquoi une constante, et pas une lecture en base
     *
     * `admin_ai_prompts` ne DECLARE nulle part la langue de redaction de ses
     * versions : la colonne n'existe pas. Cette constante est donc une dette
     * ASSUMEE et NOMMEE, pas un oubli — le jour ou un prompt actif sera redige
     * dans une autre langue, ou ou la colonne existera, c'est ici que cela se
     * corrige, en un seul endroit.
     *
     * Elle sert a une seule chose : savoir quand la garde de langue est
     * NECESSAIRE. Locale identique, aucune ligne ajoutee, prompt octet-exact.
     */
    private const PROMPT_LANGUAGE = 'fr';

    /**
     * La cle de verrou d'un tour du Shell.
     *
     * `{organization}:{user}` : le Shell est personnel, il n'y a pas de
     * troisieme dimension. Deux onglets du meme utilisateur dans la meme
     * Organization sont UN tour ; deux utilisateurs ne se bloquent jamais ;
     * deux Organizations ne partagent jamais une cle, et cela se LIT dans la
     * cle plutot que de reposer sur l'unicite des UUID.
     */
    public static function lockKey(Organization $organization, User $user): string
    {
        return 'ai_shell_turn_lock:'.$organization->id.':'.$user->id;
    }

    /** Jamais sous le timeout provider + 30 s : meme regle que T1311. */
    public static function lockTtl(): int
    {
        return max(
            (int) config('ai.shell.lock_ttl', 90),
            (int) config('ai.shell.timeout', 30) + 30,
        );
    }

    public function __construct(
        private readonly ClarifyUserHelpRequestService $clarifier,
        private readonly AiShellThread $thread,
        private readonly AiShellTurnCards $cards,
        private readonly AiSelfKnowledge $selfKnowledge,
        private readonly AiShellUsageReference $usageReference,
        // TASK-1519 : la MEME primitive que la page Dossier, jamais un second
        // moteur documentaire.
        private readonly DossierInsightsService $dossierAnswers,
        private readonly ShellGeneralAnswerService $generalAnswers,
        // TASK-1531 : les trois primitives de la decouverte, dans l'ordre ou
        // elles doivent s'executer — le perimetre AUTORISE d'abord, la
        // recherche ensuite. Aucune n'est nouvelle : ce sont celles que
        // `DossierRetrievalSource` compose deja pour le chat de Boucle.
        private readonly DossierSemanticSearchGate $semanticSearchGate,
        private readonly DossierAccessScope $dossierAccessScope,
        private readonly DossierSemanticSearchService $dossierSearch,
        private readonly ProviderResolver $providers,
        // TASK-1534 : l'autorite qui dit quelles Boucles ce membre peut lire.
        // Elle borne la troisieme famille de chunk — la connaissance derivee.
        private readonly DerivedChunkEligibility $derivedEligibility,
        // TASK-1544 : la resolution d'une reference indirecte. Pas un Entity
        // Resolver : une jointure sur la provenance deja structuree.
        private readonly LoopReferenceResolver $references,
        // TASK-1546 : People + Self. La primitive de pertinence EXISTANTE
        // (People-2), qui consomme elle-meme l'eligibilite (People-1). Aucun
        // second moteur People : le Shell n'interroge jamais l'annuaire.
        private readonly RelevantPeopleService $people,
        // TASK-1546 : le besoin se DERIVE des enonces actifs du referent, par
        // la primitive qui les lit deja (T1540).
        private readonly ClaimMemory $claims,
    ) {}

    /**
     * @param  array<string, mixed>  $pageContext  contexte de page DEJA resolu et
     *                                             deja autorise — il est trace,
     *                                             jamais utilise comme un droit.
     * @param  string|null  $conversationId  identifiant deja affiche par la
     *                                       surface, utilise SI le fil est vide :
     *                                       le premier tour ne doit pas changer
     *                                       de conversation sous les yeux de
     *                                       l'utilisateur.
     * @param  list<array<string, mixed>>  $pinnedContext  contexte epingle (T1326),
     *                                                     DEJA re-resolu par
     *                                                     `AiShellPinnedContext::resolved()`
     *                                                     dans CETTE requete — meme
     *                                                     statut que le contexte de
     *                                                     page : un indice
     *                                                     d'intention trace, jamais
     *                                                     un droit.
     * @return array{trigger: AiShellMessage, answer: AiShellMessage}
     */
    public function respond(
        Organization $organization,
        User $user,
        string $prompt,
        array $pageContext,
        ?string $conversationId = null,
        array $pinnedContext = [],
    ): array {
        $prompt = trim($prompt);

        if ($prompt === '') {
            throw new DomainException('An empty prompt never reaches a provider.');
        }

        $prompt = Str::limit($prompt, (int) config('ai.shell.max_input_chars', 2000), '');

        return AiTurnLock::runOnKey(
            self::lockKey($organization, $user),
            self::lockTtl(),
            __('ai.shell_turn_in_progress'),
            function () use ($organization, $user, $prompt, $pageContext, $conversationId, $pinnedContext) {
                // TASK-1346 : la memoire du tour est prise ICI, et nulle part
                // ailleurs. Trois raisons cumulatives, toutes structurelles :
                //
                //  - AVANT `appendUser()` : sinon le message qu'on est en train
                //    d'envoyer entrerait dans son propre contexte, en doublon du
                //    `$prompt` que `situated()` place deja en fin de prompt ;
                //  - AVANT `appendUser()` : `AiShellThread::append()` elague le
                //    fil (`prune()`) une fois l'ecriture faite — lire apres, ce
                //    serait subir une troncature de STOCKAGE au lieu de la
                //    troncature de BUDGET decidee ci-dessous ;
                //  - DANS le verrou : `AiTurnLock` serialise les tours de ce
                //    couple (organization, user). Capturer avant lui, ce serait
                //    lire un fil qu'un tour concurrent peut encore ecrire.
                $memory = $this->conversationMemory($organization, $user);
                // Capture before appendUser, under the same turn lock. Old
                // general answers can encode a superseded capability contract.
                $generalMemory = $this->conversationMemory($organization, $user, generalContractHash: ShellGeneralAnswerService::contractHash());

                // TASK-1523 : la memoire DOCUMENTAIRE ne retient que les tours
                // tenus sur le MEME objet de page. Le fil est (organization,
                // user) : il suit la personne de page en page, et c'est voulu.
                // Mais mesure sur deux Dossiers reels d'un meme membre : un
                // fait repondu sur le Dossier A (« une boucle principale par
                // organisation ») etait restitue sur le Dossier B AVEC des
                // citations [S1][S2] dont les sources etaient B — une fausse
                // attribution, malgre l'etiquette « contexte, jamais une
                // source ». L'instruction ne tient pas ; la frontiere est donc
                // structurelle : chaque message porte deja
                // `metadata.page_context.object_id`, il suffit de filtrer.
                // Aucun second store, aucune migration.
                $documentaryMemory = $this->conversationMemory($organization, $user, $this->pageObjectKey($pageContext));

                // TASK-1530 : le candidat de CONTINUITE et sa memoire se
                // prennent ici, sous le meme verrou et pour les memes raisons
                // que ci-dessus (TASK-1346) — apres `appendUser()`, le fil
                // contiendrait deja le message courant et aurait ete elague.
                //
                // Les deux lectures ne se font que si la branche peut
                // reellement s'ouvrir : la coupe est locale, deterministe et
                // sans cout, autant ne pas payer deux requetes par tour pour
                // une branche qui ne s'executera pas.
                $continuationDossierId = null;
                $continuationMemory = '';

                if (! in_array($pageContext['kind'] ?? null, [AiShellPageContext::KIND_DOSSIER, AiShellPageContext::KIND_ARTICLE], true)
                    && $this->isDocumentaryContinuation($prompt)) {
                    $continuationDossierId = $this->recentDossierObjectId($organization, $user);

                    if ($continuationDossierId !== null) {
                        $continuationMemory = $this->conversationMemory(
                            $organization,
                            $user,
                            $this->objectKey(AiShellPageContext::KIND_DOSSIER, $continuationDossierId),
                        );

                    }
                }

                // Le message humain est ecrit AVANT l'appel : meme si la generation
                // echoue, l'utilisateur retrouve ce qu'il a demande dans son fil.
                $trigger = $this->thread->appendUser($organization, $user, $prompt, [
                    'page_context' => $this->traceable($pageContext),
                ] + $this->pinnedTrace($pinnedContext), $conversationId);

                // Idempotence (T1311) : un tour deja repondu ne se rejoue pas. La
                // contrainte UNIQUE sur `reply_to_id` fait foi en base ; cette
                // lecture evite simplement l'appel provider avant de s'y heurter.
                $existing = $this->thread->answerFor($trigger);

                if ($existing instanceof AiShellMessage) {
                    return ['trigger' => $trigger, 'answer' => $existing];
                }

                // TASK-1350 : la self-knowledge s'intercale ICI — apres
                // l'ecriture du message humain et apres l'idempotence, mais
                // AVANT `generate()`, donc avant `situated()` et avant tout
                // provider. Consequence mesurable : un tour de self-knowledge
                // n'ecrit ni `AiInteraction`, ni ligne de ledger, et ne
                // consomme aucun credit.
                [$content, $metadata] = $this->selfKnowledgeTurn($organization, $user, $prompt, $pageContext, $pinnedContext)
                    // TASK-1546 : AVANT les branches documentaires, et l'ordre
                    // est le sujet. « Et moi ? » pose sur une page Dossier
                    // serait sinon captee par `dossierAnswerTurn()`, qui
                    // repondrait avec des extraits de documents a une question
                    // qui n'en demande aucun. La garde est etroite — une forme
                    // locale ET un referent herite — donc rien d'autre ne
                    // change de chemin.
                    ?? $this->peopleTurn($organization, $user, $prompt, $pageContext, $pinnedContext)
                    ?? $this->dossierAnswerTurn($organization, $user, $prompt, $pageContext, $pinnedContext, $documentaryMemory)
                    ?? $this->articleAnswerTurn($organization, $user, $prompt, $pageContext, $pinnedContext, $documentaryMemory)
                    // TASK-1530 : entre l'objet COURANT et le chemin general.
                    // Voir le docblock de la branche pour l'ordre des roles.
                    ?? $this->dossierContinuationTurn($organization, $user, $prompt, $pageContext, $pinnedContext, $continuationDossierId, $continuationMemory)
                    // TASK-1531 : en dernier recours documentaire — ni objet
                    // courant, ni objet deja discute. Sa garde de declenchement
                    // s'execute avant tout balayage de perimetre.
                    // TASK-1544 : AVANT la decouverte documentaire, et c'est
                    // l'ordre qui compte. « Le projet dont Marin parlait » ne
                    // nomme pas son sujet : la recherche semantique y
                    // repondrait par le document le plus proche des mots
                    // « projet » et « parlait », c'est-a-dire n'importe quoi.
                    ?? $this->referenceResolutionTurn($organization, $user, $prompt, $pageContext, $pinnedContext)
                    ?? $this->dossierDiscoveryTurn($organization, $user, $prompt, $pageContext, $pinnedContext)
                    ?? $this->generalAnswerTurn($organization, $user, $prompt, $pageContext, $pinnedContext, $generalMemory)
                    ?? $this->generate($organization, $user, $prompt, $pageContext, $pinnedContext, $memory);

                $answer = $this->thread->appendAssistant($organization, $user, $content, $trigger, $metadata);

                return ['trigger' => $trigger, 'answer' => $answer];
            },
        );
    }

    /**
     * @param  array<string, mixed>  $pageContext
     * @param  list<array<string, mixed>>  $pinnedContext
     * @param  string  $memory  transcript deja borne, capture AVANT l'ecriture
     *                          du declencheur (TASK-1346)
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function generate(Organization $organization, User $user, string $prompt, array $pageContext, array $pinnedContext, string $memory = ''): array
    {
        try {
            $result = $this->clarifier->clarifyForOrganization($organization, $user, $this->situated($prompt, $pageContext, $pinnedContext, $memory));
        } catch (DomainException $exception) {
            report($exception);

            return [__('ai.shell_answer_unavailable'), [
                'status' => self::STATUS_UNAVAILABLE,
                'page_context' => $this->traceable($pageContext),
            ] + $this->pinnedTrace($pinnedContext)];
        }

        if ($result->isBlocked()) {
            return [
                (string) ($result->fallback['reason'] ?? __('ai.shell_answer_blocked')),
                [
                    'status' => self::STATUS_BLOCKED,
                    'producer' => $result->producer,
                    'page_context' => $this->traceable($pageContext),
                ] + $this->pinnedTrace($pinnedContext),
            ];
        }

        // Meme position que `RequestController::formulate()` : un repli
        // deterministe n'est pas une reponse de l'IA et ne se presente jamais
        // comme telle.
        //
        // TASK-1350 — mais il ne se presente plus non plus comme une panne
        // GENERALE de l'IA. Ce repli signifie exactement une chose : la
        // clarification generative n'etait pas utilisable pour cette
        // organisation a cet instant (desactivee, aucun credential tenant, ou
        // budget atteint). Il ne dit RIEN de ce que le Shell sait encore
        // faire — et le fil vient peut-etre de le prouver deux fois, avec des
        // reponses de self-knowledge. Annoncer « l'IA n'est pas disponible »
        // serait donc faux devant l'utilisateur.
        //
        // La phrase distingue ce qui reste offert de ce qui manque, et ne
        // nomme AUCUNE cause : le repli ne porte pas laquelle des trois s'est
        // produite, et inventer « non configuree » quand c'est le budget
        // serait remplacer un mensonge par un autre. Elle ne promet rien non
        // plus — pas meme la creation manuelle, dont ce point du code ne peut
        // pas garantir l'acces. Le statut, lui, ne bouge pas : aucune IA n'a
        // repondu.
        if ($result->producer === 'deterministic_fallback') {
            return [__('ai.shell_answer_request_preparation_unavailable'), [
                'status' => self::STATUS_UNAVAILABLE,
                'producer' => $result->producer,
                'page_context' => $this->traceable($pageContext),
            ] + $this->pinnedTrace($pinnedContext)];
        }

        // TASK-1350 — le verdict d'Interaction, lu ICI et nulle part ailleurs.
        //
        // `interactionFit` arrive DEJA arbitre par la version du prompt actif
        // (voir `ClarifyUserHelpRequestService::authoritativeInteractionFit()`) :
        // `null` signifie « aucune autorite » — prompt en v1/v2, version
        // inexploitable, champ absent ou non booleen — et se comporte donc
        // exactement comme avant TASK-1350. Seul `false` change quelque chose.
        //
        // Le test `=== false` est deliberement strict : il ne se declenche ni
        // sur `null`, ni sur une chaine vide, ni sur `0`. Le fail-open est
        // porte par l'operateur, pas par une convention de lecture.
        if ($result->interactionFit === false) {
            // TASK-1350 (direct_reply V1) — et ici, le Shell REPOND.
            //
            // Sans ce champ, une non-Interaction ne recevait qu'un message
            // canonique fige : « Quel temps fait-il a Marseille ? » se voyait
            // repondre un rappel sur l'entraide. Le Shell ne transformait plus
            // tout en demande, mais il ne repondait toujours a rien.
            //
            // La bulle porte donc desormais la parole du modele quand elle
            // existe, et le message canonique quand elle n'existe pas — champ
            // absent, vide, ou prompt sans autorite pour le produire. Le repli
            // n'est pas une precaution de style : un provider degrade ou un
            // schema mal honore ne doit jamais laisser une bulle vide.
            //
            // Ce que ce champ NE change pas : le statut, la metadata (toujours
            // bornee a status / producer / page_context / pins), l'absence de
            // titre, de brouillon, de carte, et l'impossibilite de
            // `prepareRequest()`. Une reponse conversationnelle n'est pas une
            // demande, et n'en devient pas une.
            $reply = trim((string) $result->directReply);

            return [$reply !== '' ? $reply : __('ai.shell_answer_non_interaction'), [
                'status' => self::STATUS_NON_INTERACTION,
                'producer' => $result->producer,
                'page_context' => $this->traceable($pageContext),
            ] + $this->pinnedTrace($pinnedContext)];
        }

        $content = trim((string) ($result->need !== '' ? $result->need : $result->title));

        if ($content === '') {
            $content = __('ai.shell_answer_unavailable');
        }

        return [$content, [
            'status' => self::STATUS_ANSWERED,
            // TASK-1350 : l'intention telle que la clarification l'a qualifiee
            // — `offer` quand le membre PROPOSE son aide, `help_request`
            // sinon. Une offre ne se prepare pas en demande : la cle est lue
            // par `AiShell::prepareRequest()` et par les cartes du tour. Son
            // ABSENCE (tours anterieurs a TASK-1350) vaut « demande », donc le
            // fil deja ecrit se relit inchange.
            'intent' => $result->intent,
            'producer' => $result->producer,
            'scenario' => $result->scenario,
            'confidence' => $result->confidence,
            'title' => $result->title,
            'context' => $result->context,
            'expected_help_type' => $result->expectedHelpType,
            'message_draft' => $result->messageDraft,
            // TASK-1392 : les questions de clarification, quand le modele en
            // pose.
            //
            // Elles existaient deja de bout en bout — le schema de
            // `HelpRequestClarifierAgent` declare `questions_for_user`, et
            // `ClarifyUserHelpRequestService` les publie dans
            // `fallback['questions']`. La surface LOOP les affiche. Le Shell,
            // lui, ne les transportait pas jusqu'a sa vue : la donnee
            // s'arretait ici, et aucune condition d'affichage n'existait
            // en aval parce qu'il n'y avait rien a afficher.
            //
            // Le membre voyait donc un brouillon redige a la premiere
            // personne, presente comme compris, alors que le modele venait de
            // dire qu'il avait besoin de precisions. La question posee etait
            // perdue, et l'assurance affichee etait fausse.
            'clarification_questions' => array_values(array_filter(
                (array) ($result->fallback['questions'] ?? []),
                static fn ($question): bool => is_string($question) && trim($question) !== '',
            )),
            // On ne garde que l'IDENTIFIANT : le libelle et l'URL sont
            // re-resolus a l'affichage, sous la garde de la page. Un fil relu
            // demain ne peut donc pas exposer une Boucle qu'on a quittee.
            'suggested_loop_id' => is_array($result->suggestedLoop) ? ($result->suggestedLoop['id'] ?? null) : null,
            'suggested_category' => $result->suggestedCategory,
            // TASK-1325 (Shell-1) : les cartes du tour — des references
            // (identifiants + faits verifies a cet instant), re-resolues et
            // re-autorisees a CHAQUE affichage. Jamais un droit.
            'cards' => $this->cards->forAnsweredTurn(
                $organization,
                $user,
                $result->suggestedLoop,
                $pageContext,
                trim($prompt."\n".$result->need),
                $result->intent,
            ),
            'page_context' => $this->traceable($pageContext),
            // TASK-1486 — le POINTEUR vers la trace de ce tour, pour qu'un
            // verdict humain puisse designer CETTE reponse.
            //
            // `ai_interaction_feedbacks` existe depuis TASK-1256, avec ses deux
            // verdicts et son ancrage tenant. Il n'etait branche qu'au blog
            // explorer — 26 interactions sur 281 — parce que lui seul avait
            // l'identifiant de sa trace : son controleur cree l'`AiInteraction`
            // lui-meme. Le Shell, la surface la plus utilisee du produit
            // (87 interactions), n'avait aucun moyen de savoir si sa reponse
            // avait aide.
            //
            // Il n'est pose QUE sur le tour REPONDU. Les autres statuts —
            // indisponible, bloque, hors Interaction — n'ont produit aucun
            // appel provider, donc aucune trace : proposer un verdict y
            // designerait le vide.
            //
            // Ce que cette cle N'EST PAS, et la borne de metadata documentee
            // plus haut le dit deja : ni un titre, ni un brouillon, ni une
            // carte, ni une intention. Une reponse conversationnelle n'est pas
            // une demande, et n'en devient pas une parce qu'elle sait d'ou elle
            // vient. C'est une reference de trace, deja bornee au tenant et
            // deja soumise a `UserDataLifecycleRegistry`.
            'ai_interaction_id' => $result->interactionId,
        ] + $this->pinnedTrace($pinnedContext)];
    }

    /**
     * La question, situee. Le contexte de page et le contexte epingle sont des
     * INDICES d'intention donnes au modele — jamais un droit : les seules
     * donnees qui entrent dans le prompt sont celles que `ContextBuilder`
     * accepte de composer pour cet utilisateur, et les NOMS d'objets que
     * l'utilisateur a deja sous les yeux (la page courante, et la liste de
     * pins affichee dans le Shell — T1326 : ce qui est injecte est EXACTEMENT
     * ce que l'utilisateur voit epingle, rien de cache).
     *
     * TASK-1346 : le transcript de la conversation COURANTE s'ajoute ici, en
     * UN SEUL bloc delimite, entre le contexte et la question. Il n'est pas
     * davantage un droit que les deux autres : il ne contient QUE ce que cet
     * utilisateur a deja sous les yeux dans son propre fil, borne et
     * chronologique.
     *
     * TASK-1350 (P0) : et quand ce transcript existe, la question courante est
     * ETIQUETEE. Le transcript est un arriere-plan ; le tour courant est
     * l'objet. Dit autrement, et c'est la seule hierarchie que ce prompt
     * etablit : CURRENT TURN > TRANSCRIPT MEMORY.
     *
     * TASK-1358 : et la reponse sort dans la langue de l'INTERFACE, pas dans
     * celle du prompt administrable. Voir `PROMPT_LANGUAGE` ci-dessus.
     *
     * @param  array<string, mixed>  $pageContext
     * @param  list<array<string, mixed>>  $pinnedContext
     * @param  string  $memory  transcript deja borne par `conversationMemory()`
     */
    private function situated(string $prompt, array $pageContext, array $pinnedContext = [], string $memory = ''): string
    {
        $lines = [];

        // TASK-1358 — LA LANGUE, EN TETE.
        //
        // Un champ structure herite de la langue de sa MATIERE : un brouillon
        // de demande recopie les mots de l'utilisateur, donc il sortait deja
        // en anglais. Une reponse conversationnelle libre, elle, herite de la
        // langue de ses INSTRUCTIONS — et le prompt administrable actif est
        // redige en francais. D'ou le defaut mesure sur `artscilab-en` : « I am
        // new here. What can I do? » recevait une reponse en francais.
        //
        // La ligne est posee AVANT le lieu et les pins, pour qu'un transcript
        // long ne puisse jamais la repousser hors du budget du modele.
        //
        // Elle n'est posee QUE si la locale differe de la langue de redaction
        // du prompt (arbitrage MASTER : ONLY_IF_DIFFERENT). En francais, le
        // prompt reste donc OCTET-EXACT, et l'invariant de fil vide de
        // TASK-1346 continue de passer sans etre modifie.
        if (app()->getLocale() !== self::PROMPT_LANGUAGE) {
            $lines[] = __('ai.shell_prompt_language_guard');
        }

        $object = $pageContext['object'] ?? null;

        if (is_array($object) && isset($object['label'])) {
            // TASK-1359 : le nom d'un objet est ecrit par un MEMBRE, et la
            // colonne qui le porte accepte 255 caracteres. Il etait injecte ici
            // sans borne, alors que les libelles d'epingles quinze lignes plus
            // bas etaient tronques a 120 depuis T1326. Meme nature, meme borne :
            // un nom est cite, il n'est pas un canal d'instructions.
            $name = Str::limit(trim((string) $object['label']), 120, '…');

            $where = match ($object['type'] ?? '') {
                'loop' => __('ai.shell_prompt_where_loop', ['name' => $name]),
                'dossier' => __('ai.shell_prompt_where_dossier', ['name' => $name]),
                'article' => __('ai.shell_prompt_where_article', ['name' => $name]),
                default => null,
            };

            if ($where !== null) {
                $lines[] = $where;
            }
        } elseif (($pageContext['kind'] ?? null) === 'dashboard') {
            // TASK-1359 — la ligne de lieu des pages SANS objet gouverne.
            //
            // Le prompt administrable actif dit deja au modele : « Tu peux
            // t'appuyer sur la page ou se trouve le membre si elle t'est
            // indiquee. » Elle ne l'etait jamais hors Boucle/Dossier/Article :
            // le modele etait instruit d'utiliser une indication que le code ne
            // fournissait pas.
            //
            // Ce qui entre est une CLE DE LANGUE STATIQUE, choisie par un
            // `kind` deja resolu et deja garde. Jamais une URL, un chemin, une
            // query string, un slug ni un parametre de route : un identifiant
            // non garde n'a rien a faire dans un prompt, et c'est exactement ce
            // que toute cette architecture existe pour tenir dehors.
            $lines[] = __('ai.shell_prompt_where_dashboard');
        }

        // TASK-1484 — CE QUE CE LIEU EST, apres OU il est.
        //
        // La ligne de lieu ci-dessus nomme la surface ; elle ne l'explique pas.
        // Hors Boucle / Dossier / Article / tableau de bord, elle ne dit meme
        // rien du tout — et sur ces pages-la (agenda, annuaire, echanges,
        // dossiers, blog, profil) le modele repondait depuis le seul prompt
        // administrable generique. « C'est quoi cette page ? » sur l'agenda
        // rendait une reponse sur les categories et les boucles.
        //
        // La couche qui explique un lieu EXISTAIT — UsageReference, ecrite et
        // publiee par un humain — mais cote membre elle n'etait que RECITEE a
        // l'ecran, jamais donnee au modele. Le produit avait exactement
        // l'inverse de ce qu'il fallait ; cette ligne echange les deux.
        //
        // La surface vient de `$pageContext`, resolue par `AiShellPageContext`.
        // Elle n'est PAS re-derivee ici : une seconde derivation serait une
        // seconde autorite, et l'invariant de fil vide de TASK-1346 — dont le
        // contexte ne porte aucune cle `surface` — cesserait de tenir.
        $surface = $pageContext['surface'] ?? null;

        if (is_string($surface) && $surface !== '') {
            $grounding = $this->usageReference->groundingFor($surface, app()->getLocale());

            if ($grounding !== null) {
                $lines[] = $grounding;
            }
        }

        // Le budget est double : `max_pins` borne la liste, et chaque libelle
        // est tronque — un nom d'objet n'est jamais un canal de contenu.
        $items = [];

        foreach ($pinnedContext as $pin) {
            $name = Str::limit(trim((string) ($pin['label'] ?? '')), 120, '…');

            if ($name === '') {
                continue;
            }

            $item = match ($pin['kind'] ?? null) {
                'loop' => __('ai.shell_prompt_pinned_loop', ['name' => $name]),
                'dossier' => __('ai.shell_prompt_pinned_dossier', ['name' => $name]),
                'article' => __('ai.shell_prompt_pinned_article', ['name' => $name]),
                default => null,
            };

            if ($item !== null) {
                $items[] = $item;
            }
        }

        if ($items !== []) {
            $lines[] = __('ai.shell_prompt_pinned', ['items' => implode(' ; ', $items)]);
        }

        // Le transcript vient APRES le lieu et les pins, et AVANT la question :
        // le modele lit d'abord ou l'on est, puis ce qui s'est dit, puis ce
        // qu'on lui demande.
        if ($memory !== '') {
            $lines[] = $memory;

            // TASK-1350 (P0) — l'ETIQUETTE DU TOUR COURANT.
            //
            // Sans elle, le prompt presentait au modele une suite de textes de
            // meme nature — transcript, anciens brouillons a la premiere
            // personne, puis la question — sans dire lequel il devait traiter.
            // Constate en runtime : « Quel temps fait-il a Marseille ? » a
            // rendu, mot pour mot, le brouillon du tour precedent. Deux
            // lignes DISTINCTES en base, quelques secondes d'ecart, contenu
            // identique : le modele n'avait pas rejoue, il avait choisi le
            // mauvais objet.
            //
            // L'etiquette ne s'ajoute QUE lorsqu'un transcript precede. Sur un
            // fil vide il n'y a rien a departager, et le prompt reste alors
            // exactement celui d'avant TASK-1346 — invariant que la suite de
            // T1346 verifie a l'octet pres.
            $lines[] = __('ai.shell_prompt_current_turn');
        }

        return $lines === [] ? $prompt : implode("\n", [...$lines, $prompt]);
    }

    /**
     * Le fil de la conversation COURANTE, borne, pret a etre injecte.
     *
     * ## Ce que cette methode n'est pas
     *
     * Ni un resume, ni une memoire longue, ni une memoire d'une conversation a
     * l'autre. Elle relit un fil que l'utilisateur a DEJA sous les yeux, dans
     * la conversation qu'il a DEJA ouverte, et le tronque. Un fil efface
     * (`AiShellThread::clear()`) ouvre une nouvelle conversation : il n'a donc
     * plus aucune memoire, et c'est le comportement attendu.
     *
     * ## Isolation
     *
     * Aucune requete `AiShellMessage` n'est ecrite ici : la lecture passe par
     * `AiShellThread::messages()`, porte unique, qui scope d'abord par
     * `(organization_id, user_id)` PUIS par conversation. Organization =
     * Tenant ; le Shell n'a pas de dimension Boucle et cette methode n'en
     * invente aucune.
     *
     * ## Bornes
     *
     * Deux, cumulatives : le nombre de messages relus (`AiShellThread::limit()`,
     * la fenetre que la surface affiche deja) et `ai.shell.max_context_chars`.
     * La coupe se fait du PLUS ANCIEN vers le plus recent — on remonte le fil a
     * rebours et on s'arrete des que le budget est atteint — parce que le tour
     * le plus recent est celui que la question prolonge. Idiome repris tel quel
     * de {@see AiConversationContextBuilder}, prefixes compris : les deux blocs
     * de memoire du produit se lisent de la meme facon pour le modele.
     */
    private function conversationMemory(Organization $organization, User $user, ?string $onlyObjectKey = null, ?string $generalContractHash = null): string
    {
        $conversationId = $this->thread->persistedConversationId($organization, $user);

        if ($conversationId === null) {
            return '';
        }

        $budget = max(0, (int) config('ai.shell.max_context_chars', 4000));

        if ($budget === 0) {
            return '';
        }

        $messages = $this->thread->messages($organization, $user, $conversationId);

        $lines = [];
        $total = 0;
        // Un seul controle de droits par objet distinct du fil, pas un par
        // message : la fenetre est bornee, les objets y sont peu nombreux.
        $visibilityMemo = [];

        // A rebours : le plus recent d'abord, pour que ce soit le plus ANCIEN
        // qui tombe quand le budget est atteint.
        foreach ($messages->reverse() as $message) {
            if (! $this->remembered($message)) {
                continue;
            }

            // TASK-1523 : en memoire documentaire, un tour tenu sur un AUTRE
            // objet (ou sur aucun) n'entre pas. Le filtre lit ce que le
            // serveur a ecrit lui-meme a l'aller (`traceable()`), jamais une
            // donnee venue du client. TASK-1524 (audit OPUS) : la cle est le
            // COUPLE (object_type, object_id) — un Dossier et un Article ne
            // sont jamais « le meme objet », quel que soit leur identifiant —
            // et un message dont la page ne prouve pas l'objet est exclu.
            if ($onlyObjectKey !== null && $this->pageObjectKeyOf($message) !== $onlyObjectKey) {
                continue;
            }

            // TASK-1530 — un tour documentaire dont l'objet n'est PLUS visible
            // ne repart vers aucun fournisseur.
            //
            // Mesure qui a impose cette garde : un Dossier repond « les
            // partenaires sont X et Y », l'acces au Dossier est retire, la
            // continuation est correctement refusee — et le repli general
            // recevait quand meme X et Y dans son bloc conversation. Le filtre
            // de contrat T1528 ne pouvait rien y faire : il ne retire que les
            // anciennes reponses GENERALES, et un tour `dossier.answer` n'en
            // est pas une. La memoire cessait d'etre un signal pour devenir un
            // canal de fuite APRES revocation.
            //
            // La relecture des droits se fait donc au tour COURANT, comme
            // partout ailleurs : ce qui n'est plus lisible n'est plus racontable.
            if (! $this->documentaryTurnStillVisible($user, $message, $visibilityMemo)) {
                continue;
            }

            // TASK-1530 — aucune reponse DOCUMENTAIRE n'entre dans la memoire
            // remise a la capability generale.
            //
            // `shell_general_answer` declare `allowedSources:
            // [SOURCE_PRODUCT_SURFACES]` : aucune source documentaire. Le
            // contrat etait pourtant contourne sans qu'aucune garde ne le voie
            // passer — pas par les sources, par la MEMOIRE. Mesure : un Dossier
            // repond « les partenaires sont X et Y » ; la question suivante,
            // generale, partait au fournisseur general avec X et Y dans son
            // bloc conversation. Le filtre de contrat T1528 ne pouvait rien y
            // faire : il ne retire que les anciennes reponses GENERALES.
            //
            // Trois situations, une seule cause, donc une seule garde : acces
            // revoque, retrieval frais vide, ou simple question generale — dans
            // les trois cas un fait documentaire servait de contexte a une
            // reponse sans source ni retrieval.
            //
            // Seules les reponses de l'ASSISTANT sont concernees. Les messages
            // de la personne restent : c'est sa conversation, ce sont ses mots,
            // et la continuite du dialogue general n'est pas entamee.
            if ($generalContractHash !== null
                && $message->role === AiShellMessage::ROLE_ASSISTANT
                && in_array($message->metadata['producer'] ?? null, self::DOCUMENTARY_PRODUCERS, true)) {
                continue;
            }

            // TASK-1528: a past general answer is dialogue data, not an
            // authority on the current capability. Do not teach the provider
            // obsolete refusals after a contract change. Keep all member input
            // and current-contract answers; the stored conversation is intact.
            if ($generalContractHash !== null
                && $message->role === AiShellMessage::ROLE_ASSISTANT
                && ($message->metadata['producer'] ?? null) === ShellGeneralAnswerService::PRODUCER
                && ($message->metadata['general_contract_hash'] ?? null) !== $generalContractHash) {
                continue;
            }

            $body = trim((string) $message->content);

            if ($body === '') {
                continue;
            }

            $line = ($message->role === AiShellMessage::ROLE_ASSISTANT ? 'Assistant : ' : 'Membre : ').$body;

            if ($lines === []) {
                // Le tour le plus recent est conserve meme s'il excede a lui
                // seul le budget : il est tronque, jamais supprime.
                $line = mb_substr($line, 0, $budget);
                $lines[] = $line;
                $total = mb_strlen($line);

                continue;
            }

            if ($total + mb_strlen($line) + 1 > $budget) {
                break;
            }

            $lines[] = $line;
            $total += mb_strlen($line) + 1;
        }

        if ($lines === []) {
            return '';
        }

        return "Echange precedent dans cette conversation :\n".implode("\n", array_reverse($lines));
    }

    /**
     * Ce qui merite d'entrer dans la memoire du tour.
     *
     * Tout ce que la personne a ecrit ; et, de l'assistant, uniquement ce qui
     * est une VRAIE reponse. Une indisponibilite (`STATUS_UNAVAILABLE`) ou un
     * refus (`STATUS_BLOCKED`) est une reponse TECHNIQUE : la reinjecter
     * apprendrait au modele a se citer en echec, alors qu'elle ne dit rien de
     * ce dont on parle.
     */
    private function remembered(AiShellMessage $message): bool
    {
        if ($message->role !== AiShellMessage::ROLE_ASSISTANT) {
            return true;
        }

        $metadata = is_array($message->metadata) ? $message->metadata : [];

        // TASK-1350 : `STATUS_NON_INTERACTION` entre dans la memoire, au
        // contraire de `STATUS_UNAVAILABLE` et de `STATUS_BLOCKED`. La
        // difference n'est pas de statut, elle est de NATURE : une
        // indisponibilite ou un refus est une reponse technique, qui ne dit
        // rien du sujet ; une non-Interaction est une VRAIE reponse — le
        // message canonique, ou une reponse de self-knowledge. L'exclure
        // laisserait le modele lire « Membre : c'est quoi une Boucle ? » sans
        // jamais voir ce qui a ete repondu, et la conversation perdrait son
        // fil au tour suivant.
        return in_array(
            $metadata['status'] ?? null,
            [self::STATUS_ANSWERED, self::STATUS_NON_INTERACTION],
            true,
        );
    }

    /**
     * TASK-1350 — le tour de self-knowledge, ou `null` quand l'enonce n'en est
     * pas un (le cas de l'immense majorite des tours).
     *
     * ## Pourquoi c'est un tour NON_INTERACTION
     *
     * Parce que c'en est un, litteralement : « C'est quoi une Boucle ? » n'est
     * pas une demande qu'un autre membre pourrait honorer. Le statut apporte
     * gratuitement toutes les gardes voulues — pas de titre, pas de brouillon,
     * pas de carte, `prepareRequest()` impossible — sans qu'on ait a les
     * reecrire. Seul le `producer` distingue les deux chemins dans la trace.
     *
     * ## Pourquoi tout est capture
     *
     * Le catalogue lit des drapeaux de tenant et resout des routes ; la
     * Constitution lit une table. Une source qui manque, une route absente, une
     * base momentanement indisponible ne doivent pas transformer une question
     * en erreur 500 : on retombe alors sur le provider legacy, qui est
     * exactement ce qui se passait avant TASK-1350. Le fail-open est ici un
     * `catch (\Throwable)` assume, pas une negligence — et l'exception est
     * rapportee pour rester diagnosticable.
     *
     * @param  array<string, mixed>  $pageContext
     * @param  list<array<string, mixed>>  $pinnedContext
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function selfKnowledgeTurn(
        Organization $organization,
        User $user,
        string $prompt,
        array $pageContext,
        array $pinnedContext,
    ): ?array {
        try {
            $topic = $this->selfKnowledge->topicFor($prompt);

            if ($topic === null) {
                return null;
            }

            // TASK-1359 : le contexte de page etait DEJA resolu ici, et n'etait
            // pas transmis. « Que puis-je faire ici ? » etait donc repondu
            // comme « que puis-je faire dans cette organisation ».
            $content = trim($this->selfKnowledge->answer($topic, $organization, $user, $pageContext));

            // Une reponse vide n'est pas une reponse : mieux vaut le provider.
            if ($content === '') {
                return null;
            }
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }

        return [$content, [
            'status' => self::STATUS_NON_INTERACTION,
            'producer' => AiSelfKnowledge::PRODUCER,
            'page_context' => $this->traceable($pageContext),
        ] + $this->pinnedTrace($pinnedContext)];
    }

    /**
     * TASK-1519 — le Dossier COURANT devient le perimetre documentaire.
     *
     * Branche PRE-PROVIDER, au meme endroit et sur le meme patron que
     * `selfKnowledgeTurn()` : elle s'intercale avant `generate()`, donc avant
     * `clarify_help_request`. C'est tout le sujet du CDC — le Shell cessait de
     * router chaque question vers la clarification d'entraide, et repondait
     * « je ne peux pas lire les fichiers » sur un Dossier qu'il avait sous les
     * yeux.
     *
     * ## Aucune capability elargie
     *
     * `dossier.retrieval` n'est PAS ajoute aux `allowedSources` de
     * `clarify_help_request` — ce serait ouvrir le corpus a toutes les
     * questions du produit. Cette branche delegue a la primitive deja validee
     * en Phase 1, `DossierInsightsService::answer()`, qui porte ses propres
     * gardes.
     *
     * ## Le contexte de page n'est pas un droit
     *
     * `AiShellPageContext` a deja joue la policy pour construire ce contexte.
     * Elle est REJOUEE ici quand meme, et ce n'est pas une redondance : le
     * contexte est une trace de tour, il peut venir d'un etat anterieur, et
     * `answer()` sera appelee avec un Dossier. Un identifiant persistant ne
     * doit jamais devenir une autorite d'acces. `answer()` revalide une
     * troisieme fois, cote service — c'est ce que TASK-1516 a rendu testable.
     *
     * ## Les pins restent dehors
     *
     * Aucun union automatique entre Dossier courant et contextes epingles : le
     * CDC place `AiShellPinnedContext` hors du P0, et un elargissement
     * silencieux du perimetre documentaire serait exactement ce qu'un Shell ne
     * doit pas faire. Ils restent traces, comme pour tout autre tour.
     *
     * Un echec quelconque rend `null` : le tour retombe sur le chemin
     * habituel, jamais sur une erreur affichee.
     *
     * @param  array<string, mixed>  $pageContext
     * @param  list<array<string, mixed>>  $pinnedContext
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function dossierAnswerTurn(
        Organization $organization,
        User $user,
        string $prompt,
        array $pageContext,
        array $pinnedContext,
        string $memory,
    ): ?array {
        if (($pageContext['kind'] ?? null) !== AiShellPageContext::KIND_DOSSIER) {
            return null;
        }

        $objectId = $pageContext['object']['id'] ?? null;

        if (! is_string($objectId) || $objectId === '') {
            return null;
        }

        $dossier = Dossier::query()->find($objectId);

        if (! $dossier instanceof Dossier || $user->cannot('view', $dossier)) {
            return null;
        }

        try {
            $answer = $this->dossierAnswers->answer($organization, $dossier, $user, $prompt, null, $memory);
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }

        // La branche documentaire ne parle QUE si elle a des documents.
        //
        // Sur un Dossier sans contenu indexe, `answer()` rend un refus honnete
        // — parfaitement juste SUR LA PAGE, ou la question est forcement
        // documentaire. Dans le Shell, la question peut porter sur tout autre
        // chose (« comment je partage ce Dossier ? ») : repondre « je n'ai rien
        // trouve dans ce Dossier » serait moins utile que le chemin habituel.
        // On s'efface donc, et le tour suit son cours normal.
        if ($answer->consulted === []) {
            return null;
        }

        $content = trim($answer->answer);

        if ($content === '') {
            return null;
        }

        return [$content, [
            'status' => self::STATUS_NON_INTERACTION,
            'producer' => 'dossier.answer',
            'page_context' => $this->traceable($pageContext),
            // TASK-1325 : un tour repondu porte ses cartes, quel que soit le
            // chemin qui l'a produit. Sans cette ligne, une reponse
            // documentaire perdait la reference de document que TOUT autre tour
            // sur cette page portait — la CI l'a vu, pas moi.
            'cards' => $this->cards->forAnsweredTurn($organization, $user, null, $pageContext, $prompt),
            'grounded' => $answer->grounded,
            'sources' => array_map(KnowledgeAnswer::publicSource(...), $answer->sources),
            'follow_up_questions' => $answer->followUps,
            // TASK-1486 : le MEME pointeur que le chemin habituel — ce tour est
            // un tour REPONDU, et un verdict humain doit pouvoir le designer.
            'ai_interaction_id' => $answer->interactionId,
        ] + $this->pinnedTrace($pinnedContext)];
    }

    /**
     * TASK-1520 — l'ARTICLE courant devient lisible par le moteur documentaire.
     *
     * Meme patron que `dossierAnswerTurn()`, et volontairement pas le meme
     * code : ce qui se replique ici est la FORME — branche pre-provider,
     * conditionnee au `PageContext`, policy rejouee, delegation au moteur
     * existant — pas une copie.
     *
     * ## Ce qui differe d'un Dossier, et pourquoi
     *
     * Un Article n'a pas de corpus a fouiller : il EST le document. Aucune
     * recherche vectorielle, aucun embedding, aucun appel de retrieval — le
     * texte deja autorise devient l'unique source, et le moteur repond dessus.
     * C'est le sens de `answerOverSources()` : l'appelant choisit les sources
     * et repond de leur perimetre.
     *
     * ## La garde des Articles prives de Boucle
     *
     * `AiShellPageContext::articleSubject()` la joue deja — Organization,
     * publication ou qualite d'auteur, et appartenance a la Boucle quand
     * l'Article est le manifeste d'une Boucle PRIVEE. Elle est REJOUEE ici par
     * le meme chemin, parce qu'un contexte de page est une trace de tour et
     * peut venir d'un etat anterieur.
     *
     * ## Aucune capability elargie
     *
     * `blog.post` n'est ajoute a rien. Le tour emprunte la capability
     * documentaire deja utilisee par le Dossier, avec l'Article pour seule
     * source.
     *
     * @param  array<string, mixed>  $pageContext
     * @param  list<array<string, mixed>>  $pinnedContext
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function articleAnswerTurn(
        Organization $organization,
        User $user,
        string $prompt,
        array $pageContext,
        array $pinnedContext,
        string $memory,
    ): ?array {
        if (($pageContext['kind'] ?? null) !== AiShellPageContext::KIND_ARTICLE) {
            return null;
        }

        $objectId = $pageContext['object']['id'] ?? null;

        if (! is_string($objectId) || $objectId === '') {
            return null;
        }

        // La MEME garde que la resolution de page, rejouee — jamais un
        // `BlogPost::find()` nu sur un identifiant persistant.
        $sujet = app(AiShellPageContext::class)->resolve(
            $user,
            $organization,
            AiShellPageContext::KIND_ARTICLE,
            $objectId,
        );

        if (($sujet['object']['id'] ?? null) !== $objectId) {
            return null;
        }

        $post = BlogPost::query()->find($objectId);

        if (! $post instanceof BlogPost) {
            return null;
        }

        $texte = trim(strip_tags((string) $post->content));

        if ($texte === '') {
            return null;
        }

        // Le Dossier de rattachement sert de porte-trace, pas de perimetre :
        // les sources sont donnees, pas cherchees. Sans Dossier lie, la branche
        // s'efface plutot que d'inventer un rattachement.
        $dossierId = DB::table('dossier_blog_posts')
            ->where('organization_id', $organization->getKey())
            ->where('blog_post_id', $post->getKey())
            ->value('dossier_id');

        $dossier = is_string($dossierId) ? Dossier::query()->find($dossierId) : null;

        if (! $dossier instanceof Dossier) {
            return null;
        }

        try {
            $answer = $this->dossierAnswers->answerOverSources(
                $organization,
                $dossier,
                $user,
                $prompt,
                [[
                    'chunk_id' => (string) $post->getKey(),
                    'dossier_id' => (string) $dossier->getKey(),
                    'dossier_name' => (string) $dossier->name,
                    'source_type' => 'article',
                    'blog_post_id' => (string) $post->getKey(),
                    'title' => (string) $post->title,
                    'slug' => (string) $post->slug,
                    'dossier_file_id' => null,
                    'filename' => null,
                    'mime_type' => null,
                    'chunk_index' => 0,
                    'content' => $texte,
                    'distance' => null,
                ]],
                $memory,
            );
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }

        $content = trim($answer->answer);

        if ($content === '') {
            return null;
        }

        return [$content, [
            'status' => self::STATUS_NON_INTERACTION,
            'producer' => 'article.answer',
            'page_context' => $this->traceable($pageContext),
            'cards' => $this->cards->forAnsweredTurn($organization, $user, null, $pageContext, $prompt),
            'grounded' => $answer->grounded,
            'sources' => array_map(KnowledgeAnswer::publicSource(...), $answer->sources),
            'follow_up_questions' => $answer->followUps,
            'ai_interaction_id' => $answer->interactionId,
        ] + $this->pinnedTrace($pinnedContext)];
    }

    /**
     * TASK-1530 — la continuite documentaire survit a la navigation.
     *
     * Mesure du defaut ferme ici : sur la page du Dossier ARIA, « c'est quoi
     * ARIA ? » repondait avec des sources. L'utilisateur changeait de page, et
     * « qui sont les partenaires ? » tombait sur `shell_general_answer` — le
     * Shell se souvenait SEMANTIQUEMENT d'ARIA (le fil suit la personne de page
     * en page, T1523) mais avait perdu son AUTORITE documentaire : plus aucun
     * retrieval, donc une reponse de culture generale sur un sujet interne.
     *
     * ## La regle canonique, et l'ordre des roles
     *
     * La MEMOIRE aide a retrouver l'objet — elle ne prouve rien. La POLICY
     * decide s'il est encore accessible. Le RETRIEVAL FRAIS fournit les faits.
     * Les SOURCES FRAICHES prouvent la reponse. Aucune de ces quatre etapes
     * n'est facultative, et aucune ne peut en remplacer une autre : c'est
     * exactement pourquoi cette branche ne se contente pas de relire ce que le
     * fil contient deja.
     *
     * ## Pourquoi ICI dans la chaine
     *
     * Ni plus haut, ni plus bas, et ce n'est pas une commodite :
     *
     *  - APRES `dossierAnswerTurn()` / `articleAnswerTurn()` : un objet
     *    REELLEMENT sous les yeux reste l'autorite prioritaire. Cette branche
     *    exige donc que la page courante ne soit ni un Dossier ni un Article ;
     *  - AVANT `generalAnswerTurn()` : « qui sont les partenaires ? » EST une
     *    question, donc `isGeneralQuestion()` la capturerait la premiere et la
     *    branche ne s'executerait jamais. Ce placement est ce qui ferme le cas
     *    reel ;
     *  - l'intention d'ENTRAIDE reste protegee en amont de tout cela, par la
     *    meme table de marqueurs que le chemin general
     *    (`mentionsInteractionIntent()`, consultee par
     *    `isDocumentaryContinuation()`) : une ancienne page Dossier ne vole
     *    jamais « quelqu'un peut m'aider ? ».
     *
     * ## Un identifiant retrouve n'est jamais un droit
     *
     * L'identifiant vient des metadata SERVEUR du fil, jamais du client. Il est
     * ensuite revalide integralement, au tour courant : tenant d'abord, puis
     * `DossierPolicy::view` — et `answer()` revalide une troisieme fois. Un
     * Dossier devenu inaccessible, supprime, ou appartenant a une autre
     * Organization s'efface SILENCIEUSEMENT : `null`, le tour suit son cours,
     * aucun titre prive, aucun contenu, aucun appel provider documentaire.
     *
     * @param  array<string, mixed>  $pageContext
     * @param  list<array<string, mixed>>  $pinnedContext
     * @param  string  $memory  memoire documentaire du Dossier RETROUVE, captee
     *                          avant l'ecriture du declencheur (TASK-1346)
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function dossierContinuationTurn(
        Organization $organization,
        User $user,
        string $prompt,
        array $pageContext,
        array $pinnedContext,
        ?string $dossierId,
        string $memory,
    ): ?array {
        if ($dossierId === null) {
            return null;
        }

        // Un objet courant garde la main : on ne reprend un Dossier ancien que
        // depuis une page qui n'est elle-meme aucune autorite documentaire.
        if (in_array($pageContext['kind'] ?? null, [AiShellPageContext::KIND_DOSSIER, AiShellPageContext::KIND_ARTICLE], true)) {
            return null;
        }

        if (! $this->isDocumentaryContinuation($prompt)) {
            return null;
        }

        $dossier = Dossier::query()->find($dossierId);

        // Tenant d'abord, explicitement : `Dossier` ne porte pas de global
        // scope d'Organization, et un fil ne doit jamais pouvoir designer un
        // objet d'une autre Organization — meme devenu etranger apres coup.
        if (! $dossier instanceof Dossier
            || (string) $dossier->organization_id !== (string) $organization->id
            || $user->cannot('view', $dossier)) {
            return null;
        }

        try {
            $answer = $this->dossierAnswers->answer($organization, $dossier, $user, $prompt, null, $memory);
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }

        // Meme effacement que la branche courante : sans document consulte, la
        // branche documentaire se tait et laisse le chemin general repondre
        // honnetement. Rien n'est fabrique depuis la memoire.
        if ($answer->consulted === []) {
            return null;
        }

        $content = trim($answer->answer);

        if ($content === '') {
            return null;
        }

        return [$content, [
            'status' => self::STATUS_NON_INTERACTION,
            'producer' => self::PRODUCER_DOSSIER_CONTINUATION,
            // La route reste celle ou la personne se trouve VRAIMENT ; l'objet
            // trace est le Dossier repris, parce que ce tour porte bien sur lui
            // — c'est ce qui permet au tour suivant de le retrouver, et a la
            // memoire documentaire (T1523) de le rattacher au bon objet.
            'page_context' => [
                'route' => (string) ($pageContext['route'] ?? ''),
                'kind' => (string) ($pageContext['kind'] ?? 'other'),
                'object_type' => AiShellPageContext::KIND_DOSSIER,
                'object_id' => (string) $dossier->id,
            ],
            'continuation' => true,
            'cards' => $this->cards->forAnsweredTurn($organization, $user, null, $pageContext, $prompt),
            'grounded' => $answer->grounded,
            'sources' => array_map(KnowledgeAnswer::publicSource(...), $answer->sources),
            'follow_up_questions' => $answer->followUps,
            'ai_interaction_id' => $answer->interactionId,
        ] + $this->pinnedTrace($pinnedContext)];
    }

    /**
     * TASK-1531 — le Shell retrouve un Dossier autorise SANS contexte prealable.
     *
     * Dernier cas du parcours ARIA : depuis le dashboard, sans avoir ouvert le
     * Dossier, sans pin, sans historique, « qui sont les partenaires ARIA ? »
     * n'avait aucun chemin documentaire. T1519/T1520 exigent un objet SOUS LES
     * YEUX, T1530 un objet DEJA discute. Ici il n'y a ni l'un ni l'autre.
     *
     * ## Ce que cette branche ne fait PAS : chercher un NOM
     *
     * Aucun `like` sur `dossiers.name`, aucun slug — et ce n'est pas une
     * economie, c'est la garde principale. Chercher un nom inverserait l'ordre :
     * il faudrait lire le `name` de Dossiers avant de savoir si le membre y a
     * droit, et toute reponse d'ambiguite (« plusieurs Dossiers correspondent »,
     * « vouliez-vous dire X ? ») serait un ORACLE DE NOMS — le nombre autant que
     * le libelle. Le perimetre autorise est donc etabli EN PREMIER, et la
     * recherche n'existe qu'a l'interieur.
     *
     * Le nom n'est d'ailleurs pas necessaire : « ARIA » vit dans le CONTENU des
     * chunks, pas seulement dans le titre du Dossier. Un Dossier nomme « Projet
     * europeen 2026 » dont tous les documents parlent d'ARIA repond ici, la ou
     * un appariement de titre l'aurait manque en silence.
     *
     * ## L'ordre des etapes est la garde, pas une optimisation
     *
     * La coupe de declenchement s'execute AVANT toute autre chose. En dessous
     * d'elle, chaque tour paierait un embedding et, surtout, le balayage de
     * `accessibleDossierIds()` — une evaluation de policy par Dossier candidat,
     * qui peut remonter `governingDossier()`. C'est le vrai cout de cette
     * branche, bien plus que l'appel d'embedding.
     *
     * Puis : perimetre autorise -> recherche BORNEE a ce perimetre -> reponse
     * sur les sources effectivement rendues. `searchAcrossDossiers()` reborne
     * le tenant dans son SQL, et `answerOverSources()` porte capability, garde
     * economique, revalidation des references et ledger — aucun second moteur.
     *
     * Un echec quelconque rend `null` : le tour suit son cours vers le chemin
     * general, jamais vers une erreur affichee, et jamais vers une reponse
     * fabriquee depuis la memoire.
     *
     * @param  array<string, mixed>  $pageContext
     * @param  list<array<string, mixed>>  $pinnedContext
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    /**
     * TASK-1544 — resoudre une reference indirecte par la PROVENANCE.
     *
     * ## Aucun appel de modele, et ce n'est pas une economie
     *
     * Le tour ne genere rien : il rend ce que la jointure dit. Un modele
     * n'aurait ici qu'une seule chose a apporter — choisir entre deux
     * candidats — et c'est precisement ce qu'il ne doit pas faire.
     *
     * ## L'ambiguite est STRUCTURELLE, pas une consigne
     *
     * Quand deux projets repondent, les deux sont nommes et la question est
     * rendue a la personne. Ce n'est pas une instruction qu'un modele pourrait
     * mal suivre : il n'y a pas de branche qui choisisse.
     *
     * ## La correction est un tour comme un autre
     *
     * « Non, je parlais de REVIVE » arrive apres une clarification. Le fil
     * porte deja les candidats offerts au tour precedent — meme mecanisme que
     * `recentDossierObjectId()`, aucun second store. Si le nouveau message
     * nomme l'un d'eux, le referent est corrige et le contexte conserve.
     *
     * @param  array<string, mixed>  $pageContext
     * @param  list<array<string, mixed>>  $pinnedContext
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function referenceResolutionTurn(
        Organization $organization,
        User $user,
        string $prompt,
        array $pageContext,
        array $pinnedContext,
    ): ?array {
        // La CORRECTION d'abord : elle repond a un tour qu'on vient de tenir,
        // et sa phrase ne porte plus de marqueur de reference indirecte.
        $corrige = $this->referentCorrige($organization, $user, $prompt);

        if ($corrige !== null) {
            return $this->tourDeReference($organization, $user, $pageContext, $pinnedContext,
                ['candidats' => [$corrige], 'personne' => null], corrige: true);
        }

        $resolution = $this->references->resoudre((string) $organization->id, $user, $prompt);

        if ($resolution['candidats'] === []) {
            return null;
        }

        return $this->tourDeReference($organization, $user, $pageContext, $pinnedContext, $resolution);
    }

    /**
     * @param  array<string, mixed>  $pageContext
     * @param  list<array<string, mixed>>  $pinnedContext
     * @param  array{personne: ?User, candidats: list<array<string, mixed>>}  $resolution
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function tourDeReference(
        Organization $organization,
        User $user,
        array $pageContext,
        array $pinnedContext,
        array $resolution,
        bool $corrige = false,
    ): array {
        $candidats = $resolution['candidats'];
        $ambigu = count($candidats) > 1;
        $locale = str_starts_with((string) app()->getLocale(), 'en') ? 'en' : 'fr';

        $lignes = array_map(
            static fn (array $c): string => trans('ai.reference_candidate', [
                'loop' => $c['loop_name'],
                'enonce' => $c['enonces'][0]['texte'],
                'date' => $c['enonces'][0]['quand']->locale($locale)->translatedFormat('j F Y'),
            ], $locale),
            $candidats,
        );

        $content = $ambigu
            // On NOMME les deux et on rend la question. Trancher « le plus
            // recent » fabriquerait une certitude que personne n'a exprimee,
            // et la personne ne saurait pas qu'on a choisi pour elle.
            ? trans('ai.reference_ambiguous', [
                'personne' => $resolution['personne']?->name ?? '',
            ], $locale).'

'.implode('
', $lignes)
            : trans('ai.reference_resolved', [
                'loop' => $candidats[0]['loop_name'],
            ], $locale).'

'.implode('
', $lignes);

        return [$content, [
            'status' => self::STATUS_NON_INTERACTION,
            'producer' => self::PRODUCER_REFERENCE_RESOLUTION,
            'page_context' => [
                'route' => (string) ($pageContext['route'] ?? ''),
                'kind' => (string) ($pageContext['kind'] ?? 'other'),
            ],
            // Les candidats OFFERTS, pour que la correction du tour suivant
            // puisse s'y adosser. Des identifiants, jamais un droit : chaque
            // affichage et chaque reprise les reverifient.
            'reference' => [
                'ambiguous' => $ambigu,
                'corrected' => $corrige,
                'candidate_loop_ids' => array_map(static fn (array $c): string => $c['loop_id'], $candidats),
            ],
            'cards' => $this->cards->forAnsweredTurn(
                $organization,
                $user,
                $ambigu ? null : ['id' => $candidats[0]['loop_id'], 'label' => $candidats[0]['loop_name'],
                    'provenance' => ['verified' => [['type' => 'active_membership', 'loop_id' => $candidats[0]['loop_id']]]]],
                $pageContext,
                '',
            ),
            'grounded' => true,
        ] + $this->pinnedTrace($pinnedContext)];
    }

    /**
     * Le referent corrige, quand le tour precedent a demande de choisir.
     *
     * Rien n'est devine : on ne retient un candidat que si le message le NOMME
     * et qu'il figurait dans les identifiants offerts. Un « non » seul, ou un
     * nom qui n'etait pas propose, ne corrige rien.
     *
     * @return array<string, mixed>|null
     */
    private function referentCorrige(Organization $organization, User $user, string $prompt): ?array
    {
        $conversationId = $this->thread->persistedConversationId($organization, $user);

        if ($conversationId === null) {
            return null;
        }

        $offerts = [];

        foreach ($this->thread->messages($organization, $user, $conversationId)->reverse() as $message) {
            $metadata = is_array($message->metadata) ? $message->metadata : [];

            if (($metadata['producer'] ?? null) !== self::PRODUCER_REFERENCE_RESOLUTION) {
                continue;
            }

            if (($metadata['reference']['ambiguous'] ?? false) !== true) {
                return null;
            }

            $offerts = array_map('strval', (array) ($metadata['reference']['candidate_loop_ids'] ?? []));
            break;
        }

        if ($offerts === []) {
            return null;
        }

        // L'univers reste celui du serveur : on relit les Boucles offertes,
        // et on revalide l'appartenance ACTIVE au moment de la correction.
        $autorisees = $this->derivedEligibility->authorizedLoopIds((string) $organization->id, $user);
        $normalise = mb_strtolower($prompt);

        foreach (Loop::query()->whereIn('id', array_intersect($offerts, $autorisees))->get() as $loop) {
            if (! str_contains($normalise, mb_strtolower(trim((string) $loop->name)))) {
                continue;
            }

            return ['loop_id' => (string) $loop->id, 'loop_name' => (string) $loop->name, 'enonces' => [
                ['texte' => trans('ai.reference_corrected_note'), 'quand' => now()],
            ]];
        }

        return null;
    }

    /**
     * TASK-1546 — « Qui pourrait les aider ? » puis « Et moi ? ».
     *
     * ## Le serveur garde l'univers, et le Shell n'y touche pas
     *
     * Ce tour n'interroge aucun annuaire. Il delegue a
     * {@see RelevantPeopleService}, qui consomme lui-meme
     * {@see \App\Services\People\EligiblePeopleService} — appartenance ACTIVE
     * a la Boucle, profil IA PUBLIE, `viewWorkspace` du demandeur, gate
     * `ai_profiles_enabled`. Une personne d'un autre tenant ne peut donc pas
     * etre candidate : elle n'entre dans aucune des requetes.
     *
     * ## Pourquoi un referent HERITE est obligatoire
     *
     * Sans lui, ce tour deviendrait un second chemin pour « qui pourrait
     * m'aider ? » — une question que le produit route deja vers la
     * clarification d'entraide, et qui marche. La garde etroite rend ce tour
     * purement ADDITIF : hors d'une conversation qui a deja etabli un projet,
     * rien ne change de chemin.
     *
     * ## L'ambiguite non resolue BLOQUE, et se dit
     *
     * Si le dernier tour de reference a demande de choisir, aucun matching
     * n'est calcule. Prendre le premier candidat « pour avancer » ferait
     * chercher des personnes pour un projet que personne n'a designe — et
     * l'utilisateur ne saurait pas qu'on a choisi pour lui. Le tour repond
     * qu'il attend le projet ; il ne s'efface pas en silence, sans quoi le
     * chemin general repondrait quelque chose a la place.
     *
     * @param  array<string, mixed>  $pageContext
     * @param  list<array<string, mixed>>  $pinnedContext
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function peopleTurn(
        Organization $organization,
        User $user,
        string $prompt,
        array $pageContext,
        array $pinnedContext,
    ): ?array {
        // SELF d'abord : « Et moi, je pourrais aider ? » porte les deux
        // formes, et la personne interroge sa PROPRE place. Rendre une liste
        // d'autres membres serait repondre a cote.
        $self = PeopleQuestionShape::isSelf($prompt);

        if (! $self && ! PeopleQuestionShape::isPeople($prompt)) {
            return null;
        }

        $herite = $this->referentHerite($organization, $user);

        if ($herite === null) {
            return null;
        }

        $locale = str_starts_with((string) app()->getLocale(), 'en') ? 'en' : 'fr';

        if ($herite['ambiguous']) {
            return [trans('ai.people_blocked_by_ambiguity', [], $locale), [
                'status' => self::STATUS_NON_INTERACTION,
                'producer' => $self ? self::PRODUCER_SELF_MATCHING : self::PRODUCER_PEOPLE_MATCHING,
                'page_context' => $this->traceablePeoplePage($pageContext),
                ($self ? 'self' : 'people') => [
                    'referent_loop_id' => null,
                    'blocked_by' => 'unresolved_reference',
                ],
                'grounded' => true,
            ] + $this->pinnedTrace($pinnedContext)];
        }

        $loop = $herite['loop'];
        $besoin = $this->besoinDuReferent($organization, $loop);

        return $self
            ? $this->tourDeSelf($organization, $user, $loop, $besoin, $pageContext, $pinnedContext, $locale)
            : $this->tourDePeople($organization, $user, $loop, $besoin, $pageContext, $pinnedContext, $locale);
    }

    /**
     * @param  array<string, mixed>  $pageContext
     * @param  list<array<string, mixed>>  $pinnedContext
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function tourDePeople(
        Organization $organization,
        User $user,
        Loop $loop,
        string $besoin,
        array $pageContext,
        array $pinnedContext,
        string $locale,
    ): array {
        $resultat = $this->people->relevantFor($organization, $loop, $user, $besoin);

        $content = $this->texteDePeople($resultat, $loop, $besoin, $locale);

        return [$content, [
            'status' => self::STATUS_NON_INTERACTION,
            'producer' => self::PRODUCER_PEOPLE_MATCHING,
            'page_context' => $this->traceablePeoplePage($pageContext),
            'people' => [
                'referent_loop_id' => (string) $loop->id,
                // « aucun besoin derivable » et « personne ne correspond » ne
                // sont pas le meme resultat : la trace les separe comme le
                // texte les separe.
                'need_derived' => $besoin !== '',
                'authorized' => $resultat->authorized,
                'refusal_reason' => $resultat->refusalReason,
                'selected_user_ids' => array_map(
                    static fn ($person): string => $person->person->userId,
                    $resultat->people,
                ),
            ],
            // Les PersonCards sont construites par la MEME primitive et le
            // MEME besoin : le texte et les cartes ne peuvent pas diverger.
            'cards' => $this->cards->forAnsweredTurn(
                $organization,
                $user,
                ['id' => (string) $loop->id, 'label' => (string) $loop->name,
                    'provenance' => ['verified' => [['type' => 'active_membership', 'loop_id' => (string) $loop->id]]]],
                $pageContext,
                $besoin,
            ),
            'grounded' => true,
        ] + $this->pinnedTrace($pinnedContext)];
    }

    /**
     * @param  array<string, mixed>  $pageContext
     * @param  list<array<string, mixed>>  $pinnedContext
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function tourDeSelf(
        Organization $organization,
        User $user,
        Loop $loop,
        string $besoin,
        array $pageContext,
        array $pinnedContext,
        string $locale,
    ): array {
        // `moi` = l'utilisateur AUTHENTIFIE du tour. Aucun nom n'est lu dans
        // la phrase : « et Untel ? » ne peut pas emprunter ce chemin pour se
        // faire rendre le profil de quelqu'un d'autre.
        $resultat = $this->people->selfFitFor($organization, $loop, $user, $besoin);

        $content = $this->texteDeSelf($resultat, $loop, $besoin, $locale);

        return [$content, [
            'status' => self::STATUS_NON_INTERACTION,
            'producer' => self::PRODUCER_SELF_MATCHING,
            'page_context' => $this->traceablePeoplePage($pageContext),
            'self' => [
                'referent_loop_id' => (string) $loop->id,
                'need_derived' => $besoin !== '',
                'authorized' => $resultat->authorized,
                'refusal_reason' => $resultat->refusalReason,
                'assessable' => $resultat->assessable,
                'not_assessable_reason' => $resultat->notAssessableReason,
                'fits' => $resultat->fits(),
                // Le sujet de la mesure, ecrit : une trace doit pouvoir
                // prouver que c'est bien le demandeur qui a ete mesure.
                'user_id' => (string) $user->id,
            ],
            // `INTENT_OFFER` : sur « et moi ? », aucune PersonCard. La
            // coupe existe deja (T1350) et dit exactement ce qu'il faut —
            // quelqu'un qui envisage d'aider n'a pas besoin qu'on lui
            // propose d'autres aidants.
            'cards' => $this->cards->forAnsweredTurn(
                $organization,
                $user,
                ['id' => (string) $loop->id, 'label' => (string) $loop->name,
                    'provenance' => ['verified' => [['type' => 'active_membership', 'loop_id' => (string) $loop->id]]]],
                $pageContext,
                '',
                AiShellTurnCards::INTENT_OFFER,
            ),
            'grounded' => true,
        ] + $this->pinnedTrace($pinnedContext)];
    }

    private function texteDePeople(RelevantPeopleResult $resultat, Loop $loop, string $besoin, string $locale): string
    {
        if (! $resultat->authorized) {
            return trans($this->cleDeRefus($resultat->refusalReason), ['loop' => $loop->name], $locale);
        }

        // Rien d'appris sur ce projet n'est PAS « personne ne correspond ».
        if ($besoin === '') {
            return trans('ai.people_no_need', ['loop' => $loop->name], $locale);
        }

        if ($resultat->people === []) {
            return trans('ai.people_none', ['loop' => $loop->name], $locale);
        }

        $lignes = array_map(
            fn ($person): string => trans('ai.people_candidate', [
                'name' => $person->person->displayName,
                'reasons' => $this->raisons($person->reasons, $locale),
            ], $locale),
            $resultat->people,
        );

        return trans('ai.people_intro', ['loop' => $loop->name], $locale)."\n\n".implode("\n", $lignes);
    }

    private function texteDeSelf(SelfFitResult $resultat, Loop $loop, string $besoin, string $locale): string
    {
        if (! $resultat->authorized) {
            return trans($this->cleDeRefus($resultat->refusalReason), ['loop' => $loop->name], $locale);
        }

        if (! $resultat->assessable) {
            return trans('ai.self_not_assessable', ['loop' => $loop->name], $locale);
        }

        if ($besoin === '') {
            return trans('ai.people_no_need', ['loop' => $loop->name], $locale);
        }

        if (! $resultat->fits()) {
            return trans('ai.self_no_match', ['loop' => $loop->name], $locale)
                ."\n\n".trans('ai.self_limits', [], $locale);
        }

        return trans('ai.self_fit', ['loop' => $loop->name], $locale)
            ."\n\n".trans('ai.people_candidate', [
                'name' => $resultat->person?->displayName ?? '',
                'reasons' => $this->raisons($resultat->reasons, $locale),
            ], $locale)
            ."\n\n".trans('ai.self_limits', [], $locale);
    }

    /**
     * Le refus de contexte People-1, rendu en clair. Jamais une cle
     * construite depuis une valeur d'execution : une raison inconnue doit
     * tomber sur une phrase honnete, pas sur un identifiant affiche.
     */
    private function cleDeRefus(?string $reason): string
    {
        return match ($reason) {
            EligiblePeopleResult::REFUSAL_LOOP_NOT_ACTIVE => 'ai.people_refused_loop_not_active',
            EligiblePeopleResult::REFUSAL_AI_PROFILES_DISABLED => 'ai.people_refused_ai_profiles_disabled',
            default => 'ai.people_refused_not_authorized',
        };
    }

    /**
     * Les raisons, telles que le serveur les a lues : le libelle DECLARE,
     * jamais une reformulation. Aucun score, aucun rang, aucun pourcentage —
     * le contrat de People-2 s'arrete aux faits apparies.
     *
     * @param  list<array{type: string, label: string, source: array<string, string>, matched_terms: list<string>, verified: true}>  $reasons
     */
    private function raisons(array $reasons, string $locale): string
    {
        return implode(
            trans('ai.people_reason_separator', [], $locale),
            array_map(
                static fn (array $reason): string => trans('ai.people_reason', [
                    'label' => (string) $reason['label'],
                ], $locale),
                $reasons,
            ),
        );
    }

    /**
     * Meme trace de page reduite que le tour de reference : un tour de
     * personnes ne depend d'aucun objet de page, et n'a pas a en porter un.
     *
     * @param  array<string, mixed>  $pageContext
     * @return array<string, string>
     */
    private function traceablePeoplePage(array $pageContext): array
    {
        return [
            'route' => (string) ($pageContext['route'] ?? ''),
            'kind' => (string) ($pageContext['kind'] ?? 'other'),
        ];
    }

    /**
     * Le referent HERITE du fil : la Boucle etablie par le dernier tour de
     * resolution de reference.
     *
     * ## Pourquoi le PREMIER tour de reference rencontre en remontant
     *
     * Une correction ecrit un NOUVEAU tour de reference, non ambigu. En
     * remontant, c'est donc lui qu'on rencontre d'abord, et le referent
     * corrige devient le referent tout court — sans qu'aucun code de
     * correction n'existe ici. Le recalcul est COMPLET par construction :
     * rien n'est reporte d'un tour de personnes au suivant.
     *
     * ## Un identifiant de fil n'est jamais un droit
     *
     * L'appartenance est revalidee a l'instant de CETTE question, par la
     * meme autorite que partout ailleurs. Quelqu'un qui a quitte la Boucle
     * entre deux tours n'en herite plus — et le tour s'efface au lieu de
     * nommer un projet qu'il n'a plus le droit de lire.
     *
     * @return array{ambiguous: bool, loop: ?Loop}|null
     */
    private function referentHerite(Organization $organization, User $user): ?array
    {
        $conversationId = $this->thread->persistedConversationId($organization, $user);

        if ($conversationId === null) {
            return null;
        }

        foreach ($this->thread->messages($organization, $user, $conversationId)->reverse() as $message) {
            $metadata = is_array($message->metadata) ? $message->metadata : [];

            if (($metadata['producer'] ?? null) !== self::PRODUCER_REFERENCE_RESOLUTION) {
                continue;
            }

            if (($metadata['reference']['ambiguous'] ?? false) === true) {
                return ['ambiguous' => true, 'loop' => null];
            }

            $offerts = array_map('strval', (array) ($metadata['reference']['candidate_loop_ids'] ?? []));
            $loopId = $offerts[0] ?? null;

            if ($loopId === null) {
                return null;
            }

            if (! in_array($loopId, $this->derivedEligibility->authorizedLoopIds((string) $organization->id, $user), true)) {
                return null;
            }

            $loop = Loop::query()->find($loopId);

            return $loop instanceof Loop ? ['ambiguous' => false, 'loop' => $loop] : null;
        }

        return null;
    }

    /**
     * Le BESOIN du referent, derive de ses enonces ACTIFS par la primitive
     * qui les lit deja ({@see ClaimMemory::actifs()}).
     *
     * ## Pourquoi pas le texte affiche au tour precedent
     *
     * Parce qu'une CORRECTION n'affiche qu'une note de service — « Referent
     * corrige a votre demande ». Heriter du texte RENDU ferait donc d'un
     * referent corrige un besoin vide, et « Qui pourrait les aider ? » apres
     * correction ne trouverait jamais personne. L'echec serait silencieux, et
     * c'est precisement le scenario que le mandat exige de faire marcher.
     *
     * Le besoin se relit donc a la source, a chaque tour.
     */
    private function besoinDuReferent(Organization $organization, Loop $loop): string
    {
        $enonces = array_filter(array_map(
            static fn ($claim): string => trim((string) $claim->content),
            $this->claims->actifs($organization, $loop),
        ));

        return trim(implode(' ', $enonces));
    }

    private function dossierDiscoveryTurn(
        Organization $organization,
        User $user,
        string $prompt,
        array $pageContext,
        array $pinnedContext,
    ): ?array {
        // Un objet documentaire COURANT garde la main : ses branches sont
        // passees avant, et une page Dossier/Article n'a pas a declencher une
        // recherche a l'echelle de l'Organization.
        if (in_array($pageContext['kind'] ?? null, [AiShellPageContext::KIND_DOSSIER, AiShellPageContext::KIND_ARTICLE], true)) {
            return null;
        }

        // LA garde de cout, et elle est la PREMIERE. Rien de ce qui suit ne
        // doit s'executer pour un « comment ca va ? » ou une demande d'aide.
        if (! $this->isDocumentarySearch($prompt)) {
            return null;
        }

        if (! $this->semanticSearchGate->isEnabledFor((string) $organization->id)) {
            return null;
        }

        // Le credential d'embedding est celui de l'ORGANIZATION. NULL = pas
        // d'embedding tenant : refus, JAMAIS un repli sur la cle plateforme.
        $embeddingInstance = $this->providers->resolveEmbeddingInstance((string) $organization->id);

        if ($embeddingInstance === null) {
            return null;
        }

        // Le perimetre AUTORISE, etabli avant toute recherche. `null` en
        // troisieme argument : toute l'Organization, jamais une Boucle.
        $dossierIds = $this->dossierAccessScope->accessibleDossierIds((string) $organization->id, $user, null);

        if ($dossierIds === []) {
            return null;
        }

        try {
            $rows = $this->dossierSearch->searchAcrossDossiers(
                (string) $organization->id,
                $dossierIds,
                $prompt,
                $embeddingInstance,
                self::DISCOVERY_SOURCE_LIMIT,
                ['shell_dossier_discovery' => true],
                self::DISCOVERY_CANDIDATE_LIMIT,
                null,
                // TASK-1534 — les Boucles dont CE membre peut lire l'espace de
                // travail. C'est par ici que la connaissance derivee d'une
                // conversation devient retrouvable depuis n'importe quelle
                // page, et c'est aussi par ici qu'elle cesse de l'etre le jour
                // ou il quitte la Boucle : la garde est evaluee a la LECTURE,
                // jamais copiee.
                $this->derivedEligibility->authorizedLoopIds((string) $organization->id, $user),
            );
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }

        $maxDistance = (float) config('ai.knowledge.max_distance', 1.0);
        $rows = array_values(array_filter(
            $rows,
            fn (array $row): bool => (float) ($row['distance'] ?? 1.0) <= $maxDistance,
        ));

        if ($rows === []) {
            return null;
        }

        // Le Dossier de rattachement de TRACE : celui du meilleur extrait. Il
        // sort du perimetre autorise par construction ; on le relit quand meme
        // (tenant, puis policy), parce qu'une branche ne se repose pas sur ce
        // qu'un collaborateur a deja verifie.
        $traceDossier = Dossier::query()->find($rows[0]['dossier_id'] ?? null);

        if (! $traceDossier instanceof Dossier
            || (string) $traceDossier->organization_id !== (string) $organization->id
            || $user->cannot('view', $traceDossier)) {
            return null;
        }

        try {
            $answer = $this->dossierAnswers->answerOverSources($organization, $traceDossier, $user, $prompt, $rows);
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }

        // Meme effacement que les autres branches documentaires : sans source
        // consultee, elle se tait et laisse le chemin general repondre.
        if ($answer->consulted === []) {
            return null;
        }

        $content = trim($answer->answer);

        if ($content === '') {
            return null;
        }

        return [$content, [
            'status' => self::STATUS_NON_INTERACTION,
            'producer' => self::PRODUCER_DOSSIER_DISCOVERY,
            'page_context' => [
                'route' => (string) ($pageContext['route'] ?? ''),
                'kind' => (string) ($pageContext['kind'] ?? 'other'),
                'object_type' => AiShellPageContext::KIND_DOSSIER,
                'object_id' => (string) $traceDossier->id,
            ],
            'discovery' => true,
            'cards' => $this->cards->forAnsweredTurn($organization, $user, null, $pageContext, $prompt),
            'grounded' => $answer->grounded,
            'sources' => array_map(KnowledgeAnswer::publicSource(...), $answer->sources),
            'follow_up_questions' => $answer->followUps,
            'ai_interaction_id' => $answer->interactionId,
        ] + $this->pinnedTrace($pinnedContext)];
    }

    /**
     * TASK-1526 — une QUESTION generale precise ne passe plus par la
     * capability qui prepare une demande d'entraide.
     * TASK-1527 — une tache conversationnelle ordinaire adressee a l'IA suit
     * le meme chemin, meme sans point d'interrogation.
     *
     * La coupe est volontairement etroite : question explicite seulement,
     * hors branches Dossier/Article deja traitees, et jamais quand le texte
     * nomme une recherche de membre, une demande d'aide collective ou une
     * offre. Les enonces non interrogatifs gardent le chemin historique ; un
     * routeur abstrait ou un second appel LLM de classification serait hors
     * scope.
     *
     * @param  array<string, mixed>  $pageContext
     * @param  list<array<string, mixed>>  $pinnedContext
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function generalAnswerTurn(
        Organization $organization,
        User $user,
        string $prompt,
        array $pageContext,
        array $pinnedContext,
        string $memory,
    ): ?array {
        if (! $this->isGeneralQuestion($prompt)) {
            return null;
        }

        try {
            $result = $this->generalAnswers->answer(
                $organization,
                $user,
                $this->situated($prompt, $pageContext, $pinnedContext, $memory),
            );
        } catch (DomainException $exception) {
            report($exception);

            return [__('ai.shell_answer_unavailable'), [
                'status' => self::STATUS_UNAVAILABLE,
                'producer' => ShellGeneralAnswerService::PRODUCER,
                'page_context' => $this->traceable($pageContext),
            ] + $this->pinnedTrace($pinnedContext)];
        }

        return [$result->answer, [
            // Une reponse generale n'est jamais un brouillon d'Interaction :
            // ce statut interdit structurellement cartes et preparation.
            'status' => self::STATUS_NON_INTERACTION,
            'producer' => ShellGeneralAnswerService::PRODUCER,
            'page_context' => $this->traceable($pageContext),
            'ai_interaction_id' => $result->interactionId,
            'general_contract_hash' => ShellGeneralAnswerService::contractHash(),
        ] + $this->pinnedTrace($pinnedContext)];
    }

    /**
     * Coupe deterministe locale, testable et sans cout provider.
     *
     * Un faux positif ferait perdre le parcours d'entraide : les marqueurs
     * interpersonnels sont donc exclus avant le routage general.
     */
    private function isGeneralQuestion(string $prompt): bool
    {
        $normalized = $this->normalizedPrompt($prompt);

        if ($this->mentionsInteractionIntent($normalized)) {
            return false;
        }

        $isQuestion = str_contains($prompt, '?')
            || preg_match(
                '/^(qui|que|quoi|quel|quelle|quels|quelles|comment|pourquoi|ou|quand|combien|est ce que|peux tu|pouvez vous|what|who|which|how|why|where|when|is|are|do|does|can|could|would)\b/',
                $normalized,
            ) === 1;

        $isConversationalTask = preg_match(
            '/^(reformule|resume|explique|compare|brainstorme|structure|ameliore|conseille|donne moi|aide moi|'
            .'summarize|explain|compare|brainstorm|structure|improve|advise|give me|help me)\b/',
            $normalized,
        ) === 1;

        return $isQuestion || $isConversationalTask;
    }

    /**
     * La forme normalisee sur laquelle TOUTES les coupes deterministes
     * travaillent : minuscules, sans accent, ponctuation reduite a l'espace.
     * Une seule normalisation pour une seule famille de decisions.
     */
    private function normalizedPrompt(string $prompt): string
    {
        return trim((string) preg_replace(
            '/[^a-z0-9]+/',
            ' ',
            Str::lower(Str::ascii($prompt)),
        ));
    }

    /**
     * Les marqueurs d'entraide, en UN seul endroit.
     *
     * TASK-1530 : cette table etait interne a `isGeneralQuestion()`. La
     * continuite documentaire doit s'effacer devant EXACTEMENT les memes
     * enonces — deux copies auraient diverge au premier ajout, et c'est la
     * protection de l'intention d'aide humaine qui aurait glisse. Extraction
     * a l'identique, aucun terme ajoute ni retire.
     */
    private function mentionsInteractionIntent(string $normalized): bool
    {
        return preg_match(
            '/\b('
            .'qui peut m aider|qui pourrait m aider|who can help|which member|quel membre|quelle personne|'
            .'qui (?:peut|pourrait) (?:m |nous )?(?:accompagner|conseiller|relire|revoir|traduire)|'
            .'quelqu un peut m aider|quelqu un pourrait m aider|can someone help|could someone help|'
            .'je cherche (?:quelqu un|un |une |de l aide)|nous cherchons (?:quelqu un|un |une )|'
            .'j ai besoin (?:d aide|d un |d une )|nous avons besoin (?:d aide|d un |d une )|'
            .'i (?:am|m) looking for (?:someone|a |an )|we (?:are|re) looking for (?:someone|a |an )|'
            .'i need (?:help|a |an )|we need (?:help|a |an )|'
            .'demander de l aide|trouver (?:quelqu un|un membre|une personne)|find (?:someone|a member)|'
            .'mettre en relation|connect me with|'
            .'puis je aider|je peux aider|je propose mon aide|can i help|i can help|i offer my help'
            .')\b/',
            $normalized,
        ) === 1;
    }

    /**
     * TASK-1530 — l'enonce est-il une CONTINUATION documentaire plausible ?
     *
     * Coupe deterministe locale, dans l'esprit exact de `isGeneralQuestion()` :
     * testable, sans cout provider, et volontairement ETROITE. Le CDC est
     * explicite — le but n'est pas un classifieur universel, c'est d'empecher
     * les captures evidentes tout en fermant le cas reel. En cas de doute, on
     * retombe sur le chemin general : une reponse generale honnete vaut mieux
     * qu'un mauvais retrieval.
     *
     * Une continuation est un enonce REFERENTIELLEMENT INCOMPLET : il parle
     * d'un sujet qu'il ne nomme pas. Trois conditions cumulatives :
     *
     *  1. c'est une question (meme test que le chemin general) ;
     *  2. elle ne nomme AUCUN sujet a elle — un article indefini (« une
     *     Boucle », « un expert ») introduit un sujet neuf, donc autonome ;
     *  3. elle ne nomme aucun concept PRODUIT : « quelle est la difference
     *     entre une Boucle et une Organization ? » est une question sur
     *     BouclePro, jamais une question sur le Dossier qu'on regardait.
     *
     * Mesure sur les enonces du CDC :
     *   « Qui sont les partenaires ? »        -> continuation
     *   « Et son budget ? »                   -> continuation
     *   « Qui le coordonne ? »                -> continuation
     *   « Quelle est la difference entre une Boucle et une Organization ? »
     *                                         -> general (indefini + produit)
     *   « Quelqu'un peut m'aider... ? »       -> entraide, ecarte plus haut
     */
    private function isDocumentaryContinuation(string $prompt): bool
    {
        $normalized = $this->normalizedPrompt($prompt);

        if ($normalized === '') {
            return false;
        }

        // L'intention d'aide humaine n'est jamais capturee par un ancien
        // Dossier. Meme table que le chemin general, meme verdict.
        if ($this->mentionsInteractionIntent($normalized)) {
            return false;
        }

        $isQuestion = str_contains($prompt, '?')
            || preg_match(
                '/^(et |qui|que|quoi|quel|quelle|quels|quelles|comment|pourquoi|ou|quand|combien|what|who|which|how|why|where|when)\b/',
                $normalized,
            ) === 1;

        if (! $isQuestion) {
            return false;
        }

        // Un article indefini introduit un sujet que la question porte
        // elle-meme : elle se suffit, elle ne continue rien.
        if (preg_match('/\b(un|une|des|a|an)\b/', $normalized) === 1) {
            return false;
        }

        // Le vocabulaire du produit appartient au chemin general et a la
        // self-knowledge, jamais au corpus d'un Dossier.
        return ! $this->mentionsProductVocabulary($normalized);
    }

    /**
     * Le vocabulaire du PRODUIT, en UN seul endroit.
     *
     * TASK-1531 : cette table etait interne a `isDocumentaryContinuation()`.
     * La decouverte doit s'effacer devant exactement les memes enonces — deux
     * copies auraient diverge au premier ajout, et c'est le partage entre
     * « question sur BouclePro » et « question sur vos documents » qui aurait
     * glisse. Extraction a l'identique, aucun terme ajoute ni retire.
     */
    private function mentionsProductVocabulary(string $normalized): bool
    {
        return preg_match(
            '/\b(boucle|boucles|organization|organizations|organisation|organisations|'
            .'bouclepro|dossier|dossiers|plateforme|platform|shell|interaction|interactions|'
            .'article|articles|profil|profils|membre|membres|member|members)\b/',
            $normalized,
        ) === 1;
    }

    /**
     * TASK-1531 — cet enonce merite-t-il une recherche dans les Dossiers ?
     *
     * Coupe deterministe locale, dans l'esprit de `isGeneralQuestion()` et de
     * `isDocumentaryContinuation()` : testable, sans cout provider, et
     * volontairement ETROITE. Elle s'execute AVANT le balayage du perimetre
     * autorise et avant tout embedding — c'est elle qui decide si le tour paie
     * quoi que ce soit.
     *
     * Trois refus, cumulatifs :
     *
     *  1. l'intention d'ENTRAIDE, avec la meme table que partout ailleurs — un
     *     « quelqu'un peut m'aider ? » n'est pas une recherche documentaire ;
     *  2. le vocabulaire du PRODUIT — « quelle est la difference entre une
     *     Boucle et une Organization ? » releve de la self-knowledge et du
     *     chemin general, jamais du corpus d'un Dossier ;
     *  3. l'absence de SUJET. Une question doit porter au moins un mot porteur
     *     (>= 4 lettres, hors interrogatifs et mots-outils) pour qu'il y ait
     *     quelque chose a chercher. Sans cela « comment ca va ? » declencherait
     *     un balayage de policy sur tous les Dossiers de l'Organization pour
     *     n'y rien trouver.
     *
     * Ce qu'elle n'essaie PAS de faire : reconnaitre un nom de Dossier. Elle
     * ne sait pas si « ARIA » designe un Dossier, et n'a pas a le savoir — la
     * recherche semantique, bornee au perimetre autorise, le decouvre ou ne le
     * decouvre pas. En cas de doute la branche s'efface : une reponse generale
     * honnete vaut mieux qu'un mauvais retrieval.
     */
    private function isDocumentarySearch(string $prompt): bool
    {
        $normalized = $this->normalizedPrompt($prompt);

        if ($normalized === '') {
            return false;
        }

        if ($this->mentionsInteractionIntent($normalized)) {
            return false;
        }

        $isQuestion = str_contains($prompt, '?')
            || preg_match(
                '/^(qui|que|quoi|quel|quelle|quels|quelles|comment|pourquoi|ou|quand|combien|'
                .'what|who|which|how|why|where|when)\b/',
                $normalized,
            ) === 1;

        if (! $isQuestion) {
            return false;
        }

        if ($this->mentionsProductVocabulary($normalized)) {
            return false;
        }

        // Un sujet, au moins un. Les mots-outils ne sont pas un sujet.
        foreach (explode(' ', $normalized) as $token) {
            if (mb_strlen($token) >= 4 && ! in_array($token, self::SUBJECTLESS_TOKENS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * TASK-1530 — ce tour documentaire peut-il ENCORE etre raconte ?
     *
     * Seuls les tours d'ASSISTANT portant un Dossier sont concernes : c'est la
     * ou du contenu de Dossier a pu etre restitue. Les messages de la personne
     * lui appartiennent et restent son fil ; un tour sans objet documentaire
     * n'a rien a revalider.
     *
     * Le droit est relu au tour COURANT — un identifiant vu hier ne prouve
     * rien aujourd'hui —, avec le meme couple de gardes que partout ailleurs :
     * tenant puis `DossierPolicy::view`. Un Dossier supprime disparait par la
     * meme porte (`find()` rend `null`).
     *
     * @param  array<string, bool>  $memo  un controle par objet distinct, pas un par message
     */
    private function documentaryTurnStillVisible(User $user, AiShellMessage $message, array &$memo): bool
    {
        if ($message->role !== AiShellMessage::ROLE_ASSISTANT) {
            return true;
        }

        $metadata = is_array($message->metadata) ? $message->metadata : [];

        if (($metadata['page_context']['object_type'] ?? null) !== AiShellPageContext::KIND_DOSSIER) {
            return true;
        }

        $id = $metadata['page_context']['object_id'] ?? null;

        if (! is_string($id) || $id === '') {
            return true;
        }

        if (! array_key_exists($id, $memo)) {
            $dossier = Dossier::query()->find($id);

            $memo[$id] = $dossier instanceof Dossier
                && (string) $dossier->organization_id === (string) $user->organization_id
                && $user->can('view', $dossier);
        }

        return $memo[$id];
    }

    /**
     * TASK-1530 — le dernier Dossier dont CE fil a REELLEMENT parle.
     *
     * Lu sur les metadata que le SERVEUR a ecrites lui-meme a l'aller
     * (`traceable()`), jamais sur un identifiant fourni par le client : un
     * object_id venu du navigateur ferait de la trace de tour une cle d'acces.
     * `AiShellThread::messages()` borne deja la lecture au couple
     * (organization, user) — Organization = Tenant.
     *
     * Du plus RECENT au plus ancien : apres un Dossier A puis un Dossier B, la
     * continuation repart de B. Rendre un identifiant n'accorde aucun droit —
     * l'appelant revalide, systematiquement.
     */
    private function recentDossierObjectId(Organization $organization, User $user): ?string
    {
        $conversationId = $this->thread->persistedConversationId($organization, $user);

        if ($conversationId === null) {
            return null;
        }

        foreach ($this->thread->messages($organization, $user, $conversationId)->reverse() as $message) {
            $metadata = is_array($message->metadata) ? $message->metadata : [];
            $type = $metadata['page_context']['object_type'] ?? null;
            $id = $metadata['page_context']['object_id'] ?? null;

            if ($type === AiShellPageContext::KIND_DOSSIER && is_string($id) && $id !== '') {
                return $id;
            }
        }

        return null;
    }

    /**
     * La trace d'un contexte epingle : des identifiants, de quoi relire quels
     * pins etaient en vigueur au tour — jamais un libelle, jamais un droit.
     * Rien a tracer quand rien n'est epingle.
     *
     * @param  list<array<string, mixed>>  $pinnedContext
     * @return array<string, mixed>
     */
    private function pinnedTrace(array $pinnedContext): array
    {
        if ($pinnedContext === []) {
            return [];
        }

        return ['pinned_context' => array_map(
            fn (array $pin): array => [
                'kind' => (string) ($pin['kind'] ?? ''),
                'id' => (string) ($pin['id'] ?? ''),
            ],
            $pinnedContext,
        )];
    }

    /**
     * Ce qu'on trace du contexte : de quoi relire un tour, jamais de quoi
     * reconstituer un droit.
     *
     * @param  array<string, mixed>  $pageContext
     * @return array<string, mixed>
     */
    /**
     * La cle de l'objet de page : le COUPLE « type:id » (Dossier, Article...).
     * Sans objet courant — type ou id manquant — la cle est la chaine vide :
     * aucun tour stocke ne la porte, la memoire documentaire est donc VIDE.
     * Sans objet, aucun tour n'est « le meme ».
     */
    private function pageObjectKey(array $pageContext): string
    {
        $object = $pageContext['object'] ?? null;

        return $this->objectKey(
            is_array($object) ? ($object['type'] ?? null) : null,
            is_array($object) ? ($object['id'] ?? null) : null,
        );
    }

    /**
     * La cle portee par un message stocke, ou `null` quand sa page ne PROUVE
     * pas l'objet (type ou id absent) : dans le doute, il n'entre pas.
     */
    private function pageObjectKeyOf(AiShellMessage $message): ?string
    {
        $metadata = is_array($message->metadata) ? $message->metadata : [];
        $key = $this->objectKey($metadata['page_context']['object_type'] ?? null, $metadata['page_context']['object_id'] ?? null);

        return $key === '' ? null : $key;
    }

    private function objectKey(mixed $type, mixed $id): string
    {
        return is_string($type) && $type !== '' && is_string($id) && $id !== '' ? $type.':'.$id : '';
    }

    private function traceable(array $pageContext): array
    {
        $object = $pageContext['object'] ?? null;

        return [
            'route' => (string) ($pageContext['route'] ?? ''),
            'kind' => (string) ($pageContext['kind'] ?? 'other'),
            'object_type' => is_array($object) ? ($object['type'] ?? null) : null,
            'object_id' => is_array($object) ? ($object['id'] ?? null) : null,
        ];
    }
}
