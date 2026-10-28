<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1716 — une fermeture volontaire n'est pas un bannissement.
 *
 * Deux colonnes sur `users`, et rien d'autre :
 *
 * - `self_closed_at` : la personne a ferme son compte ELLE-MEME, horodate.
 * - `erasure_requested_at` : elle a demande en plus l'effacement de ses
 *   donnees personnelles. Une DEMANDE, pas une execution.
 *
 * ## Pourquoi pas un troisieme etat
 *
 * `banned_at` porte deja la desactivation sur une quarantaine de sites : les
 * deux gardes de groupe (`EnsureUserIsNotBanned`, declaree dans `web` ET
 * `api`), le refus de connexion de `LoginRequest`, trois scopes de requete,
 * `isDisplayableIn()`, `publicAvatarUrl()`, et la revocation automatique des
 * jetons dans `User::booted()`. Un etat parallele « ferme » devrait etre
 * recable partout, et ouvrirait un trou a chaque site oublie.
 *
 * `self_closed_at` ne remplace donc PAS `banned_at` : il le QUALIFIE.
 * L'invariant, pose par `ProfileController::close()` et verifie par une garde :
 *
 *     self_closed_at IS NOT NULL  =>  banned_at IS NOT NULL
 *
 * La lecture devient alors :
 *
 * | `banned_at` | `self_closed_at` | etat                        |
 * | ----------- | ---------------- | --------------------------- |
 * | NULL        | NULL             | compte actif                |
 * | non nul     | NULL             | bannissement administratif  |
 * | non nul     | non nul          | fermeture volontaire        |
 *
 * ## La non-retroactivite, ici, ne coute AUCUN backfill
 *
 * Contrairement a TASK-1714 ou `approved_at IS NULL` signifiait « en attente »
 * et devait donc etre rempli pour les 57 comptes existants, `NULL` est ici la
 * valeur JUSTE pour tout compte deja en base : aucun n'a ferme son compte
 * lui-meme, et les comptes deja bannis l'ont ete par un administrateur. La
 * table ressort donc inchangee ligne par ligne, et le tableau ci-dessus les
 * classe correctement sans qu'on ecrive une seule valeur.
 *
 * ## Un seul index, et il n'est pas decoratif
 *
 * `erasure_requested_at` est indexe : le SuperAdmin filtre les demandes
 * d'effacement en attente (`WHERE erasure_requested_at IS NOT NULL`), et c'est
 * une poignee de lignes dans une table qui en compte des milliers.
 *
 * `self_closed_at` ne l'est PAS, et c'est delibere : aucune requete ne filtre
 * dessus. Il est lu sur un modele DEJA charge — la ligne de `LoginRequest`,
 * celle de l'ecran SuperAdmin. Poser un index « au cas ou » ferait payer
 * chaque ecriture de `users` pour une lecture qui n'arrive jamais.
 *
 * ## Ce que `down()` conserve
 *
 * Les deux colonnes portent des decisions HUMAINES qu'aucun `up()` ne sait
 * regenerer : qui a ferme son compte, et qui a demande un effacement. Perdre
 * la seconde serait pire que perdre la premiere — une demande d'effacement
 * oubliee est un manquement, pas un detail. Les deux sont donc conservees
 * selon la doctrine de TASK-1711, AVANT toute destruction.
 */
return new class extends Migration
{
    private const MARQUE = '2026_10_28_090000_add_account_closure_to_users';

    private const CONSERVATION = 'rollback_conservation';

    public function up(): void
    {
        // TASK-1711 — la table de conservation existe des l'aller, vide : un
        // `down()` qui doit d'abord creer une table est plus fragile qu'un
        // `down()` qui ecrit dans une table deja la.
        $this->assurerLaConservation();

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'self_closed_at')) {
                // Juste apres `banned_at`, la colonne qu'elle qualifie : qui
                // lit le schema voit les deux cote a cote.
                $table->timestamp('self_closed_at')->nullable()->after('banned_at');
            }

            if (! Schema::hasColumn('users', 'erasure_requested_at')) {
                $table->timestamp('erasure_requested_at')->nullable()->after('self_closed_at');
                $table->index('erasure_requested_at');
            }
        });

        // AUCUN backfill. Voir le docblock : `NULL` est la valeur juste pour
        // chaque ligne existante, et ecrire quoi que ce soit ici inventerait
        // des fermetures qui n'ont jamais eu lieu.
    }

    public function down(): void
    {
        // TASK-1711 — conserver AVANT de detruire.
        if (Schema::hasColumn('users', 'self_closed_at')) {
            $this->conserverOuEchouer(
                'users.self_closed_at',
                DB::table('users')
                    ->whereNotNull('self_closed_at')
                    ->orderBy('id')
                    ->get(['id', 'organization_id', 'email', 'banned_at', 'self_closed_at']),
                'id',
                // Le sujet EST la ligne : la conservation porte une adresse
                // email, donc une donnee personnelle, et
                // `UserDeletionExecutor` doit pouvoir la retirer.
                'id',
            );
        }

        if (Schema::hasColumn('users', 'erasure_requested_at')) {
            $this->conserverOuEchouer(
                'users.erasure_requested_at',
                DB::table('users')
                    ->whereNotNull('erasure_requested_at')
                    ->orderBy('id')
                    ->get(['id', 'organization_id', 'email', 'erasure_requested_at']),
                'id',
                'id',
            );
        }

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'erasure_requested_at')) {
                $table->dropIndex(['erasure_requested_at']);
                $table->dropColumn('erasure_requested_at');
            }

            if (Schema::hasColumn('users', 'self_closed_at')) {
                $table->dropColumn('self_closed_at');
            }
        });
    }

    /**
     * La conservation partagee des rollbacks (TASK-1711).
     *
     * Le bloc est recopie plutot que partage, deliberement : une migration ne
     * doit pas dependre d'une classe applicative qui peut changer, ou
     * disparaitre, apres son execution. Meme doctrine qu'en TASK-1709/1711/1714.
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
                "TASK-1716 — ROLLBACK INTERROMPU, aucune donnee detruite.\n"
                ."Surface « {$surface} » : {$attendu} ligne(s) a conserver, {$conserve} relue(s) "
                .'dans « '.self::CONSERVATION." ».\n"
                ."Ce `down()` detruirait des decisions qu'il ne sait pas rendre : les comptes\n"
                ."fermes par leur proprietaire — qui ressortiraient en simples bannis — et les\n"
                ."demandes d'effacement de donnees personnelles, qui disparaitraient sans trace.\n"
                ."Procedure :\n"
                ."  1. verifier que la table « ".self::CONSERVATION." » est accessible en ecriture ;\n"
                ."  2. exporter la surface a la main : pg_dump -Fc -t <table> ;\n"
                ."  3. relancer le rollback, ou arbitrer la perte avec le responsable."
            );
        }
    }
};
