<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use App\Services\UserDataLifecycleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1711 — aucun `down()` de la release 1.709 ne detruit de donnee.
 *
 * Cyril : « je refuse toute perte de donnees implicite. Je privilegie des
 * `down()` non destructeurs. Si certaines migrations ne peuvent pas etre
 * inversees sans perte, elles doivent echouer explicitement, avec une procedure
 * de restauration documentee. »
 *
 * ## Le defaut, et comment il avait echappe a TASK-1709
 *
 * TASK-1709 avait prouve la reversibilite des **gabarits email** — son objet —
 * a 24/24 lignes sur onze champs. Mais quatre `down()` de la MEME release
 * etaient purement destructeurs, et personne ne les avait regardes :
 *
 * | migration | son `down()` | ce qui disparaissait |
 * |---|---|---|
 * | `add_public_slug_to_users` | `dropIfExists` + `dropColumn` | tous les slugs publics |
 * | `create_dossier_file_shares_table` | `dropIfExists` | les partages de fichiers |
 * | `create_dossier_file_loop_shares_table` | `dropIfExists` | les partages avec une Boucle |
 * | `add_file_attachment_columns_to_messages` | `dropColumn` x5 | les pieces jointes |
 *
 * **Aucune sauvegarde prise AVANT le deploiement ne pouvait les couvrir** : ces
 * lignes n'existent qu'apres, puisque ce sont les capacites que la release
 * apporte qui les produisent.
 *
 * ## Ce que ce fichier mesure
 *
 * Pas la fidelite d'un retour en arriere — ces `down()` doivent bien faire
 * disparaitre leurs tables et leurs colonnes, c'est leur travail. Ce qui est
 * fige ici, c'est qu'**aucune ligne ne disparait sans avoir ete conservee
 * d'abord**, et que la conservation est RELUE avant la destruction.
 */
class TASK1711NonDestructiveRollbackTest extends TestCase
{
    use RefreshDatabase;

    private const CONSERVATION = 'rollback_conservation';

    /** L'ordre est celui de l'application : il compte, `down()` se joue a l'envers. */
    private const MIGRATIONS = [
        '2026_10_22_090000_add_public_slug_to_users',
        '2026_10_24_200000_create_dossier_file_shares_table',
        '2026_10_24_210000_create_dossier_file_loop_shares_table',
        '2026_10_26_090000_add_file_attachment_columns_to_messages',
    ];

    private function migration(int $rang): object
    {
        return require database_path('migrations/'.self::MIGRATIONS[$rang].'.php');
    }

    /**
     * Les lignes conservees pour une surface, decodees.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function conservees(string $surface): Collection
    {
        if (! Schema::hasTable(self::CONSERVATION)) {
            return collect();
        }

        return DB::table(self::CONSERVATION)
            ->where('surface', $surface)
            ->orderBy('conservation_id')
            ->get()
            ->map(fn (object $l) => json_decode((string) $l->donnees, true));
    }

    // ── La table de conservation ────────────────────────────────────────────

    /**
     * Elle existe des l'ALLER, et vide.
     *
     * La creer seulement au `down()` la rendait absente de toute base migree
     * normalement. Ce n'etait pas theorique : l'ecran « Integrite des donnees »
     * rendait **500** — `no such table: rollback_conservation`.
     */
    public function test_la_table_de_conservation_existe_des_l_aller_et_vide(): void
    {
        $this->assertTrue(
            Schema::hasTable(self::CONSERVATION),
            'La table de conservation n\'existe pas apres les migrations.'
        );

        $this->assertSame(
            0,
            DB::table(self::CONSERVATION)->count(),
            'La conservation n\'est pas vide en marche normale : un `up()` y ecrit.'
        );
    }

    /**
     * Elle ne porte PAS d'`organization_id`, et c'est ce qui la dispense des
     * registres de tenant.
     *
     * J'avais d'abord declare cette table a `UnprotectedReferenceRegistry` et a
     * `DatasetRegistry`, en transposant le precedent de TASK-1709 — ou la table
     * de trace, elle, porte un `organization_id` nullable. Les deux gardes ont
     * rougi : la condition qui declenche ces registres est la PRESENCE de cette
     * colonne, pas le fait d'etre une table d'archive. Si quelqu'un l'ajoute un
     * jour, ce test le dira, et il faudra declarer.
     */
    public function test_la_conservation_n_est_pas_une_table_rattachee_a_un_tenant(): void
    {
        $this->assertFalse(
            Schema::hasColumn(self::CONSERVATION, 'organization_id'),
            'La conservation porte un `organization_id` : elle doit alors etre classifiee dans '
            .'`DatasetRegistry` et `UnprotectedReferenceRegistry`, sinon les gardes de couverture rougiront.'
        );
    }

