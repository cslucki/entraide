<?php

namespace Tests\Feature\ScenarioManifest;

use App\Models\Organization;
use App\Models\ScenarioManifestVersion;
use App\Models\ScenarioPackEntity;
use App\Models\ScenarioPackLoad;
use App\Models\User;
use App\Support\ScenarioManager\ScenarioLifecycleService;
use App\Support\ScenarioPacks\Manifest\ManifestSandboxLoadService;
use App\Support\ScenarioManager\ScenarioVersionRefused;
use App\Support\ScenarioManifest\ScenarioManifestValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TASK-1650 — approuver, charger, reinitialiser, retirer.
 *
 * C'est la premiere TASK ou une Organization apparait VRAIMENT. Jusqu'ici le
 * pire defaut possible etait un brouillon perdu ; ici c'est un monde de trop,
 * ou une Organization cliente detruite.
 *
 * Ce fichier ne verifie donc pas seulement que les gestes marchent. Il verifie
 * surtout **ce qu'ils refusent de faire**, et que dans le doute ils ne
 * detruisent RIEN.
 */
class ScenarioLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Organization $organizationDuSuperAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        App::setLocale('fr');

        $this->organizationDuSuperAdmin = Organization::factory()->create();
        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->organizationDuSuperAdmin->id,
            'is_admin' => true,
            'preferred_locale' => 'fr',
        ]);
    }

    // =====================================================================
    // L'approbation : la SEULE porte
    // =====================================================================

    public function test_approuver_exige_l_etat_valide(): void
    {
        $brouillon = $this->versionValidee();
        $brouillon->forceFill(['state' => ScenarioManifestVersion::STATE_DRAFT])->save();

        $this->attendreRefus(ScenarioVersionRefused::NOT_VALID, fn () => $this->service()->approve($brouillon, $this->superAdmin));

        $this->assertNull($brouillon->fresh()->approved_digest);
    }

    public function test_approuver_fige_le_digest_montre_a_l_humain(): void
    {
        // CDC 12.2 : « le SuperAdmin doit confirmer le contenu EXACT ».
        // L'approbation porte donc sur un digest, pas sur une version.
        $version = $this->versionValidee();

        $this->service()->approve($version, $this->superAdmin);
        $version->refresh();

        $this->assertSame($version->digest, $version->approved_digest);
        $this->assertSame($this->superAdmin->id, $version->approved_by);
        $this->assertNotNull($version->approved_at);
        $this->assertTrue($version->approvalMatchesCurrentDigest());

        // Approuver ne charge RIEN : une seule Organization, celle du SuperAdmin.
        $this->assertSame(1, Organization::query()->count());
        $this->assertSame(0, ScenarioPackLoad::query()->count());
        $this->assertFalse($version->isLoaded());
    }

    public function test_charger_sans_approbation_est_refuse_et_ne_cree_aucun_monde(): void
    {
        $version = $this->versionValidee();

        $this->attendreRefus(ScenarioVersionRefused::NOT_APPROVED, fn () => $this->service()->load($version));

        $this->assertSame(1, Organization::query()->count());
        $this->assertSame(0, ScenarioPackLoad::query()->count());
    }

    public function test_une_approbation_PERIMEE_ne_charge_pas(): void
    {
        // Le cas qui compte : le document a change depuis son approbation.
        // T1649 efface deja l'approbation a chaque modification — mais la
        // garde doit tenir meme si quelque chose, un jour, ne l'effacait pas.
        $version = $this->versionValidee();
        $this->service()->approve($version, $this->superAdmin);

        $version->forceFill(['digest' => str_repeat('f', 64)])->save();

        $this->attendreRefus(ScenarioVersionRefused::NOT_APPROVED, fn () => $this->service()->load($version->fresh()));

        $this->assertSame(1, Organization::query()->count());
    }

    // =====================================================================
    // Load : une sandbox NEUVE, jamais un tenant existant
    // =====================================================================

    public function test_charger_cree_une_sandbox_NEUVE_et_relie_la_version(): void
    {
        $version = $this->versionApprouvee();

        $resultat = $this->service()->load($version);
        $version->refresh();

        // Exactement UNE Organization de plus, et c'est une sandbox.
        $this->assertSame(2, Organization::query()->count());
        $this->assertNotNull($resultat->organization->scenario_sandbox_created_at);
        $this->assertTrue($resultat->organization->isNot($this->organizationDuSuperAdmin));

        // Le lien administratif : c'est lui qui fait passer la version en
        // LOADED, etat DERIVE que T1646 a laisse sans colonne.
        $this->assertSame($resultat->packLoad->load->id, $version->scenario_pack_load_id);
        $this->assertTrue($version->isLoaded());
        $this->assertSame(ScenarioManifestVersion::STATE_VALID, $version->state);

        // Le monde existe vraiment.
        $this->assertSame(22, User::query()->where('organization_id', $resultat->organization->id)->count());

        // Et la tuile « Charge » de la bibliotheque, a 0 depuis T1646, compte
        // enfin quelque chose.
        $this->assertSame(1, ScenarioManifestVersion::query()->loaded()->count());
    }

    public function test_recharger_le_meme_monde_approuve_rend_le_chargement_EXISTANT(): void
    {
        // CDC 13.3 : meme `(pack_id, manifest_digest)` rend le chargement
        // existant, et l'UI dit « deja charge », pas une erreur. Un double
        // clic ne doit pas fabriquer deux mondes.
        $version = $this->versionApprouvee();

        $premier = $this->service()->load($version);
        $second = $this->service()->load($version->fresh());

        $this->assertTrue($second->wasReplay, 'Le second chargement est un REJEU.');
        $this->assertSame($premier->organization->id, $second->organization->id);
        $this->assertSame(2, Organization::query()->count(), 'Aucune seconde sandbox.');
        $this->assertSame(1, ScenarioPackLoad::query()->count());
    }

    public function test_aucune_organization_existante_n_est_jamais_ciblee(): void
    {
        // Le CDC 13.2 l'interdit, et la garde est STRUCTURELLE : aucune
        // methode de ce service ne prend d'Organization en parametre. Ce test
        // fige la propriete, et verifie qu'une Organization preexistante
        // ressort rigoureusement identique.
        $cliente = Organization::factory()->create();
        $avant = $cliente->fresh()->toArray();

        $version = $this->versionApprouvee();
        $this->service()->load($version);

        $this->assertSame($avant, $cliente->fresh()->toArray(), 'Une Organization cliente ne doit pas etre touchee.');
        $this->assertNull($cliente->fresh()->scenario_sandbox_created_at);
    }

    // =====================================================================
    // Reset : la MEME sandbox
    // =====================================================================

    public function test_reinitialiser_garde_la_meme_sandbox(): void
    {
        $version = $this->versionApprouvee();
        $chargement = $this->service()->load($version);
        $sandbox = $chargement->organization->id;

        $sandboxApres = $this->service()->reset($version->fresh());

        $this->assertSame($sandbox, $sandboxApres->id, 'CDC 14.1 : Reset conserve la MEME sandbox.');
        $this->assertSame(2, Organization::query()->count());
        $this->assertSame(22, User::query()->where('organization_id', $sandbox)->count());
        $this->assertTrue($version->fresh()->isLoaded());
    }

    public function test_reinitialiser_une_version_non_chargee_est_refuse(): void
    {
        $version = $this->versionApprouvee();

        $this->attendreRefus(ScenarioVersionRefused::NOT_LOADED, fn () => $this->service()->reset($version));
    }

    // =====================================================================
    // Remove : le geste qui detruit, donc celui qui doit le plus refuser
    // =====================================================================

    public function test_retirer_supprime_la_sandbox_et_laisse_la_version_VALID(): void
    {
        $version = $this->versionApprouvee();
        $chargement = $this->service()->load($version);
        $sandbox = $chargement->organization->id;

        $this->service()->remove($version->fresh());
        $version->refresh();

        // La sandbox est REELLEMENT supprimee : `Organization` est en
        // SoftDeletes, une suppression douce laisserait une ligne occupant son
        // slug et qu'aucun ecran ne montre plus.
        $this->assertSame(0, Organization::withTrashed()->whereKey($sandbox)->count());
        $this->assertSame(0, User::query()->where('organization_id', $sandbox)->count());
        $this->assertSame(0, ScenarioPackLoad::query()->count());

        // CDC 14.2 : la version reste VALID, son digest approuve n'ayant pas
        // change. Elle redeviendra chargeable.
        $this->assertSame(ScenarioManifestVersion::STATE_VALID, $version->state);
        $this->assertNull($version->scenario_pack_load_id);
        $this->assertFalse($version->isLoaded());
        $this->assertTrue($version->approvalMatchesCurrentDigest());

        // Et l'Organization du SuperAdmin n'a pas bouge.
        $this->assertSame(1, Organization::query()->count());
    }

    public function test_retirer_NE_TOUCHE_RIEN_quand_la_provenance_sandbox_n_est_pas_prouvee(): void
    {
        // Le test le plus important de cette TASK.
        //
        // `scenario_sandbox_created_at` n'est pose que par le provisionneur.
        // S'il manque, rien ne PROUVE que cette Organization est une sandbox —
        // et le moteur refuse alors d'y TOUCHER, pas seulement de la
        // supprimer. C'est plus fort que ce que j'avais concu : je pensais
        // vider le monde et garder l'Organization ; le moteur ne vide meme
        // pas. Une Organization cliente ne peut donc pas etre videe, quel que
        // soit l'appelant.
        $version = $this->versionApprouvee();
        $chargement = $this->service()->load($version);
        $sandbox = $chargement->organization;

        // On efface la seule preuve de provenance.
        $sandbox->forceFill(['scenario_sandbox_created_at' => null])->save();

        $this->attendreRefus(ScenarioVersionRefused::NOT_A_SANDBOX, fn () => $this->service()->remove($version->fresh()));

        $this->assertSame(1, Organization::query()->whereKey($sandbox->id)->count(), 'Sans preuve, on ne supprime pas.');
        $this->assertSame(22, User::query()->where('organization_id', $sandbox->id)->count(), 'Et on ne vide meme pas.');
        $this->assertNotNull($version->fresh()->scenario_pack_load_id, 'Le lien administratif reste : rien n a eu lieu.');
    }

    public function test_retirer_NE_SUPPRIME_PAS_une_organization_que_le_pack_n_a_pas_creee(): void
    {
        // Seconde preuve, independante de la premiere :
        // `organization_created_by_pack`. Les deux doivent etre vraies.
        $version = $this->versionApprouvee();
        $chargement = $this->service()->load($version);
        $sandbox = $chargement->organization;

        ScenarioPackLoad::query()->whereKey($chargement->packLoad->load->id)
            ->update(['organization_created_by_pack' => false]);

        $this->service()->remove($version->fresh());

        // Ici le moteur accepte de toucher — l'Organization EST une sandbox
        // prouvee — mais la seconde preuve manque, donc on vide sans
        // supprimer. Les deux gardes ne protegent pas la meme chose.
        $this->assertSame(1, Organization::query()->whereKey($sandbox->id)->count(), 'Sans cette preuve-la, on ne supprime pas.');
        $this->assertSame(0, User::query()->where('organization_id', $sandbox->id)->count(), 'Mais le monde est bien vide.');
        $this->assertNull($version->fresh()->scenario_pack_load_id);
    }

    public function test_retirer_une_version_non_chargee_est_refuse(): void
    {
        $version = $this->versionApprouvee();

        $this->attendreRefus(ScenarioVersionRefused::NOT_LOADED, fn () => $this->service()->remove($version));

        $this->assertSame(1, Organization::query()->count());
    }

    public function test_un_chargement_disparu_laisse_la_version_non_chargee_et_ne_detruit_rien(): void
    {
        // La version pointe vers un chargement qui n'existe plus.
        //
        // Mesure : la FK `scenario_pack_load_id` est en `SET NULL`, donc
        // supprimer le chargement DENOUE le lien de lui-meme — la version
        // redevient simplement non chargee, et le refus est `not_loaded`, pas
        // `load_mismatch`. La garde `load_mismatch` reste en place pour les
        // cas que la FK ne couvre pas, mais elle n'est pas atteignable par
        // cette voie, et ce test dit laquelle des deux repond vraiment.
        $version = $this->versionApprouvee();
        $chargement = $this->service()->load($version);
        $sandbox = $chargement->organization->id;

        ScenarioPackLoad::query()->whereKey($chargement->packLoad->load->id)->delete();

        $this->assertNull($version->fresh()->scenario_pack_load_id, 'La FK SET NULL denoue le lien.');

        $this->attendreRefus(ScenarioVersionRefused::NOT_LOADED, fn () => $this->service()->remove($version->fresh()));

        $this->assertSame(1, Organization::query()->whereKey($sandbox)->count(), 'Rien n a ete detruit.');
    }

    // =====================================================================
    // Par l'ECRAN : ce qu'un SuperAdmin voit et peut declencher
    // =====================================================================

    public function test_un_membre_ordinaire_ne_peut_declencher_aucun_geste_du_cycle_de_vie(): void
    {
        // Les gestes de T1650 detruisent ou font naitre des mondes. L'acces se
        // prouve sur CHACUN, pas sur un echantillon.
        $membre = User::factory()->create(['organization_id' => $this->organizationDuSuperAdmin->id, 'is_admin' => false]);
        $version = $this->versionApprouvee();

        $this->actingAs($membre);

        $this->get(route('admin.outils.scenarios.approval', $version))->assertForbidden();
        $this->post(route('admin.outils.scenarios.approve', $version))->assertForbidden();
        $this->post(route('admin.outils.scenarios.load', $version))->assertForbidden();
        $this->post(route('admin.outils.scenarios.reset', $version))->assertForbidden();
        $this->post(route('admin.outils.scenarios.remove', $version))->assertForbidden();

        $this->assertSame(1, Organization::query()->count(), 'Aucun monde n a pu naitre.');
    }

    public function test_l_ecran_d_approbation_montre_le_CONTENU_et_non_le_titre(): void
    {
        // CDC 12.2 : on approuve un contenu exact. Un ecran qui se contenterait
        // d'un bouton « Approuver » viderait l'etape de son sens.
        $version = $this->versionValidee();

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios.approval', $version))
            ->assertOk()->getContent();

        // Sur un CROCHET de structure, pas sur le nombre seul : « 22 » se
        // trouve dans les traces SVG du rail d'administration, et l'assertion
        // passait donc sur une page qui n'affichait AUCUN compteur.
        $this->assertMatchesRegularExpression(
            '#data-counter="users"[^>]*>\s*<span[^>]*>\s*22\s*</span>#',
            $html,
            'Le compteur des personnes doit etre rendu, et valoir 22.'
        );
        $this->assertStringContainsString('data-verdict="VALID"', $html);
        $this->assertStringContainsString((string) $version->digest, $html, 'Le digest approuve doit etre lisible.');
        $this->assertStringContainsString(__('admin.scenario_manager.approval_no_error'), $html);

        // CDC 13.2, dit AVANT le clic.
        $this->assertStringContainsString(e(__('admin.scenario_manager.load_notice')), $html);
    }

    public function test_retirer_une_sandbox_ne_PROMEUT_pas_ses_traductions_en_traductions_plateforme(): void
    {
        // Trouve en revue, et le mecanisme est traitre.
        //
        // `translation_overrides.organization_id` est en ON DELETE SET NULL.
        // Dans CE produit, `NULL` ne veut pas dire « orpheline » : il veut
        // dire PLATEFORME — le jeu d'overrides applique en repli a TOUTES les
        // Organizations. Un `forceDelete()` de sandbox promouvait donc la
        // formulation d'un monde de demonstration en formulation par defaut
        // du produit entier.
        $version = $this->versionApprouvee();
        $chargement = $this->service()->load($version);
        $sandbox = $chargement->organization;

        $plateformeAvant = DB::table('translation_overrides')->whereNull('organization_id')->count();

        DB::table('translation_overrides')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid7(),
            'organization_id' => $sandbox->id,
            'locale' => 'fr',
            'group' => 'admin',
            'key' => 'scenario_manager.library_title',
            'value' => 'FORMULATION DE LA SANDBOX',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->service()->remove($version->fresh());

        $this->assertSame(
            0,
            DB::table('translation_overrides')->where('organization_id', $sandbox->id)->count(),
            'Les overrides de la sandbox partent avec elle.'
        );

        $this->assertSame(
            $plateformeAvant,
            DB::table('translation_overrides')->whereNull('organization_id')->count(),
            'Et AUCUN n a ete promu au niveau plateforme.'
        );
    }

    public function test_TOUTES_les_tables_a_semantique_plateforme_sont_nettoyees_au_retrait(): void
    {
        // `translation_overrides` n'etait qu'UNE des six tables ou
        // `organization_id IS NULL` veut dire PLATEFORME et dont la FK est en
        // SET NULL. Le depot tient deja le registre canonique de cette
        // semantique ; ce test verifie qu'on le LIT, au lieu d'avoir recopie
        // une liste qui se perimerait.
        $version = $this->versionApprouvee();
        $chargement = $this->service()->load($version);
        $sandbox = $chargement->organization;

        $globales = array_values(array_filter(
            (new \App\Support\AssignData\DatasetRegistry)->all(),
            static fn ($d) => $d->classification === \App\Support\AssignData\DatasetClassification::GlobalNullValid
        ));

        $this->assertGreaterThan(1, count($globales), 'Le registre doit declarer plusieurs tables a semantique plateforme.');

        // On seme une ligne de la sandbox dans CHAQUE table concernee.
        $semees = [];

        foreach ($globales as $dataset) {
            if (! Schema::hasTable($dataset->table) || ! Schema::hasColumn($dataset->table, 'organization_id')) {
                continue;
            }

            $avant = DB::table($dataset->table)->where('organization_id', $sandbox->id)->count();

            try {
                // L'insertion vit dans sa PROPRE transaction imbriquee, donc
                // derriere un SAVEPOINT.
                //
                // Sans cela, le test est vert en SQLite et ROUGE en
                // PostgreSQL : une violation de contrainte y AVORTE la
                // transaction entiere, et tout ce qui suit le `catch` echoue
                // en 25P02 — y compris le `Schema::hasTable()` du tour
                // suivant. Le depot porte deja cette lecon depuis T1642 ; je
                // viens de la repayer.
                DB::transaction(fn () => DB::table($dataset->table)->insert(array_merge(
                    $this->colonnesMinimales($dataset->table),
                    ['organization_id' => $sandbox->id]
                )));
            } catch (\Illuminate\Database\QueryException) {
                // Certaines de ces tables exigent une ligne parente (une
                // `skill` veut sa `category`). Les fabriquer toutes ferait de
                // ce test un jeu de fixtures, pas une preuve. On seme celles
                // qu'on sait semer, et on exige que TOUTES les lignes semees
                // disparaissent — la regle testee est la lecture du registre,
                // pas l'ecriture d'une ligne.
                continue;
            }

            $this->assertSame(
                $avant + 1,
                DB::table($dataset->table)->where('organization_id', $sandbox->id)->count(),
                "La ligne temoin doit exister dans {$dataset->table}."
            );

            // On note le nombre de lignes PLATEFORME avant le retrait.
            // C'est LA grandeur qui compte, et la seule qui distingue une
            // suppression d'une promotion.
            $semees[$dataset->table] = DB::table($dataset->table)->whereNull('organization_id')->count();
        }

        // On exige plusieurs tables, sinon ce test se reduirait a celui qui
        // ne couvrait que `translation_overrides` — exactement le defaut
        // qu'il est cense fermer.
        $this->assertGreaterThan(2, count($semees), 'Plusieurs tables a semantique plateforme doivent etre eprouvees.');
        $this->assertArrayHasKey('translation_overrides', $semees);
        $this->assertArrayHasKey('system_email_templates', $semees);

        $this->service()->remove($version->fresh());

        foreach ($semees as $table => $plateformeAvant) {
            // LA bonne assertion, et la premiere ecriture de ce test se
            // trompait de grandeur.
            //
            // J'avais assert « plus aucune ligne ne porte l'id de la
            // sandbox ». Or c'est EXACTEMENT ce que produit le defaut :
            // `ON DELETE SET NULL` met la colonne a NULL, donc la ligne cesse
            // d'etre « de la sandbox »... en devenant PLATEFORME. Le test
            // etait satisfait par le defaut qu'il devait attraper, et le
            // sabotage l'a montre : il restait vert avec le nettoyage reduit
            // a une seule table.
            //
            // Ce qu'il faut mesurer, c'est le nombre de lignes PLATEFORME :
            // il ne doit pas avoir AUGMENTE.
            $this->assertSame(
                $plateformeAvant,
                DB::table($table)->whereNull('organization_id')->count(),
                "Une ligne de la sandbox a ete PROMUE au niveau plateforme dans {$table}."
            );

            $this->assertSame(
                0,
                DB::table($table)->where('organization_id', $sandbox->id)->count(),
                "Et plus aucune ligne de la sandbox ne subsiste dans {$table}."
            );
        }
    }

    /**
     * De quoi ecrire une ligne minimale dans une table donnee, sans supposer
     * ses colonnes : on les LIT.
     *
     * @return array<string, mixed>
     */
    private function colonnesMinimales(string $table): array
    {
        $valeurs = [];

        // Toutes ces tables n'ont pas une cle UUID : certaines portent un
        // auto-increment, et lui donner un UUID fait echouer l'insertion. On
        // LIT le type plutot que de le supposer — le reflexe que cette TASK a
        // deja paye plusieurs fois.
        $typeDeLaCle = Schema::getColumnType($table, 'id');

        if (! in_array($typeDeLaCle, ['integer', 'bigint', 'int', 'int8'], true)) {
            $valeurs['id'] = (string) \Illuminate\Support\Str::uuid7();
        }

        $exemples = [
            'locale' => 'fr', 'group' => 'admin', 'key' => 'temoin.'.uniqid(),
            'value' => 'temoin', 'slug' => 'temoin-'.uniqid(), 'name' => 'Temoin',
            'name_b2c' => 'Temoin', 'name_b2b' => 'Temoin', 'label' => 'Temoin',
            'level' => 1, 'points_min' => 1, 'points_max' => 2,
            'loop_type' => 'temoin', 'based_on' => 'project', 'scope' => 'platform',
            'setting_kind' => 'temoin', 'provider' => 'temoin', 'model' => 'temoin',
            'status' => 'ok', 'feature' => 'temoin', 'is_active' => true,
            'enabled' => true, 'available' => true, 'subject' => 'Temoin',
            'body' => 'Temoin', 'content' => 'Temoin',
            'content_html' => '<p>Temoin</p>', 'variables' => null,
            'changed_by' => null, 'user_id' => null, 'category_id' => null,
        ];

        foreach (Schema::getColumnListing($table) as $colonne) {
            if ($colonne === 'id' || $colonne === 'organization_id') {
                continue;
            }

            if (array_key_exists($colonne, $exemples)) {
                $valeurs[$colonne] = $exemples[$colonne];

                continue;
            }

            if (in_array($colonne, ['created_at', 'updated_at'], true)) {
                $valeurs[$colonne] = now();
            }
        }

        return $valeurs;
    }

    public function test_l_ecran_porte_REELLEMENT_les_gestes_du_CDC_13_5(): void
    {
        // Trouve en revue : les gestes n'etaient prouves qu'au niveau ROUTE.
        // On POSTait sur `reset` et `remove` sans jamais verifier qu'un humain
        // puisse les ATTEINDRE. Supprimer le lien « Open sandbox » ou les deux
        // formulaires laissait toute la suite verte — donc l'exigence du CDC
        // 13.5 pouvait se reperdre exactement comme elle s'etait perdue une
        // premiere fois.
        $this->actingAs($this->superAdmin);

        $version = $this->versionValidee();

        // Avant le chargement : l'entree vers l'etape HUMAINE (CDC 12.2) doit
        // exister sur l'ecran. C'est le SEUL chemin de navigation vers elle.
        $avant = $this->get(route('admin.outils.scenarios.show', $version))->assertOk()->getContent();
        $this->assertStringContainsString(
            e(route('admin.outils.scenarios.approval', $version)),
            $avant,
            'L etape humaine doit etre atteignable depuis l ecran.'
        );

        $this->post(route('admin.outils.scenarios.approve', $version))->assertRedirect();
        $this->post(route('admin.outils.scenarios.load', $version->fresh()))->assertRedirect();
        $version->refresh();
        $sandbox = $version->scenarioPackLoad->organization;

        $html = $this->get(route('admin.outils.scenarios.show', $version))->assertOk()->getContent();

        // « Open sandbox » : le geste nomme par le CDC 13.5, et dont la cle de
        // langue avait vecu MORTE dans les deux locales.
        $this->assertStringContainsString('data-sandbox-open', $html);
        $this->assertStringContainsString(e(route('admin.organizations.edit', $sandbox)), $html);

        // Reset et Remove : leurs FORMULAIRES, pas seulement leurs routes.
        $this->assertStringContainsString(e(route('admin.outils.scenarios.reset', $version)), $html);
        $this->assertStringContainsString(e(route('admin.outils.scenarios.remove', $version)), $html);
    }

    public function test_l_ecran_d_une_sandbox_EN_CORBEILLE_garde_Reset_et_Remove(): void
    {
        // Le correctif `withTrashed()` sur la relation n'etait prouve par
        // RIEN : le test existant passait par le SERVICE, qui resout la
        // sandbox lui-meme. Seule la VUE lit la relation.
        //
        // Sans `withTrashed()`, la relation rend null, le panneau disparait,
        // et l'ecran propose « Approuver » a la place de Reset et Remove : la
        // sandbox et ses comptes redeviennent irretirables PAR L'ECRAN, le
        // defaut meme que cette TASK repare.
        $this->actingAs($this->superAdmin);

        $version = $this->versionApprouvee();
        $chargement = $this->service()->load($version);
        $chargement->organization->delete();

        $this->assertNotNull($chargement->organization->fresh()->deleted_at);

        $html = $this->get(route('admin.outils.scenarios.show', $version->fresh()))->assertOk()->getContent();

        $this->assertStringContainsString(__('admin.scenario_manager.sandbox_title'), $html);
        $this->assertStringContainsString(e(route('admin.outils.scenarios.reset', $version)), $html);
        $this->assertStringContainsString(e(route('admin.outils.scenarios.remove', $version)), $html);

        // Et on dit ce qui EST plutot que de tendre un lien mort : la liaison
        // de route refuserait une Organization en corbeille.
        $this->assertStringContainsString('data-sandbox-trashed', $html);
        $this->assertStringNotContainsString('data-sandbox-open', $html);
    }

    public function test_le_detail_d_un_refus_moteur_est_REELLEMENT_rendu(): void
    {
        // Le defaut que la revue precedente avait trouve : les deux cles de
        // langue attendaient `:detail` et personne ne le leur donnait, donc le
        // SuperAdmin lisait « Detail : :detail ». Corrige — mais rien ne le
        // prouvait, et retirer le parametre faisait revenir le bug a
        // l'identique sans qu'un test bronche.
        foreach (['fr', 'en'] as $locale) {
            App::setLocale($locale);

            foreach ([
                ScenarioVersionRefused::engineRefused('chargement absent'),
                ScenarioVersionRefused::revalidationFailed('index d avatars absent'),
            ] as $refus) {
                // Le detail est PORTE dans les deux cas — un appelant sans
                // interface, un journal, en ont besoin.
                $this->assertArrayHasKey('detail', $refus->parametres, 'Le refus doit PORTER son detail.');

                $phrase = __($refus->translationKey(), $refus->parametres);

                // LA propriete qui manquait : aucun marqueur ne survit au
                // rendu. C'est elle qui rougit si l'on retire le parametre.
                $this->assertStringNotContainsString(':detail', $phrase, "Marqueur non resolu en locale {$locale}.");

                // Et la ou la phrase PROMET le detail, il est bien la. Les
                // deux cles ne le promettent pas : `revalidation_failed` se
                // suffit a elle-meme, et l'exiger ici ferait rougir une
                // traduction parfaitement correcte.
                if (str_contains((string) __($refus->translationKey()), ':detail')) {
                    $this->assertStringContainsString($refus->parametres['detail'], $phrase);
                }
            }
        }

        App::setLocale('fr');
    }

    public function test_le_parcours_complet_par_l_ecran(): void
    {
        $version = $this->versionValidee();
        $this->actingAs($this->superAdmin);

        // Approuver.
        $this->post(route('admin.outils.scenarios.approve', $version))->assertRedirect();
        $this->assertTrue($version->fresh()->approvalMatchesCurrentDigest());

        // Charger.
        $this->post(route('admin.outils.scenarios.load', $version->fresh()))->assertRedirect();
        $version->refresh();
        $this->assertTrue($version->isLoaded());

        $sandbox = $version->scenarioPackLoad->organization;

        // L'ecran de detail montre la sandbox : slug REEL, date, digest charge
        // (CDC 13.5).
        $html = $this->get(route('admin.outils.scenarios.show', $version))->assertOk()->getContent();
        $this->assertStringContainsString(__('admin.scenario_manager.sandbox_title'), $html);

        // DANS le panneau, pas n'importe ou dans la page : sans collision le
        // slug reel vaut le slug propose, et le digest charge vaut le digest
        // courant — l'apercu de T1648 rend les deux sur TOUTE version. Une
        // assertion sur la chaine seule restait donc verte sans le panneau.
        foreach ([
            'data-sandbox-slug' => $sandbox->slug,
            'data-sandbox-digest' => (string) $version->scenarioPackLoad->manifest_digest,
            'data-sandbox-name' => $sandbox->name,
        ] as $crochet => $attendu) {
            $this->assertMatchesRegularExpression(
                '#'.$crochet.'[^>]*>\s*'.preg_quote(e($attendu), '#').'\s*<#',
                $html,
                "Le panneau doit rendre {$crochet}."
            );
        }

        // Recharger : « deja charge », pas une erreur (CDC 13.3).
        $this->post(route('admin.outils.scenarios.load', $version->fresh()))
            ->assertRedirect()
            ->assertSessionHas('status', __('admin.scenario_manager.flash_already_loaded', ['slug' => $sandbox->slug]));
        $this->assertSame(2, Organization::query()->count());

        // Reinitialiser : meme sandbox.
        $this->post(route('admin.outils.scenarios.reset', $version->fresh()))->assertRedirect();
        $this->assertSame(2, Organization::query()->count());
        $this->assertSame(22, User::query()->where('organization_id', $sandbox->id)->count());

        // Retirer : le monde et la sandbox disparaissent, la version reste VALID.
        $this->post(route('admin.outils.scenarios.remove', $version->fresh()))->assertRedirect();
        $version->refresh();
        $this->assertSame(0, Organization::withTrashed()->whereKey($sandbox->id)->count());
        $this->assertSame(ScenarioManifestVersion::STATE_VALID, $version->state);
        $this->assertFalse($version->isLoaded());
    }

    public function test_un_refus_arrive_a_l_ecran_comme_une_phrase(): void
    {
        // Pas une 500. Charger sans approbation est un refus METIER, et
        // l'utilisateur doit lire pourquoi.
        $version = $this->versionValidee();

        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.scenarios.load', $version))
            ->assertSessionHasErrors(['scenario' => __('admin.scenario_manager.refus_not_approved')]);

        $this->assertSame(1, Organization::query()->count());
    }

    // =====================================================================
    // Ce que les deux revues adversariales ont trouve
    // =====================================================================

    public function test_reinitialiser_restaure_EXACTEMENT_le_monde_dans_la_MEME_sandbox(): void
    {
        // Les neuf points exiges par MASTER avant que T1650 puisse etre
        // mergee, sur une sandbox REELLEMENT salie.
        //
        // La version precedente de ce test passait sur un NO-OP complet : ses
        // assertions — meme sandbox, 2 Organizations, 22 comptes — seraient
        // toutes restees vraies si `reset()` n'avait rien fait. Aucune ne
        // SALISSAIT le monde. C'est ce qui m'a fait manquer que le Resetter
        // recreait ce qui manquait sans defaire ce qui avait ete modifie.
        $version = $this->versionApprouvee();
        $chargement = $this->service()->load($version);
        $sandbox = $chargement->organization;
        $digestCharge = $chargement->packLoad->load->manifest_digest;
        $idDuChargement = $chargement->packLoad->load->id;

        $boucle = \App\Models\Loop::query()->where('organization_id', $sandbox->id)->orderBy('id')->firstOrFail();
        $nomOrigine = $boucle->name;
        $comptesOrigine = User::query()->where('organization_id', $sandbox->id)->count();

        // 1. renommer une Boucle
        \App\Models\Loop::query()->whereKey($boucle->id)->update(['name' => 'NOM SABOTE']);

        // 2. modifier un contenu
        $message = \App\Models\LoopMessage::query()->where('loop_id', $boucle->id)->orderBy('id')->firstOrFail();
        $corpsOrigine = $message->body;
        \App\Models\LoopMessage::query()->whereKey($message->id)->update(['body' => 'CORPS SABOTE']);

        // 3. supprimer un objet du Manifest
        $supprime = User::query()->where('organization_id', $sandbox->id)->orderByDesc('id')->firstOrFail();
        User::query()->whereKey($supprime->id)->delete();

        // 4. creer un objet declarable supplementaire, au nom d'une personne
        //    du pack — sinon le preflight refuserait, et a juste titre.
        $auteurDuPack = User::query()->where('organization_id', $sandbox->id)->orderBy('id')->firstOrFail();
        $enTrop = \App\Models\Loop::query()->create([
            'organization_id' => $sandbox->id,
            'name' => 'BOUCLE EN TROP',
            'slug' => 'boucle-en-trop',
            'description' => 'Creee apres le chargement.',
            'created_by' => $auteurDuPack->id,
        ]);

        // 4 bis. ajouter un message DANS une Boucle DU SCENARIO — le cas que
        //        le CDC 15.2 appelle l'evolution normale d'une sandbox
        //        vivante, et que la troisieme phrase du libelle Reset
        //        pretendait conserver.
        $messageApresChargement = (string) \Illuminate\Support\Str::uuid7();
        \App\Models\LoopMessage::query()->create([
            'id' => $messageApresChargement,
            'loop_id' => $boucle->id,
            'organization_id' => $sandbox->id,
            'sender_id' => $auteurDuPack->id,
            'body' => 'Ecrit APRES le chargement.',
            'type' => $message->type,
        ]);

        // 5. Reset
        $sandboxApres = $this->service()->reset($version->fresh());

        // 6. le monde RESTAURE, verifie par son CONTENU et non par ses cles.
        //
        // Une restauration exacte purge puis recree : les identifiants
        // CHANGENT, et c'est normal. Asserter sur une cle ferait rougir un
        // reset parfaitement correct — c'est exactement ce que MASTER
        // demandait d'eviter en disant « pas seulement les IDs ».
        $nomsDeBoucles = \App\Models\Loop::query()->where('organization_id', $sandbox->id)->pluck('name')->all();

        $this->assertContains($nomOrigine, $nomsDeBoucles, 'Un renommage doit etre DEFAIT.');
        $this->assertNotContains('NOM SABOTE', $nomsDeBoucles);

        $boucleRestauree = \App\Models\Loop::query()
            ->where('organization_id', $sandbox->id)
            ->where('name', $nomOrigine)
            ->firstOrFail();

        $corpsRestaures = \App\Models\LoopMessage::query()->where('loop_id', $boucleRestauree->id)->pluck('body')->all();

        $this->assertContains($corpsOrigine, $corpsRestaures, 'Un contenu modifie doit etre restaure.');
        $this->assertNotContains('CORPS SABOTE', $corpsRestaures);

        $this->assertSame($comptesOrigine, User::query()->where('organization_id', $sandbox->id)->count(), 'Un objet supprime doit revenir.');

        // 7. la MEME Organization
        $this->assertSame($sandbox->id, $sandboxApres->id);
        $this->assertSame(2, Organization::query()->count(), 'Aucune seconde sandbox.');

        // 8. le MEME chargement, au MEME digest
        $version->refresh();
        $this->assertSame($idDuChargement, $version->scenario_pack_load_id);
        $this->assertSame($digestCharge, $version->scenarioPackLoad->manifest_digest);
        $this->assertTrue($version->isLoaded());

        // LA BORNE, ET SA LIMITE — les deux dites, parce que n'en dire qu'une
        // serait un mensonge confortable.
        //
        // Ce qui est VRAI : un objet de PREMIER NIVEAU que le pack n'a jamais
        // inscrit survit au Reset.
        $this->assertSame(1, \App\Models\Loop::query()->whereKey($enTrop->id)->count(), 'Une Boucle que le pack n a pas inscrite lui survit.');

        // Ce qui est FAUX, et que j'avais ecrit ici comme un invariant : « le
        // moteur ne detruit que ce qu'il a inscrit ». Il detruit aussi tout ce
        // qui DEPEND de ce qu'il a inscrit, par les cascades du schema. Le
        // test ci-dessous le PROUVE plutot que de le laisser croire : un
        // message ecrit APRES le chargement, dans une Boucle du scenario, part
        // avec elle.
        //
        // C'est la difference entre une borne et un sous-ensemble choisi :
        // mon assertion d'origine ne portait que sur le cas ou l'enonce se
        // verifiait.
        $this->assertSame(
            0,
            \App\Models\LoopMessage::query()->whereKey($messageApresChargement)->count(),
            'Un message ajoute dans une Boucle du scenario part en CASCADE avec elle.'
        );

        // 9. un second Reset est idempotent
        $empreinteApresPremier = $this->empreinteSandbox($sandbox->id);
        $this->service()->reset($version->fresh());
        $this->assertSame($empreinteApresPremier, $this->empreinteSandbox($sandbox->id), 'Le second Reset ne change plus rien.');
    }

    public function test_une_AUTRE_version_ne_peut_pas_charger_un_monde_deja_possede(): void
    {
        // Verdict MASTER du 27/09 : « une sandbox vivante doit avoir une seule
        // version administrative proprietaire. »
        //
        // La situation naissait sans rien forcer : `(pack_id, digest)` se
        // calcule sur le DOCUMENT, pas sur `scenario_key`. Coller deux fois le
        // meme JSON sous deux cles differentes donnait deux versions qui
        // declaraient le meme monde, et l'une pouvait detruire la sandbox que
        // l'autre continuait d'afficher. La garde l'empeche desormais de
        // NAITRE, au lieu de la constater au retrait.
        $premiere = $this->versionApprouvee();
        $chargement = $this->service()->load($premiere);

        $seconde = $this->versionValidee2('jumelle');
        $this->service()->approve($seconde, $this->superAdmin);

        $avant = Organization::query()->count();

        $refus = $this->attendreRefus(
            ScenarioVersionRefused::LOAD_ALREADY_OWNED,
            fn () => $this->service()->load($seconde->fresh())
        );

        // Le refus NOMME le proprietaire : sans cela, l'operateur voit « non »
        // sans savoir quelle version retirer pour avancer.
        $this->assertSame($premiere->name, $refus->parametres['scenario'] ?? null);
        $this->assertSame($premiere->version, $refus->parametres['version'] ?? null);

        $this->assertSame($avant, Organization::query()->count(), 'Aucune sandbox de plus.');
        $this->assertFalse($seconde->fresh()->isLoaded(), 'La seconde version n est pas chargee.');
        $this->assertTrue($premiere->fresh()->isLoaded(), 'Et la premiere garde son monde.');
        $this->assertSame(
            $chargement->packLoad->load->id,
            $premiere->fresh()->scenario_pack_load_id,
            'Le chargement de la premiere version est intact.'
        );
    }

    public function test_la_version_PROPRIETAIRE_peut_rejouer_son_propre_chargement(): void
    {
        // L'autre moitie du verdict, et elle doit etre prouvee separement :
        // une garde d'exclusivite ecrite trop large refuserait le rejeu de la
        // proprietaire elle-meme — c'est-a-dire l'idempotence que le CDC 13.3
        // exige.
        $version = $this->versionApprouvee();
        $premier = $this->service()->load($version);

        $avant = Organization::query()->count();

        $rejeu = $this->service()->load($version->fresh());

        $this->assertTrue($rejeu->wasReplay, 'Le meme document rend le MEME chargement.');
        $this->assertSame($premier->organization->id, $rejeu->organization->id);
        $this->assertSame($premier->packLoad->load->id, $rejeu->packLoad->load->id);
        $this->assertSame($avant, Organization::query()->count(), 'Aucune sandbox de plus.');
    }

    public function test_retirer_refuse_quand_une_AUTRE_version_declare_le_meme_chargement(): void
    {
        // La ceinture, maintenant que la bretelle existe.
        //
        // L'exclusivite au Load empeche cette situation de naitre par le
        // produit. Elle peut encore exister en base — deux versions ecrites
        // avant cette TASK, une reprise manuelle. Le refus au retrait reste
        // donc necessaire, et il doit etre prouve : on installe l'etat
        // DIRECTEMENT, puisque le chemin qui le produisait est desormais ferme.
        $premiere = $this->versionApprouvee();
        $chargement = $this->service()->load($premiere);

        $seconde = $this->versionValidee2('jumelle');
        $this->service()->approve($seconde, $this->superAdmin);

        // `scenario_pack_load_id` est hors `$fillable` : c'est un attribut
        // systeme, et seul `forceFill` l'ecrit.
        $seconde->fresh()->forceFill([
            'scenario_pack_load_id' => $chargement->packLoad->load->id,
        ])->save();

        $this->attendreRefus(
            ScenarioVersionRefused::LOAD_SHARED,
            fn () => $this->service()->remove($premiere->fresh())
        );

        $this->assertSame(1, Organization::query()->whereKey($chargement->organization->id)->count(), 'Rien n a ete detruit.');
        $this->assertTrue($seconde->fresh()->isLoaded(), 'Et l autre version garde son lien.');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('gestesDestructeurs')]
    public function test_une_contrainte_PROTEGEE_refuse_le_geste_et_n_abime_RIEN(string $geste): void
    {
        // Les tests que MASTER exige pour lever le dernier blocker de T1650
        // (MASTER_DECISION_RESTRICT = A).
        //
        // On fabrique le blocage REEL, pas une doublure : une ecriture de
        // points au nom d'un persona. `point_ledger.user_id` est en
        // ON DELETE RESTRICT depuis T1635, donc la purge de ce persona
        // devient impossible — exactement ce qui arrive des qu'une sandbox a
        // servi.
        $version = $this->versionApprouvee();
        $chargement = $this->service()->load($version);
        $sandbox = $chargement->organization;

        $persona = User::query()->where('organization_id', $sandbox->id)->firstOrFail();

        // `reason` est un ENUM ferme et la table n'a pas d'`updated_at` : la
        // ligne est construite sur le schema REEL, pas sur l'idee que je m'en
        // fais.
        DB::table('point_ledger')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid7(),
            'user_id' => $persona->id,
            'organization_id' => $sandbox->id,
            'delta' => 5,
            'reason' => 'adjustment',
            'created_at' => now(),
        ]);

        $avant = $this->empreinteParLigne();

        // 1. Le refus arrive comme une PHRASE, jamais comme une 500.
        $refus = $this->attendreRefus(
            ScenarioVersionRefused::PROTECTED_DATA,
            fn () => $this->service()->{$geste}($version->fresh())
        );

        // 2. Le message est GENERIQUE : aucun nom de table ni de contrainte.
        $this->assertStringNotContainsString('point_ledger', $refus->getMessage());
        $this->assertSame([], $refus->parametres, 'Le detail technique reste dans les journaux.');

        // 3. AUCUNE mutation ne persiste : la transaction est entierement
        //    annulee, et l'etat d'avant est bit pour bit celui d'apres.
        $this->assertSame($avant, $this->empreinteParLigne(), 'La transaction doit etre ENTIEREMENT annulee.');

        // 4. La sandbox existe toujours, et n'est pas passee en corbeille.
        $this->assertSame(1, Organization::query()->withTrashed()->whereKey($sandbox->id)->count());
        $this->assertNull(Organization::query()->withTrashed()->whereKey($sandbox->id)->value('deleted_at'));

        // 5. La version reste LOADED : le refus ne l'a pas detachee de son
        //    monde.
        $this->assertTrue($version->fresh()->isLoaded(), 'La version doit rester LOADED apres le refus.');

        // 6. Un SECOND essai rend le MEME refus, pas un etat degrade. C'est
        //    ce point qui distingue une transaction vraiment annulee d'une
        //    transaction a moitie passee.
        $this->attendreRefus(
            ScenarioVersionRefused::PROTECTED_DATA,
            fn () => $this->service()->{$geste}($version->fresh())
        );

        $this->assertSame($avant, $this->empreinteParLigne(), 'Et le second essai ne laisse pas plus de trace que le premier.');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function gestesDestructeurs(): array
    {
        // Les DEUX gestes, parce que proteger le seul Remove laisserait Reset
        // comme une autre voie vers la meme destruction — la dissymetrie que
        // MASTER avait relevee sur le preflight.
        return ['reset' => ['reset'], 'remove' => ['remove']];
    }

    public function test_une_propriete_INCONNUE_au_registre_fait_refuser_le_reset_avant_toute_purge(): void
    {
        // La pre-garde du Resetter en mode exact, que la revue a montree
        // supprimable sans faire rougir un test.
        //
        // Elle compte : le mode exact DETRUIT les entites du registre. Une
        // ligne dont on ignore si le pack l'a creee ou seulement reutilisee
        // ne doit pas etre detruite « au cas ou ». Fail-closed.
        $version = $this->versionApprouvee();
        $chargement = $this->service()->load($version);

        // Une seule ligne perd sa propriete — exactement l'etat des registres
        // ecrits avant que l'ownership ne soit note.
        $uneEntite = ScenarioPackEntity::query()
            ->where('scenario_pack_load_id', $chargement->packLoad->load->id)
            ->firstOrFail();

        ScenarioPackEntity::query()->whereKey($uneEntite->id)->update(['ownership' => null]);

        $avant = $this->empreinteParLigne();

        $this->attendreRefus(
            ScenarioVersionRefused::ENGINE_REFUSED,
            fn () => $this->service()->reset($version->fresh())
        );

        $this->assertSame($avant, $this->empreinteParLigne(), 'Et RIEN n a ete purge avant le refus.');
    }

    public function test_un_digest_corrompu_refuse_le_chargement_par_une_PHRASE(): void
    {
        // `enTraduisantLeRefusDuMoteur()`, que la revue a montre supprimable
        // sans faire rougir un test.
        //
        // Le moteur REVALIDE toujours, et peut refuser un document pourtant
        // approuve. Sans ce traducteur, le refus sortait en exception moteur
        // nue — donc en 500 sur l'ecran, pour un cas qui est un refus METIER.
        $version = $this->versionValidee();
        $this->service()->approve($version, $this->superAdmin);

        // Les DEUX digests bougent ensemble : l'approbation reste coherente
        // avec le document, donc la porte humaine passe, et c'est bien la
        // REVALIDATION du moteur qui refuse.
        $faux = str_repeat('0', 64);
        $version->forceFill(['digest' => $faux, 'approved_digest' => $faux])->save();

        $this->attendreRefus(
            ScenarioVersionRefused::REVALIDATION_FAILED,
            fn () => $this->service()->load($version->fresh())
        );

        $this->assertSame(0, ScenarioPackLoad::query()->count(), 'Aucun monde n a ete cree.');
    }

    public function test_une_organization_REMPLIE_par_le_pack_mais_pas_NEE_sandbox_est_videe_sans_etre_supprimee(): void
    {
        // LA couverture du terme `scenario_sandbox_created_at` de `remove()`,
        // celle que le test precedent croyait apporter.
        //
        // Pour l'atteindre il faut que le moteur LAISSE PASSER : l'Organization
        // doit etre dans l'allowlist — sinon sa garde refuse — et les entites
        // du registre doivent bien lui appartenir, sinon le purger refuse.
        // C'est exactement le cas reel d'une Organization hote, declaree dans
        // `config/scenario_packs.php`, qu'un pack a REMPLIE sans l'avoir creee.
        //
        // Remove doit alors la VIDER et l'laisser vivre.
        $version = $this->versionApprouvee();
        $chargement = $this->service()->load($version);
        $organization = $chargement->organization;

        config(['scenario_packs.allowed_organizations' => [$organization->slug]]);

        // Elle perd sa preuve de NAISSANCE, sans rien perdre d'autre :
        // le chargement garde `organization_created_by_pack`.
        Organization::query()->whereKey($organization->id)->update(['scenario_sandbox_created_at' => null]);

        $this->service()->remove($version->fresh());

        $this->assertSame(
            1,
            Organization::query()->withTrashed()->whereKey($organization->id)->count(),
            'Sans preuve de naissance sandbox, l Organization est videe mais SURVIT.'
        );

        $this->assertNull(
            Organization::query()->withTrashed()->whereKey($organization->id)->value('deleted_at'),
            'Et pas davantage en corbeille : elle n a pas ete touchee.'
        );

        $this->assertFalse($version->fresh()->isLoaded(), 'Le monde a bien ete retire.');
    }

    public function test_une_sandbox_en_corbeille_ne_bloque_plus_le_retrait_pour_toujours(): void
    {
        // `Organization` est en SoftDeletes. La relation ordinaire rendait
        // `null` sur une sandbox en corbeille, et on repondait « le chargement
        // n'appartient pas a cette version » — une phrase FAUSSE. Reset et
        // Remove refusaient tous deux, donc la sandbox et ses comptes
        // restaient en base POUR TOUJOURS.
        $version = $this->versionApprouvee();
        $chargement = $this->service()->load($version);
        $sandbox = $chargement->organization;

        $sandbox->delete();
        $this->assertNotNull($sandbox->fresh()->deleted_at);

        // Le geste aboutit desormais : la sandbox est retrouvee malgre la
        // corbeille, et le monde peut etre retire.
        $this->service()->remove($version->fresh());

        $this->assertSame(0, Organization::withTrashed()->whereKey($sandbox->id)->count());
        $this->assertNull($version->fresh()->scenario_pack_load_id);
    }

    public function test_une_organization_de_l_allowlist_n_est_JAMAIS_supprimee(): void
    {
        // Les Organizations declarees dans `config/scenario_packs.php` sont
        // REELLES, avec du contenu reel, et ne doivent jamais etre supprimees.
        //
        // CE QUE CE TEST PROUVE, EXACTEMENT : que le refus arrive comme une
        // PHRASE. Il ne couvre PAS le terme `scenario_sandbox_created_at` de
        // `remove()` — le moteur refuse AVANT, parce que les entites du
        // registre appartiennent a une autre Organization.
        //
        // Mon commentaire d'origine affirmait le contraire, et c'est une
        // relecture adverse qui l'a mesure : le terme se supprimait sans faire
        // rougir un seul des 27 tests. Un docblock qui AFFIRME une couverture
        // est aussi dangereux qu'un test absent — il fait croire que la
        // question est reglee. La vraie couverture est le test suivant.
        $cliente = Organization::factory()->create(['slug' => 'organisation-allowlistee']);
        config(['scenario_packs.allowed_organizations' => ['organisation-allowlistee']]);

        $version = $this->versionApprouvee();
        $chargement = $this->service()->load($version);

        // On repointe le chargement vers l'Organization de l'allowlist.
        ScenarioPackLoad::query()->whereKey($chargement->packLoad->load->id)
            ->update(['organization_id' => $cliente->id]);

        // MESURE : le moteur refuse d'abord — sa propre ceinture voit que les
        // entites du registre appartiennent a une AUTRE Organization. Le refus
        // arrive donc comme une phrase, pas comme une panne.
        $this->attendreRefus(
            ScenarioVersionRefused::ENGINE_REFUSED,
            fn () => $this->service()->remove($version->fresh())
        );

        $this->assertSame(
            1,
            Organization::query()->whereKey($cliente->id)->count(),
            'Une Organization de l allowlist n est pas nee comme sandbox : elle ne se supprime pas.'
        );

        // Et la vraie sandbox n'a pas ete touchee non plus.
        $this->assertSame(1, Organization::query()->whereKey($chargement->organization->id)->count());
    }

    public function test_l_ecran_d_approbation_n_offre_QUE_ce_que_l_etat_autorise(): void
    {
        // La matrice n'etait figee dans AUCUN sens : inverser une condition de
        // la vue laissait les tests verts, et l'ecran aurait propose
        // « Creer la sandbox et charger » sur un brouillon invalide.
        $this->actingAs($this->superAdmin);

        $brouillon = $this->versionValidee();
        $brouillon->forceFill(['state' => ScenarioManifestVersion::STATE_DRAFT, 'validation_summary' => null, 'digest' => null])->save();

        $this->get(route('admin.outils.scenarios.approval', $brouillon))
            ->assertOk()
            ->assertSee(__('admin.scenario_manager.approval_blocked'))
            ->assertDontSee(__('admin.scenario_manager.approval_confirm'))
            ->assertDontSee(__('admin.scenario_manager.load_action'))
            // Et « 0 erreur » ne doit PAS s'afficher : rien n'a ete valide.
            ->assertDontSee(__('admin.scenario_manager.approval_no_error'));

        $valide = $this->versionValidee2('a-approuver');
        $this->get(route('admin.outils.scenarios.approval', $valide))
            ->assertOk()
            ->assertSee(__('admin.scenario_manager.approval_confirm'))
            ->assertDontSee(__('admin.scenario_manager.load_action'))
            ->assertDontSee(__('admin.scenario_manager.approval_blocked'));

        $this->service()->approve($valide, $this->superAdmin);
        $this->get(route('admin.outils.scenarios.approval', $valide->fresh()))
            ->assertOk()
            ->assertSee(__('admin.scenario_manager.load_action'))
            ->assertDontSee(__('admin.scenario_manager.approval_confirm'));

        // Une fois CHARGEE, l'ecran ne promet plus une nouvelle sandbox.
        $this->service()->load($valide->fresh());
        $this->get(route('admin.outils.scenarios.approval', $valide->fresh()))
            ->assertOk()
            ->assertDontSee(__('admin.scenario_manager.load_action'))
            ->assertDontSee(e(__('admin.scenario_manager.load_notice')), false);
    }

    public function test_approuver_deux_fois_ne_reecrit_pas_la_trace_d_audit(): void
    {
        // `approved_by` et `approved_at` sont la seule trace de la porte
        // humaine. Sans garde, un POST direct permettait a un autre SuperAdmin
        // de devenir l'approbateur enregistre d'un contenu qu'il n'a jamais vu.
        $version = $this->versionApprouvee();
        $premierApprobateur = $version->approved_by;
        $premiereDate = $version->approved_at;

        $autre = User::factory()->create(['organization_id' => $this->organizationDuSuperAdmin->id, 'is_admin' => true]);

        $this->travel(1)->hours();
        $this->service()->approve($version->fresh(), $autre);

        $version->refresh();
        $this->assertSame($premierApprobateur, $version->approved_by);
        $this->assertTrue($premiereDate->equalTo($version->approved_at));
    }

    // =====================================================================
    // Contenu etranger : les DEUX gestes refusent, ZERO mutation
    // =====================================================================

    public function test_un_compte_etranger_fait_refuser_le_RETRAIT_sans_toucher_un_octet(): void
    {
        // Le scenario mesure en revue, par des ecrans deja en production :
        // un vrai prospect est place dans la sandbox depuis /admin/users, qui
        // accepte n'importe quelle Organization. Sans ce controle, Remove
        // laissait ce compte avec `organization_id` a NULL — hors de tout
        // tenant, invisible partout — et detruisait son contenu.
        [$version, $sandbox] = $this->sandboxAvecUnCompteEtranger();

        $avant = $this->empreinteParLigne();

        $this->attendreRefus(
            ScenarioVersionRefused::FOREIGN_CONTENT,
            fn () => $this->service()->remove($version->fresh())
        );

        $this->assertSame($avant, $this->empreinteParLigne(), 'ZERO mutation : ni INSERT, ni UPDATE, ni DELETE.');
        $this->assertSame(1, Organization::query()->whereKey($sandbox->id)->count());
    }

    public function test_un_compte_etranger_fait_refuser_la_REINITIALISATION_sans_toucher_un_octet(): void
    {
        // Le point que je n'avais pas vu et que MASTER a pose : proteger le
        // seul Remove laisserait Reset comme une AUTRE voie pour effacer les
        // memes donnees. Un Reset exact est destructif vis-a-vis de tout ce
        // qui a ete fait apres le Load.
        [$version, $sandbox] = $this->sandboxAvecUnCompteEtranger();

        $avant = $this->empreinteParLigne();

        $this->attendreRefus(
            ScenarioVersionRefused::FOREIGN_CONTENT,
            fn () => $this->service()->reset($version->fresh())
        );

        $this->assertSame($avant, $this->empreinteParLigne(), 'ZERO mutation : ni INSERT, ni UPDATE, ni DELETE.');
        $this->assertSame(1, Organization::query()->whereKey($sandbox->id)->count());
    }

    public function test_le_contenu_d_un_compte_etranger_le_fait_refuser_meme_apres_son_depart(): void
    {
        // La personne est repartie, son contenu reste. Il ne doit pas etre
        // emporte pour autant.
        [$version, $sandbox, $etranger] = $this->sandboxAvecUnCompteEtranger();

        $boucle = \App\Models\Loop::query()->where('organization_id', $sandbox->id)->firstOrFail();
        // `created_by`, pas `owner_id` : le nom de colonne est LU, pas devine.
        \App\Models\Loop::query()->whereKey($boucle->id)->update(['created_by' => $etranger->id]);

        // La personne quitte la sandbox : seul son contenu la designe encore.
        User::query()->whereKey($etranger->id)->update(['organization_id' => $this->organizationDuSuperAdmin->id]);

        $avant = $this->empreinteParLigne();

        $this->attendreRefus(
            ScenarioVersionRefused::FOREIGN_CONTENT,
            fn () => $this->service()->remove($version->fresh())
        );

        $this->assertSame($avant, $this->empreinteParLigne());
    }

    public function test_la_version_supprimee_PENDANT_le_chargement_ne_laisse_aucune_orpheline(): void
    {
        // Exigence MASTER, mot pour mot : « prouve par test que l'exception
        // finale ne ressuscite pas l'orpheline. »
        //
        // Le defaut etait invisible a la lecture : le nettoyage et le `throw`
        // vivaient dans le MEME `DB::transaction()`. Or `transaction()`
        // attrape, ROLLBACK, puis relance — le refus defaisait donc
        // systematiquement le nettoyage qui le precedait, et la sandbox,
        // committee plus tot par le moteur, survivait sans version pour la
        // retirer.
        //
        // La couture : un service de chargement qui supprime la version
        // APRES que le moteur a cree le monde, exactement la fenetre reelle.
        $version = $this->versionApprouvee();

        $this->app->bind(ManifestSandboxLoadService::class, fn ($app) => new class(
            $app->make(\App\Support\ScenarioPacks\Manifest\ScenarioSandboxProvisioner::class),
            $app->make(\App\Support\ScenarioPacks\ScenarioPackLoader::class),
            $version->getKey()
        ) extends ManifestSandboxLoadService {
            public function __construct(
                \App\Support\ScenarioPacks\Manifest\ScenarioSandboxProvisioner $provisioner,
                \App\Support\ScenarioPacks\ScenarioPackLoader $loader,
                private readonly string $versionASupprimer,
            ) {
                parent::__construct($provisioner, $loader);
            }

            public function load(string $json, string $approvedDigest): \App\Support\ScenarioPacks\Manifest\ManifestSandboxLoadResult
            {
                $resultat = parent::load($json, $approvedDigest);

                ScenarioManifestVersion::query()->whereKey($this->versionASupprimer)->delete();

                return $resultat;
            }
        });

        $organizationsAvant = Organization::query()->withTrashed()->count();

        $this->attendreRefus(
            ScenarioVersionRefused::VERSION_GONE,
            fn () => $this->service()->load($version)
        );

        // LA question : que reste-t-il ?
        $this->assertSame(
            $organizationsAvant,
            Organization::query()->withTrashed()->count(),
            'La sandbox orpheline doit avoir ete DETRUITE, pas ressuscitee par le rollback.'
        );

        $this->assertSame(0, ScenarioPackLoad::query()->count(), 'Et sa ligne de chargement avec elle.');
    }

    public function test_les_MESSAGES_d_un_compte_etranger_font_refuser_les_deux_gestes(): void
    {
        // LE defaut bloquant de la revue, transforme en test.
        //
        // `loop_messages` ne porte NI `user_id` NI `author_id` : elle designe
        // son auteur par `sender_id`. L'ancienne liste ecrite a la main
        // n'avait pas ce nom, donc cette table — la plus volumineuse du
        // produit — n'etait jamais inspectee. Les messages ChatLoop d'une
        // personne reelle passee par la sandbox partaient en CASCADE avec les
        // Boucles, sans un mot.
        //
        // Ce test ne nomme AUCUNE colonne du preflight : il ecrit un message
        // comme le produit l'ecrit, et exige le refus.
        [$version, $sandbox, $etranger] = $this->sandboxAvecUnCompteEtranger();

        $boucle = \App\Models\Loop::query()->where('organization_id', $sandbox->id)->firstOrFail();

        $message = \App\Models\LoopMessage::query()->where('loop_id', $boucle->id)->firstOrFail();
        \App\Models\LoopMessage::query()->whereKey($message->id)->update([
            'sender_id' => $etranger->id,
            'body' => 'Message ecrit par une personne reelle.',
        ]);

        // Elle repart : plus aucun compte etranger n'est PRESENT dans la
        // sandbox. Seul son message la designe encore.
        User::query()->whereKey($etranger->id)->update(['organization_id' => $this->organizationDuSuperAdmin->id]);

        $inventaire = (new \App\Support\ScenarioManager\ScenarioSandboxPreflight)
            ->inventaireEtranger($version->fresh()->scenarioPackLoad, $sandbox);

        $this->assertArrayHasKey('loop_messages', $inventaire, 'Le message doit etre VU.');
        $this->assertArrayNotHasKey('users', $inventaire, 'Et la personne, elle, est bien partie.');

        $avant = $this->empreinteParLigne();

        $this->attendreRefus(
            ScenarioVersionRefused::FOREIGN_CONTENT,
            fn () => $this->service()->remove($version->fresh())
        );

        $this->attendreRefus(
            ScenarioVersionRefused::FOREIGN_CONTENT,
            fn () => $this->service()->reset($version->fresh())
        );

        $this->assertSame($avant, $this->empreinteParLigne(), 'Et RIEN n a bouge, dans aucun des deux gestes.');
    }

    // =====================================================================
    // Outillage
    // =====================================================================

    /**
     * Une sandbox chargee, dans laquelle un compte ETRANGER a ete place.
     *
     * @return array{0: ScenarioManifestVersion, 1: Organization, 2: User}
     */
    private function sandboxAvecUnCompteEtranger(): array
    {
        $version = $this->versionApprouvee();
        $chargement = $this->service()->load($version);
        $sandbox = $chargement->organization;

        $etranger = User::factory()->create([
            'organization_id' => $this->organizationDuSuperAdmin->id,
            'is_admin' => false,
        ]);

        // Exactement ce que fait `/admin/users` : une affectation, sans plus.
        User::query()->whereKey($etranger->id)->update(['organization_id' => $sandbox->id]);

        return [$version->fresh(), $sandbox, $etranger];
    }

    /**
     * Une empreinte qui voit INSERT, UPDATE **et** DELETE.
     *
     * Un compte de lignes ne voit qu'INSERT et DELETE — et encore, il les
     * confond quand ils se compensent. C'est precisement ce qui m'a laisse
     * croire qu'un Reset no-op fonctionnait. Ici chaque LIGNE est resumee par
     * une empreinte de son contenu complet : modifier une colonne change
     * l'empreinte.
     *
     * @return array<string, list<string>>
     */
    /**
     * L'empreinte METIER de cette sandbox.
     *
     * Les colonnes comparees sont NOMMEES, une par une. Deux raisons, toutes
     * deux mesurees :
     *
     * - une restauration exacte purge puis recree, donc chaque identifiant et
     *   chaque cle etrangere CHANGENT. Les comparer reviendrait a comparer des
     *   adresses la ou l'on veut comparer un monde ;
     * - certaines colonnes ne sont pas deterministes d'un chargement a
     *   l'autre — un mot de passe re-hache avec un sel neuf, un jeton. Les
     *   inclure ferait rougir un second Reset parfaitement idempotent.
     *
     * Une liste nommee dit donc exactement ce que « le monde est identique »
     * signifie ici, au lieu de le laisser dependre du schema.
     *
     * @return array<string, list<string>>
     */
    private function empreinteSandbox(string $organizationId): array
    {
        $colonnesMetier = [
            'loops' => ['name', 'slug', 'description', 'type', 'visibility', 'access_mode', 'tagline'],
            'users' => ['first_name', 'name', 'email', 'bio', 'location'],
            'dossiers' => ['name', 'visibility', 'system_role'],
            'loop_messages' => ['body', 'type'],
        ];

        $empreinte = [];

        foreach ($colonnesMetier as $table => $colonnes) {
            $lignes = DB::table($table)->where('organization_id', $organizationId)->get();

            $empreinte[$table] = $lignes
                ->map(static function (object $ligne) use ($colonnes): string {
                    $valeurs = [];

                    foreach ($colonnes as $colonne) {
                        $valeurs[$colonne] = ((array) $ligne)[$colonne] ?? null;
                    }

                    return md5(json_encode($valeurs));
                })
                ->sort()
                ->values()
                ->all();
        }

        return $empreinte;
    }

    private function empreinteParLigne(): array
    {
        $empreinte = [];

        foreach (Schema::getTables() as $table) {
            $nom = $table['name'];

            if (in_array($nom, ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs'], true)) {
                continue;
            }

            $lignes = DB::table($nom)->get()
                ->map(static fn (object $ligne): string => md5(json_encode((array) $ligne)))
                ->all();

            sort($lignes);

            $empreinte[$nom] = $lignes;
        }

        ksort($empreinte);

        return $empreinte;
    }

    private function service(): ScenarioLifecycleService
    {
        return app(ScenarioLifecycleService::class);
    }

    /**
     * @param  \Closure():mixed  $geste
     */
    /**
     * Le refus est RENDU, pas seulement constate.
     *
     * Verifier la seule `reason` laisse passer un refus dont les parametres
     * sont vides — c'est exactement comme ca que `refus_engine_refused`
     * affichait litteralement « :detail » en production sans qu'aucun test ne
     * bronche. Les tests qui ont quelque chose a dire sur le contenu du refus
     * l'assertent donc a partir de cette valeur.
     */
    private function attendreRefus(string $raisonAttendue, \Closure $geste): ScenarioVersionRefused
    {
        try {
            $geste();
        } catch (ScenarioVersionRefused $refus) {
            $this->assertSame($raisonAttendue, $refus->reason, 'Le refus doit porter la bonne raison.');

            return $refus;
        }

        $this->fail('Le geste aurait du etre refuse pour la raison '.$raisonAttendue.'.');
    }

    private function versionValidee(): ScenarioManifestVersion
    {
        $json = file_get_contents(base_path('tests/Fixtures/ScenarioManifest/amt-formation-ia.json'));
        $resultat = app(ScenarioManifestValidator::class)->validate($json);

        $this->assertTrue($resultat->isValid(), 'La fixture AMT doit etre valide.');

        $version = new ScenarioManifestVersion([
            'scenario_key' => 'amt-formation-ia',
            'name' => 'AMT — Formation IA',
            'version' => '1.0.0',
            'usage' => ScenarioManifestVersion::USAGE_DOGFOODING,
            'origin' => ScenarioManifestVersion::ORIGIN_IMPORT,
            'json_source' => $json,
            'created_by' => $this->superAdmin->id,
        ]);

        $version->forceFill([
            'state' => ScenarioManifestVersion::STATE_VALID,
            'digest' => $resultat->digest(),
            'validation_summary' => $resultat->toArray(),
        ])->save();

        return $version;
    }

    private function versionValidee2(string $cle): ScenarioManifestVersion
    {
        $json = file_get_contents(base_path('tests/Fixtures/ScenarioManifest/amt-formation-ia.json'));
        $resultat = app(ScenarioManifestValidator::class)->validate($json);

        $version = new ScenarioManifestVersion([
            'scenario_key' => $cle,
            'name' => 'Jumelle '.$cle,
            'version' => '1.0.0',
            'usage' => ScenarioManifestVersion::USAGE_QA,
            'origin' => ScenarioManifestVersion::ORIGIN_IMPORT,
            'json_source' => $json,
            'created_by' => $this->superAdmin->id,
        ]);

        $version->forceFill([
            'state' => ScenarioManifestVersion::STATE_VALID,
            'digest' => $resultat->digest(),
            'validation_summary' => $resultat->toArray(),
        ])->save();

        return $version;
    }

    private function versionApprouvee(): ScenarioManifestVersion
    {
        $version = $this->versionValidee();
        $this->service()->approve($version, $this->superAdmin);

        return $version->fresh();
    }
}
