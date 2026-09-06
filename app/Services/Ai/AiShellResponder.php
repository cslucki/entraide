<?php

namespace App\Services\Ai;

use App\Models\AiShellMessage;
use App\Models\Organization;
use App\Models\User;
use App\Support\Ai\AiSelfKnowledge;
use App\Support\Ai\AiShellThread;
use App\Support\Ai\AiShellTurnCards;
use App\Support\Ai\AiTurnLock;
use DomainException;
use Illuminate\Support\Str;

/**
 * TASK-1315 — UN tour de conversation du Shell « BouclePro IA ».
 *
 * ## Ce service n'est PAS un moteur de conversation
 *
 * Il n'appelle aucun provider, ne compose aucun prompt, ne resout aucun modele,
 * n'ecrit pas le ledger et ne declare aucune capability. Il DELEGUE a une
 * autorite qui existe deja et qui est deja en production :
 * `ClarifyUserHelpRequestService::clarifyForOrganization()` — le meme chemin
 * que `RequestController::formulate()`.
 *
 * Ce que cette delegation apporte, sans qu'on le reconstruise :
 *  - capability `CLARIFY_HELP_REQUEST`, scope `ORGANIZATION` deja autorise ;
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
    private function conversationMemory(Organization $organization, User $user): string
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

        // A rebours : le plus recent d'abord, pour que ce soit le plus ANCIEN
        // qui tombe quand le budget est atteint.
        foreach ($messages->reverse() as $message) {
            if (! $this->remembered($message)) {
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
