<?php

namespace App\Support\Ai;

/**
 * TASK-1566 / CDC-01 V0-A — le TRANSPORT de la trace du TOUR, et rien d'autre.
 *
 * ## Le probleme qu'il resout
 *
 * `DossierRetrievalTraceRecorder` (TASK-1565) a rendu observable UN etage : le
 * retrieval documentaire. Le reste du tour est reste muet. Une abstention, un
 * refus economique, un fallback vers `FakeAIProvider`, un `ContextBuilder`
 * jamais appele — rien de tout cela ne laisse aujourd'hui de trace lisible, et
 * personne ne peut donc dire APRES COUP quel etage a decide quoi.
 *
 * Ce collecteur est la generalisation du meme patron au tour entier.
 *
 * ## Ce qu'il est — et ce qu'il n'est pas
 *
 * Un TRANSPORT. Chaque composant y depose CE QU'IL A OBSERVE, la ou il
 * l'observe ; le writer unique du moteur reclame le tout au moment d'ecrire son
 * interaction. Le collecteur ne cherche rien, ne compte rien, ne DEDUIT rien.
 *
 * Il ne reconstruit JAMAIS une decision. Si un composant n'a rien depose, le
 * tour n'aura pas cette etape — et c'est une information exacte, pas un trou a
 * combler par une heuristique. Le jour ou ce fichier se mettrait a inferer
 * qu'« un tour qui a des sources a forcement execute le retrieval », il
 * cesserait d'etre un transport pour devenir une seconde autorite qui imite le
 * pipeline (invariant I2).
 *
 * Il n'a AUCUNE dependance a `retrieval_trace` : les deux transports coexistent
 * sans se connaitre. Le detail fin du retrieval reste sous sa propre cle, deja
 * lue par `AiTurnInspection` ; les fusionner est explicitement remis a P1
 * (CDC-01 §5.1), et seulement si cela simplifie.
 *
 * ## Pourquoi une propriete statique et non `Context`
 *
 * Meme raison, mot pour mot, que `RecordSdkEmbeddingsInvocation` (TASK-1556) et
 * `DossierRetrievalTraceRecorder` (TASK-1565) : `Context` est deshydrate dans
 * chaque job dispatche, la trace suivrait le job, et un tour execute la-bas
 * reclamerait des etapes qui ne sont pas les siennes.
 *
 * ## L'identite du tour est la seule cle
 *
 * `ContexteIa::$turnId` — un uuid ne AVANT le retrieval, distinct du
 * `correlationId` qu'une operation metier entiere partage. La cle est
 * composite, `{organizationId}|{turnId}`, pour la meme raison que chez son
 * predecesseur : une trace ne peut pas etre reclamee sous un autre tenant.
 *
 * Un tour interrompu entre son premier depot et l'ecriture de son interaction
 * laisse une entree que PERSONNE ne peut reclamer — jamais le tour suivant,
 * meme sous le meme tenant et dans le meme processus. Chaque trace n'est rendue
 * qu'UNE fois (claim-once).
 *
 * ## Ce qui n'y entre jamais (invariant I9)
 *
 * Aucun prompt, aucun texte de chunk, aucun titre, aucun extrait, aucun
 * credential, aucun contenu de reponse. Des noms d'etapes, des statuts bornes,
 * des `reason_code` techniques, des compteurs et des durees deja mesurees.
 */
final class AiTurnTrace
{
    /**
     * Cle de `metadata` sous laquelle le tour depose son bloc canonique.
     *
     * DELIBEREMENT imbriquee, et non eclatee au premier niveau : ce dernier est
     * lu par des composants PRODUIT. Le precedent fait foi — `sources_denied`
     * au premier niveau allume un bandeau VISIBLE PAR LE MEMBRE
     * (`AiResponseExplanationService::ragPanel()` -> `loops.why_denied`). Une
     * TASK d'observabilite a comportement produit inchange ne peut pas se
     * permettre d'allumer une surface au passage : sa trace voyage donc sous
     * une cle qu'aucun lecteur produit ne consomme.
     */
    public const TURN_METADATA_KEY = 'turn';

    /**
     * Version du schema `turn`. Toute evolution INCREMENTE, et le lecteur
     * connait les deux (CDC-01 §11). Le gate `TRACE0_SCHEMA_FROZEN` gele cette
     * version 1 une fois V0-A + V0-C + V0-G + V0-L mergees.
     */
    public const SCHEMA_VERSION = 1;

    /** Borne du journal : au-dela, les entrees les plus anciennes tombent. */
    private const JOURNAL_LIMIT = 64;

    /** Borne du nombre d'etapes d'UN tour : une boucle folle ne mange pas la memoire. */
    private const STEP_LIMIT = 32;

