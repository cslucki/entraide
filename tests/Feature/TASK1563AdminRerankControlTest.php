<?php

namespace Tests\Feature;

use App\Models\AiConfig;
use App\Models\AiCreditSettingChange;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiRerankSettings;
use App\Services\Ai\AiUserCreditSettings;
use App\Services\Dossiers\DossierRerankGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TASK-1563 — le rerank se pilote depuis l'ADMINISTRATION.
 *
 * Deux interrupteurs remplacent deux variables d'environnement, et une trace
 * signee dit qui a agi. Ce que ces tests protegent avant tout : qu'AUCUNE
 * Organization ne s'active toute seule, et qu'un reglage ne puisse pas changer
 * sans laisser de nom.
 */
class TASK1563AdminRerankControlTest extends TestCase
{
    use RefreshDatabase;

    // ───────────────────────────────── A. migrations et defaut ferme

    /** REQ 1 — les deux migrations posent des defauts FERMES. */
    public function test_the_migrations_close_by_default(): void
    {
        $this->assertTrue(Schema::hasColumn('organization_ai_settings', 'rerank_enabled'));
        $this->assertTrue(Schema::hasColumn('ai_credit_setting_changes', 'setting_kind'));

        $setting = OrganizationAiSetting::factory()->create();

        $this->assertFalse((bool) $setting->fresh()->rerank_enabled, 'Le defaut de la colonne doit fermer.');
    }

    /**
     * REQ 2 — une Organization qui existait AVANT la migration n'est pas
     * activee par elle.
     *
     * C'est la garantie qui compte au deploiement : la migration ajoute une
     * colonne, elle n'ecrit AUCUNE donnee. Une activation doit etre un geste,
     * jamais un heritage.
     */
    public function test_no_pre_existing_organization_is_activated_by_the_migration(): void
    {
        // Une ligne ecrite sans jamais mentionner le drapeau — exactement ce
        // qu'une ligne d'avant la migration devient apres elle.
        $setting = OrganizationAiSetting::factory()->create();

        $this->assertFalse(
            app(AiRerankSettings::class)->organizationEnabled((string) $setting->organization_id),
            'Aucune Organization existante ne doit se retrouver activee.',
        );
    }

    /** REQ 3 — une Organization sans AUCUN reglage IA est fermee. */
    public function test_an_organization_without_ai_settings_is_closed(): void
    {
        $organization = Organization::factory()->create();

        config(['ai.knowledge.rerank.enabled' => true]);
        AiConfig::set(AiRerankSettings::KEY_PLATFORM_ENABLED, '1');

        $this->assertFalse(app(DossierRerankGate::class)->isEnabledFor($organization->id));
    }

    // ───────────────────────────────── B. la table de verite

    /**
     * REQ 4 a 7 — les deux verrous, en serie.
     *
     * C'est LE contrat de cette TASK, et il se lit en quatre lignes.
     */
    public function test_the_two_locks_are_in_series(): void
    {
        $organization = Organization::factory()->create();
        $setting = OrganizationAiSetting::factory()->create(['organization_id' => $organization->id]);
        $gate = app(DossierRerankGate::class);

        foreach ([
            ['plateforme' => false, 'org' => false, 'attendu' => false],
            ['plateforme' => false, 'org' => true, 'attendu' => false],
            ['plateforme' => true, 'org' => false, 'attendu' => false],
            ['plateforme' => true, 'org' => true, 'attendu' => true],
        ] as $cas) {
            AiConfig::set(AiRerankSettings::KEY_PLATFORM_ENABLED, $cas['plateforme'] ? '1' : '0');
            $setting->rerank_enabled = $cas['org'];
            $setting->save();

            $this->assertSame(
                $cas['attendu'],
                $gate->isEnabledFor($organization->id),
                sprintf('plateforme=%s org=%s', var_export($cas['plateforme'], true), var_export($cas['org'], true)),
            );
        }
    }

    // ───────────────────────────────── C. base contre environnement

