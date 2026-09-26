<?php

namespace Tests\Feature\ScenarioManifest;

use App\Models\Organization;
use App\Models\ScenarioManifestVersion;
use App\Http\Controllers\Admin\AdminScenarioManagerController;
use App\Models\ScenarioPackLoad;
use App\Models\User;
use App\Support\ScenarioManifest\ManifestSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * TASK-1648 — la bibliotheque et le Preview.
 *
 * T1646 avait prouve que la surface EXISTE et qu'elle est reservee au
 * SuperAdmin. T1648 lui donne du contenu, et ce contenu amene trois promesses
 * qu'un commentaire ne suffit pas a tenir :
 *
 * 1. **Lecture seule.** Non pas « aucune route mutante » — c'etait deja
 *    prouve — mais « ouvrir ces ecrans ne change RIEN en base ». Une vue qui
 *    touche un `updated_at` au passage n'est plus une lecture.
 * 2. **Le Validator ne tourne pas au rendu** (CDC 11.1). Cela ne se prouve pas
 *    en comptant des objets justes : si le document et la colonne disent la
 *    meme chose, les deux lectures sont indiscernables. Le seul test decisif
 *    fait MENTIR la colonne, et verifie que c'est elle qu'on lit.
 * 3. **Un filtre filtre.** Un filtre qui rend tout passe n'importe quelle
 *    assertion de presence : chaque filtre se teste par ce qu'il EXCLUT.
 */
class ScenarioLibraryPreviewTest extends TestCase
{
    use RefreshDatabase;

    /** Une chaine qui n'apparait dans aucun champ rendu par un onglet. */
    private const SENTINELLE = 'SENTINELLEJSONBRUT';

