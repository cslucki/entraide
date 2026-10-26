<?php

namespace Tests\Feature;

use Database\Seeders\SystemEmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TASK-1709 — l'aller-retour des gabarits d'email rend l'etat de depart.
 *
 * Diagnostic en TASK-1708 : trois migrations mutaient des lignes de
 * `system_email_templates` sans savoir les rendre. Mesure sur un jumeau de la
 * PROD 1.676 — quatre lignes globales avant, UNE apres un aller-retour, et deux
 * lignes par Organization qui perdaient leur nom anglais.
 *
 * | migration | ce que `up()` changeait | ce que `down()` rendait |
 * |---|---|---|
 * | `…230000_welcome_template_names_the_organization` | `subject` ET `content_html` | `subject` seulement |
 * | `…100000_rename_welcome_template_to_points_grant` | `slug` ET `name`, sur TOUTES les lignes `welcome` | le slug, puis `name = 'Bienvenue'` **en dur** |
 * | `…140000_normalize_system_email_templates_to_global` | supprimait ou promouvait les lignes globales sans langue | rien |
 *
 * Chacune ecrit desormais un instantane AVANT de muter, dans
 * `system_email_templates_trace`, et ne relit que le sien.
 *
 * ## Ce que ce fichier mesure, et comment
 *
 * Il rejoue les trois migrations a la main sur un etat seme a la forme de la
 * PROD, puis les annule en ordre inverse. C'est la seule facon de mesurer un
 * `down()` : `RefreshDatabase` ne joue que l'aller.
 */