    /**
     * REQ 8 a 11 — la BASE fait autorite, l'environnement n'AMORCE que.
     *
     * L'environnement repond tant que personne n'a tranche ; des qu'un
     * administrateur touche l'interrupteur, c'est lui qui parle.
     */
    public function test_the_database_wins_over_the_environment(): void
    {
        $organization = Organization::factory()->create();
        OrganizationAiSetting::factory()->create(['organization_id' => $organization->id, 'rerank_enabled' => true]);
        $gate = app(DossierRerankGate::class);

        // REQ 8 — rien en base, env ferme : ferme.
        config(['ai.knowledge.rerank.enabled' => false]);
        $this->assertFalse($gate->isEnabledFor($organization->id));

        // REQ 9 — rien en base, env ouvert : l'environnement amorce.
        config(['ai.knowledge.rerank.enabled' => true]);
        $this->assertTrue($gate->isEnabledFor($organization->id));

        // REQ 10 — la base dit NON, l'environnement dit oui : la base gagne.
        AiConfig::set(AiRerankSettings::KEY_PLATFORM_ENABLED, '0');
        $this->assertFalse($gate->isEnabledFor($organization->id), 'La base doit primer sur l\'environnement.');

        // REQ 11 — la base dit OUI, l'environnement dit non : la base gagne.
        config(['ai.knowledge.rerank.enabled' => false]);
        AiConfig::set(AiRerankSettings::KEY_PLATFORM_ENABLED, '1');
        $this->assertTrue($gate->isEnabledFor($organization->id), 'La base doit primer sur l\'environnement.');
    }

    /**
     * REQ 12 — une valeur illisible en base FERME, elle n'ouvre jamais.
     *
     * `ai_configs.value` est une colonne `text`. Un `(bool)` naif y serait un
     * piege : `(bool) 'false'` vaut TRUE, et `'oui'` aussi. La lecture passe
     * donc par une liste blanche a comparaison stricte.
     */
    public function test_an_unreadable_stored_value_fails_closed(): void
    {
        $organization = Organization::factory()->create();
        OrganizationAiSetting::factory()->create(['organization_id' => $organization->id, 'rerank_enabled' => true]);

        // L'environnement dit OUI : si la lecture de la base etait laxiste, ces
        // valeurs ouvriraient. Elles doivent toutes fermer.
        config(['ai.knowledge.rerank.enabled' => true]);
        $gate = app(DossierRerankGate::class);

        foreach (['false', '0', '', 'non', 'oui', 'peut-etre', '2', 'null'] as $illisible) {
            AiConfig::set(AiRerankSettings::KEY_PLATFORM_ENABLED, $illisible);

            $this->assertFalse(
                $gate->isEnabledFor($organization->id),
                "La valeur « {$illisible} » ne doit pas ouvrir la porte.",
            );
        }

        // Et la seule forme acceptee ouvre bien.
        AiConfig::set(AiRerankSettings::KEY_PLATFORM_ENABLED, '1');
        $this->assertTrue($gate->isEnabledFor($organization->id));
    }

    // ───────────────────────────────── D. les deux ecrans

    /** REQ 13 — l'administrateur plateforme bascule l'interrupteur general. */
    public function test_a_platform_admin_can_switch_the_master_flag(): void
    {
        $admin = $this->superAdmin();
        $settings = app(AiRerankSettings::class);

        config(['ai.knowledge.rerank.enabled' => false]);
        $this->assertFalse($settings->platformEnabled());

        $this->actingAs($admin)->post(route('admin.ai-config.update'), [
            'rerank_enabled' => '1',
        ])->assertRedirect();

        $this->assertTrue($settings->platformEnabled(), 'Cocher la case doit allumer la plateforme.');

        // Et decocher l'eteint : une case absente du formulaire vaut « non ».
        $this->actingAs($admin)->post(route('admin.ai-config.update'), [])->assertRedirect();

        $this->assertFalse($settings->platformEnabled(), 'Decocher la case doit eteindre la plateforme.');
    }

