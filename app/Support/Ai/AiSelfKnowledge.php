<?php

namespace App\Support\Ai;

use App\Models\Loop;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\AiShellResponder;
use App\Support\Loops\VisibleLoops;
use App\Support\Onboarding\MemberOnboardingSteps;
use Illuminate\Support\Str;

/**
 * TASK-1350 — ce que BouclePro IA sait de BouclePro.
 *
 * ## Le probleme resolu
 *
 * « C'est quoi BouclePro ? » n'est pas une demande d'aide. Envoyee au
 * clarificateur, elle revenait en brouillon de demande — le Shell
 * sur-interpretait, et l'utilisateur recevait un titre et une description pour
 * une demande qu'il n'avait jamais voulu formuler. Ces quatre questions ont une
 * reponse que le produit connait deja : elle n'a rien a faire chez un provider.
 *
 * ## FULL MATCH, jamais `contains`
 *
 * L'interception est volontairement etroite. On NORMALISE l'enonce puis on le
 * compare a une table de formulations, en EGALITE STRICTE. Jamais une
 * inclusion : « c'est quoi BouclePro pour une association de quartier ? » est
 * une vraie question, contextuelle, qui doit atteindre le modele. Une
 * interception par `contains` la volerait — et volerait avec elle toutes les
 * variantes qu'on n'a pas imaginees. Le faux negatif (on n'intercepte pas) est
 * benin : le tour part au provider comme avant. Le faux positif (on intercepte
 * a tort) est une reponse a cote, donc inacceptable.
 *
 * ## La normalisation
 *
 * Casse, accents, apostrophes (ASCII `'` comme typographique `U+2019`), tirets,
 * ponctuation et espaces multiples sont neutralises. La table de formulations
 * est donc ecrite une seule fois, en minuscules sans accents, avec l'apostrophe
 * ASCII et des espaces a la place des tirets.
 *
 * ## Les sources sont canoniques, et rien n'est reecrit
 *
 * Aucune prose produit n'est inventee ici : les reponses viennent de
 * `lang/{fr,en}/about.php`, `lang/{fr,en}/marketplace.php`, de la phrase
 * fondatrice de la Constitution plateforme (reprise mot pour mot dans
 * `ai.self_knowledge_platform`) et du catalogue tenant-aware
 * {@see AiCapabilityCatalogue}. Aucune nouvelle table, aucun parsing de Blade,
 * aucun RAG.
 *
 * Une source canonique n'est pas une reponse — c'est une matiere. Recoller
 * quatre phrases de page d'accueil a quelqu'un qui demande « c'est quoi
 * BouclePro ? » etait fidele aux sources et mauvais pour la personne.
 *
 * ## Une erreur ici n'est jamais une erreur pour l'utilisateur
 *
 * L'appelant ({@see AiShellResponder}) enveloppe l'appel : si
 * une source manque ou leve, le tour repart vers le provider legacy. Une
 * indisponibilite de catalogue ne doit jamais devenir un 500 ni une reponse
 * degradee.
 */
final class AiSelfKnowledge
{
    public const TOPIC_PLATFORM = 'platform';

    public const TOPIC_LOOP = 'loop';

    public const TOPIC_ASK_HELP = 'ask_help';

    public const TOPIC_CAPABILITIES = 'capabilities';

    /**
     * TASK-1361 — « je commence par quoi ? »
     *
     * La question d'un nouvel arrivant, et le produit savait DEJA y repondre :
     * `DashboardController` calcule quatre etapes d'installation, avec leurs
     * textes en FR et en EN. Elles vivaient sur une page que beaucoup de
     * membres n'ouvrent jamais — la redirection de connexion mene aux Boucles,
     * pas au tableau de bord — et dans un accordeon replie par defaut.
     *
     * Le Shell, lui, est sur CHAQUE page. Repondre ici ne cree donc aucune
     * connaissance : cela rend accessible celle qui existait deja.
     */
    public const TOPIC_GET_STARTED = 'get_started';

    /** TASK-1361 — « comment rejoindre une Boucle ? », le parcours existe deja. */
    public const TOPIC_JOIN_LOOP = 'join_loop';

