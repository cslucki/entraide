<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1731 (A5b) — la liaison explicite et revocable entre deux comptes.
 *
 * ## La paire est ORDONNEE, et ce n'est pas un detail d'implementation
 *
 * Une liaison est bidirectionnelle : A lie a B, c'est B lie a A. Stocker
 * `user_id` et `linked_user_id` aurait laisse la base accepter les DEUX lignes
 * (A,B) et (B,A) — deux verites pour un seul fait, et c'est la seconde qu'on
 * oublie de revoquer. En rangeant toujours le plus petit identifiant dans
 * `user_a_id`, un unique index sur la paire suffit a rendre la bidirectionnalite
 * et l'unicite STRUCTURELLES, pas seulement tenues par le code.
 *
 * Exigence MASTER (30/10) : « unicite de la paire et interdiction de
 * l'auto-liaison EN BASE ».
 *
 * ## L'auto-liaison est interdite PAR LA BASE sur les DEUX moteurs
 *
 * Exigence MASTER, maintenue apres verification : une CI verte sur SQLite ne
 * demontre rien si la regle n'y est tenue que par le code applicatif.
 *
 * **Il n'existe aucune declaration portable.** Verifie sur la version
 * installee, Laravel 13.7.0 : le `Blueprint` n'expose aucune methode de
 * contrainte `CHECK`, et aucune grammaire de schema n'en compile. Deux
 * expressions d'une meme regle sont donc necessaires :
 *
 *  - **PostgreSQL** : `CHECK (user_a_id <> user_b_id)`, ajoutee en
 *    `ALTER TABLE`. Elle couvre `INSERT` ET `UPDATE` d'office.
 *  - **SQLite** : deux DECLENCHEURS, `BEFORE INSERT` et `BEFORE UPDATE`, avec
 *    `RAISE(ABORT)`. SQLite ne sait pas ajouter une contrainte apres coup,
 *    mais il sait poser un declencheur — et le refus vient bien de la BASE
 *    (`SQLSTATE[23000]`), donc il tient contre n'importe quel ecrivain, y
 *    compris du SQL direct qui contournerait le service.
 *
 * **DEUX declencheurs et pas un** : c'est la limite du mecanisme, et elle se
 * paie en oubli. Une contrainte `CHECK` surveille la ligne ; un declencheur ne
 * surveille que l'operation qu'il ecoute. N'ecouter que l'insertion laisserait
 * transformer une paire valide en auto-liaison par une simple mise a jour.
 *
 * **Le rollback** : aucune suppression explicite n'est necessaire — les deux
 * moteurs emportent ces objets avec la table (`DROP TABLE` detruit les
 * declencheurs SQLite comme la contrainte PostgreSQL). Ce n'est pas suppose :
 * un test fait `down()` puis `up()` et verifie que l'interdiction est de
 * nouveau tenue.
 *
 * ## Pas d'`organization_id`, et c'est le sujet
 *
 * Cette table est INTER-TENANT par construction : elle relie deux comptes de
 * deux Organizations differentes. Elle ne peut donc pas porter
 * `organization_id`, ce qui la fait tomber dans
 * `ScenarioSandboxPreflight::TABLES_HORS_PORTEE` — ou elle est declaree avec sa
 * raison. Elle est aussi inscrite au `UserDataLifecycleRegistry`, une entree
 * par colonne. Ces deux declarations ne sont pas optionnelles : leurs tests de
 * completude exigent une correspondance exacte au schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_links', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // `cascadeOnDelete` des deux cotes : un lien ne survit a aucun de
            // ses deux comptes. C'est la CEINTURE ; la bretelle est l'entree
            // POLICY_DELETE du registre de cycle de vie, qui fait que la
            // suppression auditee les compte et les retire explicitement.
            $table->foreignUuid('user_a_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('user_b_id')->constrained('users')->cascadeOnDelete();

            // Qui a cree le lien. Trace seulement : ce n'est NI une propriete,
            // ni un privilege — la revocation est ouverte aux deux comptes.
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['user_a_id', 'user_b_id'], 'account_links_pair_unique');
        });

        $this->interdireLAutoLiaison();
    }

    /**
     * La meme regle, dans les deux langues que parlent les deux moteurs.
     */
    private function interdireLAutoLiaison(): void
    {
        $connexion = Schema::getConnection();

        if ($connexion->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE account_links ADD CONSTRAINT account_links_not_self CHECK (user_a_id <> user_b_id)'
            );

            return;
        }

        if ($connexion->getDriverName() !== 'sqlite') {
            return;
        }

        foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffixe => $operation) {
            DB::statement(sprintf(
                'CREATE TRIGGER account_links_not_self_%s BEFORE %s ON account_links '
                .'FOR EACH ROW WHEN NEW.user_a_id = NEW.user_b_id '
                ."BEGIN SELECT RAISE(ABORT, 'account_links: un compte ne peut pas etre lie a lui-meme'); END",
                $suffixe,
                $operation,
            ));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('account_links');
    }
};
