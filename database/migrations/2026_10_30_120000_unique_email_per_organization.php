<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1729 (A4) — l'email est unique PAR ORGANIZATION, pas sur la plateforme.
 *
 * ## Le point de non-retour du chantier
 *
 * A1 (T1726) a rendu l'Organization d'un compte immuable, A2 (T1727) a fait
 * qu'un token de reinitialisation designe un compte, A3 (T1728) qu'une
 * connexion designe un compte. Les trois ont ete ecrites POUR cet etat sans
 * pouvoir l'atteindre. Celle-ci l'ouvre.
 *
 * ## Les TROIS index, et pourquoi il en faut trois
 *
 * 1. `users_organization_email_unique` — `(organization_id, LOWER(email))`.
 *    Le coeur : une adresse par Organization.
 *
 * 2. `users_email_orphan_unique` — `LOWER(email)` WHERE `organization_id IS NULL`.
 *    **Indispensable, et ce n'est pas une ceinture de securite.** Mesure sur
 *    les DEUX moteurs : dans un index unique, les NULL sont DISTINCTS. Le
 *    composite seul laisserait donc deux comptes orphelins porter la meme
 *    adresse — et un orphelin n'a qu'une porte, le login GLOBAL, qui refuse
 *    l'ambiguite par conception (T1728). Deux orphelins homonymes seraient
 *    deux comptes muets.
 *
 * 3. `users_email_lower_index` — `LOWER(email)`, NON unique.
 *    Remplace la capacite de LECTURE que `users_email_unique` fournissait
 *    gratuitement. Sans lui, le login global et la demande de
 *    reinitialisation — qui cherchent une adresse sans Organization —
 *    parcourraient toute la table. Un index unique qu'on retire, c'est aussi
 *    un chemin d'acces qu'on retire.
 *
 * ## `LOWER()` partout : l'unicite LOGIQUE sans distinction de casse (D3)
 *
 * `users.email` est sensible a la casse sous PostgreSQL, et `/admin/users`
 * enregistrait sans regle `lowercase` : `Alice@x.com` et `alice@x.com`
 * pouvaient coexister. Avec un composite sensible a la casse, elles auraient pu
 * coexister DANS LA MEME Organization — deux comptes pour une seule boite,
 * dans un seul tenant. `OrganizationInvitationService` comparait deja en
 * `LOWER(email)` et avait deja souffert de cet ecart.
 *
 * Les index portent donc sur `LOWER(email)`. **Aucune donnee n'est reecrite** :
 * on ne touche pas a l'adresse de qui que ce soit, on change ce que la base
 * considere comme un doublon. Les comptes existants dont l'adresse porte des
 * majuscules gardent leur adresse, et continuent de fonctionner.
 *
 * ## Ce que cette migration NE fait pas
 *
 * Elle ne supprime, ne deplace et ne reecrit **aucune ligne**. Elle remplace
 * des index. C'est la seule etape du chantier dont l'etat de depart est
 * trivialement sain : l'unique global garantit qu'il n'existe aujourd'hui
 * aucun doublon. Le preflight ci-dessous le VERIFIE quand meme, parce que
 * « garanti par une contrainte » et « verifie » ne sont pas la meme chose — et
 * parce que la casse, elle, n'etait garantie par rien.
 */
