<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1714 volet 2 — l'administrateur valide le compte apres l'email.
 *
 * Deux colonnes, et c'est tout :
 *
 * - `organizations.requires_account_approval` : le reglage, par Organization,
 *   defaut `false`. Une Organization qui ne l'active pas ne change pas d'un
 *   iota.
 * - `users.approved_at` : la decision, horodatee. `null` = en attente.
 *
 * ## La non-retroactivite est EXPLICITE, pas un effet de bord
 *
 * MASTER : « ne pas bloquer retroactivement les comptes existants ». On
 * pourrait l'obtenir implicitement, en ne bloquant que les comptes crees apres
 * l'activation du reglage — mais cela demanderait de comparer deux dates a
 * chaque requete, et la regle serait invisible en base.
 *
 * Le backfill la rend LISIBLE : tout compte existant recoit
 * `approved_at = created_at`. Un `SELECT` suffit alors a repondre « qui est en
 * attente ? », et la garde se reduit a « le reglage est actif ET
 * `approved_at IS NULL` ».
 *
 * `created_at` plutot que `now()` : l'horodatage doit dire quand la personne a
 * ete admise, pas quand cette migration est passee. Mettre `now()` ferait
 * croire que 57 comptes ont ete approuves a la seconde pres un mardi apres-midi.
 *
 * ## Ce que `down()` conserve, et ce qu'il ne conserve pas
 *
 * Le `up()` est idempotent et REMET tout le monde a « approuve ». Un
 * aller-retour perdrait donc exactement deux choses, et elles seules :
 *
 * - les Organizations qui avaient ACTIVE le reglage (il repasserait a `false`) ;
 * - les comptes qui etaient EN ATTENTE (ils ressortiraient approuves).
 *
 * Ce sont des decisions humaines, et le second cas est une frontiere de
 * securite : cinq comptes refuses entreraient en silence. Les deux sont donc
 * conserves, selon la doctrine de TASK-1711.
 *
 * Les 57 `approved_at` des comptes DEJA approuves ne sont PAS conserves : le
 * `up()` les reconstruit a l'identique depuis `created_at`. Conserver ce qu'on
 * sait regenerer n'est pas de la prudence, c'est du bruit qui noie ce qui
 * compte.
 */
