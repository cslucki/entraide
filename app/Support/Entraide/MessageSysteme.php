<?php

namespace App\Support\Entraide;

use App\Models\Message;
use App\Models\Transaction;

/**
 * TASK-1754 (§3.4, obstacle n°2) — les messages systeme cessent d'etre du
 * francais fige dans la base.
 *
 * ## Le defaut
 *
 * `addSystemMessage()` ecrivait la phrase FRANCAISE dans `messages.body`. Trois
 * consequences :
 *
 * 1. renommer le lexique n'aurait rien change a ce qui est deja ecrit ;
 * 2. un anglophone lisait « L'acheteur a declare la prestation terminee » dans
 *    son fil, quelle que soit sa langue ;
 * 3. la phrase la plus visible du vocabulaire e-commerce — celle que Cyril a
 *    relevee en recette — vivait precisement la.
 *
 * ## La solution, ADDITIVE et sans reprise de donnees
 *
 * On ecrit desormais DEUX choses :
 *
 * - `metadata->system_key` + `metadata->params` : la clef et ses parametres ;
 * - `body` : la phrase rendue au moment de l'ecriture, qui reste le REPLI.
 *
 * Au rendu, `rendre()` traduit si la clef est connue, sinon il rend le `body`.
 * L'historique anterieur n'a pas de clef : il s'affiche tel qu'il a ete ecrit.
 * C'est une divergence ASSUMEE et bornee dans le temps — arbitrage Q5, deja
 * inscrit au critere d'acceptation n°6 du CDC. Aucune migration, aucune
 * reprise.
 *
 * ## Pourquoi une classe et non deux methodes privees
 *
 * `addSystemMessage()` existait en DEUX exemplaires : le controleur web et le
 * controleur d'API, chacun avec sa propre copie des phrases. Les laisser
 * diverger, c'est garantir qu'un jour le fil raconte une chose depuis le site
 * et une autre depuis l'application.
 */
final class MessageSysteme
{
    /**
     * Le catalogue. Une clef absente d'ici ne sera jamais traduite — c'est
     * voulu : on ne devine pas une traduction a partir d'un texte libre.
     *
     * @var list<string>
     */
    public const CLEFS = [
        'proposition_envoyee',
        'echange_accepte',
        'echange_decline',
        'temps_ajuste',
        'echange_annule',
        'aide_recue',
        'echange_termine',
        'pas_encore_termine',
    ];

    /**
     * Ecrit le fait dans le fil : la clef pour l'avenir, la phrase pour le repli.
     *
     * @param  array<string, mixed>  $params
     */
    public static function ecrire(Transaction $transaction, string $clef, array $params = []): Message
    {
        return Message::create([
            'transaction_id' => $transaction->id,
            'sender_id' => null,
            'organization_id' => $transaction->organization_id,
            'body' => self::phrase($clef, $params),
            'type' => 'system',
            'metadata' => ['system_key' => $clef, 'params' => $params],
        ]);
    }

    /**
     * Ce que le fil affiche.
     *
     * Traduit SI la clef est connue ; sinon le `body` tel qu'il a ete ecrit —
     * c'est tout l'historique anterieur au 05/11/2026.
     */
    public static function rendre(Message $message): string
    {
        $metadata = $message->metadata ?? [];
        $clef = is_array($metadata) ? ($metadata['system_key'] ?? null) : null;

        if (! is_string($clef) || ! in_array($clef, self::CLEFS, true)) {
            return (string) $message->body;
        }

        $params = is_array($metadata['params'] ?? null) ? $metadata['params'] : [];

        return self::phrase($clef, $params);
    }

    /**
     * La phrase, dans la langue courante.
     *
     * `:temps` est reconstruit a partir des POINTS et non recopie : une phrase
     * ecrite avant un changement d'affichage doit se relire dans le format du
     * jour, pas dans celui d'hier.
     *
     * @param  array<string, mixed>  $params
     */
    private static function phrase(string $clef, array $params): string
    {
        if (array_key_exists('points', $params)) {
            $params['temps'] = TempsPartage::libelle((int) $params['points']);
        }

        return __('messages.systeme.'.$clef, $params);
    }
}
