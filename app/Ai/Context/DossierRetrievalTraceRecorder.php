<?php

namespace App\Ai\Context;

/**
 * TASK-1565 — le TRANSPORT de la trace de retrieval, et rien d'autre.
 *
 * ## Le probleme qu'il resout
 *
 * Les etages du retrieval documentaire — bassin dense, filtre `max_distance`,
 * rerank, `diversify()` — vivent TOUS a l'interieur de
 * `DossierRetrievalSource::collect()`, qui ne rend qu'un `SourceFragment`
 * (texte + provenance). Quand le filtre vide le bassin, la source rend
 * `SourceFragment::empty()`, `ContextBuilder` le PURGE sans un mot, et le tour
 * devient invisible aux trois autorites existantes en meme temps : aucun
 * `Log::info('ai.rerank')` (court-circuite par le retour a vide), aucune entree
 * `sourcesDenied` (rien n'a ete REFUSE — une recherche infructueuse n'est pas
 * une porte fermee), aucune entree `sourcesUsed`.
 *
 * C'est exactement le cas qu'il faut voir pour mesurer le seuil dense.
 *
 * ## Ce qu'il est
 *
 * Un TRANSPORT. Il ne cherche rien, ne compte rien, ne deduit rien : il porte
 * ce que le composant reel a observe, de la ou ca se passe jusqu'au seul
 * endroit qui ecrit la trace du tour. Il ne devient jamais une seconde
 * autorite — le jour ou il calculerait une valeur que le pipeline ne calcule
 * pas, ce serait le signe qu'on a commence a fabriquer une imitation.
 *
 * ## Pourquoi une propriete statique et non `Context`
 *
 * Meme raison, mot pour mot, que `RecordSdkEmbeddingsInvocation` (TASK-1556) :
 * `Context` est deshydrate dans chaque job dispatche, la trace suivrait le job,
 * et un tour execute la-bas reclamerait une mesure qui n'est pas la sienne.
 *
 * ## L'identite du tour est la seule cle
 *
 * `ContexteIa::$turnId` — un uuid ne AVANT le retrieval. Un tour interrompu
 * entre sa recherche et l'ecriture de son interaction laisse une entree que
 * PERSONNE ne peut reclamer : jamais le tour suivant, meme sous le meme tenant
 * et dans le meme processus. Chaque trace n'est rendue qu'UNE fois.
 *
 * ## Ce qui n'y entre jamais
 *
 * Aucun texte de chunk, aucun titre, aucun extrait, aucun prompt, aucun
 * credential. Des compteurs, des rangs, des distances et des identifiants
 * techniques — les memes (`chunk_id`, `dossier_id`) que
 * `AiInteraction.metadata['retrieval']` porte deja pour les sources consultees.
 */
final class DossierRetrievalTraceRecorder
{
    /**
     * Cle de `AiInteraction.metadata` sous laquelle le tour depose sa trace.
     *
     * DELIBEREMENT distincte de `sources_denied` au premier niveau : cette
     * derniere est lue par `AiResponseExplanationService::ragPanel()` et allume
     * un bandeau VISIBLE PAR LE MEMBRE (`loops.why_denied`). T1565 est une
     * TASK d'observabilite a comportement produit inchange — sa trace voyage
     * donc sous une cle qu'aucun lecteur produit ne consomme.
     */
    public const TURN_METADATA_KEY = 'retrieval_trace';

    /** Borne du journal : au-dela, les entrees les plus anciennes tombent. */
    private const JOURNAL_LIMIT = 64;

    /**
     * Journal PROCESS-LOCAL des traces observees, en attente d'etre reclamees
     * par le tour qui les a produites.
     *
     * @var array<string, array<string, mixed>> "{organizationId}|{turnId}" => trace
     */
    private static array $journal = [];

    /**
     * Depose la trace de CE tour. Le dernier depot d'un meme tour l'emporte :
     * une source qui rend son fragment apres avoir observe tous ses etages
     * ecrit une trace plus complete que celle d'un retour anticipe.
     *
     * @param  array<string, mixed>  $trace
     */
    public static function record(string $organizationId, string $turnId, array $trace): void
    {
        if (! self::enabled() || $organizationId === '' || $turnId === '') {
            return;
        }

        self::$journal[self::key($organizationId, $turnId)] = $trace;

        if (count(self::$journal) > self::JOURNAL_LIMIT) {
            self::$journal = array_slice(self::$journal, -self::JOURNAL_LIMIT, null, true);
        }
    }

    /**
     * Reclame la trace de CE tour, UNE seule fois.
     *
     * `null` signifie « ce tour n'a produit aucune trace de retrieval » — la
     * source n'a pas tourne, ou la collecte est coupee. Jamais un tableau vide,
     * qui se lirait comme une mesure a zero.
     *
     * @return array<string, mixed>|null
     */
    public static function claim(string $organizationId, string $turnId): ?array
    {
        $cle = self::key($organizationId, $turnId);
        $trace = self::$journal[$cle] ?? null;

        unset(self::$journal[$cle]);

        return $trace;
    }

    /** Tests uniquement : repart d'un journal vide. */
    public static function forgetJournal(): void
    {
        self::$journal = [];
    }

    /**
     * La collecte peut etre COUPEE, et le produit doit alors se comporter a
     * l'identique — c'est la garde de non-dependance exigee par le mandat :
     * une observabilite dont la reponse depend n'est plus une observabilite,
     * c'est une dependance fonctionnelle.
     *
     * Le defaut est `true`, contrairement au rerank (DEFAULT_OFF_BY_DESIGN) et
     * pour une raison qui lui est opposee : cette trace n'appelle AUCUN
     * provider, ne coute rien, ne change rien de ce qui part au modele ni de ce
     * qui est cite. Un defaut ferme rendrait simplement la production
     * inobservable, ce qui est tout le probleme que cette TASK ouvre.
     */
    private static function enabled(): bool
    {
        return (bool) config('ai.knowledge.retrieval_trace.enabled', true);
    }

    private static function key(string $organizationId, string $turnId): string
    {
        return $organizationId.'|'.$turnId;
    }
}
