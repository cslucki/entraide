<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1727 (A2) — un token de reinitialisation designe UN COMPTE, plus un email.
 *
 * ## Le defaut corrige
 *
 * `password_reset_tokens.email` etait PRIMARY KEY. La table ne pouvait donc
 * PHYSIQUEMENT pas porter deux tokens pour une meme adresse : des que deux
 * comptes partageront un email (cible du chantier identite), le second
 * `sendResetLink` ECRASERAIT le token du premier, et chacun des deux liens
 * ouvrirait le compte que `->first()` rend en premier.
 *
 * Second defaut, independant des homonymes et deja vrai aujourd'hui : rien ne
 * reliait un token a un COMPTE. Supprimer un compte laissait son token vivre,
 * indexe par une adresse que le compte suivant pouvait reprendre. La cle
 * etrangere `cascadeOnDelete` ferme cela par construction.
 *
 * ## Pourquoi une RECONSTRUCTION de table
 *
 * SQLite ne sait pas retirer une PRIMARY KEY en place. Deplacer la cle de
 * `email` vers `user_id` impose donc create + copy + drop + rename — le seul
 * chemin portable PostgreSQL ET SQLite. `Schema::rename()` l'est aussi.
 *
 * ## Ce qui est CONSERVE a l'identique
 *
 * `token` (le hash, jamais recalcule), `created_at` (donc l'expiration, que le
 * broker derive de cette date) et `email`. Un lien deja envoye reste donc
 * valide apres migration : c'est la condition de compatibilite posee en D1.
 *
 * ## Les tokens ORPHELINS
 *
 * Une ligne dont l'email ne correspond a aucun compte est conservee avec
 * `user_id` a NULL — jamais supprimee. Elle devient inutilisable, puisque le
 * depot de tokens interroge desormais `user_id` et qu'aucun compte n'a NULL
 * pour identifiant. PostgreSQL comme SQLite acceptent plusieurs NULL dans un
 * index unique : il n'y a donc pas de collision a craindre entre orphelins.
 *
 * « Conserves et comptes » valait mieux que « disparus » : le comptage
 * avant/apres ci-dessous est ce qui rend l'exigence « aucun token actif perdu
 * silencieusement » VERIFIABLE, et non simplement affirmee.
 */
return new class extends Migration
{
    private const TABLE = 'password_reset_tokens';

    private const TEMP = 'password_reset_tokens_t1727';

    public function up(): void
    {
        $avant = DB::table(self::TABLE)->count();

        Schema::dropIfExists(self::TEMP);

        Schema::create(self::TEMP, function (Blueprint $table) {
            // Une cle technique : `user_id` ne peut pas etre PRIMARY KEY, il
            // doit accepter NULL pour les orphelins.
            $table->id();

            // LA cle de resolution. `unique` : un compte, au plus un token en
            // vol — c'est ce que `deleteExisting()` garantissait par l'email,
            // desormais garanti par le schema.
            $table->uuid('user_id')->nullable()->unique();

            // CONSERVE. Plus une cle, mais le lien l'emporte toujours et
            // l'affichage comme la tracabilite s'en servent.
            $table->string('email')->index();

            $table->string('token');
            $table->timestamp('created_at')->nullable();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // LEFT JOIN, pas INNER : c'est lui qui CONSERVE les orphelins, avec
        // `user_id` a NULL. Un INNER JOIN les aurait fait disparaitre en
        // silence — exactement ce que MASTER a interdit.
        $lignes = DB::table(self::TABLE.' as p')
            ->leftJoin('users as u', 'u.email', '=', 'p.email')
            ->select('u.id as user_id', 'p.email', 'p.token', 'p.created_at')
            ->get();

        foreach ($lignes->chunk(500) as $lot) {
            DB::table(self::TEMP)->insert($lot->map(fn (object $l): array => [
                'user_id' => $l->user_id,
                'email' => $l->email,
                'token' => $l->token,
                'created_at' => $l->created_at,
            ])->all());
        }

        $apres = DB::table(self::TEMP)->count();

        // FAIL-CLOSED. Une migration de tokens qui perd une ligne doit
        // s'arreter DANS sa transaction, pas se decouvrir trois jours plus
        // tard sur un lien qui n'ouvre plus rien.
        if ($apres !== $avant) {
            throw new RuntimeException(sprintf(
                'TASK-1727 : reprise des tokens incomplete — %d avant, %d apres. Migration annulee.',
                $avant,
                $apres
            ));
        }

        Schema::drop(self::TABLE);
        Schema::rename(self::TEMP, self::TABLE);
    }

    /**
     * Restauration a la forme `email` PRIMARY KEY.
     *
     * FAIL-CLOSED aux DEUX risques que cette forme fait courir, et refus AVANT
     * d'avoir touche quoi que ce soit :
     *
     *  - COLLISION : deux tokens partageant un email ne peuvent pas coexister
     *    sous une cle primaire `email`. Restaurer en silence en perdrait un, et
     *    on ne saurait pas lequel ;
     *  - PERTE : un token orphelin (`user_id` NULL) n'a rien d'invalide ici —
     *    `email` reste sa cle. Il repart donc avec les autres, et le comptage
     *    final le verifie.
     */
    public function down(): void
    {
        $collisions = DB::table(self::TABLE)
            ->select('email')
            ->groupBy('email')
            ->havingRaw('count(*) > 1')
            ->pluck('email');

        if ($collisions->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'TASK-1727 down() refuse : %d email(s) portent plusieurs tokens (%s). '
                .'La forme anterieure indexe par email et en perdrait. Resolvez les doublons avant de redescendre.',
                $collisions->count(),
                $collisions->take(3)->implode(', ')
            ));
        }

        $avant = DB::table(self::TABLE)->count();

        Schema::dropIfExists(self::TEMP);

        Schema::create(self::TEMP, function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        $lignes = DB::table(self::TABLE)->select('email', 'token', 'created_at')->get();

        foreach ($lignes->chunk(500) as $lot) {
            DB::table(self::TEMP)->insert($lot->map(fn (object $l): array => [
                'email' => $l->email,
                'token' => $l->token,
                'created_at' => $l->created_at,
            ])->all());
        }

        $apres = DB::table(self::TEMP)->count();

        if ($apres !== $avant) {
            Schema::drop(self::TEMP);

            throw new RuntimeException(sprintf(
                'TASK-1727 down() refuse : %d tokens avant, %d restaures. Rien n\'a ete remplace.',
                $avant,
                $apres
            ));
        }

        Schema::drop(self::TABLE);
        Schema::rename(self::TEMP, self::TABLE);
    }
};
