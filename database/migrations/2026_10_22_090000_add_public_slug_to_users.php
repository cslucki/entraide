<?php

use App\Models\User;
use App\Services\UserSlugService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1693 bug Z — l'adresse publique d'un membre.
 *
 * Deux objets, pas un :
 *
 * - `users.slug` porte l'adresse COURANTE. Nullable : un compte sans nom
 *   exploitable garde son UUID jusqu'a ce que la personne choisisse.
 * - `user_slugs` est le REGISTRE : il garde chaque slug attribue, courant ou
 *   passe, et c'est lui qui empeche le recyclage. Sans lui, changer de slug
 *   libererait le precedent, et quiconque le reprendrait heriterait des liens
 *   deja partages vers quelqu'un d'autre.
 *
 * Unicite COMPOSITE `['organization_id', 'slug']` dans les deux cas :
 * Organization = Tenant, et deux Marie Dupont de deux Organizations
 * differentes ne doivent pas se gener. Meme forme que `tags`, passe d'un
 * unique global a un composite le 25/06/2026.
 *
 * ## La reprise
 *
 * Les comptes existants recoivent un slug `prenom-nom`, suffixe en cas de
 * collision. Arbitrage Cyril du 22/10 : leur nom est deja visible dans
 * l'annuaire pour exactement les memes personnes qui peuvent ouvrir le profil,
 * donc l'exposition supplementaire est quasi nulle et le benefice immediat.
 * Les comptes sans nom exploitable restent en UUID — fabriquer un
 * `membre-1234` serait plus joli et ne voudrait rien dire.
 */