    /**
     * Journal PROCESS-LOCAL des tours en cours, en attente d'etre reclames par
     * le writer du moteur qui les a produits.
     *
     * @var array<string, array{identity: array<string, mixed>, steps: list<array<string, mixed>>}>
     */
    private static array $journal = [];

    /**
     * La collecte est-elle active ? TOUJOURS vraie en production — seul
     * `pauseCollectionForTesting()` l'abaisse, et `forgetJournal()` la retablit.
     */
    private static bool $collecting = true;

    /**
     * Depose ce que l'on sait de l'IDENTITE du tour.
     *
     * Fusionne avec ce qui est deja connu : le moteur pose la surface et le
     * mode tot, le `ProviderResolver` complete le provider effectif plus tard.
     * Une valeur `null` n'ECRASE jamais une valeur deja observee — un composant
     * qui ne sait pas ne doit pas pouvoir effacer ce qu'un autre a mesure.
     *
     * @param  array<string, mixed>  $identity
     */
    public static function identity(string $organizationId, string $turnId, array $identity): void
    {
        if (! self::enabled() || $organizationId === '' || $turnId === '') {
            return;
        }

        $entree = self::entry($organizationId, $turnId);

        foreach ($identity as $cle => $valeur) {
            if ($valeur !== null) {
                $entree['identity'][$cle] = $valeur;
            }
        }

        self::put($organizationId, $turnId, $entree);
    }

    /**
     * Depose UNE etape du tour, dans son ordre d'execution.
     *
     * Le composant decrit ce qu'il a fait — rien d'autre. Il ne dit pas ce que
     * le tour vaut, ni ce que l'utilisateur verra : c'est le moteur qui, au
     * moment d'ecrire, construira le verdict a partir de ces depots et du sien.
     *
     * Les etapes s'AJOUTENT, elles ne se remplacent pas : un meme nom peut
     * apparaitre deux fois si le pipeline l'a reellement traverse deux fois
     * (un fallthrough documentaire du Shell, par exemple). Ecraser par nom
     * mentirait sur la chronologie.
     *
     * @param  array<string, mixed>|null  $metrics  compteurs et durees deja mesures — jamais du contenu
     */
    public static function step(
        string $organizationId,
        string $turnId,
        string $name,
        string $status,
        ?string $reasonCode = null,
        ?array $metrics = null,
    ): void {
        if (! self::enabled() || $organizationId === '' || $turnId === '' || $name === '') {
            return;
        }

        $entree = self::entry($organizationId, $turnId);

        if (count($entree['steps']) >= self::STEP_LIMIT) {
            return;
        }

        $etape = ['name' => $name, 'status' => $status];

        // `array_filter` ecraserait un compteur legitime a 0 — or « zero
        // candidat trouve » est precisement la mesure qui nous interesse. Les
        // cles optionnelles sont donc ajoutees une par une, sur leur propre
        // condition d'existence.
        if ($reasonCode !== null) {
            $etape['reason_code'] = $reasonCode;
        }

        if ($metrics !== null && $metrics !== []) {
            $etape['metrics'] = $metrics;
        }

        $entree['steps'][] = $etape;

        self::put($organizationId, $turnId, $entree);
    }

    /**
     * TASK-1568 / V0-G (C18) — l'etape `conversation_history` d'un moteur
     * PARTAGE, traduite depuis ce que l'appelant lui a deja donne.
     *
     * Un moteur partage (Dossier, Shell general, clarifier) ne calcule aucun
     * historique : il recoit de son point d'entree le bloc `history` que V0-L
     * a defini (CDC-01 P0.12) — ou rien. Cette methode ne fait que TRADUIRE
     * cette donnee, deja en main du writer, en etape :
     *
     *   - un bloc recu, meme a `count = 0`  → `executed` : le mecanisme
     *     d'historique de l'appelant a tourne (le fil Shell est toujours
     *     consulte, il peut simplement etre vide) ;
     *   - aucun bloc (`[]`)                  → `not_applicable` : l'appelant
     *     n'a pas d'etage de conversation (page Dossier, formulaire de
     *     Demande, decouverte documentaire du Shell).
     *
     * C'est exactement le meme `$history` qui decide, chez ces writers, si
     * `turn.history` est ecrit ou absent : aucune heuristique, aucun prefixe
     * de chemin, aucune donnee nouvelle. Les metriques sont celles du bloc.
     *
     * @param  array<string, mixed>  $history
     */
    public static function conversationHistoryStep(string $organizationId, string $turnId, array $history): void
    {
        if ($history === []) {
            self::step($organizationId, $turnId, 'conversation_history', 'not_applicable');

            return;
        }

        $metrics = [];

        foreach (['count', 'chars'] as $cle) {
            if (array_key_exists($cle, $history) && $history[$cle] !== null) {
                $metrics[$cle] = $history[$cle];
            }
        }

        self::step($organizationId, $turnId, 'conversation_history', 'executed', null, $metrics);
    }