    // ── Surface 1 : les slugs publics ───────────────────────────────────────

    /**
     * Le `down()` conserve les slugs publics avant de les effacer.
     *
     * La plus sensible des quatre : ces slugs ont ete PUBLIES, des liens
     * externes les portent, et leur `up()` passe par `UserSlugService` — une
     * re-attribution ne garantit pas les memes valeurs.
     */
    public function test_les_slugs_publics_sont_conserves_avant_d_etre_effaces(): void
    {
        // `RefreshDatabase` part d'une base VIDE : la migration n'a eu aucun
        // compte a qui attribuer un slug. On cree donc des comptes
        // representatifs, puis on rejoue son `up()` — c'est lui qui attribue.
        $organisation = Organization::factory()->create();
        User::factory()->count(3)->create(['organization_id' => $organisation->id]);

        $this->migration(0)->up();

        $slugsAvant = DB::table('users')->whereNotNull('slug')->pluck('slug', 'id');
        $this->assertGreaterThan(0, $slugsAvant->count(), 'Aucun slug attribue : le test ne mesurerait rien.');

        $lignesUserSlugs = DB::table('user_slugs')->count();
        $this->assertGreaterThan(0, $lignesUserSlugs, 'L\'historique `user_slugs` est vide : le test ne mesurerait rien.');

        $this->migration(0)->down();

        // Le `down()` fait bien son travail : la colonne et la table partent.
        $this->assertFalse(Schema::hasColumn('users', 'slug'));
        $this->assertFalse(Schema::hasTable('user_slugs'));

        // Et rien n'a ete perdu.
        $conserves = $this->conservees('users.slug');
        $this->assertCount($slugsAvant->count(), $conserves, 'Des slugs ont disparu sans etre conserves.');

        foreach ($conserves as $ligne) {
            $this->assertSame(
                $slugsAvant[$ligne['id']],
                $ligne['slug'],
                'Le slug conserve ne correspond pas a celui qui a ete efface.'
            );
        }

        $this->assertCount(
            $lignesUserSlugs,
            $this->conservees('user_slugs'),
            'L\'historique `user_slugs` a ete detruit sans etre conserve.'
        );
    }

    // ── Surfaces 2 et 3 : les partages de fichiers ──────────────────────────

