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
 *
 * ## CONCURRENCE — la fenetre lecture -> remplacement (revue MASTER)
 *
 * Le comptage avant/apres NE SUFFIT PAS, et il est aveugle a la seule fenetre
 * qui compte. `$avant` et `$apres` sont tous deux pris AVANT le `DROP` : une
 * ligne committee par une autre connexion entre la lecture de copie et le
 * `DROP` n'est donc ni dans la copie, ni dans `$avant`. Les deux comptes
 * concordent, la garde passe, et la ligne part avec l'ancienne table. Un token
 * actif perdu EN SILENCE — exactement ce que la garde pretendait empecher.
 *
 * Et la fenetre n'est pas theorique : `/forgot-password` est une route
 * publique. Un deploiement se fait sur une application qui sert encore.
 *
 * ### Les deux moteurs ne partent PAS du meme etat, et c'est mesure
 *
 * - **PostgreSQL** : `PostgresGrammar::$transactions = true`, donc Laravel
 *   enveloppe la migration dans une transaction. Mais l'isolation par defaut
 *   est READ COMMITTED : chaque instruction prend un instantane neuf, et un
 *   `INSERT` concurrent committe pendant la fenetre est bel et bien perdu. La
 *   transaction ne protege RIEN ici — elle rend seulement le rollback propre.
 * - **SQLite** : `SQLiteGrammar` ne declare pas `$transactions` et herite donc
 *   `false`. La migration ne tourne dans AUCUNE transaction : chaque
 *   instruction s'auto-committe, et aucun verrou ne survit a la precedente. La
 *   fenetre y est plus large encore.
 *
 * ### Ce que fait la correction
 *
 * On ne depend PLUS de l'enveloppe du framework, puisqu'elle differe selon le
 * moteur : le corps s'execute dans une transaction qu'on ouvre nous-memes
 * (point de sauvegarde sous PostgreSQL, vraie transaction sous SQLite), et le
 * verrou est pris AVANT le premier comptage.
 *
 * - **PostgreSQL** : `LOCK TABLE ... IN ACCESS EXCLUSIVE MODE`. C'est le mode
 *   que `DROP TABLE` prend de toute facon ; le prendre des le debut avance
 *   simplement l'exclusion au lieu de la subir a la fin. Il entre en conflit
 *   avec `ROW EXCLUSIVE` (tout `INSERT`/`UPDATE`/`DELETE`) et tient jusqu'au
 *   commit : aucune ecriture ne peut se glisser dans la fenetre.
 * - **SQLite** : il n'existe pas de `LOCK TABLE`, et il n'en a pas besoin —
 *   son verrouillage porte sur le FICHIER entier. La creation de la table
 *   temporaire est donc deplacee AVANT le premier comptage : cette ecriture
 *   fait passer la transaction en `RESERVED`, et toute autre connexion qui
 *   tente d'ecrire recoit `SQLITE_BUSY` jusqu'au commit.
 *
 * Dans les deux cas le premier comptage a lieu SOUS verrou : `$avant` et la
 * copie voient donc le meme ensemble, ce qui n'etait pas garanti avant. La
 * garde de comptage cesse d'etre aveugle.
 */
return new class extends Migration
{
    private const TABLE = 'password_reset_tokens';

    private const TEMP = 'password_reset_tokens_t1727';

    /**
     * Interdit toute ecriture concurrente jusqu'au commit.
     *
     * Appelee APRES la creation de la table temporaire (l'ecriture qui escalade
     * le verrou de fichier sous SQLite) et AVANT le premier comptage, afin que
     * le comptage et la copie voient le meme ensemble.
     */
    private function verrouillerContreLesEcrituresConcurrentes(string $table): void
    {
        $connexion = Schema::getConnection();

        if ($connexion->transactionLevel() === 0) {
            // FAIL-CLOSED. Sans transaction, aucun verrou ne survit a
            // l'instruction qui le prend : migrer serait jouer. Le corps est
            // enveloppe par `DB::transaction()`, donc ceci ne doit jamais
            // arriver — et si cela arrive, on veut l'erreur, pas le risque.
            throw new RuntimeException(
                'TASK-1727 : la reprise des tokens exige une transaction pour tenir son verrou. Migration annulee.'
            );
        }

        if ($connexion->getDriverName() === 'pgsql') {
            $connexion->statement('LOCK TABLE "'.$table.'" IN ACCESS EXCLUSIVE MODE');
        }

        // SQLite : rien a prendre. Son verrouillage porte sur le fichier, et la
        // creation de la table temporaire vient de faire passer la transaction
        // en RESERVED — toute autre connexion qui tente d'ecrire recoit
        // SQLITE_BUSY. Un `LOCK TABLE` n'existe pas et serait une erreur de
        // syntaxe.
    }

    public function up(): void
    {
        // Notre propre transaction, et non celle du framework : elle existe
        // sous PostgreSQL (point de sauvegarde imbrique) comme sous SQLite, ou
        // Laravel n'en ouvre aucune pour les migrations.
        DB::transaction(fn () => $this->reprendreLesTokens());
    }

    private function reprendreLesTokens(): void
    {
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

        // Le verrou vient APRES la creation ci-dessus — c'est elle qui escalade
        // le verrou de fichier sous SQLite — et AVANT le comptage, pour que le
        // comptage et la copie voient le MEME ensemble.
        $this->verrouillerContreLesEcrituresConcurrentes(self::TABLE);

        $avant = DB::table(self::TABLE)->count();

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
     *
     * ## CONCURRENCE — le MEME risque, et une raison de plus (revue MASTER)
     *
     * `down()` lit puis remplace exactement comme `up()` : une ligne committee
     * entre sa lecture et son `DROP` serait perdue de la meme facon, et son
     * comptage y serait aveugle pour la meme raison. Il prend donc le meme
     * verrou, au meme endroit.
     *
     * Et il a un besoin que `up()` n'a pas : son controle de collision
     * d'emails. Sans verrou, deux tokens pourraient devenir homonymes APRES la
     * verification et AVANT la copie — la restauration echouerait alors sur la
     * cle primaire, ou pire, perdrait la ligne. Le verrou rend le controle et
     * la copie solidaires : ce qui a ete verifie est ce qui est copie.
     */
    public function down(): void
    {
        DB::transaction(fn () => $this->restaurerLaFormeAnterieure());
    }

    private function restaurerLaFormeAnterieure(): void
    {
        Schema::dropIfExists(self::TEMP);

        Schema::create(self::TEMP, function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        // Avant TOUTE lecture, controle de collision compris : c'est ce qui
        // rend le controle et la copie solidaires.
        $this->verrouillerContreLesEcrituresConcurrentes(self::TABLE);

        $collisions = DB::table(self::TABLE)
            ->select('email')
            ->groupBy('email')
            ->havingRaw('count(*) > 1')
            ->pluck('email');

        if ($collisions->isNotEmpty()) {
            // La transaction annulera la table temporaire : le refus ne laisse
            // aucune trace, et « rien n'a ete remplace » reste vrai.
            throw new RuntimeException(sprintf(
                'TASK-1727 down() refuse : %d email(s) portent plusieurs tokens (%s). '
                .'La forme anterieure indexe par email et en perdrait. Resolvez les doublons avant de redescendre.',
                $collisions->count(),
                $collisions->take(3)->implode(', ')
            ));
        }

        $avant = DB::table(self::TABLE)->count();

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