return new class extends Migration
{
    private const MARQUE = '2026_10_27_140000_add_account_approval_to_organizations_and_users';

    private const CONSERVATION = 'rollback_conservation';

    public function up(): void
    {
        // TASK-1711 — la table de conservation existe des l'aller, vide : un
        // `down()` qui doit d'abord creer une table est plus fragile qu'un
        // `down()` qui ecrit dans une table deja la.
        $this->assurerLaConservation();

        Schema::table('organizations', function (Blueprint $table) {
            if (! Schema::hasColumn('organizations', 'requires_account_approval')) {
                $table->boolean('requires_account_approval')->default(false)->after('membership_enabled');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('email_verified_at');
                // La garde lit « reglage actif ET approved_at IS NULL » a
                // CHAQUE requete authentifiee : l'index n'est pas decoratif.
                $table->index('approved_at');
            }
        });

        // Le backfill, par lots : une base PROD de plusieurs milliers de
        // comptes ne doit pas tenir en memoire.
        DB::table('users')
            ->whereNull('approved_at')
            ->orderBy('id')
            ->chunkById(500, function (Collection $lot) {
                DB::table('users')
                    ->whereIn('id', $lot->pluck('id'))
                    ->update(['approved_at' => DB::raw('created_at')]);
            });
    }

    public function down(): void
    {
        // TASK-1711 — conserver AVANT de detruire.
        if (Schema::hasColumn('organizations', 'requires_account_approval')) {
            $this->conserverOuEchouer(
                'organizations.requires_account_approval',
                DB::table('organizations')
                    ->where('requires_account_approval', true)
                    ->orderBy('id')
                    ->get(['id', 'slug', 'requires_account_approval']),
                'id',
                // Le sujet n'est pas une personne : une Organization n'a pas de
                // `users.id`. La colonne reste nulle, et c'est correct — la
                // purge RGPD d'un compte n'a rien a retirer ici.
                null,
            );
        }

        if (Schema::hasColumn('users', 'approved_at')) {
            $this->conserverOuEchouer(
                'users.approved_at',
                // Les comptes EN ATTENTE, et eux seuls : voir le docblock.
                DB::table('users')
                    ->whereNull('approved_at')
                    ->orderBy('id')
                    ->get(['id', 'organization_id', 'email', 'created_at']),
                'id',
                // Le sujet EST la ligne : la conservation porte une adresse
                // email, donc une donnee personnelle, et
                // `UserDeletionExecutor` doit pouvoir la retirer.
                'id',
            );
        }

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'approved_at')) {
                $table->dropIndex(['approved_at']);
                $table->dropColumn('approved_at');
            }
        });

        Schema::table('organizations', function (Blueprint $table) {
            if (Schema::hasColumn('organizations', 'requires_account_approval')) {
                $table->dropColumn('requires_account_approval');
            }
        });
    }

    /**
     * La conservation partagee des rollbacks (TASK-1711).
     *
     * Le bloc est recopie plutot que partage, deliberement : une migration ne
     * doit pas dependre d'une classe applicative qui peut changer, ou
     * disparaitre, apres son execution. Meme doctrine qu'en TASK-1709/1711.
     */
    private function assurerLaConservation(): void
    {
        if (Schema::hasTable(self::CONSERVATION)) {
            if (! Schema::hasColumn(self::CONSERVATION, 'user_id')) {
                Schema::table(self::CONSERVATION, function (Blueprint $table) {
                    // Sans contrainte : SQLite refuse
                    // `ALTER TABLE ... ADD CONSTRAINT FOREIGN KEY`.
                    $table->uuid('user_id')->nullable()->after('surface');
                    $table->index('user_id');
                });
            }

            return;
        }

        Schema::create(self::CONSERVATION, function (Blueprint $table) {
            $table->bigIncrements('conservation_id');
            $table->string('migration');
            $table->string('surface');
            $table->foreignUuid('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('cle')->nullable();
            $table->json('donnees');
            $table->timestamp('archived_at');
            $table->index(['migration', 'surface']);
        });
    }

    /**
     * Conserve des lignes, et n'en detruit aucune si la conservation echoue.
     *
     * L'insertion PRECEDE le `drop` : si elle echoue, PDO leve, le `down()`
     * s'interrompt, et la donnee est encore la.
     *
     * La relecture porte sur `conservation_id > $borne`, jamais sur un
     * horodatage : deux rollbacks successifs tombent dans la MEME seconde, et
     * une borne temporelle compterait les depots du passage precedent.
     *
     * @param  Collection<int, object>  $lignes
     */
    private function conserverOuEchouer(string $surface, Collection $lignes, string $clef, ?string $colonneSujet = null): void
    {
        if ($lignes->isEmpty()) {
            return;
        }

        $this->assurerLaConservation();

        $attendu = $lignes->count();
        $borne = (int) (DB::table(self::CONSERVATION)->max('conservation_id') ?? 0);

        foreach ($lignes->chunk(200) as $lot) {
            DB::table(self::CONSERVATION)->insert($lot->map(fn (object $ligne) => [
                'migration' => self::MARQUE,
                'surface' => $surface,
                'user_id' => $colonneSujet === null ? null : ($ligne->{$colonneSujet} ?? null),
                'cle' => (string) ($ligne->{$clef} ?? ''),
                'donnees' => json_encode((array) $ligne, JSON_UNESCAPED_UNICODE),
                'archived_at' => now(),
            ])->all());
        }

        $conserve = DB::table(self::CONSERVATION)
            ->where('migration', self::MARQUE)
            ->where('surface', $surface)
            ->where('conservation_id', '>', $borne)
            ->count();

        if ($conserve !== $attendu) {
            throw new RuntimeException(
                "TASK-1714 — ROLLBACK INTERROMPU, aucune donnee detruite.\n"
                ."Surface « {$surface} » : {$attendu} ligne(s) a conserver, {$conserve} relue(s) "
                .'dans « '.self::CONSERVATION." ».\n"
                ."Ce `down()` detruirait des decisions qu'il ne sait pas rendre : le reglage\n"
                ."d'approbation d'une Organization, et des comptes laisses EN ATTENTE qui\n"
                ."ressortiraient approuves. Procedure :\n"
                ."  1. verifier que la table « ".self::CONSERVATION." » est accessible en ecriture ;\n"
                ."  2. exporter la surface a la main : pg_dump -Fc -t <table> ;\n"
                ."  3. relancer le rollback, ou arbitrer la perte avec le responsable."
            );
        }
    }
};
