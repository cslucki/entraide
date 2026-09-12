<?php

namespace App\Services\Knowledge;

use App\Models\DerivedKnowledgeNote;
use App\Models\LoopMessage;

/**
 * TASK-1548 — une correction humaine ne se defait pas toute seule.
 *
 * ## Le defaut que cette classe ferme
 *
 * Corriger un claim ECRIT un message dans la Boucle. Ce message change donc
 * `empreinteSource`, et c'est precisement ce qui relance le compiler
 * ({@see LoopClaimCompiler::compile()}). Le modele relit alors le corpus : le
 * fait retracte y est TOUJOURS ecrit, dans les memes anciens messages, et rien
 * ne l'empeche de le re-proposer. Sans garde, la correction humaine tenait dix
 * minutes — le temps du prochain balayage — puis disparaissait en silence.
 *
 * Compter sur le prompt pour l'eviter aurait rendu la garantie probabiliste,
 * ce que le contrat W1.5-A interdit explicitement : la correction ne doit
 * dependre d'AUCUNE nouvelle interpretation LLM.
 *
 * ## Ce qui est bloque, et ce qui ne l'est pas
 *
 * La regle n'est pas « ce sujet est gele ». Elle est :
 *
 *   un enonce corrige a la main ne revient JAMAIS sur la seule foi de preuves
 *   ANTERIEURES a la correction ; il revient des qu'une preuve humaine
 *   REELLEMENT POSTERIEURE le dit.
 *
 * La Boucle continue donc d'apprendre — y compris a contredire la personne qui
 * a corrige, si quelqu'un l'ecrit APRES. Ce qu'on interdit n'est pas le
 * changement, c'est le rejeu du passe.
 *
 * ## Pourquoi le `subject_key` ne suffisait pas
 *
 * Premiere redaction, fausse : « un `subject_key` humainement retracte ne peut
 * plus etre re-ADDe ». Elle ne tient pas, et le test le montre. Un `ADD` ne
 * porte AUCUN identifiant — le serveur lui en forge un neuf
 * ({@see ClaimMemory::appliquer()}, `Str::uuid()`). Le modele ne « ressuscite »
 * donc pas le claim retracte : il en cree un AUTRE, avec le meme contenu et un
 * `subject_key` different. Une garde par identite ne l'aurait jamais vu passer.
 *
 * L'ancre doit donc etre ce qui SURVIT au changement d'identite. Deux ancres,
 * et il faut les deux :
 *
 *  - **le texte**, normalise — le cas ou le modele reecrit le meme enonce ;
 *  - **les preuves**, par inclusion — le cas ou le modele REFORMULE. Le texte
 *    change, mais il le derive des MEMES anciens messages. Un enonce dont
 *    toutes les preuves sont deja celles d'un enonce corrige, sans rien de
 *    neuf, ne s'appuie sur rien que la correction n'ait deja tranche.
 *
 * ## « Posterieur » se lit dans l'ordre du repo, pas sur une horloge
 *
 * `created_at` seul ne suffit pas a ordonner deux messages : deux ecritures de
 * la meme transaction le partagent. L'ordre canonique des messages de ce depot
 * est `(created_at, id)` — celui que `LoopChat` applique partout. C'est lui
 * qu'on compare, et jamais une soustraction de timestamps.
 */
final class ClaimResurrectionGuard
{
    /**
     * Borne de lecture des frontieres d'une Boucle.
     *
     * Une Boucle tres corrigee n'en accumule pas des milliers ; la borne
     * protege le cas pathologique sans changer le cas reel.
     */
    private const MAX_FRONTIERES = 200;

    /**
     * Les corrections humaines de cette Boucle, sous la forme ou la garde les
     * consomme.
     *
     * La lecture des `provenance` se fait en PHP, jamais en `jsonb`: les six
     * voies PostgreSQL de la CI ne sont pas seules, SQLite tourne a cote et un
     * operateur JSON specifique y serait un faux vert local.
     *
     * @param  list<DerivedKnowledgeNote>  $corrigees  les claims archives portant une correction humaine
     * @return list<array{subject_key: string, text_hash: string, evidence_ids: list<string>, position: array{0: string, 1: string}}>
     */
    public static function frontieresDeVerite(array $corrigees): array
    {
        $frontieres = [];

        foreach ($corrigees as $claim) {
            $marque = ($claim->provenance ?? [])['human_correction'] ?? null;

            if (! is_array($marque)) {
                continue;
            }

            $position = $marque['position'] ?? null;

            if (! is_array($position) || count($position) !== 2) {
                continue;
            }

            $frontieres[] = [
                // ADDENDUM 1 — la frontiere se retrouve par le SUJET, et elle
                // survit au RETRACT : la ligne archivee qui la porte reste en
                // base meme quand plus aucun enonce actif n'existe.
                'subject_key' => (string) $claim->subject_key,
                'text_hash' => (string) ($marque['text_hash'] ?? ''),
                'evidence_ids' => array_map('strval', (array) ($marque['evidence_ids'] ?? [])),
                'position' => [(string) $position[0], (string) $position[1]],
            ];
        }

        return array_slice($frontieres, 0, self::MAX_FRONTIERES);
    }