    private User $superAdmin;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
            'preferred_locale' => 'fr',
        ]);

        // Une requete LAISSE la locale changee : `SetLocale` la bascule sur
        // celle de l'utilisateur et ne la remet pas. PHP evaluant les
        // arguments de gauche a droite, `__()` ecrit AVANT un appel HTTP et
        // `__()` ecrit APRES ne rendent alors pas la meme langue, et une
        // assertion sur un libelle devient dependante de son ordre
        // d'ecriture. Les deux cotes sont donc fixes ici.
        App::setLocale('fr');
    }

    // =====================================================================
    // Acces
    // =====================================================================

    public function test_un_membre_ordinaire_ne_peut_pas_ouvrir_un_preview(): void
    {
        // La route de detail est neuve : l'acces se prouve sur ELLE, le test
        // de T1646 ne couvrant que la bibliotheque.
        $membre = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => false,
        ]);

        $this->actingAs($membre)
            ->get(route('admin.outils.scenarios.show', $this->version()))
            ->assertForbidden();
    }

    public function test_un_visiteur_anonyme_est_redirige_depuis_un_preview(): void
    {
        $this->get(route('admin.outils.scenarios.show', $this->version()))->assertRedirect();
    }

    // =====================================================================
    // Lecture seule : la promesse centrale de T1648
    // =====================================================================

    public function test_ouvrir_la_bibliotheque_et_un_preview_ne_change_rien_en_base(): void
    {
        $version = $this->version();

        // `updated_at` est stocke a la SECONDE : sans ce recul, un `touch()`
        // survenu pendant le test tomberait dans la meme seconde que
        // l'empreinte et serait invisible. Recule d'une heure, il redeviendrait
        // « maintenant » et creverait les yeux.
        ScenarioManifestVersion::query()->update(['updated_at' => now()->subHour()]);

        $avant = $this->empreinteBase();

        $this->actingAs($this->superAdmin);
        $this->get(route('admin.outils.scenarios'))->assertOk();

        foreach (array_keys(AdminScenarioManagerController::onglets()) as $onglet) {
            $this->get(route('admin.outils.scenarios.show', ['version' => $version, 'onglet' => $onglet]))->assertOk();
        }

        $this->assertSame(
            $avant,
            $this->empreinteBase(),
            'T1648 est en LECTURE SEULE : parcourir la bibliotheque et les neuf onglets ne doit rien deplacer du domaine.'
        );
    }

    public function test_la_surface_n_emprunte_ni_le_validator_ni_la_porte_du_load(): void
    {
        // Le CDC 11.1 interdit de relancer le Validator au rendu, et
        // `fromApprovedJson()` est la porte du Load : elle exige un digest
        // approuve et refuse un document invalide. Le Preview doit justement
        // montrer les brouillons invalides.
        //
        // C'est une garde STRUCTURELLE, et c'est voulu : le jour ou quelqu'un
        // cablera une validation dans le chemin de rendu, ce test rougira
        // avant que la bibliotheque ne devienne cent validations par page.
        $interdits = ['ScenarioManifestValidator', 'fromApprovedJson', 'ScenarioPackLoader'];

        foreach ([
            app_path('Http/Controllers/Admin/AdminScenarioManagerController.php'),
            app_path('Support/ScenarioManager/ScenarioPreview.php'),
        ] as $fichier) {
            // Les COMMENTAIRES sont retires avant la recherche. Sans cela la
            // garde se trouve elle-meme : les docblocks de ces deux fichiers
            // nomment `fromApprovedJson()` pour expliquer pourquoi ils ne
            // l'empruntent pas. Une garde qui ne distingue pas le code de la
            // prose qui parle du code rougit sur sa propre justification.
            $source = php_strip_whitespace($fichier);
            $this->assertNotSame('', $source);

            foreach ($interdits as $interdit) {
                $this->assertStringNotContainsString(
                    $interdit.'(',
                    $source,
                    basename($fichier).' ne doit pas appeler '.$interdit.' : le rendu est une lecture.'
                );
            }
        }
    }

    // =====================================================================
    // Les compteurs viennent de la COLONNE
    // =====================================================================

    public function test_les_compteurs_affiches_viennent_de_la_colonne_et_non_d_un_recomptage(): void
    {
        // Le document declare 2 personnes ; la colonne en annonce 7. Les deux
        // se contredisent EXPRES : c'est la seule maniere de savoir laquelle
        // des deux l'ecran a lue.
        $version = $this->version([], [
            'validation_summary' => [
                'verdict' => 'VALID',
                'counters' => ['users' => 7, 'loops' => 41],
                'errors' => [],
            ],
        ]);

        $this->assertCount(2, $this->manifeste()['users'], 'Le document doit bien contredire la colonne.');

        $bibliotheque = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios'))->assertOk()->getContent();

        $this->assertStringContainsString('7 '.__('admin.scenario_manager.counter_users'), $bibliotheque);
        $this->assertStringNotContainsString('2 '.__('admin.scenario_manager.counter_users'), $bibliotheque);

        $resume = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios.show', $version))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#users</dt>\s*<dd[^>]*>\s*7\s*</dd>#', $resume);
        $this->assertMatchesRegularExpression('#loops</dt>\s*<dd[^>]*>\s*41\s*</dd>#', $resume);
    }

    public function test_un_scenario_jamais_valide_le_dit_au_lieu_d_afficher_des_zeros(): void
    {
        // Sans resume enregistre, afficher « 0 personne » serait un mensonge :
        // le document en declare deux. L'ecran doit dire qu'il ne SAIT pas.
        $version = $this->version();

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios.show', $version))->assertOk()->getContent();

        $this->assertStringContainsString(__('admin.scenario_manager.preview_no_counters'), $html);
        $this->assertStringContainsString(__('admin.scenario_manager.preview_verdict_none'), $html);
    }

    public function test_chaque_carte_ouvre_son_preview(): void
    {
        // Le lien est l'action principale de la carte (CDC 6.2). C'est aussi
        // lui qui rattache la bibliotheque au Preview : si quelqu'un retirait
        // l'ecran de detail en laissant le bandeau annoncer qu'il existe, ce
        // test rougirait avant l'utilisateur.
        $version = $this->version();

        $this->assertStringContainsString(
            'href="'.route('admin.outils.scenarios.show', $version).'"',
            $this->requete([])
        );
    }

    // =====================================================================
    // Le Preview
    // =====================================================================

    public function test_le_preview_annonce_une_nouvelle_sandbox_et_un_slug_propose(): void
    {
        // CDC 11.2 : la mention est EXPLICITE. Un Load ne cible jamais une
        // Organization existante, et le slug n'est qu'une proposition — le
        // loader choisit le slug reel.
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios.show', $this->version()))->assertOk()->getContent();

        $this->assertStringContainsString(__('admin.scenario_manager.preview_new_sandbox'), $html);
        $this->assertStringContainsString(__('admin.scenario_manager.preview_proposed_slug'), $html);
        $this->assertStringContainsString('atelier-fictif', $html);
    }

    public function test_chaque_onglet_montre_sa_propre_famille(): void
    {
        $version = $this->version();

        // Une valeur que SEULE cette famille porte : si l'onglet se trompait
        // de collection, ou n'en rendait aucune, elle manquerait.
        $temoins = [
            'personnes' => 'Alice Martin',
            'boucles' => 'Boucle des essais',
            'dossiers' => 'Dossier racine',
            'chatloop' => 'msg-seulement-chatloop',
            'entraide' => 'Coup de main deco',
            'collaboration' => 'Quel jour se voit-on',
            'formation' => 'Module d accueil',
        ];

        foreach ($temoins as $onglet => $temoin) {
            $html = $this->actingAs($this->superAdmin)
                ->get(route('admin.outils.scenarios.show', ['version' => $version, 'onglet' => $onglet]))
                ->assertOk()->getContent();

            $this->assertStringContainsString(
                e($temoin),
                $html,
                "L onglet {$onglet} doit rendre sa famille."
            );
        }
    }

    public function test_le_document_brut_n_est_servi_que_sur_son_onglet(): void
    {
        // La sentinelle vit dans un champ qu'AUCUN onglet n'affiche. Elle ne
        // peut donc apparaitre que si le document entier a ete verse dans la
        // page — ce qui, a 2 Mio autorises, couterait cher a chaque onglet.
        $version = $this->version();

        foreach (array_keys(AdminScenarioManagerController::onglets()) as $onglet) {
            $html = $this->actingAs($this->superAdmin)
                ->get(route('admin.outils.scenarios.show', ['version' => $version, 'onglet' => $onglet]))
                ->assertOk()->getContent();

            if ($onglet === 'json') {
                $this->assertStringContainsString(self::SENTINELLE, $html, 'L onglet JSON doit bien servir le document.');

                continue;
            }

            $this->assertStringNotContainsString(
                self::SENTINELLE,
                $html,
                "L onglet {$onglet} ne doit pas embarquer le document brut."
            );
        }

        $this->assertStringNotContainsString(
            self::SENTINELLE,
            $this->actingAs($this->superAdmin)->get(route('admin.outils.scenarios'))->getContent(),
            'La bibliotheque ne doit jamais servir les documents de ses cartes.'
        );
    }

    public function test_un_onglet_borne_l_affichage_et_le_dit(): void
    {
        $manifeste = $this->manifeste();
        $manifeste['users'] = [];

        for ($i = 1; $i <= 201; $i++) {
            $manifeste['users'][] = [
                'key' => sprintf('user-%04d', $i),
                'first_name' => 'Personne',
                'name' => sprintf('Personne %04d', $i),
                'organization_role' => 'member',
            ];
        }

        $version = $this->version(['json_source' => json_encode($manifeste)]);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios.show', ['version' => $version, 'onglet' => 'personnes']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('user-0200', $html);
        $this->assertStringNotContainsString('user-0201', $html, 'La borne doit vraiment borner.');
        $this->assertStringContainsString('(201)', $html, 'Le TOTAL reste affiche, sans quoi la borne cacherait ce qu elle coupe.');
        $this->assertStringContainsString(
            __('admin.scenario_manager.preview_truncated', ['limit' => 200, 'total' => 201]),
            $html
        );
    }

    public function test_un_document_illisible_reste_consultable(): void
    {
        // CDC 7.2 : un brouillon qui ne parse pas doit rester ouvrable, sinon
        // c'est un brouillon perdu. L'ecran montre alors ce que la derniere
        // validation savait.
        $version = $this->version(['json_source' => '{"schema_version":1, "organization": '], [
            'validation_summary' => [
                'verdict' => 'INVALID',
                'counters' => [],
                'errors' => [[
                    'code' => 'INVALID_JSON',
                    'path' => '/',
                    'message' => 'Le document se termine avant la fin de l objet.',
                ]],
            ],
        ]);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios.show', $version))->assertOk()->getContent();

        $this->assertStringContainsString(e(__('admin.scenario_manager.preview_unreadable')), $html);
        $this->assertStringContainsString(e('Le document se termine avant la fin de l objet.'), $html);
        // CDC 11.5 : le message d abord, le pointeur ensuite — mais il est la.
        $this->assertStringContainsString('INVALID_JSON', $html);

        // Et les onglets d objets ne cassent pas : ils sont simplement vides.
        $this->get(route('admin.outils.scenarios.show', ['version' => $version, 'onglet' => 'personnes']))
            ->assertOk()
            ->assertSee(__('admin.scenario_manager.preview_empty_tab'));
    }

    public function test_un_onglet_inconnu_retombe_sur_le_resume(): void
    {
        // Un parametre d URL est une entree libre : il ne doit ni casser la
        // page, ni ouvrir un onglet qui n existe pas.
        $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios.show', ['version' => $this->version(), 'onglet' => 'famille-inventee']))
            ->assertOk()
            ->assertSee(__('admin.scenario_manager.preview_counters'));
    }

    // =====================================================================
    // Les filtres, prouves par ce qu ils EXCLUENT
    // =====================================================================

    public function test_la_recherche_porte_sur_le_nom_et_sur_la_cle(): void
    {
        $ofsh = $this->version(['scenario_key' => 'ofsh', 'name' => 'Organisation fictive']);
        $persona = $this->version(['scenario_key' => 'persona', 'name' => 'Atelier des personas']);

        $parNom = $this->requete(['q' => 'personas']);
        $this->assertMontre($parNom, $persona);
        $this->assertNeMontrePas($parNom, $ofsh);

        $parCle = $this->requete(['q' => 'ofsh']);
        $this->assertMontre($parCle, $ofsh);
        $this->assertNeMontrePas($parCle, $persona);
    }

    public function test_la_recherche_ne_laisse_pas_passer_les_jokers_du_like(): void
    {
        // `%` et `_` sont les jokers de LIKE. Non echappes, `?q=%` ramene TOUTE
        // la bibliotheque pendant que l'ecran affiche « filtre actif » — et un
        // scenario nomme `100_%_couverture` devient introuvable en collant son
        // propre nom dans la recherche.
        $ofsh = $this->version(['scenario_key' => 'ofsh', 'name' => 'Organisation fictive']);
        $litteral = $this->version(['scenario_key' => 'couverture', 'name' => '100_%_couverture']);

        foreach (['%', '_', '%%'] as $joker) {
            $this->assertNeMontrePas(
                $this->requete(['q' => $joker]),
                $ofsh,
                "Le joker {$joker} ne doit pas ramener toute la bibliotheque."
            );
        }

        $this->assertMontre($this->requete(['q' => '100_%_couverture']), $litteral);
    }

    public function test_la_recherche_ne_fouille_pas_le_document(): void
    {
        // Choix delibere : chercher dans `json_source` ferait un `LIKE` sur
        // plusieurs Mio par ligne. Le test fige la decision au lieu de la
        // laisser a un commentaire.
        $this->version(['scenario_key' => 'ofsh', 'name' => 'Organisation fictive']);

        $this->assertStringContainsString(
            __('admin.scenario_manager.no_result'),
            $this->requete(['q' => self::SENTINELLE])
        );
    }

    public function test_le_filtre_d_etat_exclut_les_autres_etats(): void
    {
        $brouillon = $this->version(['scenario_key' => 'brouillon', 'name' => 'Le brouillon']);
        $valide = $this->version(['scenario_key' => 'valide', 'name' => 'Le valide'], ['state' => ScenarioManifestVersion::STATE_VALID]);

        $html = $this->requete(['etat' => ScenarioManifestVersion::STATE_VALID]);
        $this->assertMontre($html, $valide);
        $this->assertNeMontrePas($html, $brouillon);
    }

    public function test_un_filtre_ignore_n_allume_pas_le_bandeau_de_filtre_actif(): void
    {
        // Une valeur hors liste est ignoree, et c'est bien. Mais l'ecran ne
        // doit pas pretendre filtrer : « Tout afficher » sur une liste
        // complete ferait croire a un filtre qui n'a rien retire.
        $version = $this->version();

        $html = $this->requete(['etat' => 'loaded', 'usage' => 'nimporte', 'charge' => 'peut-etre']);

        $this->assertMontre($html, $version);
        $this->assertStringNotContainsString(__('admin.scenario_manager.filter_reset'), $html);
    }

    public function test_le_filtre_d_usage_exclut_les_autres_usages(): void
    {
        $qa = $this->version(['scenario_key' => 'pour-la-qa', 'name' => 'Pour la QA', 'usage' => ScenarioManifestVersion::USAGE_QA]);
        $demo = $this->version(['scenario_key' => 'pour-la-demo', 'name' => 'Pour la demo', 'usage' => ScenarioManifestVersion::USAGE_DEMO]);

        $html = $this->requete(['usage' => ScenarioManifestVersion::USAGE_DEMO]);
        $this->assertMontre($html, $demo);
        $this->assertNeMontrePas($html, $qa);
    }

    public function test_le_filtre_charge_porte_le_meme_predicat_que_la_carte(): void
    {
        $load = ScenarioPackLoad::create([
            'pack_id' => 'manifest-ofsh',
            'pack_version' => '1.0.0',
            'organization_id' => $this->organization->id,
            'loaded_at' => now(),
        ]);

        $chargee = $this->version(['scenario_key' => 'chargee', 'name' => 'La chargee'], [
            'state' => ScenarioManifestVersion::STATE_VALID,
            'scenario_pack_load_id' => $load->id,
        ]);

        // Valide MAIS sans chargement : c'est le cas qui distingue « charge »
        // d'un simple synonyme de « valide ».
        $valideSeule = $this->version(['scenario_key' => 'valide-seule', 'name' => 'La valide seule'], [
            'state' => ScenarioManifestVersion::STATE_VALID,
        ]);

        $oui = $this->requete(['charge' => 'oui']);
        $this->assertMontre($oui, $chargee);
        $this->assertNeMontrePas($oui, $valideSeule);

        $non = $this->requete(['charge' => 'non']);
        $this->assertMontre($non, $valideSeule);
        $this->assertNeMontrePas($non, $chargee);
    }

    public function test_les_filtres_survivent_a_la_pagination(): void
    {
        // Un filtre perdu au clic sur « page 2 » renvoie l utilisateur dans
        // une liste qu il n a pas demandee.
        for ($i = 1; $i <= 30; $i++) {
            $this->version([
                'scenario_key' => sprintf('demo-%02d', $i),
                'name' => sprintf('Demo %02d', $i),
                'usage' => ScenarioManifestVersion::USAGE_DEMO,
            ]);
        }

        $html = $this->requete(['usage' => ScenarioManifestVersion::USAGE_DEMO]);

        $this->assertStringContainsString('usage=demo', $html, 'Les liens de pagination doivent porter le filtre.');
    }

    public function test_la_bibliotheque_ne_charge_jamais_les_documents_qu_elle_n_affiche_pas(): void
    {
        // Le modele autorise 2 MiB par document. Un `select *` sur une page de
        // 24 cartes hydraterait donc jusqu'a 48 Mio de chaines pour une page
        // qui n'en rend aucune — au-dessus d'un `memory_limit` a 128 M.
        $this->version();

        $requetes = [];
        \Illuminate\Support\Facades\DB::listen(function ($requete) use (&$requetes): void {
            $requetes[] = $requete->sql;
        });

        $this->requete([]);

        $surLaTable = array_values(array_filter(
            $requetes,
            static fn (string $sql): bool => str_contains($sql, 'scenario_manifest_versions') && str_starts_with($sql, 'select')
        ));

        $this->assertNotEmpty($surLaTable, 'La bibliotheque doit bien interroger sa table.');

        foreach ($surLaTable as $sql) {
            $this->assertStringNotContainsString(
                'json_source',
                $sql,
                'La bibliotheque ne doit jamais ramener le document : '.$sql
            );
            $this->assertStringNotContainsString(
                'select *',
                $sql,
                '`select *` ramene `json_source` sans le nommer : '.$sql
            );
        }
    }

    public function test_les_neuf_onglets_couvrent_toutes_les_familles_du_schema(): void
    {
        // La carte des onglets est declaree UNE fois, dans le controleur. Ce
        // test ne la recopie pas — il la confronte a une autorite INDEPENDANTE,
        // `ManifestSchema`. Une famille ajoutee au schema et oubliee des
        // onglets rougit ici ; une famille inventee dans les onglets aussi.
        $duSchema = array_merge(
            array_keys(ManifestSchema::referencableCollections()),
            array_keys(ManifestSchema::keylessCollections())
        );

        $desOnglets = [];

        foreach (AdminScenarioManagerController::onglets() as $onglet => $declaration) {
            foreach (array_keys($declaration['familles']) as $famille) {
                $this->assertArrayNotHasKey(
                    $famille,
                    $desOnglets,
                    "La famille {$famille} est montree par deux onglets."
                );
                $desOnglets[$famille] = $onglet;
            }
        }

        sort($duSchema);
        $vues = array_keys($desOnglets);
        sort($vues);

        $this->assertSame($duSchema, $vues, 'Chaque famille du schema doit etre montree par exactement un onglet.');
    }

    public function test_chaque_famille_declaree_rend_sa_section_et_son_total(): void
    {
        // Un temoin par ONGLET laisserait treize familles sans garde : l'onglet
        // resterait vert sur le seul temoin de sa premiere famille. Ici chaque
        // famille doit rendre son en-tete et le compte du document.
        $version = $this->version();
        $manifeste = $this->manifeste();

        foreach (AdminScenarioManagerController::onglets() as $onglet => $declaration) {
            if ($declaration['familles'] === []) {
                continue;
            }

            $html = $this->actingAs($this->superAdmin)
                ->get(route('admin.outils.scenarios.show', ['version' => $version, 'onglet' => $onglet]))
                ->assertOk()->getContent();

            foreach (array_keys($declaration['familles']) as $famille) {
                $titre = \Illuminate\Support\Str::afterLast($famille, '.');
                $attendu = count(data_get($manifeste, $famille, []));

                $this->assertMatchesRegularExpression(
                    '#'.preg_quote($titre, '#').'\s*<span[^>]*>\s*\('.$attendu.'\)#',
                    $html,
                    "L onglet {$onglet} doit rendre la famille {$famille} et son total ({$attendu})."
                );
            }
        }
    }

    public function test_une_liste_d_erreurs_tronquee_le_dit(): void
    {
        // Le CDC 11.5 fait de cet ecran l'endroit ou l'on comprend pourquoi un
        // brouillon est invalide. Couper cinquante erreurs sans le dire y fait
        // perdre exactement ce qu'on vient y chercher.
        $erreurs = [];

        for ($i = 1; $i <= 250; $i++) {
            $erreurs[] = [
                'code' => 'INVALID_ENUM',
                'path' => '/users/'.$i,
                'message' => sprintf('ERREUR%04d', $i),
            ];
        }

        $version = $this->version([], ['validation_summary' => ['verdict' => 'INVALID', 'counters' => [], 'errors' => $erreurs]]);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios.show', $version))->assertOk()->getContent();

        $this->assertStringContainsString('ERREUR0200', $html);
        $this->assertStringNotContainsString('ERREUR0201', $html);
        $this->assertStringContainsString(
            __('admin.scenario_manager.preview_truncated', ['limit' => 200, 'total' => 250]),
            $html
        );
    }

    public function test_un_identifiant_qui_n_est_pas_un_uuid_rend_404_et_non_500(): void
    {
        // Ce test fige un COMPORTEMENT, pas une garde : mesure faite en
        // SQLite ET en PostgreSQL, avec et sans `whereUuid` sur la route, les
        // quatre combinaisons rendent 404. C'est `HasUuids` qui refuse la
        // valeur avant toute requete SQL, donc le SQLSTATE 22P02 redoute sur
        // une colonne `uuid` native ne se produit pas ici.
        //
        // Il reste utile parce qu'il rougirait si le modele perdait
        // `HasUuids` ET la route sa contrainte — c'est alors le 500 qui
        // arriverait, en production seulement.
        $this->actingAs($this->superAdmin)
            ->get('/admin/outils/scenarios/pas-un-uuid')
            ->assertNotFound();
    }

    // =====================================================================
    // Outillage
    // =====================================================================

    /**
     * La carte d'un scenario est presente — prouve par son LIEN, pas par son
     * nom.
     *
     * Un nom peut apparaitre ailleurs dans la page : le formulaire reemet la
     * recherche saisie dans `value="..."`, et le pied de page explique l'etat
     * « charge » avec les mots « valide » et « version ». Une assertion sur du
     * texte passe alors sur une liste VIDE. Le lien vers le Preview, lui,
     * n'est emis que par une carte, et il est unique par ligne.
     */
    private function assertMontre(string $html, ScenarioManifestVersion $version, string $message = ''): void
    {
        $this->assertStringContainsString(
            'href="'.route('admin.outils.scenarios.show', $version).'"',
            $html,
            $message !== '' ? $message : 'La carte de '.$version->name.' devait etre affichee.'
        );
    }

    private function assertNeMontrePas(string $html, ScenarioManifestVersion $version, string $message = ''): void
    {
        $this->assertStringNotContainsString(
            'href="'.route('admin.outils.scenarios.show', $version).'"',
            $html,
            $message !== '' ? $message : 'La carte de '.$version->name.' ne devait pas etre affichee.'
        );
    }

    /**
     * @param  array<string, mixed>  $declares
     * @param  array<string, mixed>  $systeme
     */
    private function version(array $declares = [], array $systeme = []): ScenarioManifestVersion
    {
        $version = new ScenarioManifestVersion(array_merge([
            'scenario_key' => 'ofsh',
            'name' => 'Organisation fictive',
            'version' => '1.0.0',
            'usage' => ScenarioManifestVersion::USAGE_QA,
            'origin' => ScenarioManifestVersion::ORIGIN_NEW,
            'json_source' => json_encode($this->manifeste()),
            'created_by' => $this->superAdmin->id,
        ], $declares));

        // `state` et les colonnes systeme ne sont pas remplissables en masse :
        // le test les pose comme le fera la production.
        if ($systeme !== []) {
            $version->forceFill($systeme);
        }

        $version->save();

        return $version;
    }

    /**
     * Un document reduit dont les NOMS DE CHAMPS sont ceux du schema, pas des
     * noms plausibles — c'est ce qui fait qu'une colonne mal nommee dans une
     * vue rend « — » ici plutot que de passer inapercue.
     *
     * Les VALEURS, elles, ne sont pas garanties valides au sens du Validator :
     * le Preview ne valide rien, et un document fixe a la main derive du schema
     * sans que personne ne s'en apercoive. Ne pas s'en servir comme fixture de
     * validation.
     *
     * @return array<string, mixed>
     */
    private function manifeste(): array
    {
        return [
            'schema_version' => '1.0',
            'id' => 'ofsh',
            'version' => '1.0.0',
            'name' => 'Organisation fictive',
            'description' => 'Un monde de demonstration.',
            'purpose' => 'Montrer ce que le Preview sait lire.',
            'locale' => 'fr',
            'organization' => [
                'name' => 'Atelier fictif',
                'proposed_slug' => 'atelier-fictif',
                'description' => 'Une sandbox.',
                'locale' => 'fr',
            ],
            'users' => [
                // La sentinelle vit dans `bio`, qu aucun onglet n affiche.
                ['key' => 'alice', 'first_name' => 'Alice', 'name' => 'Alice Martin', 'organization_role' => 'admin', 'bio' => self::SENTINELLE],
                ['key' => 'bruno', 'first_name' => 'Bruno', 'name' => 'Bruno Petit', 'organization_role' => 'member'],
            ],
            'loops' => [
                ['key' => 'essais', 'name' => 'Boucle des essais', 'type' => 'project', 'owner' => 'alice', 'visibility' => 'private'],
            ],
            'memberships' => [
                ['loop' => 'essais', 'user' => 'bruno', 'role' => 'member'],
            ],
            'dossiers' => [
                ['key' => 'racine', 'name' => 'Dossier racine', 'owner' => 'alice', 'loop' => 'essais', 'visibility' => 'loop'],
            ],
            'articles' => [
                ['key' => 'art-1', 'dossier' => 'racine', 'author' => 'alice', 'title' => 'Premier article'],
            ],
            'files' => [
                ['key' => 'fic-1', 'dossier' => 'racine', 'name' => 'notes.md', 'media_type' => 'text/markdown'],
            ],
            'messages' => [
                // Cette cle n'est citee par AUCUNE autre famille : c'est ce qui
                // en fait un temoin valable pour l'onglet ChatLoop.
                ['key' => 'msg-seulement-chatloop', 'loop' => 'essais', 'author' => 'alice', 'order' => 1],
                ['key' => 'msg-decision', 'loop' => 'essais', 'author' => 'alice', 'order' => 2],
            ],
            'categories' => [
                ['key' => 'cat-deco', 'name' => 'Decoration'],
            ],
            'skills' => [
                ['key' => 'skill-peinture', 'category' => 'cat-deco', 'name' => 'Peinture'],
            ],
            'service_requests' => [
                ['key' => 'dem-1', 'author' => 'bruno', 'title' => 'Coup de main deco'],
            ],
            'services' => [
                ['key' => 'ser-1', 'author' => 'alice', 'title' => 'Atelier peinture'],
            ],
            'polls' => [
                ['key' => 'son-1', 'loop' => 'essais', 'author' => 'alice', 'question' => 'Quel jour se voit-on', 'status' => 'open'],
            ],
            'events' => [
                ['key' => 'evt-1', 'loop' => 'essais', 'author' => 'alice', 'title' => 'Reunion de lancement', 'status' => 'scheduled'],
            ],
            'decisions' => [
                ['key' => 'dec-1', 'loop' => 'essais', 'author' => 'alice', 'title' => 'On commence lundi', 'message' => 'msg-decision'],
            ],
            'roadmap_items' => [
                ['key' => 'road-1', 'loop' => 'essais', 'title' => 'Preparer la salle', 'status' => 'todo'],
            ],
            'training' => [
                'modules' => [
                    ['key' => 'mod-1', 'loop' => 'essais', 'title' => 'Module d accueil'],
                ],
                'sequences' => [
                    ['key' => 'seq-1', 'module' => 'mod-1', 'title' => 'Premiere sequence'],
                ],
                'assignments' => [
                    ['key' => 'dev-1', 'loop' => 'essais', 'sequence' => 'seq-1', 'title' => 'Premier devoir'],
                ],
                'submissions' => [
                    ['assignment' => 'dev-1', 'user' => 'bruno', 'status' => 'submitted'],
                ],
                'progress' => [
                    ['sequence' => 'seq-1', 'user' => 'bruno', 'status' => 'in_progress'],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, string>  $filtres
     */
    private function requete(array $filtres): string
    {
        return $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios', $filtres))
            ->assertOk()
            ->getContent();
    }

    /**
     * Ce qu'une lecture ne doit pas deplacer, et la portee EXACTE de la preuve.
     *
     * Les deux tables du Scenario Manager sont comparees LIGNE A LIGNE : un
     * compte inchange laisserait passer une colonne reecrite ou un etat
     * bascule. Toutes les autres tables de la base sont comparees en NOMBRE de
     * lignes — c'est plus faible, mais cela couvre les 68 tables au lieu de
     * deux, et cela attrape la creation ou la suppression silencieuse.
     *
     * Ce que cette empreinte NE prouve PAS, et qu'il serait malhonnete de
     * laisser croire :
     *
     * - `updated_at` est stocke a la SECONDE. Un `touch()` survenu dans la
     *   meme seconde que la prise d'empreinte serait invisible — et un test
     *   s'execute en quelques dizaines de millisecondes. C'est pourquoi les
     *   dates des versions sont RECULEES avant le parcours : un `touch()` les
     *   ramenerait a maintenant, donc bien au-dela de la seconde.
     * - la session. En production `SESSION_DRIVER=database` et chaque GET
     *   touche une ligne ; le harnais force le pilote `array` et ne peut pas
     *   le voir. « Lecture seule » porte sur le DOMAINE, pas sur
     *   l'infrastructure.
     *
     * @return array<string, mixed>
     */
    private function empreinteBase(): array
    {
        $comptes = [];

        foreach ($this->tablesDeLaBase() as $table) {
            $comptes[$table] = \Illuminate\Support\Facades\DB::table($table)->count();
        }

        return [
            'versions' => ScenarioManifestVersion::query()->orderBy('id')->get()->toArray(),
            'loads' => ScenarioPackLoad::query()->orderBy('id')->get()->toArray(),
            'comptes' => $comptes,
        ];
    }

    /**
     * @return list<string>
     */
    private function tablesDeLaBase(): array
    {
        $tables = array_map(
            static fn (array $t): string => $t['name'],
            \Illuminate\Support\Facades\Schema::getTables()
        );

        sort($tables);

        return $tables;
    }
}
