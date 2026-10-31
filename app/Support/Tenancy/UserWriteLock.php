<?php

namespace App\Support\Tenancy;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * TASK-1729, revue F2 — interdire les ecritures concurrentes sur `users` le
 * temps d'un controle suivi de son ecriture.
 *
 * ## Le defaut corrige
 *
 * Deux ecrans d'administration verifiaient l'absence de collision d'adresses
 * AVANT d'ouvrir leur transaction : la suppression d'une Organization et le
 * rattachement en masse des orphelins. Entre le controle et l'ecriture, une
 * inscription concurrente — `/register` est une route PUBLIQUE — pouvait creer
 * le compte qui rend le controle faux. On aurait alors soit detache un compte
 * en le rendant muet, soit leve une violation d'unicite, donc un 500, apres
 * avoir annonce que tout allait bien.
 *
 * Verifier puis ecrire dans la meme transaction ne suffit PAS : en READ
 * COMMITTED (le defaut de PostgreSQL), chaque instruction prend un instantane
 * neuf. Il faut un verrou qui tienne jusqu'au commit.
 *
 * ## Le mode choisi, et pourquoi pas le plus fort
 *
 * - **PostgreSQL** : `SHARE ROW EXCLUSIVE`. Il entre en conflit avec
 *   `ROW EXCLUSIVE` — tout `INSERT`/`UPDATE`/`DELETE` — et avec lui-meme, donc
 *   deux suppressions concurrentes se serialisent. Mais il laisse passer
 *   `ACCESS SHARE`, c'est-a-dire les lectures. `ACCESS EXCLUSIVE`, pris par la
 *   migration, aurait bloque jusqu'aux `SELECT` : acceptable pour une bascule
 *   de schema, excessif pour un geste d'administration sur une application qui
 *   sert.
 * - **SQLite** : il n'existe pas de verrou de table, et il n'en faut pas — son
 *   verrouillage porte sur le FICHIER. Une ecriture neutre fait passer la
 *   transaction en `RESERVED`, et toute autre connexion qui tente d'ecrire
 *   recoit `SQLITE_BUSY` jusqu'au commit.
 *
 * ## Pourquoi un helper plutot que deux fois le meme SQL
 *
 * Les deux appelants ont besoin du meme verrou, et ce verrou s'exprime
 * differemment selon le moteur. Recopier cette condition aurait produit deux
 * regles pour une idee — et c'est la copie qu'on oublie de corriger.
 */
class UserWriteLock
{
    /**
     * A appeler DANS une transaction, avant le controle qu'on veut rendre
     * solidaire de son ecriture.
     */
    public static function acquire(): void
    {
        $connexion = DB::connection();

        if ($connexion->transactionLevel() === 0) {
            // FAIL-CLOSED : hors transaction, le verrou ne survit pas a
            // l'instruction qui le prend. Un appelant qui oublie la
            // transaction doit le savoir, pas croire etre protege.
            throw new RuntimeException(
                'UserWriteLock::acquire() exige une transaction ouverte : hors transaction, aucun verrou ne tient.'
            );
        }

        match ($connexion->getDriverName()) {
            'pgsql' => $connexion->statement('LOCK TABLE "users" IN SHARE ROW EXCLUSIVE MODE'),
            'sqlite' => self::escaladerSqlite($connexion),
            default => null,
        };
    }

    /**
     * SQLite : une ecriture neutre et bornee, dont le seul effet est de faire
     * passer la transaction en `RESERVED`.
     */
    private static function escaladerSqlite($connexion): void
    {
        $connexion->statement('CREATE TABLE IF NOT EXISTS t1729_user_write_lock (id INTEGER PRIMARY KEY)');
        $connexion->statement('DROP TABLE IF EXISTS t1729_user_write_lock');
    }
}