    /**
     * TASK-1364 — « quelles Boucles sont dispo ? »
     *
     * Le Shell repondait qu'il ne pouvait pas renseigner, et invitait a
     * consulter la plateforme — pour une donnee que la plateforme affiche a
     * deux clics. Il envoyait quelqu'un chercher ailleurs ce qu'il avait sous
     * la main.
     *
     * La reponse ne cree AUCUNE connaissance : elle nomme exactement les
     * Boucles que `/loops` montre deja, via l'autorite partagee
     * {@see VisibleLoops}. Ecrire ici une seconde regle de
     * visibilite les ferait diverger, et rendrait le Shell moins fiable que
     * l'interface — le defaut meme qu'on corrige.
     */
    public const TOPIC_VISIBLE_LOOPS = 'visible_loops';

    /** Producteur trace dans la metadata du tour — jamais montre a l'utilisateur. */
    public const PRODUCER = 'self_knowledge';

    /**
     * TASK-1359 — meme borne que les libelles d'epingles du prompt. Un nom
     * d'objet est ecrit par un membre : il est cite, jamais laisse libre.
     */
    private const MAX_PLACE_CHARS = 120;

    /**
     * Formulations reconnues, deja normalisees. Une entree = une facon de poser
     * EXACTEMENT la meme question. FR et EN dans la meme table : la locale de
     * l'interface ne conditionne pas la reconnaissance, seulement la reponse.
     *
     * @var array<string, list<string>>
     */
    private const FORMULATIONS = [
        self::TOPIC_PLATFORM => [
            "c'est quoi bouclepro",
            "bouclepro c'est quoi",
            "qu'est ce que bouclepro",
            "qu'est ce que c'est bouclepro",
            'what is bouclepro',
            "what's bouclepro",
        ],
        self::TOPIC_LOOP => [
            "c'est quoi une boucle",
            "une boucle c'est quoi",
            "qu'est ce qu'une boucle",
            "qu'est ce que c'est une boucle",
            "c'est quoi les boucles",
            'what is a loop',
            "what's a loop",
            'what are loops',
        ],
        self::TOPIC_ASK_HELP => [
            "comment demander de l'aide",
            "comment je demande de l'aide",
            "comment puis je demander de l'aide",
            "comment faire pour demander de l'aide",
            'how do i ask for help',
            'how to ask for help',
            'how can i ask for help',
        ],
        self::TOPIC_CAPABILITIES => [
            'que puis je faire ici',
            'que puis je faire',
            "qu'est ce que je peux faire ici",
            "qu'est ce que je peux faire",
            'je peux faire quoi ici',
            'what can i do here',
            'what can i do',
        ],
        self::TOPIC_GET_STARTED => [
            'je commence par quoi',
            'par quoi je commence',
            'par ou commencer',
            'par ou je commence',
            'je suis nouveau ici',
            'je suis nouvelle ici',
            // TASK-1364 — l'entree livree en 1.361 s'ecrivait « d arriver »,
            // avec une espace. `normalize()` CONSERVE l'apostrophe : elle ne
            // pouvait donc jamais etre atteinte. Mesure a l'appui, pas lecture.
            "je viens d'arriver",
            'where do i start',
            'where should i start',
            'how do i get started',
            'i am new here',
            "i'm new here",
        ],
        self::TOPIC_JOIN_LOOP => [
            'comment rejoindre une boucle',
            'comment je rejoins une boucle',
            'comment puis je rejoindre une boucle',
            'comment rejoindre des boucles',
            'how do i join a loop',
            'how to join a loop',
            'how can i join a loop',
        ],
        // TASK-1364. « quels boucles sont dispo » est la formulation EXACTE
        // que Cyril a tapee : l'accord fautif et l'abreviation sont dans la
        // table parce que les gens ecrivent ainsi, pas comme une grammaire.
        // `TOPIC_LOOP` porte deja « c'est quoi les boucles » — une DEFINITION.
        // Aucune formulation d'ici ne doit lui ressembler : on demande la
        // LISTE, pas le concept.
        self::TOPIC_VISIBLE_LOOPS => [
            'quelles boucles sont disponibles',
            'quelles boucles sont dispo',
            'quels boucles sont dispo',
            'quels boucles sont disponibles',
            'quelles sont les boucles disponibles',
            'quelles boucles je peux voir',
            'quelles boucles puis je voir',
            'quelles sont mes boucles',
            "quelles boucles j'ai",
            'mes boucles',
            'what loops are available',
            'which loops are available',
            'what are the available loops',
            'what loops can i see',
            'which loops can i see',
            'what are my loops',
            'my loops',
        ],
    ];

