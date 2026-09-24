<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1633 — deux bases nees des memes migrations n'avaient pas le meme
 * schema.
 *
 * ## Ce qui a diverge, et pourquoi
 *
 * `create_referrals_table` et `create_referral_rewards_table` declarent
 * `$table->uuid('organization_id')->nullable()->index()` : un UUID indexe,
 * **sans `foreign()`** — alors que toutes les autres colonnes de reference
 * des memes migrations portent la leur.
 *
 * La contrainte n'apparait que dans `2026_05_28_000002_drop_community_id_from_tables`,
 * et sous condition :
 *
 *     if (Schema::hasColumn('referrals', 'community_id')) { ... }
 *
 * `community_id` n'a jamais existe sur une base creee APRES l'ere Community.
 * La condition y est donc fausse, le bloc saute, et la cle n'est jamais
 * posee. Resultat mesure au deploiement de la 1.632 : la PROD porte les deux
 * contraintes, une installation fraiche n'en a aucune.
 *
 * Un correctif conditionne a une colonne LEGACY ne s'applique jamais sur une
 * base neuve. Cette migration-ci n'est donc conditionnee a RIEN d'historique :
 * elle regarde l'etat reel du schema.
 *
 * ## Le contrat pose est celui de la PROD, releve et non invente
 *
 *     FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
 *
 * non deferrable, sans ON UPDATE explicite. Confirme a l'identique sur la
 * PROD, sur `bouclepro_prod_mirror` et sur `bouclepro_mock_prod`. Il est
 * coherent avec l'intention metier — un parrainage appartient a une
 * Organization et n'a aucun sens sans elle — et avec le modele, qui porte
 * deja `HasOrganizationId`.
 *
 * Les noms suivent la convention Laravel `{table}_{colonne}_foreign`, ceux-la
 * memes que la PROD porte : rien a renommer nulle part.
 *
 * ## Pourquoi on detecte la CONTRAINTE et pas la colonne
 *
 * `Schema::hasColumn()` ne distingue rien ici : la colonne existe des deux
 * cotes. C'est precisement l'erreur qui a cree la divergence. On interroge
 * donc `pg_constraint`, et **par la colonne visee plutot que par le nom** :
 * une base qui porterait la meme contrainte sous un autre nom ne doit pas en
 * recevoir une seconde, equivalente et invisible.
 *
 * ## Pourquoi PostgreSQL seulement
 *
 * Mesure : SQLite refuse `ALTER TABLE ... ADD CONSTRAINT ... FOREIGN KEY`
 * (« syntax error near CONSTRAINT ») — il n'accepte une cle etrangere qu'a la
 * creation de la table. Meme limite que pour `dossiers_holder_xor`
 * (TASK-1130) : PostgreSQL est le moteur de reference, et c'est lui qui porte
 * la garde. Sur SQLite la regle reste tenue par le code applicatif.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const TABLES = ['referrals', 'referral_rewards'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TABLES as $table) {
            if ($this->hasOrganizationForeignKey($table)) {
                // La PROD historique passe par ici : sa contrainte existe
                // deja, avec le bon contrat. On n'y touche pas — la
                // supprimer pour la recreer a l'identique ferait prendre un
                // verrou exclusif a une table de production pour rien.
                continue;
            }

            DB::statement(
                'ALTER TABLE '.$table.' ADD CONSTRAINT '.$table.'_organization_id_foreign '
                .'FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE'
            );
        }
    }

    /**
     * Volontairement vide.
     *
     * Cette migration n'a pas cree la contrainte partout : sur une base
     * historique, elle l'a trouvee deja posee et n'a rien fait. Un `down()`
     * qui la supprimerait detruirait donc une contrainte que TASK-1633 n'a
     * jamais creee — et laisserait la PROD dans un etat plus degrade qu'avant
     * le rollback.
     *
     * Laravel ne conserve aucun etat permettant de savoir, au moment du
     * rollback, laquelle des deux situations s'est presentee ; et le mandat
     * interdit d'inventer un marqueur pour le retenir. Entre supprimer a
     * tort et ne rien faire, ne rien faire est le seul choix qui ne peut pas
     * casser une production.
     *
     * Le depot pratique deja ce `down()` explicitement vide ailleurs
     * (`2026_05_13_000005_drop_point_ledger_reason_check_constraint`).
     */
    public function down(): void
    {
        // Aucun rollback : voir le commentaire ci-dessus.
    }

    /**
     * Une cle etrangere porte-t-elle deja `organization_id` sur cette table ?
     *
     * La recherche se fait sur la COLONNE (`conkey` contient son `attnum`),
     * jamais sur le nom de contrainte : c'est le contrat qui compte, pas son
     * etiquette.
     */
    private function hasOrganizationForeignKey(string $table): bool
    {
        $rows = DB::select(<<<'SQL'
            SELECT 1
            FROM pg_constraint con
            JOIN pg_class rel ON rel.oid = con.conrelid
            JOIN pg_namespace nsp ON nsp.oid = rel.relnamespace
            JOIN pg_attribute att ON att.attrelid = rel.oid AND att.attnum = ANY (con.conkey)
            WHERE con.contype = 'f'
              AND rel.relname = ?
              AND att.attname = 'organization_id'
              AND nsp.nspname = current_schema()
            LIMIT 1
        SQL, [$table]);

        return $rows !== [];
    }
};
