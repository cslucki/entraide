<?php

namespace App\Support\Ai;

use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\AiShellResponder;
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

    /** Producteur trace dans la metadata du tour — jamais montre a l'utilisateur. */
    public const PRODUCER = 'self_knowledge';

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
    ];

    public function __construct(private readonly AiCapabilityCatalogue $catalogue) {}

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
    public function answer(string $topic, Organization $organization, User $user): string
    {
        return match ($topic) {
            self::TOPIC_PLATFORM => $this->platformAnswer(),
            self::TOPIC_LOOP => $this->loopAnswer(),
            self::TOPIC_ASK_HELP => $this->askHelpAnswer(),
            self::TOPIC_CAPABILITIES => $this->capabilitiesAnswer($organization, $user),
            default => '',
        };
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

    private function capabilitiesAnswer(Organization $organization, User $user): string
    {
        $entries = $this->catalogue->forMember($organization, $user);

        if ($entries === []) {
            return __('ai.self_knowledge_capabilities_empty');
        }

        $lines = [__('ai.self_knowledge_capabilities_intro')];

        foreach ($entries as $entry) {
            $lines[] = '— '.$entry['label'];
        }

        $lines[] = __('ai.self_knowledge_capabilities_outro');

        return implode("\n", $lines);
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