return new class extends Migration
{
    private const MARQUE = '2026_10_22_090000_add_public_slug_to_users';

    private const CONSERVATION = 'rollback_conservation';

    public function up(): void
    {
        // TASK-1711 — la table de conservation existe des l'aller, vide.
        //
        // La creer seulement au `down()` la rendait absente de toute base migree
        // normalement : declaree au registre d'integrite mais inexistante, elle
        // faisait rendre 500 a l'ecran « Integrite des donnees ». Et un `down()`
        // qui doit d'abord creer une table est plus fragile qu'un `down()` qui
        // ecrit dans une table deja la.
        $this->assurerLaConservation();

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'slug')) {
                $table->string('slug', 60)->nullable()->after('name');
                $table->unique(['organization_id', 'slug'], 'users_organization_slug_unique');
            }
        });

        if (! Schema::hasTable('user_slugs')) {
            Schema::create('user_slugs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
                $table->string('slug', 60);
                $table->boolean('is_current')->default(true);
                $table->timestamps();

                $table->unique(['organization_id', 'slug'], 'user_slugs_organization_slug_unique');
                $table->index(['user_id', 'is_current']);
            });
        }

        $service = app(UserSlugService::class);

        User::query()->whereNull('slug')->orderBy('created_at')->chunkById(100, function ($users) use ($service) {
            foreach ($users as $user) {
                $service->attribuerDepuisLeNom($user);
            }
        });
    }

    public function down(): void
    {
        // TASK-1711 — conserver AVANT de detruire.
        //
        // Ce `down()` est le plus sensible des quatre : il efface les slugs
        // publics de tous les comptes. Ces slugs n'existaient pas avant la
        // migration, donc les retirer est fidele — mais ils ont ete PUBLIES, et
        // des liens externes les portent. Et leur `up()` passe par
        // `UserSlugService`, qui ne regenererait pas forcement les memes : sans
        // conservation, un aller-retour casse definitivement ces liens.
        if (Schema::hasTable('user_slugs')) {
            $this->conserverOuEchouer(
                'user_slugs',
                DB::table('user_slugs')->orderBy('id')->get(),
                'id',
            );
        }

        if (Schema::hasColumn('users', 'slug')) {
            $this->conserverOuEchouer(
                'users.slug',
                DB::table('users')->whereNotNull('slug')->orderBy('id')
                    ->get(['id', 'organization_id', 'slug']),
                'id',
            );
        }

        Schema::dropIfExists('user_slugs');

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'slug')) {
                $table->dropUnique('users_organization_slug_unique');
                $table->dropColumn('slug');
            }
        });
    }

    /**
     * La conservation partagee des rollbacks de la release 1.709 (TASK-1711).
     *
     * Quatre `down()` de cette release etaient purement destructeurs : ils
     * supprimaient des donnees que leur `up()` ou les utilisateurs avaient
     * creees, et aucun dump pris AVANT le deploiement ne pouvait les couvrir.
     * Decision de Cyril : « je refuse toute perte de donnees implicite ».
     *
     * Chaque migration depose donc, AVANT de detruire, les lignes concernees
     * dans cette table, et ne lit jamais que SES propres depots — d'ou la
     * colonne `migration`. Si la conservation ne peut pas etre prouvee, le
     * `down()` ECHOUE et ne detruit rien.
     *
     * Le bloc est recopie dans les quatre fichiers, deliberement : une
     * migration ne doit pas dependre d'une classe applicative qui peut changer,
     * ou disparaitre, apres son execution. Meme doctrine qu'en TASK-1709.
     */
    private function assurerLaConservation(): void
    {
        if (Schema::hasTable(self::CONSERVATION)) {
            return;
        }

        Schema::create(self::CONSERVATION, function (Blueprint $table) {
            $table->bigIncrements('conservation_id');
            $table->string('migration');
            $table->string('surface');
            $table->string('cle')->nullable();
            $table->json('donnees');
            $table->timestamp('archived_at');
            $table->index(['migration', 'surface']);
        });
    }

    /**
     * Conserve des lignes, et n'en detruit aucune si la conservation echoue.
     *
     * Ce qui protege VRAIMENT, et c'est mesure : l'insertion PRECEDE le `drop`.
     * Si elle echoue, PDO leve, le `down()` s'interrompt, et la donnee est
     * encore la. C'est la demande de Cyril — « echouer explicitement » plutot
     * que detruire a l'aveugle. Un sabotage qui remonte le `drop` au-dessus de
     * la conservation fait rougir deux gardes.
     *
     * La relecture qui suit, et l'exception qu'elle peut lever, DOUBLENT cette
     * protection sans rien y ajouter d'observable : une insertion partielle qui
     * ne leverait pas n'existe pas avec PDO. Les deux sabotages qui la
     * neutralisent — retirer l'interruption, remplacer la relecture par le
     * compteur local — laissent les neuf gardes VERTES. Elle est donc
     * defensive, pas load-bearing, et mieux vaut l'ecrire que de pretendre
     * l'avoir prouvee : son role est de faire echouer bruyamment une
     * modification future de ce bloc qui casserait l'insertion en silence.
     *
     * La relecture porte sur `conservation_id > $borne`, jamais sur un
     * horodatage : deux rollbacks successifs tombent dans la MEME seconde, et
     * une borne temporelle comptait alors les depots du passage precedent — ce
     * `down()` s'interrompait en annoncant une perte qui n'existait pas. Une
     * garde l'a trouve.
     *
     * Une surface vide ne conserve rien et n'echoue pas : il n'y a rien a
     * perdre.
     *
     * @param  Collection<int, object>  $lignes
     */
    private function conserverOuEchouer(string $surface, Collection $lignes, string $clef): void
    {
        if ($lignes->isEmpty()) {
            return;
        }

        $this->assurerLaConservation();

        $attendu = $lignes->count();

        // La borne est l'IDENTIFIANT du dernier depot, pas un horodatage.
        //
        // `archived_at >= now()` semblait suffire et ne l'etait pas : deux
        // rollbacks successifs tombent dans la MEME seconde, la relecture
        // comptait alors les depots du passage precedent, et ce `down()`
        // s'interrompait en annoncant une perte qui n'existait pas. Un
        // discriminant temporel ne separe pas deux instants ; une sequence,
        // oui.
        $borne = (int) (DB::table(self::CONSERVATION)->max('conservation_id') ?? 0);

        foreach ($lignes->chunk(200) as $lot) {
            DB::table(self::CONSERVATION)->insert($lot->map(fn (object $ligne) => [
                'migration' => self::MARQUE,
                'surface' => $surface,
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
                "TASK-1711 — ROLLBACK INTERROMPU, aucune donnee detruite.\n"
                ."Surface « {$surface} » : {$attendu} ligne(s) a conserver, {$conserve} relue(s) "
                .'dans « '.self::CONSERVATION." ».\n"
                ."Ce `down()` detruirait des donnees qu'il ne sait pas rendre. Procedure :\n"
                .'  1. verifier que la table « '.self::CONSERVATION." » est accessible en ecriture ;\n"
                ."  2. exporter la surface a la main : pg_dump -Fc -t <table> ;\n"
                ."  3. relancer le rollback, ou arbitrer la perte avec le responsable de la release.\n"
                .'Reference : TASK-1710, runbook de la release 1.709, etape 3.0.'
            );
        }
    }
};