return new class extends Migration
{
    private const COMPOSITE = 'users_organization_email_unique';

    private const ORPHELINS = 'users_email_orphan_unique';

    private const LECTURE = 'users_email_lower_index';

    private const GLOBAL = 'users_email_unique';

    public function up(): void
    {
        DB::transaction(fn () => $this->basculer());
    }

    private function basculer(): void
    {
        $this->verrouiller();

        $this->refuserSiDoublonsLogiques();

        // L'ORDRE N'EST PAS COSMETIQUE : les nouveaux index AVANT le retrait de
        // l'ancien. Si une creation echoue, l'unique global est encore la et la
        // table reste protegee. L'inverse ouvrirait une fenetre ou AUCUNE
        // contrainte ne tient — sur `users.email`, sur une application qui sert.
        $this->creerIndex();

        Schema::table('users', function (Blueprint $table) {
            // `dropUnique()`, jamais `DROP INDEX` : sous PostgreSQL un
            // `->unique()` de Laravel est une CONTRAINTE adossee a un index, et
            // `DROP INDEX` y echoue. Mesure T1729 heritee de T1728, ou cette
            // erreur a coute un aller-retour de CI complet.
            $table->dropUnique(self::GLOBAL);
        });

        $this->refuserSiLesIndexNeSontPasLa();
    }

    /**
     * Interdit toute ecriture concurrente jusqu'au commit.
     *
     * ## Pourquoi c'est plus critique ici qu'ailleurs
     *
     * `/register` est une route PUBLIQUE. Un compte cree entre le preflight et
     * la creation des index ne serait pas « perdu » : il ferait **ECHOUER la
     * migration**, en pleine production, apres que le preflight a dit oui.
     *
     * ## Les deux moteurs ne partent pas du meme etat
     *
     * `PostgresGrammar::$transactions = true` — migration en transaction, mais
     * en READ COMMITTED : chaque instruction prend un instantane neuf, donc la
     * transaction seule ne protege rien. `SQLiteGrammar` ne declare pas
     * `$transactions` et herite donc `false` : AUCUNE transaction de migration.
     * On n'en depend pas — `up()` ouvre la sienne.
     */
    private function verrouiller(): void
    {
        $connexion = Schema::getConnection();

        if ($connexion->transactionLevel() === 0) {
            // FAIL-CLOSED : sans transaction, aucun verrou ne survit a
            // l'instruction qui le prend. Migrer serait jouer.
            throw new RuntimeException(
                'TASK-1729 : la bascule exige une transaction pour tenir son verrou. Migration annulee.'
            );
        }

        if ($connexion->getDriverName() === 'pgsql') {
            $connexion->statement('LOCK TABLE "users" IN ACCESS EXCLUSIVE MODE');
        }

        // SQLite : rien a prendre, son verrouillage porte sur le FICHIER. La
        // creation d'index plus bas est une ecriture ; mais le preflight la
        // PRECEDE, donc on escalade ici par une ecriture neutre et bornee.
        // Sans elle, une inscription concurrente pourrait se glisser entre le
        // comptage et le premier `CREATE UNIQUE INDEX`.
        if ($connexion->getDriverName() === 'sqlite') {
            $connexion->statement('CREATE TABLE IF NOT EXISTS t1729_verrou (id INTEGER PRIMARY KEY)');
            $connexion->statement('DROP TABLE IF EXISTS t1729_verrou');
        }
    }

    /**
     * Les doublons que les nouveaux index refuseraient — nommes, avant d'agir.
     *
     * Sans ce preflight, `CREATE UNIQUE INDEX` echouerait avec un message de
     * base de donnees : on saurait qu'il y a un doublon, pas lequel. Ici on
     * rend les adresses en cause, et la transaction annule tout.
     *
     * La base de travail n'en portait aucun au moment de l'audit (22 comptes,
     * 0 orphelin, 0 collision de casse). La PROD peut en porter : c'est
     * precisement pourquoi ce controle existe, et non parce qu'on l'imagine.
     */
    private function refuserSiDoublonsLogiques(): void
    {
        $intraOrg = DB::table('users')
            ->selectRaw('organization_id, LOWER(email) as adresse, COUNT(*) as n')
            ->whereNotNull('organization_id')
            ->groupByRaw('organization_id, LOWER(email)')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $orphelins = DB::table('users')
            ->selectRaw('LOWER(email) as adresse, COUNT(*) as n')
            ->whereNull('organization_id')
            ->groupByRaw('LOWER(email)')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($intraOrg->isEmpty() && $orphelins->isEmpty()) {
            return;
        }

        throw new RuntimeException(sprintf(
            "TASK-1729 : bascule refusee, des doublons logiques existent deja. Rien n'a ete modifie.\n".
            "  memes (Organization, adresse) : %s\n".
            '  memes adresses sans Organization : %s',
            $intraOrg->isEmpty() ? 'aucun' : $intraOrg->map(fn ($l) => $l->adresse.' x'.$l->n)->implode(', '),
            $orphelins->isEmpty() ? 'aucun' : $orphelins->map(fn ($l) => $l->adresse.' x'.$l->n)->implode(', '),
        ));
    }

    private function creerIndex(): void
    {
        $connexion = Schema::getConnection();

        // DDL BRUT, et il faut dire pourquoi : le Blueprint de Laravel n'a
        // d'API ni pour un index PARTIEL (`WHERE`), ni pour un index
        // FONCTIONNEL (`LOWER(...)`). La syntaxe employee ici est la meme sur
        // PostgreSQL et SQLite — verifie sur les deux avant d'ecrire, et exerce
        // par les deux gates de CI, parce qu'un DDL ecrit a la main ne vaut que
        // pour le moteur sur lequel on l'a essaye.
        $connexion->statement('CREATE UNIQUE INDEX '.self::COMPOSITE.' ON users (organization_id, LOWER(email))');
        $connexion->statement('CREATE UNIQUE INDEX '.self::ORPHELINS.' ON users (LOWER(email)) WHERE organization_id IS NULL');
        $connexion->statement('CREATE INDEX '.self::LECTURE.' ON users (LOWER(email))');
    }

    private function refuserSiLesIndexNeSontPasLa(): void
    {
        $presents = collect(Schema::getIndexes('users'))->pluck('name');

        $manquants = collect([self::COMPOSITE, self::ORPHELINS, self::LECTURE])
            ->reject(fn (string $nom) => $presents->contains($nom));

        if ($manquants->isNotEmpty()) {
            throw new RuntimeException(
                'TASK-1729 : index manquants apres bascule ('.$manquants->implode(', ').'). Migration annulee.'
            );
        }
    }

    /**
     * Retour a l'unicite GLOBALE.
     *
     * FAIL-CLOSED, et le refus est ici structurel plutot que prudentiel : des
     * qu'une seule adresse est portee par deux comptes, la forme anterieure ne
     * peut PAS les contenir. Redescendre en silence perdrait un compte, et on
     * ne saurait pas lequel.
     *
     * Autrement dit : cette migration est reversible jusqu'au premier compte
     * frere, et plus apres. C'est la definition du point de non-retour, et le
     * `down()` ne peut que la dire — pas la contourner.
     */
    public function down(): void
    {
        DB::transaction(fn () => $this->redescendre());
    }

    private function redescendre(): void
    {
        $this->verrouiller();

        $doublons = DB::table('users')
            ->selectRaw('LOWER(email) as adresse, COUNT(*) as n')
            ->groupByRaw('LOWER(email)')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($doublons->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                "TASK-1729 down() refuse : %d adresse(s) sont portees par plusieurs comptes (%s). ".
                "L'unicite globale ne peut pas les contenir et en perdrait. Rien n'a ete modifie.",
                $doublons->count(),
                $doublons->take(3)->map(fn ($l) => $l->adresse.' x'.$l->n)->implode(', ')
            ));
        }

        // Meme logique d'ordre qu'a la montee, dans l'autre sens : on repose la
        // contrainte globale AVANT de retirer les trois nouvelles.
        Schema::table('users', function (Blueprint $table) {
            $table->unique('email', self::GLOBAL);
        });

        $connexion = Schema::getConnection();

        foreach ([self::COMPOSITE, self::ORPHELINS, self::LECTURE] as $index) {
            $connexion->statement('DROP INDEX '.($connexion->getDriverName() === 'pgsql' ? '' : 'IF EXISTS ').$index);
        }
    }
};