    /** REQ 14 — l'administrateur bascule l'autorisation d'une Organization. */
    public function test_a_platform_admin_can_switch_one_organization(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        OrganizationAiSetting::factory()->create(['organization_id' => $organization->id]);
        $settings = app(AiRerankSettings::class);

        $this->assertFalse($settings->organizationEnabled((string) $organization->id));

        $this->actingAs($admin)
            ->put(route('admin.organizations.update', $organization), $this->organizationPayload($organization, ['rerank_enabled' => '1']))
            ->assertRedirect();

        $this->assertTrue($settings->organizationEnabled((string) $organization->id));

        $this->actingAs($admin)
            ->put(route('admin.organizations.update', $organization), $this->organizationPayload($organization))
            ->assertRedirect();

        $this->assertFalse($settings->organizationEnabled((string) $organization->id));
    }

    /**
     * REQ 15 — autoriser une Organization n'en touche aucune autre, et ne
     * fabrique aucune configuration IA.
     */
    public function test_authorizing_stays_scoped_and_never_fabricates_a_configuration(): void
    {
        $avecIa = Organization::factory()->create();
        OrganizationAiSetting::factory()->create(['organization_id' => $avecIa->id]);
        $sansIa = Organization::factory()->create();

        $settings = app(AiRerankSettings::class);

        $this->assertTrue($settings->canBeEnabledFor($avecIa));
        $this->assertFalse($settings->canBeEnabledFor($sansIa), 'Sans configuration IA, il n\'y a rien a autoriser.');

        $settings->updateOrganization($avecIa, true, null);

        // La voisine sans IA n'a RIEN gagne — surtout pas une configuration
        // fabriquee pour loger un drapeau.
        $this->assertDatabaseMissing('organization_ai_settings', ['organization_id' => $sansIa->id]);
        $this->assertFalse($settings->organizationEnabled((string) $sansIa->id));

        // Et la tentative sur une Organization sans IA n'ecrit ni ligne, ni trace.
        $this->assertNull($settings->updateOrganization($sansIa, true, null));
        $this->assertDatabaseMissing('organization_ai_settings', ['organization_id' => $sansIa->id]);
    }

    /**
     * REQ 16 — aucune cle d'API n'est selectionnee ni rendue par l'ecran.
     *
     * La doctrine du depot est explicite sur cet ecran : « jamais la cle —
     * colonne exclue de la selection ». Ce test la mesure sur le RENDU.
     */
    public function test_the_screen_never_exposes_an_api_key(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        OrganizationAiSetting::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'openrouter',
            'api_key' => 'sk-secret-task1563',
            'rerank_enabled' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.organizations.edit', $organization))
            ->assertOk()
            ->assertDontSee('sk-secret-task1563');
    }

    /**
     * REQ 16 bis — une requete FORGEE n'ouvre pas ce que l'ecran refuse.
     *
     * La case desactivee ne protege rien : elle informe. Ce qui tient, c'est le
     * refus cote service — et c'est le chemin HTTP complet qu'il faut mesurer,
     * pas seulement le service appele a la main.
     */
    public function test_a_forged_request_cannot_authorize_an_organization_without_ai_config(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();

        AiConfig::set(AiRerankSettings::KEY_PLATFORM_ENABLED, '1');

        // Le champ est poste alors que l'ecran l'avait rendu desactive.
        $this->actingAs($admin)
            ->put(route('admin.organizations.update', $organization), $this->organizationPayload($organization, ['rerank_enabled' => '1']))
            ->assertRedirect();

        // Aucune configuration IA fabriquee, aucune autorisation accordee,
        // aucune trace mensongere ecrite.
        $this->assertDatabaseMissing('organization_ai_settings', ['organization_id' => $organization->id]);
        $this->assertFalse(app(DossierRerankGate::class)->isEnabledFor($organization->id));
        $this->assertNull(app(AiRerankSettings::class)->lastChange($organization));
    }

    // ───────────────────────────────── E. la trace