    /**
     * L'empreinte d'un enonce, insensible a ce qui ne change pas son sens :
     * la casse, les espaces multiples, la ponctuation de fin.
     *
     * Elle n'est pas semantique, et ne pretend pas l'etre — un modele qui
     * reformule vraiment lui echappe. C'est la seconde ancre, l'inclusion des
     * preuves, qui couvre ce cas-la.
     */
    public static function empreinteTexte(string $texte): string
    {
        $normalise = mb_strtolower(trim($texte));
        $normalise = (string) preg_replace('/\s+/u', ' ', $normalise);
        $normalise = (string) preg_replace('/[.!?;:,\s]+$/u', '', $normalise);

        return hash('sha256', $normalise);
    }

    /**
     * La position canonique d'un message : `(created_at, id)`.
     *
     * @return array{0: string, 1: string}
     */
    public static function position(LoopMessage $message): array
    {
        return [
            (string) ($message->created_at?->format('Y-m-d H:i:s.u') ?? ''),
            (string) $message->id,
        ];
    }

    /**
     * @param  array{0: string, 1: string}  $a
     * @param  array{0: string, 1: string}  $b
     */
    public static function compare(array $a, array $b): int
    {
        return ($a[0] <=> $b[0]) ?: strcmp($a[1], $b[1]);
    }

    /**
     * Cette operation rejoue-t-elle un passe qu'une correction humaine a
     * deja tranche ?
     *
     * @param  array<string, mixed>  $operation  une operation ADD ou UPDATE acceptee
     * @param  list<array{subject_key: string, text_hash: string, evidence_ids: list<string>, position: array{0: string, 1: string}}>  $frontieres
     * @param  array<string, LoopMessage>  $messagesParId  la source lue pour ce tour
     */
    public static function estUneResurrection(array $operation, array $frontieres, array $messagesParId): bool
    {
        if ($frontieres === []) {
            return false;
        }

        $empreinte = self::empreinteTexte((string) ($operation['text'] ?? ''));
        $preuves = array_map('strval', (array) ($operation['evidence'] ?? []));
        $sujet = (string) ($operation['claim_id'] ?? '');

        foreach ($frontieres as $frontiere) {
            if (! self::viseCetteFrontiere($empreinte, $preuves, $sujet, $frontiere)) {
                continue;
            }

            // Elle touche un terrain deja corrige. Elle ne passe QUE si TOUTE
            // sa preuve est posterieure a cette correction.
            if (! self::toutesLesPreuvesSontPosterieures($preuves, $frontiere['position'], $messagesParId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $preuves
     * @param  array{subject_key: string, text_hash: string, evidence_ids: list<string>, position: array{0: string, 1: string}}  $frontiere
     */
    private static function viseCetteFrontiere(string $empreinte, array $preuves, string $sujet, array $frontiere): bool
    {
        // ADDENDUM 4 — « une operation automatique SUR CE SUBJECT_KEY ». Un
        // UPDATE nomme sa cible : si ce sujet porte une frontiere, l'operation
        // la touche, quel que soit son texte.
        if ($sujet !== '' && $frontiere['subject_key'] !== '' && $sujet === $frontiere['subject_key']) {
            return true;
        }

        if ($frontiere['text_hash'] !== '' && hash_equals($frontiere['text_hash'], $empreinte)) {
            return true;
        }

        // L'inclusion des preuves. Un enonce SANS preuve n'existe pas
        // (`ClaimPatch::valider()` le refuse deja) ; le tester ici evite qu'un
        // ensemble vide soit lu comme « inclus dans tout ».
        if ($preuves === [] || $frontiere['evidence_ids'] === []) {
            return false;
        }

        foreach ($preuves as $id) {
            if (! in_array($id, $frontiere['evidence_ids'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * ADDENDUM 2 et 4 — le corpus MIXTE.
     *
     * Premiere redaction, trop laxiste : « il suffit qu'UNE preuve soit
     * posterieure ». Elle laissait passer l'operation qui cite un message neuf
     * A COTE des anciens — c'est-a-dire le moyen le plus simple de rejouer le
     * passe en le maquillant de neuf. Un seul mot ecrit apres la correction
     * aurait rouvert tout le corpus qui la precede.
     *
     * La regle est donc : CHAQUE preuve est strictement posterieure, et il en
     * faut au moins une. La derivation peut rester machine ; l'autorite
     * probante, elle, est humaine et datee.
     *
     * @param  list<string>  $preuves
     * @param  array{0: string, 1: string}  $correction
     * @param  array<string, LoopMessage>  $messagesParId
     */
    private static function toutesLesPreuvesSontPosterieures(array $preuves, array $correction, array $messagesParId): bool
    {
        if ($preuves === []) {
            return false;
        }

        foreach ($preuves as $id) {
            $message = $messagesParId[$id] ?? null;

            if ($message === null) {
                // Une preuve hors du tour ne peut pas etablir une posteriorite :
                // on ne sait pas QUAND elle a ete ecrite. Fail closed.
                return false;
            }

            if (! self::estUnMessageHumain($message)) {
                // « de vrais LoopMessage humains » : une projection technique
                // ou une bulle IA ne fait pas autorite contre une correction.
                return false;
            }

            if (self::compare(self::position($message), $correction) <= 0) {
                return false;
            }
        }

        return true;
    }

    private static function estUnMessageHumain(LoopMessage $message): bool
    {
        return (string) $message->type === 'user'
            && $message->sender_id !== null
            && $message->deleted_at === null;
    }
}