    /**
     * TASK-1573 / CDC-01 V0-E — le bloc `turn.sources`, FORMATE depuis ce que
     * le writer a deja en main. Quatre familles (P0.6), aucune mesure nouvelle :
     *
     *   `retrieved` / `reranked`  compteurs lus dans la trace que
     *                             `DossierRetrievalSource` a ecrite (T1565) et
     *                             que le writer vient de reclamer — le detail
     *                             fin reste sous `retrieval_trace` ;
     *   `used`                    `ContexteBorne::sourcesUsed` (W3A) ;
     *   `denied`                  `ContexteBorne::sourcesDenied`, source => raison,
     *                             rendue en liste `{source, reason}` (§5.2) —
     *                             CHAQUE refus avec sa raison, jamais un compte.
     *
     * Une famille que le writer n'a pas est ABSENTE (UNAVAILABLE), jamais
     * remplie : un chemin sans `ContextBuilder` n'a pas de `used`, un moteur
     * qui ne passe pas par `DossierRetrievalSource` n'a pas de `retrieved`.
     * `used = []` et `denied = []`, eux, sont des MESURES et s'ecrivent.
     *
     * @param  list<string>|null  $used
     * @param  array<string, string>|null  $denied
     * @param  array<string, mixed>|null  $dossierRetrieval  sous-bloc `dossier_retrieval` de `retrieval_trace`
     * @return array<string, mixed>
     */
    public static function sourcesBlock(?array $used, ?array $denied, ?array $dossierRetrieval = null): array
    {
        $bloc = [];

        if (is_array($dossierRetrieval) && array_key_exists('dense_candidates_count', $dossierRetrieval)) {
            $bloc['retrieved'] = [
                'candidates' => $dossierRetrieval['dense_candidates_count'],
                'after_filter' => $dossierRetrieval['after_distance_filter_count'] ?? null,
                'final' => $dossierRetrieval['final_context_count'] ?? null,
            ];

            if (array_key_exists('rerank_attempted', $dossierRetrieval)) {
                $bloc['reranked'] = [
                    'attempted' => (bool) $dossierRetrieval['rerank_attempted'],
                    'succeeded' => $dossierRetrieval['rerank_succeeded'] ?? null,
                    'sent' => $dossierRetrieval['candidates_sent_to_rerank_count'] ?? null,
                    'result_count' => $dossierRetrieval['rerank_result_count'] ?? null,
                ];
            }
        }

        if ($used !== null) {
            $bloc['used'] = array_values($used);
        }

        if ($denied !== null) {
            $liste = [];
            foreach ($denied as $source => $reason) {
                $liste[] = ['source' => (string) $source, 'reason' => (string) $reason];
            }
            $bloc['denied'] = $liste;
        }

        return $bloc;
    }

    /**
     * Reclame la trace de CE tour, UNE seule fois.
     *
     * `null` signifie « ce tour n'a depose aucune observation » — le moteur
     * n'est pas instrumente, ou la collecte est coupee. Jamais un tableau vide,
     * qui se lirait comme un tour reellement passe par zero etage.
     *
     * @return array{identity: array<string, mixed>, steps: list<array<string, mixed>>}|null
     */
    public static function claim(string $organizationId, string $turnId): ?array
    {
        $cle = self::key($organizationId, $turnId);
        $entree = self::$journal[$cle] ?? null;

        unset(self::$journal[$cle]);

        return $entree;
    }

    /** Tests uniquement : repart d'un journal vide ET d'une collecte active. */
    public static function forgetJournal(): void
    {
        self::$journal = [];
        self::$collecting = true;
    }

    /**
     * Tests UNIQUEMENT : simule une collecte coupee.
     *
     * ## Pourquoi un seam de test et non une option de configuration
     *
     * `DossierRetrievalTraceRecorder` (T1565) a bien, lui, un drapeau produit
     * (`ai.knowledge.retrieval_trace.enabled`). La difference tient a ce que
     * chacun porte : la trace de retrieval est une MESURE ajoutee, qu'on peut
     * legitimement vouloir eteindre ; le bloc `turn`, lui, porte l'IDENTITE
     * canonique du tour, dont CDC-01 fait une propriete du tour et non une
     * option. Offrir de l'eteindre en production reviendrait a offrir de rendre
     * des tours anonymes — exactement ce que la campagne cherche a supprimer.
     *
     * Aucune exigence de CDC-01 ne reclame un drapeau pour V0-A : la seule
     * mention d'un « test ND (collecte coupee) » vise **V0-L** (§13, scenario
     * 13). Ce seam sert ce test-la, et rien d'autre.
     *
     * Note : meme coupee, la collecte n'empeche jamais le writer d'ecrire
     * `turn.schema` et `turn.id` — `compose()` et `identityOnly()` ne consultent
     * pas ce drapeau. Ce qui se coupe, ce sont les OBSERVATIONS des composants,
     * jamais l'identite du tour.
     */
    public static function pauseCollectionForTesting(): void
    {
        self::$collecting = false;
    }