    /** REQ 17 — un changement plateforme est trace, dans les deux sens. */
    public function test_a_platform_change_is_traced_both_ways(): void
    {
        $admin = $this->superAdmin();
        $settings = app(AiRerankSettings::class);
        config(['ai.knowledge.rerank.enabled' => false]);

        $allume = $settings->updatePlatform(true, $admin);
        $this->assertNotNull($allume);
        $this->assertSame(AiCreditSettingChange::KIND_RERANK, $allume->setting_kind);
        $this->assertSame(AiCreditSettingChange::SCOPE_PLATFORM, $allume->scope);
        $this->assertSame((string) $admin->id, (string) $allume->changed_by);
        $this->assertSame(['from' => false, 'to' => true], $allume->changes['rerank_enabled']);

        $eteint = $settings->updatePlatform(false, $admin);
        $this->assertNotNull($eteint);
        $this->assertSame(['from' => true, 'to' => false], $eteint->changes['rerank_enabled']);

        // Enregistrer sans rien changer n'ecrit pas une ligne pour le dire.
        $this->assertNull($settings->updatePlatform(false, $admin));
    }

    /** REQ 18 — un changement d'Organization est trace, dans les deux sens. */
    public function test_an_organization_change_is_traced_both_ways(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        OrganizationAiSetting::factory()->create(['organization_id' => $organization->id]);
        $settings = app(AiRerankSettings::class);

        $allume = $settings->updateOrganization($organization, true, $admin);
        $this->assertNotNull($allume);
        $this->assertSame(AiCreditSettingChange::KIND_RERANK, $allume->setting_kind);
        $this->assertSame(AiCreditSettingChange::SCOPE_ORGANIZATION, $allume->scope);
        $this->assertSame((string) $organization->id, (string) $allume->organization_id);
        $this->assertSame(['from' => false, 'to' => true], $allume->changes['rerank_enabled']);

        $eteint = $settings->updateOrganization($organization, false, $admin);
        $this->assertSame(['from' => true, 'to' => false], $eteint->changes['rerank_enabled']);

        $this->assertNull($settings->updateOrganization($organization, false, $admin));
    }

    /**
     * REQ 19 + REQ 20 — les deux natures de reglage ne se melangent JAMAIS.
     *
     * Elles partagent une table. Sans discriminant, le dernier changement de
     * rerank remonterait sur l'ecran de monetisation presente comme un
     * changement de credit — un mensonge silencieux sur un ecran existant.
     */
    public function test_the_two_setting_kinds_never_contaminate_each_other(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        OrganizationAiSetting::factory()->create(['organization_id' => $organization->id]);

        $rerank = app(AiRerankSettings::class);
        $credit = app(AiUserCreditSettings::class);

        // Un changement de CREDIT, puis un changement de RERANK, dans cet ordre.
        $credit->updatePlatform(['free_enabled' => true, 'monthly_uses' => 42, 'alert_percent' => 80, 'offer_subscription' => true], $admin);
        $rerank->updatePlatform(true, $admin);

        $dernierCredit = $credit->lastChange(null);
        $dernierRerank = $rerank->lastChange(null);

        $this->assertNotNull($dernierCredit);
        $this->assertNotNull($dernierRerank);
        $this->assertSame(AiCreditSettingChange::KIND_CREDIT, $dernierCredit->setting_kind, 'Le dernier changement CREDIT ne doit jamais etre un rerank.');
        $this->assertSame(AiCreditSettingChange::KIND_RERANK, $dernierRerank->setting_kind, 'Le dernier changement RERANK ne doit jamais etre un credit.');
        $this->assertNotSame((string) $dernierCredit->id, (string) $dernierRerank->id);

        // Et par Organization, meme separation.
        $credit->updateOrganization($organization, OrganizationAiSetting::USER_CREDIT_MODE_UNLIMITED, null, $admin);
        $rerank->updateOrganization($organization, true, $admin);

        $this->assertSame(AiCreditSettingChange::KIND_CREDIT, $credit->lastChange($organization)->setting_kind);
        $this->assertSame(AiCreditSettingChange::KIND_RERANK, $rerank->lastChange($organization)->setting_kind);
    }

