<?php

namespace App\Services\Ai\DTO;

use App\Support\Ai\AiUserCreditStatus;

/**
 * Reponse documentaire sourcee (TASK-1213 / RAG V1).
 *
 * `sources` ne contient QUE des entrees de provenance du Context Builder :
 * aucune citation ne peut designer un document que le retrieval n'a pas
 * fourni — donc que l'utilisateur ne peut pas ouvrir.
 */
final class KnowledgeAnswer
{
    /**
     * @param  list<array<string, mixed>>  $sources  provenance citee (ou consultee si rien n'est cite)
     * @param  list<array<string, mixed>>  $consulted  toute la provenance fournie au modele
     */
    public function __construct(
        public readonly string $answer,
        public readonly array $sources,
        public readonly array $consulted,
        public readonly bool $grounded,
        public readonly ?string $interactionId,
        /**
         * TASK-1229 : etat du credit IA du demandeur APRES cette reponse (ce
         * qu'il lui reste, alerte de seuil) — jamais un chiffre d'Organization.
         */
        public readonly ?AiUserCreditStatus $credit = null,
        /**
         * TASK-1516 : 0 a 3 questions d'approfondissement, produites DANS le
         * meme tour provider que la reponse — jamais un second appel.
         *
         * Texte inerte et suggestif : aucune citation n'est exigee d'elles, et
         * elles n'autorisent rien. Une question proposee ne devient une vraie
         * question que si un humain la choisit, et elle repasse alors par la
         * meme porte que n'importe quelle autre.
         *
         * Defaut vide : `generate()` (Smart Dossier) et LoopChat construisent
         * ce DTO sans ce parametre et gardent exactement leur forme.
         *
         * @var list<string>
         */
        public readonly array $followUps = [],
        /**
         * TASK-1554 / W3A — ce que la FRONTIERE savait, dans la semantique
         * commune du contexte : quelles sources ont reellement fourni de la
         * matiere, et lesquelles ont ete REFUSEES pour une raison deterministe
         * connue du serveur.
         *
         * Memes formes que `ContexteBorne` — `list<string>` de noms de source,
         * et `nom => raison technique`. Le vocabulaire des raisons est celui,
         * deja etabli, de `SourceDenied` / `DossierRetrievalSource::REASON_*` :
         * W3A n'en cree aucune.
         *
         * Ce qui n'entre JAMAIS dans `sourcesDenied` : un zero hit, une preuve
         * insuffisante, un provider indisponible, un document absent. Une
         * source qui n'a rien a dire n'est pas une source refusee — c'est
         * exactement ce que `ContextSource::collect()` dit deja, et l'inverse
         * ferait passer une recherche infructueuse pour une porte fermee.
         *
         * Defaut vide : les constructions existantes gardent leur forme.
         * `toArray()` NE LES EXPOSE PAS — la forme JSON publique de la reponse
         * documentaire est inchangee.
         *
         * ATTENTION, et c'est la seule chose a savoir avant de lire ces champs :
         * un tableau vide ne distingue pas « rien n'a ete utilise / refuse » de
         * « ce producteur ne declare pas encore ».
         *
         * TASK-1565 : la dette W3A est payee — `LoopKnowledgeAnswerService`
         * calculait les deux dans son `ContexteBorne` et les jetait ; il les
         * porte desormais, comme `OrganizationDoctrineSandbox` et
         * `DossierInsightsService`.
         *
         * L'avertissement qui accompagnait cette dette, lui, reste ENTIEREMENT
         * valable, et il porte sur la METADATA, pas sur ce DTO : ecrire
         * `sources_denied` au premier niveau de `AiInteraction.metadata` rend
         * `why_denied` visible sur des reponses ChatLoop existantes
         * (`AiResponseExplanationService::ragPanel()` -> `loops.why_denied`).
         * C'est une difference PRODUIT, qui demande sa propre mesure et pas un
         * branchement de commodite. Le chemin Loop les depose donc sous
         * `metadata['retrieval_trace']`, que seule l'inspection lit.
         *
         * @var list<string>
         */
        public readonly array $sourcesUsed = [],
        /** @var array<string, string> */
        public readonly array $sourcesDenied = [],
    ) {}

    /**
     * Forme publique d'une source : jamais de chunk_id ni d'identifiant
     * interne — la meme pour la reponse JSON et la metadata du LoopMessage
     * persiste (TASK-1297).
     *
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    public static function publicSource(array $source): array
    {
        return [
            'ref' => $source['ref'] ?? null,
            'title' => $source['title'] ?? null,
            'dossier_name' => $source['dossier_name'] ?? null,
            'excerpt' => $source['extrait'] ?? null,
            'url' => $source['url'] ?? null,
            // TASK-1391 : ce que la source a REELLEMENT fourni.
            //
            // Le manifest liste des documents sans en lire une ligne —
            // l'absence de cle `extrait` dans `DossierManifestSource` est
            // deliberee et commentee. Cette forme publique jetait pourtant le
            // `type`, et rien en aval ne pouvait plus distinguer un document
            // LU d'un document seulement REPERTORIE : les deux s'affichaient
            // sous « Sources utilisées », avec un lien « Ouvrir ». Le seul
            // indice etait le prefixe `M` de la reference — lisible par un
            // humain averti, jamais par le code.
            //
            // Mesure avant correctif, base de developpement : 14 messages IA
            // sur 73 presentaient ainsi des sources dont aucun contenu
            // n'avait ete lu.
            'type' => $source['type'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'answer' => $this->answer,
            'grounded' => $this->grounded,
            'sources' => array_map(self::publicSource(...), $this->sources),
            'consulted' => array_map(self::publicSource(...), $this->consulted),
            'credit' => $this->credit?->toArray(),
            'follow_up_questions' => $this->followUps,
        ];
    }
}