    /**
     * ASSEMBLE le bloc `turn` canonique. Il FORMATE — il ne deduit rien.
     *
     * Deux entrees, deux origines, et la distinction est le coeur du patron :
     *
     *   `$observe`  ce que les composants ont depose en traversant le tour
     *               (rendu par `claim()`), ou `null` si rien n'a ete observe ;
     *   `$verdict`  ce que le MOTEUR sait au moment d'ecrire, et que lui seul
     *               sait : l'issue du tour, l'etage terminal, le composant qui
     *               a decide, la latence deja mesuree, les axes d'etat.
     *
     * Aucune de ces valeurs n'est calculee ici. Si le moteur ne fournit pas un
     * champ, ce champ est ABSENT du bloc — jamais rempli par defaut. Un champ
     * absent se lit `UNAVAILABLE` chez le lecteur (CDC-01 §11) ; un champ
     * rempli par politesse se lirait comme une mesure, et mentirait.
     *
     * @param  array{identity: array<string, mixed>, steps: list<array<string, mixed>>}|null  $observe
     * @param  array<string, mixed>  $verdict
     * @return array<string, mixed>
     */
    public static function compose(string $turnId, ?array $observe, array $verdict): array
    {
        $bloc = [
            'schema' => self::SCHEMA_VERSION,
            'id' => $turnId,
        ];

        $identity = array_merge($observe['identity'] ?? [], $verdict['identity'] ?? []);

        if ($identity !== []) {
            $bloc['identity'] = $identity;
        }

        // Chacune de ces cles n'apparait que si le moteur l'a reellement
        // fournie. `array_filter` ne conviendrait pas : `latency_ms = 0` est une
        // latence mesuree, pas une absence.
        foreach (['status', 'stage', 'reason_code', 'decided_by', 'latency_ms'] as $cle) {
            if (array_key_exists($cle, $verdict) && $verdict[$cle] !== null) {
                $bloc[$cle] = $verdict[$cle];
            }
        }

        if (($observe['steps'] ?? []) !== []) {
            $bloc['steps'] = $observe['steps'];
        }

        foreach (['sources', 'history', 'state'] as $cle) {
            if (isset($verdict[$cle]) && $verdict[$cle] !== []) {
                $bloc[$cle] = $verdict[$cle];
            }
        }

        return $bloc;
    }

    /**
     * Le bloc MINIMAL : l'identite canonique du tour, et rien d'autre.
     *
     * C'est ce qu'ecrivent, en V0-A, les writers P0 qui ne sont pas le pilote.
     * Leur instrumentation complete appartient a V0-G ; ce qui ne pouvait pas
     * attendre, c'est l'IDENTITE — sans elle, aucun de ces tours ne peut etre
     * relie a ses invocations au ledger ni, plus tard, compare a un autre
     * (TRACE-1, CDC-02).
     *
     * @return array<string, mixed>
     */
    public static function identityOnly(string $turnId): array
    {
        return [
            'schema' => self::SCHEMA_VERSION,
            'id' => $turnId,
        ];
    }

    /**
     * La collecte peut etre coupee PAR UN TEST, et le produit doit alors se
     * comporter a l'identique — c'est la garde de NON-DEPENDANCE : une
     * observabilite dont la reponse depend n'est plus une observabilite, c'est
     * une dependance fonctionnelle.
     *
     * En production, ce drapeau vaut TOUJOURS `true` : il n'est lie a aucune
     * configuration ni variable d'environnement, et seul
     * `pauseCollectionForTesting()` peut l'abaisser.
     */
    private static function enabled(): bool
    {
        return self::$collecting;
    }

    /**
     * @return array{identity: array<string, mixed>, steps: list<array<string, mixed>>}
     */
    private static function entry(string $organizationId, string $turnId): array
    {
        return self::$journal[self::key($organizationId, $turnId)]
            ?? ['identity' => [], 'steps' => []];
    }

    /**
     * @param  array{identity: array<string, mixed>, steps: list<array<string, mixed>>}  $entree
     */
    private static function put(string $organizationId, string $turnId, array $entree): void
    {
        self::$journal[self::key($organizationId, $turnId)] = $entree;

        if (count(self::$journal) > self::JOURNAL_LIMIT) {
            self::$journal = array_slice(self::$journal, -self::JOURNAL_LIMIT, null, true);
        }
    }

    private static function key(string $organizationId, string $turnId): string
    {
        return $organizationId.'|'.$turnId;
    }
}