    /**
     * REQ 20 bis — l'HISTORIQUE de /admin/ai-monetization ne montre aucun
     * changement de rerank.
     *
     * Trouve par la revue SENSITIVE du SHA 26ab7bbc, et c'est le defaut que
     * `setting_kind` etait cense empecher. Il y avait TROIS lecteurs de cette
     * table, pas deux : les deux `lastChange()` filtraient, la requete
     * `history` du controleur de monetisation etait restee nue. Le formateur du
     * Blade rend n'importe quel champ de `changes` de facon generique, donc
     * « rerank_enabled : off -> on » s'affichait au milieu des quotas.
     *
     * Ce test mesure le RENDU, pas la requete : c'est ce que voit l'exploitant.
     */
    public function test_the_monetization_history_never_shows_a_rerank_change(): void
    {
        $admin = $this->superAdmin();
        $organization = Organization::factory()->create();
        OrganizationAiSetting::factory()->create(['organization_id' => $organization->id]);

        // Un changement de CREDIT — il DOIT rester visible...
        app(AiUserCreditSettings::class)->updatePlatform(
            ['free_enabled' => true, 'monthly_uses' => 77, 'alert_percent' => 80, 'offer_subscription' => true],
            $admin,
        );

        // ...et un changement de RERANK, qui ne doit PAS apparaitre.
        app(AiRerankSettings::class)->updatePlatform(true, $admin);
        app(AiRerankSettings::class)->updateOrganization($organization, true, $admin);

        $reponse = $this->actingAs($admin)->get(route('admin.ai-monetization'))->assertOk();

        // La garde qui compte.
        $reponse->assertDontSee('rerank_enabled');

        // Et la contre-garde : l'historique n'est pas simplement VIDE. Sans
        // elle, un filtre inverse — ou une colonne mal nommee — viderait
        // silencieusement le cote CREDIT et ce test resterait vert.
        //
        // Elle porte sur le MARQUEUR DE LIGNE de l'historique, pas sur une
        // chaine libre : une premiere version assertait « monthly_uses », qui
        // est aussi le `name` d'un champ du formulaire plateforme rendu juste
        // au-dessus (index.blade.php:87). Elle passait donc quel que soit
        // l'etat de l'historique — une contre-garde qui ne gardait rien.
        // Trouve par la re-review du SHA f7fa8295.
        $reponse->assertSee('data-ai-monetization-history-row', false);
    }

    /**
     * REQ 21 — les lignes historiques, ecrites avant le discriminant, sont
     * qualifiees CREDIT.
     *
     * Elles le sont toutes par construction : c'etait le seul appelant qui
     * existait. Le defaut de colonne dit la verite sur le passe.
     */
    public function test_historic_rows_without_an_explicit_kind_are_credit(): void
    {
        $change = AiCreditSettingChange::create([
            'scope' => AiCreditSettingChange::SCOPE_PLATFORM,
            'changes' => ['monthly_uses' => ['from' => 10, 'to' => 20]],
            'changed_by' => null,
        ]);

        $this->assertSame(AiCreditSettingChange::KIND_CREDIT, $change->fresh()->setting_kind);

        // Et elle remonte bien cote credit, jamais cote rerank.
        $this->assertNotNull(app(AiUserCreditSettings::class)->lastChange(null));
        $this->assertNull(app(AiRerankSettings::class)->lastChange(null));
    }

    // ───────────────────────────────── F. l'ancien contrat est mort

    /**
     * REQ 22 — l'allowlist d'environnement de TASK-1562 n'a PLUS d'effet.
     *
     * La laisser vivre a cote du nouvel interrupteur aurait casse la table de
     * verite : une Organization listee dans l'environnement mais eteinte a
     * l'ecran aurait rerankee quand meme.
     */
    public function test_the_old_environment_allowlist_has_no_effect_anymore(): void
    {
        $organization = Organization::factory()->create(['slug' => 'ancien-pilote']);
        OrganizationAiSetting::factory()->create(['organization_id' => $organization->id]);

        config([
            'ai.knowledge.rerank.enabled' => true,
            // Les deux cles de l'ancien contrat, posees exactement comme avant.
            'ai.knowledge.rerank.organization_ids' => [$organization->id],
            'ai.knowledge.rerank.organization_slugs' => ['ancien-pilote'],
        ]);

        $this->assertFalse(
            app(DossierRerankGate::class)->isEnabledFor($organization->id),
            'L\'ancienne allowlist ne doit plus ouvrir quoi que ce soit.',
        );

        // Et les deux cles ont disparu de la configuration du produit : plus
        // aucun code ne peut les relire par megarde.
        $this->assertFileDoesNotContainAllowlist();
    }