    public function __construct(
        private readonly AiCapabilityCatalogue $catalogue,
        private readonly AiFabContext $fab,
        private readonly MemberOnboardingSteps $onboarding,
        private readonly VisibleLoops $visibleLoops,
    ) {}

    /**
     * Le sujet de self-knowledge de cet enonce, ou `null` — et `null` est le
     * cas ATTENDU pour l'immense majorite des tours.
     */
    public function topicFor(string $prompt): ?string
    {
        $normalized = $this->normalize($prompt);

        if ($normalized === '') {
            return null;
        }

        foreach (self::FORMULATIONS as $topic => $formulations) {
            // Egalite stricte : ni `str_contains`, ni prefixe, ni distance.
            if (in_array($normalized, $formulations, true)) {
                return $topic;
            }
        }

        return null;
    }

    /**
     * La reponse a un sujet reconnu, composee depuis les sources canoniques.
     * Un sujet inconnu rend une chaine vide : l'appelant repart alors vers le
     * provider, il ne fabrique rien.
     */
    public function answer(string $topic, Organization $organization, User $user, array $pageContext = []): string
    {
        return match ($topic) {
            self::TOPIC_PLATFORM => $this->platformAnswer(),
            self::TOPIC_LOOP => $this->loopAnswer(),
            self::TOPIC_ASK_HELP => $this->askHelpAnswer(),
            self::TOPIC_CAPABILITIES => $this->capabilitiesAnswer($organization, $user, $pageContext),
            self::TOPIC_GET_STARTED => $this->getStartedAnswer($organization, $user),
            self::TOPIC_JOIN_LOOP => $this->joinLoopAnswer(),
            self::TOPIC_VISIBLE_LOOPS => $this->visibleLoopsAnswer($organization, $user),
            default => '',
        };
    }

    /**
     * TASK-1361 — « je commence par quoi ? »
     *
     * Les etapes viennent de {@see MemberOnboardingSteps}, la MEME source que
     * le tableau de bord, et leurs libelles des MEMES cles de langue. Aucune
     * prose produit n'est ecrite ici : une seconde formulation aurait diverge
     * de la premiere des la premiere retouche editoriale.
     *
     * Le Shell ne dit JAMAIS que quelqu'un est « nouveau » : il ne le sait
     * pas, et le produit n'a aucun signal honnete pour l'affirmer. Il repond
     * a la question POSEE, et enumere ce qui reste.
     *
     * Un membre qui a tout complete recoit une reponse honnete — il ne reste
     * rien — plutot qu'une etape inventee pour avoir quelque chose a dire.
     */
    private function getStartedAnswer(Organization $organization, User $user): string
    {
        $remaining = $this->onboarding->remainingFor(
            $user,
            $organization,
            MemberAiProfile::forUser($user)->forOrganization($organization)->first(),
        );

        if ($remaining === []) {
            return __('ai.self_knowledge_get_started_complete');
        }

        $lines = [__('ai.self_knowledge_get_started_intro')];

        foreach ($remaining as $key) {
            $lines[] = '— '.__("dashboard.steps.{$key}.title");
        }

        $lines[] = __('ai.self_knowledge_get_started_outro');

        return implode("\n", $lines);
    }

    /**
     * TASK-1361 — « comment rejoindre une Boucle ? »
     *
     * En prose seule, et sans lien : le catalogue de capacites n'a jamais
     * porte d'URL, et cette reponse suit la meme regle. Le parcours decrit
     * existe (`loops.index`, demande d'adhesion), et chaque page rejoue sa
     * propre garde au clic — ce n'est donc pas au Shell de fabriquer une
     * destination.
     */
    private function joinLoopAnswer(): string
    {
        return __('ai.self_knowledge_join_loop');
    }