class TASK1709EmailTemplateRollbackFidelityTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATIONS = [
        '2026_10_23_230000_welcome_template_names_the_organization',
        '2026_10_24_100000_rename_welcome_template_to_points_grant',
        '2026_10_24_140000_normalize_system_email_templates_to_global',
    ];

    private const TRACE = 'system_email_templates_trace';

    /** @var array<string, mixed> l'empreinte de l'etat seme */
    private array $origine = [];

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('system_email_templates')->delete();

        // La forme de la PROD 1.676, mesuree : une ligne globale SANS langue
        // par slug, et des lignes par Organization avec leur langue.
        $organisation = \App\Models\Organization::factory()->create(['slug' => 'org-1709']);

        // 1 — globale, PERSONNALISEE : son sujet porte la marque historique,
        //     donc `normalize` la PROMEUT au lieu de la supprimer.
        DB::table('system_email_templates')->insert([
            'id' => 1,
            'slug' => 'welcome',
            'locale' => null,
            'organization_id' => null,
            'name' => 'Bienvenue (inscription)',
            'subject' => 'Bienvenue sur Entraide, {{ name }} !',
            'content_html' => '<h1>Bienvenue sur Entraide, {{ name }} !</h1><p>Texte maison.</p>',
            'variables' => json_encode(['name', 'url']),
            'enabled' => true,
            'created_at' => now()->subYear(),
            'updated_at' => now()->subYear(),
        ]);

        // 2 — globale, qui REPETE le catalogue : `normalize` la SUPPRIME.
        $catalogue = SystemEmailTemplateSeeder::rowFor('new_message', 'fr');
        DB::table('system_email_templates')->insert([
            'id' => 2,
            'slug' => 'new_message',
            'locale' => null,
            'organization_id' => null,
            'name' => 'Nouveau message recu',
            'subject' => $catalogue['subject'],
            'content_html' => $catalogue['content_html'],
            'variables' => json_encode($catalogue['variables']),
            'enabled' => true,
            'created_at' => now()->subYear(),
            'updated_at' => now()->subYear(),
        ]);

        // 5 et 6 — par Organization, avec DEUX noms differents. C'est le cas
        //          que le `down()` en dur ecrasait.
        foreach ([['id' => 5, 'locale' => 'fr', 'name' => 'Bienvenue'], ['id' => 6, 'locale' => 'en', 'name' => 'Welcome']] as $ligne) {
            DB::table('system_email_templates')->insert([
                'id' => $ligne['id'],
                'slug' => 'welcome',
                'locale' => $ligne['locale'],
                'organization_id' => $organisation->id,
                'name' => $ligne['name'],
                'subject' => 'Sujet '.$ligne['locale'],
                'content_html' => '<p>Corps '.$ligne['locale'].'</p>',
                'variables' => json_encode(['name']),
                'enabled' => true,
                'created_at' => now()->subYear(),
                'updated_at' => now()->subYear(),
            ]);
        }

        DB::table(self::TRACE)->delete();

        $this->origine = $this->empreinte();
    }

    /**
     * L'empreinte complete d'une ligne — horodatages COMPRIS.
     *
     * `created_at` ET `updated_at` en font partie : un retour en arriere fidele
     * rend l'etat « comme si la migration n'avait pas tourne », et `updated_at`
     * est une donnee comme une autre. Laisser une ligne restauree avec un
     * `updated_at` au moment de la restauration la ferait passer pour
     * fraichement editee alors qu'elle vient d'etre RAMENEE a un etat ancien —
     * ce que lirait de travers tout ce qui se synchronise dessus : une purge de
     * cache, un export incremental, un ecran « modifies recemment ».
     *
     * @return array<int, string> id => empreinte complete de la ligne
     */
    private function empreinte(): array
    {
        return DB::table('system_email_templates')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn ($l) => [(int) $l->id => implode('|', [
                $l->slug, $l->locale ?? '<NULL>', $l->organization_id ?? 'GLOBAL',
                $l->name, $l->subject, md5((string) $l->content_html),
                (string) $l->enabled, (string) $l->variables,
                'cree='.($l->created_at ?? '<NULL>'),
                'maj='.($l->updated_at ?? '<NULL>'),
            ])])
            ->all();
    }

    private function aller(): void
    {
        foreach (self::MIGRATIONS as $nom) {
            (require database_path('migrations/'.$nom.'.php'))->up();
        }
    }

    private function retour(): void
    {
        foreach (array_reverse(self::MIGRATIONS) as $nom) {
            (require database_path('migrations/'.$nom.'.php'))->down();
        }
    }

    private function migration(int $rang): object
    {
        return require database_path('migrations/'.self::MIGRATIONS[$rang].'.php');
    }

    // ── La fidelite ─────────────────────────────────────────────────────────

    public function test_l_aller_retour_rend_l_etat_de_depart(): void
    {
        $this->aller();

        $this->assertNotSame($this->origine, $this->empreinte(), 'L\'aller n\'a rien change : le test ne mesurerait rien.');

        $this->retour();

        $this->assertSame($this->origine, $this->empreinte(), 'L\'aller-retour ne rend pas l\'etat de depart.');
    }

    /**
     * Un SECOND cycle rend le meme etat.
     *
     * C'est la garde contre une trace perimee : le `down()` doit lire le dernier
     * instantane, pas le premier.
     */
    public function test_un_second_cycle_rend_le_meme_etat(): void
    {
        $this->aller();
        $this->retour();
        $this->aller();
        $this->retour();

        $this->assertSame($this->origine, $this->empreinte(), 'Le second cycle diverge du premier.');
    }

    /**
     * Rollbacker la SEULE migration `normalize` rend aussi son horodatage.
     *
     * Pourquoi cette garde existe, et pourquoi elle ne ressemble pas aux
     * autres : dans un retour COMPLET, l'horodatage que `normalize` restaure
     * est aussitot reecrit par les deux `down()` qui la suivent — l'ordre
     * inverse les fait finir par la plus ancienne. Remettre `now()` dans
     * `normalize` laissait donc les huit autres gardes VERTES. Un sabotage qui
     * ne rougit pas ne prouve pas que le code est inutile : il dit que
     * l'observable choisi ne le traverse pas.
     *
     * Le cas ou il le traverse est reel et c'est le plus courant en
     * exploitation : `migrate:rollback --step=1` ne defait qu'UN batch. On
     * l'exerce donc tel quel.
     *
     * La valeur attendue n'est PAS celle du depart — `names_the_organization`
     * et `rename` ont legitimement touche la ligne avant — et elle n'est pas
     * lue dans la trace, ce qui ne prouverait que la recopie de la trace par
     * elle-meme. Elle est OBSERVEE dans la table, juste avant que `normalize`
     * ne mute la ligne.
     */
    public function test_rollbacker_la_seule_migration_normalize_rend_son_horodatage(): void
    {
        $this->migration(0)->up();
        $this->migration(1)->up();

        // L'horodatage attendu est POSE, et lointain.
        //
        // Le lire apres les deux premieres migrations ne marchait pas : les
        // trois `up()` tombent dans la MEME seconde, donc `now()` rend deux
        // fois la meme chaine et la pre-condition « l'aller a bien change
        // l'horodatage » etait fausse sans qu'aucun defaut n'existe. Un test
        // qui ne separe pas deux instants ne prouve pas lequel est lu.
        //
        // Cette valeur represente un etat legitime — la ligne a ete editee ce
        // jour-la — et elle est observee dans la table, pas lue dans la trace,
        // ce qui ne prouverait que la recopie de la trace par elle-meme.
        $avantNormalize = '2024-03-07 09:15:00';

        DB::table('system_email_templates')->where('id', 1)->update(['updated_at' => $avantNormalize]);

        $this->migration(2)->up();

        // L'aller a bien mute la ligne : sinon la suite ne mesurerait rien.
        $this->assertSame('fr', DB::table('system_email_templates')->where('id', 1)->value('locale'));
        $this->assertNotSame($avantNormalize, $this->horodatageDeMiseAJour(1));

        $this->migration(2)->down();

        $this->assertNull(
            DB::table('system_email_templates')->where('id', 1)->value('locale'),
            'La langue n\'est pas revenue a NULL.'
        );
        $this->assertSame(
            $avantNormalize,
            $this->horodatageDeMiseAJour(1),
            'Le rollback d\'un seul batch laisse la ligne promue avec l\'horodatage de la restauration.'
        );
    }

    /**
     * L'horodatage d'une ligne sous une forme comparable sur les DEUX moteurs.
     *
     * SQLite rend la chaine stockee, PostgreSQL un `timestamp` que le pilote
     * presente autrement : comparer les valeurs brutes ferait rougir ce test
     * sur un seul des deux moteurs, pour une raison qui ne concerne pas le
     * correctif.
     */
    private function horodatageDeMiseAJour(int $id): string
    {
        $valeur = DB::table('system_email_templates')->where('id', $id)->value('updated_at');

        return \Illuminate\Support\Carbon::parse((string) $valeur)->format('Y-m-d H:i:s');
    }

    /**
     * La ligne globale qui REPETE le catalogue revient, avec son `id`.
     *
     * C'est le defaut principal de TASK-1708 : elle etait supprimee a l'aller et
     * jamais rendue.
     */
    public function test_une_ligne_globale_supprimee_revient_avec_son_id(): void
    {
        $this->aller();

        $this->assertDatabaseMissing('system_email_templates', ['id' => 2]);

        $this->retour();

        $ligne = DB::table('system_email_templates')->where('id', 2)->first();
        $this->assertNotNull($ligne, 'La ligne globale supprimee n\'est pas revenue.');
        $this->assertSame('new_message', $ligne->slug);
        $this->assertNull($ligne->locale, 'Sa langue d\'origine (NULL) n\'est pas rendue.');
        $this->assertSame('Nouveau message recu', $ligne->name);
    }

    /**
     * La ligne globale PERSONNALISEE retrouve sa langue, son nom et son texte.
     *
     * Trois migrations l'avaient touchee : promotion de `locale`, renommage, et
     * reecriture du sujet et du corps.
     */
    public function test_une_ligne_globale_personnalisee_retrouve_tout(): void
    {
        $this->aller();
        $this->retour();

        $ligne = DB::table('system_email_templates')->where('id', 1)->first();

        $this->assertSame('welcome', $ligne->slug, 'Le slug n\'est pas rendu.');
        $this->assertNull($ligne->locale, 'La promotion de langue n\'est pas defaite.');
        $this->assertSame('Bienvenue (inscription)', $ligne->name, 'Le nom d\'origine n\'est pas rendu.');
        $this->assertSame('Bienvenue sur Entraide, {{ name }} !', $ligne->subject);
        $this->assertStringContainsString('Bienvenue sur Entraide,', (string) $ligne->content_html, 'Le corps garde la reecriture.');
        $this->assertStringNotContainsString('{{ organisation }}', (string) $ligne->content_html);
    }

    /**
     * Les lignes par Organization gardent LEUR nom — y compris l'anglais.
     *
     * Organization = Tenant : le `down()` en dur ecrivait « Bienvenue » sur
     * toutes les lignes `welcome`, donc sur deux tenants a la fois.
     */
    public function test_les_lignes_par_organization_gardent_leur_nom(): void
    {
        $this->aller();
        $this->retour();

        $this->assertSame('Bienvenue', DB::table('system_email_templates')->where('id', 5)->value('name'));
        $this->assertSame(
            'Welcome',
            DB::table('system_email_templates')->where('id', 6)->value('name'),
            'La ligne anglaise d\'une Organization a perdu son nom.'
        );
    }

    // ── Les refus : aucun ecrasement silencieux ─────────────────────────────

    /**
     * Une ligne renommee APRES l'aller n'est pas ecrasee par le retour.
     *
     * Son slug revient — c'est l'etiquetage, et le retour en a la charge — mais
     * le nom que quelqu'un a choisi reste.
     */
    public function test_un_nom_choisi_apres_l_aller_n_est_pas_ecrase(): void
    {
        $horodatageDOrigine = (string) DB::table('system_email_templates')->where('id', 6)->value('updated_at');

        $this->aller();

        DB::table('system_email_templates')->where('id', 6)->update(['name' => 'Nom choisi a la main']);

        $this->retour();

        $this->assertSame(
            'Nom choisi a la main',
            DB::table('system_email_templates')->where('id', 6)->value('name'),
            'Le retour a ecrase un nom choisi depuis l\'administration.'
        );
        $this->assertSame('welcome', DB::table('system_email_templates')->where('id', 6)->value('slug'));

        // Et l'horodatage NE revient PAS a celui de l'instantane.
        //
        // C'est l'autre moitie de l'arbitrage de TASK-1709 sur `updated_at`,
        // et sans elle rien n'empecherait de « simplifier » les trois `down()`
        // en restaurant toujours l'instantane : cette ligne-ci est un MELANGE
        // — notre slug, le nom de quelqu'un d'autre — et se declarer inchangee
        // depuis un an effacerait la trace d'une decision humaine posterieure.
        $this->assertNotSame(
            $horodatageDOrigine,
            (string) DB::table('system_email_templates')->where('id', 6)->value('updated_at'),
            'Une restauration PARTIELLE rend l\'horodatage d\'origine : la retouche humaine qu\'elle conserve devient invisible.'
        );
    }

    /**
     * Cas A — un nom choisi apres l'aller n'est pas ANTIDATE.
     *
     * Le volet precedent prouve que la VALEUR survit. Celui-ci porte sur
     * l'horodatage, et il a fallu une mesure sur jumeau pour le voir : la
     * branche partielle de `rename` laisse bien `now()`, mais
     * `names_the_organization` tourne APRES elle au retour, et sa propre
     * restauration est integrale — l'humain n'a touche ni le sujet ni le corps.
     * Elle reecrivait donc `updated_at` depuis son instantane, et la ligne
     * ressortait en annoncant « rien n'a change depuis un an » alors qu'elle
     * portait le nom de quelqu'un.
     *
     * **La derniere migration a ecrire gouverne.** Aucune ne peut donc juger la
     * fidelite sur les seuls champs qu'elle a mutes : il faut que la LIGNE
     * ENTIERE retrouve l'instantane.
     *
     * Les deux moities sont mesurees, et aucune ne suffit : rendre le sujet
     * sans proteger l'horodatage efface une decision humaine ; proteger
     * l'horodatage sans rendre le sujet laisserait l'aller en place.
     */
    public function test_un_nom_choisi_apres_l_aller_n_est_pas_antidate(): void
    {
        $sujetDOrigine = (string) DB::table('system_email_templates')->where('id', 1)->value('subject');
        $horodatageDOrigine = $this->horodatageDeMiseAJour(1);

        $this->aller();

        DB::table('system_email_templates')->where('id', 1)->update(['name' => 'Nom decide par un humain']);

        $this->retour();

        $ligne = DB::table('system_email_templates')->where('id', 1)->first();

        // La valeur humaine survit, et le reste est bien rendu.
        $this->assertSame('Nom decide par un humain', $ligne->name, 'Le nom choisi a la main a ete ecrase.');
        $this->assertSame('welcome', $ligne->slug, 'Le slug n\'a pas ete rendu.');
        $this->assertSame($sujetDOrigine, (string) $ligne->subject, 'Le sujet n\'a pas ete rendu.');

        // Et la ligne ne pretend pas etre restee inchangee depuis un an.
        $this->assertNotSame(
            $horodatageDOrigine,
            $this->horodatageDeMiseAJour(1),
            'Une ligne qui porte le nom de quelqu\'un d\'autre est antidatee a son etat d\'avant l\'aller : la decision humaine devient invisible.'
        );
    }

    /**
     * Un `id` occupe n'est jamais ecrase : on renonce a restaurer.
     *
     * Mieux vaut un retour incomplet qu'un retour destructeur.
     */
    public function test_un_id_occupe_n_est_pas_ecrase(): void
    {
        $this->aller();

        DB::table('system_email_templates')->insert([
            'id' => 2,
            'slug' => 'un-autre-gabarit',
            'locale' => 'fr',
            'organization_id' => null,
            'name' => 'Pose entre-temps',
            'subject' => 'Sujet',
            'content_html' => '<p>Corps</p>',
            'variables' => json_encode([]),
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->retour();

        $this->assertSame(
            'Pose entre-temps',
            DB::table('system_email_templates')->where('id', 2)->value('name'),
            'Le retour a ecrase une ligne posee entre-temps.'
        );
    }

    /**
     * Sans trace, le retour ne detruit rien — il s'abstient.
     *
     * C'est le cas des environnements migres AVANT TASK-1709 : la trace y est
     * irreconstituable, et inventer serait fabriquer une preuve.
     */
    public function test_sans_trace_le_retour_ne_detruit_rien(): void
    {
        $this->aller();

        $apresAller = $this->empreinte();

        Schema::drop(self::TRACE);

        $this->retour();

        $restantes = DB::table('system_email_templates')->count();

        $this->assertGreaterThanOrEqual(
            1,
            $restantes,
            'Sans trace, le retour a vide la table.'
        );
        $this->assertNotEmpty($apresAller);
    }

    /** La trace accumule, elle n'ecrase jamais. */
    public function test_la_trace_accumule_un_instantane_par_passage(): void
    {
        $this->aller();
        $premier = DB::table(self::TRACE)->count();

        $this->retour();
        $this->aller();

        $this->assertSame(
            $premier * 2,
            DB::table(self::TRACE)->count(),
            'Le second passage a ecrase les instantanes du premier.'
        );
    }
}