    // ───────────────────────────────── G. le cout des deux ecrans

    /**
     * REQ 23 — les lectures ajoutees par TASK-1563 ne grossissent PAS avec le
     * nombre d'Organizations.
     *
     * `/admin/ai-config` boucle deja sur toutes les Organizations pour
     * construire les configurations de blog — une N+1 preexistante, hors
     * perimetre de cette TASK. Ce test ne la mesure donc pas : il compte
     * UNIQUEMENT les requetes des trois tables du rerank, et exige qu'elles
     * soient les MEMES avec 2 Organizations qu'avec 12.
     */
    public function test_the_added_reads_do_not_grow_with_the_number_of_organizations(): void
    {
        $admin = $this->superAdmin();

        $avecDeux = $this->countRerankQueriesOnAiConfig($admin, 2);
        $avecDouze = $this->countRerankQueriesOnAiConfig($admin, 12);

        $this->assertSame(
            $avecDeux,
            $avecDouze,
            "Les lectures du rerank doivent etre constantes ({$avecDeux} avec 2 Organizations, {$avecDouze} avec 12).",
        );

        // Et elles restent en tres petit nombre. Les QUATRE, nommement :
        // `default_provider` et `clarification_enabled` etaient deja la ;
        // TASK-1563 ajoute le drapeau `rerank_enabled` et sa derniere trace.
        // Si ce chiffre monte un jour, c'est qu'une lecture est entree dans
        // une boucle.
        $this->assertLessThanOrEqual(4, $avecDeux, 'La page plateforme ne doit pas multiplier les lectures du rerank.');
    }

    // ───────────────────────────────────────────────────── harnais

    private function superAdmin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    /**
     * Le formulaire d'Organization valide bien d'autres champs : ce corps
     * minimal les fournit, pour que le test mesure le rerank et rien d'autre.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function organizationPayload(Organization $organization, array $extra = []): array
    {
        return array_merge([
            'name' => $organization->name,
            'slug' => $organization->slug,
            'welcome_points' => 0,
        ], $extra);
    }

    /**
     * Rend `/admin/ai-config` avec `$organizations` Organizations et compte les
     * requetes qui touchent les tables du rerank — les autres ne regardent pas
     * cette TASK.
     */
    private function countRerankQueriesOnAiConfig(User $admin, int $organizations): int
    {
        Organization::factory()->count($organizations)->create();

        // Le journal de requetes, et pas `DB::listen()` : un listener ne se
        // desinscrit pas, donc deux mesures successives se contamineraient.
        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        $this->actingAs($admin)->get(route('admin.ai-config'))->assertOk();

        $requetes = DB::connection()->getQueryLog();
        DB::connection()->disableQueryLog();
        DB::connection()->flushQueryLog();

        // Les noms de tables ENTRE GUILLEMETS, jamais en sous-chaine nue :
        // `blog_ai_configs` contient `ai_configs`, et la boucle preexistante
        // qui la remplit organisation par organisation se serait comptee ici
        // comme une N+1 de cette TASK. Elle ne l'est pas.
        return count(array_filter(
            $requetes,
            static fn (array $requete): bool => str_contains($requete['query'], '"ai_configs"')
                || str_contains($requete['query'], '"ai_credit_setting_changes"')
                || str_contains($requete['query'], '"organization_ai_settings"'),
        ));
    }

    private function assertFileDoesNotContainAllowlist(): void
    {
        $config = (string) file_get_contents(config_path('ai.php'));

        $this->assertStringNotContainsString('AI_KNOWLEDGE_RERANK_ORGANIZATION_IDS', $config);
        $this->assertStringNotContainsString('AI_KNOWLEDGE_RERANK_ORGANIZATION_SLUGS', $config);
    }
}