    /**
     * TASK-1364 — « quelles Boucles sont dispo ? »
     *
     * Deux listes, parce que le produit distingue reellement deux choses :
     * celles dont on est membre, et celles que le catalogue rend decouvrables.
     * L'etat d'acces des secondes vient des Policies, jamais d'un champ lu au
     * passage — une Policy sait des choses qu'`access_mode` ignore.
     *
     * Aucune URL, aucun identifiant : le catalogue de capacites n'en a jamais
     * porte, et chaque page rejoue sa garde au clic. Nommer une Boucle
     * n'accorde aucun droit d'entree.
     *
     * L'etat vide est HONNETE : il ne dit pas combien de Boucles existent
     * ailleurs, ni qu'il en existe.
     */
    private function visibleLoopsAnswer(Organization $organization, User $user): string
    {
        $grouped = $this->visibleLoops->groupedFor($organization, $user);

        if ($grouped['member']->isEmpty() && $grouped['other']->isEmpty()) {
            return __('ai.self_knowledge_visible_loops_empty');
        }

        $lines = [];

        if ($grouped['member']->isNotEmpty()) {
            $lines[] = __('ai.self_knowledge_visible_loops_mine');

            foreach ($grouped['member'] as $loop) {
                $lines[] = '— '.$this->loopName($loop);
            }
        }

        if ($grouped['other']->isNotEmpty()) {
            if ($lines !== []) {
                $lines[] = '';
            }

            $lines[] = __('ai.self_knowledge_visible_loops_others');

            foreach ($grouped['other'] as $loop) {
                $lines[] = '— '.$this->loopName($loop).' ('.__(match ($this->visibleLoops->accessStateFor($loop, $user)) {
                    VisibleLoops::ACCESS_OPEN => 'ai.self_knowledge_visible_loops_access_open',
                    VisibleLoops::ACCESS_REQUEST => 'ai.self_knowledge_visible_loops_access_request',
                    VisibleLoops::ACCESS_PENDING => 'ai.self_knowledge_visible_loops_access_pending',
                    default => 'ai.self_knowledge_visible_loops_access_invitation',
                }).')';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Le nom d'une Boucle est ecrit par un membre : borne comme tout libelle
     * cite dans une reponse, exactement comme les noms de lieu de T1359.
     */
    private function loopName(Loop $loop): string
    {
        return Str::limit(trim((string) $loop->name), self::MAX_PLACE_CHARS, '…');
    }

    /**
     * « C'est quoi BouclePro ? »
     *
     * Deux phrases, et c'est deliberé. La premiere EST la phrase fondatrice de
     * la Constitution plateforme — « BouclePro est une plateforme de pedagogie
     * par l'entraide. » — la doctrine canonique elle-meme. La seconde dit
     * concretement ce qu'on y fait.
     *
     * La version precedente recollait `about.s1_title`, `about.s2_text`,
     * `about.s7_punch` et un extrait brut de la Constitution : cinq phrases de
     * page d'accueil servies a quelqu'un qui pose une question simple. Une
     * source canonique n'est pas une reponse ; c'est une matiere.
     */
    private function platformAnswer(): string
    {
        return __('ai.self_knowledge_platform');
    }

    private function loopAnswer(): string
    {
        return implode("\n\n", [
            __('about.s3_title').' '.__('about.s3_text'),
            __('ai.self_knowledge_loop_memory'),
        ]);
    }

    private function askHelpAnswer(): string
    {
        return implode("\n\n", [
            __('marketplace.request_intro_title'),
            __('marketplace.request_intro_body'),
            __('ai.self_knowledge_ask_help_path'),
        ]);
    }

    /**
     * TASK-1359 — « Que puis-je faire ICI ? » repond enfin sur ICI.
     *
     * La question portait deja le mot « ici » dans la table de formulations,
     * et la reponse ignorait l'endroit : elle enumerait les capacites de
     * l'ORGANIZATION. Le contexte de page etait pourtant deja resolu, et deja
     * sous la main de l'appelant — il n'etait simplement pas transmis.
     *
     * Trois regles, et elles sont toutes des refus :
     *
     *  - le LIEU est nomme seulement s'il a passe sa propre garde. Un objet
     *    refuse rend un `pageContext` sans objet : il n'y a donc rien a nommer,
     *    et rien n'est nomme ;
     *  - les ACTIONS proposees sont celles que `AiFabContext::loopActions()`
     *    calcule DEJA, sous les gardes de la page. Aucune seconde verite
     *    editoriale : une liste ecrite a la main aurait fini par promettre ce
     *    que la page ne permet pas ;
     *  - le CONTENU de la Boucle n'entre jamais. On nomme un endroit, on
     *    n'ouvre pas ses portes.
     *
     * @param  array<string, mixed>  $pageContext
     */
    private function capabilitiesAnswer(Organization $organization, User $user, array $pageContext = []): string
    {
        $entries = $this->catalogue->forMember($organization, $user);
        $here = $this->hereLines($user, $pageContext);

        if ($entries === [] && $here === []) {
            return __('ai.self_knowledge_capabilities_empty');
        }

        $lines = $here;

        if ($entries !== []) {
            $lines[] = __('ai.self_knowledge_capabilities_intro');

            foreach ($entries as $entry) {
                $lines[] = '— '.$entry['label'];
            }

            $lines[] = __('ai.self_knowledge_capabilities_outro');
        }

        return implode("\n", $lines);
    }

    /**
     * Le lieu, puis ce que CE lieu permet — ou rien du tout.
     *
     * @param  array<string, mixed>  $pageContext
     * @return list<string>
     */
    private function hereLines(User $user, array $pageContext): array
    {
        if (($pageContext['refused'] ?? false) === true) {
            return [];
        }

        $object = $pageContext['object'] ?? null;
        $name = is_array($object)
            ? Str::limit(trim((string) ($object['label'] ?? '')), self::MAX_PLACE_CHARS, '…')
            : '';

        // FAIL-CLOSED, exactement comme `situated()`.
        //
        // La premiere ecriture de cette methode gardait sur « le libelle de
        // page n'est pas vide ». C'etait faux : `AiShellPageContext::label()`
        // ne rend JAMAIS vide — son `default` rend le NOM DE L'ORGANIZATION.
        // Consequence mesuree en review : sur `/profile/edit`, le Shell
        // repondait « Vous etes sur : Org X. », presentant une organisation
        // comme un lieu, sur la majorite des pages de l'application.
        //
        // Le nom d'une Organization n'est donc JAMAIS un repli de lieu, et un
        // `kind` non retenu ne produit rien du tout.
        $here = match ($pageContext['kind'] ?? null) {
            'loop' => $name === '' ? null : __('ai.self_knowledge_here_loop', ['name' => $name]),
            'dossier' => $name === '' ? null : __('ai.self_knowledge_here_dossier', ['name' => $name]),
            'article' => $name === '' ? null : __('ai.self_knowledge_here_article', ['name' => $name]),
            'dashboard' => __('ai.self_knowledge_here_dashboard'),
            default => null,
        };

        if ($here === null) {
            return [];
        }

        $lines = [$here];

        if (! is_array($object) || ($object['type'] ?? null) !== 'loop') {
            return $lines;
        }

        $loop = Loop::query()->find($object['id'] ?? null);

        if (! $loop instanceof Loop) {
            return $lines;
        }

        // Les gardes vivent DANS `loopActions()` : membre actif, Boucle
        // ecrivable, ChatLoop actif. Un non-membre recoit `[]`, donc le lieu
        // est nomme et aucune action n'est promise.
        $actions = $this->fab->loopActions($loop, $user);

        if ($actions === []) {
            return $lines;
        }

        $lines[] = __('ai.self_knowledge_here_actions');

        foreach ($actions as $action) {
            $lines[] = '— '.$action['label'];
        }

        return $lines;
    }

    /**
     * Casse, accents, apostrophes, tirets, ponctuation, espaces : tout ce qui
     * ne change pas la question disparait. Ce qui reste est compare en egalite
     * stricte, donc cette methode est le SEUL endroit ou l'on est permissif.
     */
    private function normalize(string $prompt): string
    {
        $value = mb_strtolower(trim($prompt));

        // Apostrophes typographiques ramenees a l'apostrophe ASCII — c'est la
        // difference la plus frequente entre ce qu'un clavier produit et ce
        // qu'une table de formulations contient.
        $value = str_replace(["\u{2019}", "\u{2018}", "\u{02BC}", "\u{00B4}", '`'], "'", $value);

        // Tirets (y compris insecables et cadratins) ramenes a l'espace :
        // « qu'est-ce que » et « qu'est ce que » sont la meme question.
        $value = str_replace(['-', "\u{2010}", "\u{2011}", "\u{2013}", "\u{2014}"], ' ', $value);

        // Accents : `Str::ascii()` translittere, il ne supprime pas.
        $value = Str::ascii($value);

        // Ne subsistent que lettres, chiffres, apostrophe et espace. La
        // ponctuation finale (« ? », « ! », « … ») tombe donc ici, comme les
        // guillemets et les emoji.
        $value = preg_replace("/[^a-z0-9' ]+/", ' ', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }
}