    public function test_les_partages_de_fichiers_sont_conserves_avant_le_drop(): void
    {
        $c = $this->contexteDePartage();

        DB::table('dossier_file_shares')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $c['organisation'],
            'dossier_file_id' => $c['fichier'],
            'user_id' => $c['membre'],
            'shared_by' => $c['membre'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('dossier_file_loop_shares')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $c['organisation'],
            'dossier_file_id' => $c['fichier'],
            'loop_id' => $c['boucle'],
            'shared_by' => $c['membre'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Ordre INVERSE, comme un vrai rollback.
        $this->migration(2)->down();
        $this->migration(1)->down();

        $this->assertFalse(Schema::hasTable('dossier_file_shares'));
        $this->assertFalse(Schema::hasTable('dossier_file_loop_shares'));

        $partageMembre = $this->conservees('dossier_file_shares');
        $this->assertCount(1, $partageMembre, 'Le partage avec un membre a ete detruit sans etre conserve.');
        $this->assertSame($c['membre'], $partageMembre->first()['user_id']);
        $this->assertSame($c['fichier'], $partageMembre->first()['dossier_file_id']);

        $partageBoucle = $this->conservees('dossier_file_loop_shares');
        $this->assertCount(1, $partageBoucle, 'Le partage avec une Boucle a ete detruit sans etre conserve.');
        $this->assertSame($c['boucle'], $partageBoucle->first()['loop_id']);
    }

    // ── Surface 4 : les pieces jointes ──────────────────────────────────────

    /**
     * Les metadonnees d'une piece jointe sont conservees.
     *
     * Elles sont la SEULE reference vers un fichier du disque prive
     * `message_files`, qui n'expose ni route `serve` ni `url` (constat de
     * TASK-1704). Sans elles, les octets restent sur le disque, orphelins et
     * introuvables.
     */
    public function test_les_pieces_jointes_sont_conservees_avant_le_drop_des_colonnes(): void
    {
        $messageId = $this->messagePorteurDUnePieceJointe();

        DB::table('messages')->where('id', $messageId)->update([
            'file_path' => 'message_files/2026/10/piece-jointe.pdf',
            'file_disk' => 'local',
            'file_name' => 'Devis OFSH.pdf',
            'file_mime' => 'application/pdf',
            'file_size' => 48_213,
        ]);

        $this->migration(3)->down();

        $this->assertFalse(Schema::hasColumn('messages', 'file_path'));

        $conserve = $this->conservees('messages.file_attachments');
        $this->assertCount(1, $conserve, 'La piece jointe a ete detruite sans etre conservee.');

        $ligne = $conserve->first();
        $this->assertSame('message_files/2026/10/piece-jointe.pdf', $ligne['file_path']);
        $this->assertSame('Devis OFSH.pdf', $ligne['file_name']);
        $this->assertSame('application/pdf', $ligne['file_mime']);
        $this->assertSame(48_213, (int) $ligne['file_size']);
        $this->assertSame('local', $ligne['file_disk']);
    }

    // ── Les cas limites ─────────────────────────────────────────────────────

    /**
     * Une surface VIDE ne conserve rien, et n'echoue pas.
     *
     * Sans ce volet, le correctif aurait pu ecrire une ligne
     * inconditionnellement — et la conservation se remplirait de bruit a chaque
     * rollback d'une base neuve.
     */
    public function test_une_surface_vide_ne_conserve_rien(): void
    {
        DB::table('dossier_file_shares')->delete();
        DB::table('dossier_file_loop_shares')->delete();

        $this->migration(2)->down();
        $this->migration(1)->down();

        $this->assertCount(0, $this->conservees('dossier_file_shares'));
        $this->assertCount(0, $this->conservees('dossier_file_loop_shares'));
    }

    /**
     * Un second rollback n'ecrase pas le depot du premier.
     *
     * La conservation est un journal : chaque passage ajoute. Un `down()` qui
     * remplacerait ferait perdre le depot le plus ancien — donc le seul qui
     * contienne l'etat d'origine.
     */
    public function test_deux_passages_cumulent_les_depots(): void
    {
        $organisation = Organization::factory()->create();
        User::factory()->count(2)->create(['organization_id' => $organisation->id]);

        $this->migration(0)->up();
        $this->migration(0)->down();

        $premier = DB::table(self::CONSERVATION)->count();
        $this->assertGreaterThan(0, $premier, 'Le premier passage n\'a rien conserve : le test ne mesurerait rien.');

        $this->migration(0)->up();
        $this->migration(0)->down();

        $this->assertGreaterThan(
            $premier,
            DB::table(self::CONSERVATION)->count(),
            'Le second passage a ecrase le depot du premier.'
        );
    }

    /**
     * Si la conservation est IMPOSSIBLE, le `down()` ECHOUE.
     *
     * C'est la demande explicite de Cyril : « si certaines migrations ne peuvent
     * pas etre inversees sans perte, elles doivent echouer explicitement ». Un
     * `down()` qui detruirait quand il n'arrive pas a conserver serait pire que
     * le defaut d'origine — il aurait l'air d'avoir fonctionne.
     *
     * ## Ce que ce test ne peut PAS prouver, et pourquoi il ne le pretend pas
     *
     * Il ne verifie rien APRES l'exception. Deux raisons, et la seconde est la
     * vraie :
     *
     * 1. PostgreSQL rejette toute commande qui suit une erreur dans la meme
     *    transaction (`25P02`), et `RefreshDatabase` enveloppe le test. Mes
     *    assertions post-echec rougissaient donc sur PG seulement.
     * 2. Surtout : le DDL est **transactionnel** sur les deux moteurs. Meme si
     *    le `drop` avait lieu AVANT la conservation, le rollback de fin de test
     *    rendrait la colonne — l'assertion « rien n'a ete detruit » serait vraie
     *    par le rollback, pas par l'ordre du code. Un faux vert.
     *
     * L'ordre est donc verifie par la garde structurelle suivante, qui est le
     * seul endroit ou il est reellement observable.
     */
    public function test_une_conservation_impossible_interrompt_le_down(): void
    {
        $organisation = Organization::factory()->create();
        User::factory()->count(2)->create(['organization_id' => $organisation->id]);
        $this->migration(0)->up();

        $this->assertGreaterThan(0, DB::table('users')->whereNotNull('slug')->count());

        // La conservation devient inutilisable : `assurerLaConservation()` la
        // laisse en place puisqu'elle existe, et l'insertion echouera.
        Schema::dropIfExists(self::CONSERVATION);
        Schema::create(self::CONSERVATION, function ($table) {
            $table->bigIncrements('conservation_id');
            // ni `migration`, ni `surface`, ni `donnees`.
        });

        $this->expectException(\Throwable::class);

        $this->migration(0)->down();
    }

    /**
     * Dans les QUATRE migrations, la conservation PRECEDE la destruction.
     *
     * C'est le seul endroit ou l'ordre est observable : a l'execution, le DDL
     * transactionnel des deux moteurs le masque (voir la garde precedente).
     *
     * La mesure porte sur la position des appels dans le corps du `down()`.
     * Grossier ? Oui — et c'est precisement ce qui le rend fiable : deplacer un
     * `drop` avant sa conservation fait rougir ce test, et rien d'autre ne le
     * ferait.
     */
    public function test_la_conservation_precede_la_destruction_dans_les_quatre_migrations(): void
    {
        foreach (self::MIGRATIONS as $nom) {
            $source = (string) file_get_contents(database_path('migrations/'.$nom.'.php'));

            $debut = strpos($source, 'public function down(): void');
            $this->assertNotFalse($debut, "{$nom} : pas de `down()`.");

            $corps = substr($source, $debut);
            $corps = substr($corps, 0, strpos($corps, "\n    }\n") ?: strlen($corps));

            $conservation = strpos($corps, 'conserverOuEchouer');
            $this->assertNotFalse($conservation, "{$nom} : son `down()` ne conserve rien avant de detruire.");

            foreach (['Schema::dropIfExists', 'dropColumn'] as $destruction) {
                $position = strpos($corps, $destruction);

                if ($position === false) {
                    continue;
                }

                $this->assertLessThan(
                    $position,
                    $conservation,
                    "{$nom} : `{$destruction}` precede la conservation — la donnee serait detruite avant d'etre gardee."
                );
            }
        }
    }

    // ── RGPD : la conservation ne survit pas a la suppression d'un compte ──

    /**
     * Supprimer un compte efface SES lignes conservees, et PRESERVE les autres.
     *
     * Le finding qui a motive cette garde : `rollback_conservation` garde des
     * slugs publics derives des noms et prenoms — des donnees personnelles. La
     * garde `every user foreign key is declared in lifecycle registry` etait
     * verte, mais pour une raison qu'il fallait nommer : la table stockait les
     * identifiants dans du JSON, sans cle etrangere, et echappait donc au
     * critere du registre au lieu d'y etre conforme.
     *
     * La correction passe par le mecanisme CANONIQUE, et par lui seul : une
     * vraie cle etrangere `user_id` en `cascadeOnDelete`, plus une entree
     * `POLICY_DELETE` dans `UserDataLifecycleRegistry`. Aucun code
     * d'execution nouveau — `UserDeletionExecutor` applique deja les
     * politiques du registre.
     *
     * Les DEUX moities sont mesurees, et aucune ne suffit : un `delete` trop
     * large effacerait la conservation des autres membres, et c'est
     * exactement la perte de donnees que ce lot existe pour empecher.
     */
    public function test_supprimer_un_compte_efface_ses_lignes_conservees_et_preserve_les_autres(): void
    {
        $organisation = Organization::factory()->create();
        $partant = User::factory()->create(['organization_id' => $organisation->id]);
        $restant = User::factory()->create(['organization_id' => $organisation->id]);

        $autreOrganisation = Organization::factory()->create();
        $tiers = User::factory()->create(['organization_id' => $autreOrganisation->id]);

        $this->migration(0)->up();

        // Les SLUGS, captures avant le rollback : c'est la donnee personnelle,
        // et c'est elle qu'il faut chercher ensuite.
        $slugs = DB::table('users')->whereNotNull('slug')->pluck('slug', 'id');

        $this->migration(0)->down();

        // La mesure porte sur le CONTENU conserve, pas sur la colonne `user_id`.
        //
        // Ma premiere version comptait `where('user_id', $id)`, et un sabotage
        // qui remplacait `cascadeOnDelete` par `nullOnDelete` restait VERT : la
        // ligne survivait, son `user_id` passait a NULL, et le compteur rendait
        // zero — avec le slug toujours dans le JSON. Je mesurais l'index, pas la
        // donnee.
        $presence = fn (string $slug) => DB::table(self::CONSERVATION)
            ->where('donnees', 'like', '%"'.$slug.'"%')
            ->count();

        $this->assertGreaterThan(0, $presence($slugs[$partant->id]), 'Rien n\'est conserve pour ce compte : le test ne mesurerait rien.');
        $this->assertGreaterThan(0, $presence($slugs[$restant->id]));
        $this->assertGreaterThan(0, $presence($slugs[$tiers->id]));

        $avantRestant = $presence($slugs[$restant->id]);
        $avantTiers = $presence($slugs[$tiers->id]);

        // La cle etrangere `cascadeOnDelete` fait le travail : c'est le
        // mecanisme de la base, pas un geste applicatif qu'on pourrait oublier.
        DB::table('users')->where('id', $partant->id)->delete();

        $this->assertSame(
            0,
            $presence($slugs[$partant->id]),
            'Le slug du compte supprime survit dans la conservation : une donnee personnelle apres suppression.'
        );

        $this->assertSame($avantRestant, $presence($slugs[$restant->id]), 'La conservation d\'un autre membre a ete effacee.');
        $this->assertSame($avantTiers, $presence($slugs[$tiers->id]), 'La conservation d\'une autre Organization a ete effacee.');
    }

    /**
     * Le registre canonique declare bien cette table, en POLICY_DELETE.
     *
     * La cle etrangere suffirait a la base ; cette entree sert a l'OUTIL — la
     * previsualisation de suppression de compte doit annoncer ces lignes, sinon
     * un administrateur signerait une suppression dont il ne voit pas la
     * portee.
     */
    public function test_la_conservation_est_declaree_au_registre_de_cycle_de_vie(): void
    {
        $entree = collect(UserDataLifecycleRegistry::entries())
            ->first(fn (array $e) => ($e['table'] ?? null) === self::CONSERVATION);

        $this->assertNotNull($entree, 'La conservation n\'est pas declaree dans `UserDataLifecycleRegistry`.');
        $this->assertSame('user_id', $entree['column']);
        $this->assertSame(
            UserDataLifecycleRegistry::POLICY_DELETE,
            $entree['policy'],
            'La politique doit etre DELETE : conserver des slugs apres suppression du compte serait le defaut d\'origine.'
        );
    }

    // ── Outillage ───────────────────────────────────────────────────────────

    /**
     * Un contexte de partage representatif, monte par les factories du projet.
     *
     * Mes premiers inserts etaient ecrits a la main et faux sur deux tables :
     * `dossier_files` n'a pas de `name` mais `original_name`/`display_name` et
     * `size_bytes`, et `messages.transaction_id` est NOT NULL. Les factories
     * portent le schema reel ; le recopier a la main revenait a le deviner.
     *
     * @return array{fichier: string, membre: string, organisation: string, boucle: string}
     */
    private function contexteDePartage(): array
    {
        $organisation = Organization::factory()->create();
        $membre = User::factory()->create(['organization_id' => $organisation->id]);

        $dossier = Dossier::factory()->create([
            'organization_id' => $organisation->id,
            'owner_id' => $membre->id,
        ]);

        $fichier = DossierFile::factory()->create([
            'organization_id' => $organisation->id,
            'dossier_id' => $dossier->id,
        ]);

        $boucle = Loop::factory()->create([
            'organization_id' => $organisation->id,
            'created_by' => $membre->id,
        ]);

        return [
            'fichier' => (string) $fichier->id,
            'membre' => (string) $membre->id,
            'organisation' => (string) $organisation->id,
            'boucle' => (string) $boucle->id,
        ];
    }

    /**
     * Un message porteur d'une piece jointe, avec son echange.
     *
     * `messages.transaction_id` est NOT NULL : un message n'existe pas hors
     * d'un echange.
     */
    private function messagePorteurDUnePieceJointe(): string
    {
        $echange = Transaction::factory()->create();

        return (string) Message::factory()->create([
            'transaction_id' => $echange->id,
            'organization_id' => $echange->organization_id,
        ])->id;
    }
}
