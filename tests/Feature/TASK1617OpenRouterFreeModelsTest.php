<?php

namespace Tests\Feature;

use App\Models\LoopPluginAiModel;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\LoopPluginAiModels;
use App\Services\Ai\LoopPluginModelGuard;
use App\Services\Ai\OpenRouterModelCatalog;
use App\Services\Ai\OrganizationAiEconomicUsage;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiPricingCatalog;
use App\Support\Ai\AiUsage;
use App\Support\Ai\Pricing\DynamicPricingSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1617 / SLICE C — les modeles gratuits d'OpenRouter, la capability
 * `loop_multi_ai`, et l'economie.
 *
 * Quatre choses sont mesurees, et la premiere commande les trois autres :
 *
 *  1. **la PREUVE de gratuite** se lit dans les tarifs publies, jamais dans
 *     le nom. Un `:free` ne prouve rien, un `request` non nul suffit a
 *     refuser, un champ ambigu refuse aussi ;
 *  2. **la garde FERMEE PAR DEFAUT** : preuve perimee, modele disparu,
 *     releve impossible -> aucune generation, et aucun repli ;
 *  3. **une seule autorite economique** : `loop_multi_ai` lit le ledger
 *     canonique, un modele prouve gratuit vaut un cout CONNU de 0, un modele
 *     sans preuve vaut INCONNU ;
 *  4. **le chiffrage ne sort jamais sur le reseau**, et ne mute jamais la
 *     configuration.
 *
 * L'API OpenRouter est TOUJOURS doublee : aucun test ne depend du reseau.
 */
class TASK1617OpenRouterFreeModelsTest extends TestCase
{
    use RefreshDatabase;

    private const PLUGIN = 'multi_ai_assistants';

    private User $superAdmin;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget(OpenRouterModelCatalog::CACHE_KEY);

        $organization = Organization::factory()->create(['is_active' => true, 'is_default' => true]);

        $this->superAdmin = User::factory()->create([
            'is_admin' => true, 'organization_id' => $organization->id, 'preferred_locale' => 'fr',
        ]);
        $this->membre = User::factory()->create([
            'is_admin' => false, 'organization_id' => $organization->id, 'preferred_locale' => 'fr',
        ]);
    }

    // ── 1. LA PREUVE DE GRATUITE ────────────────────────────────────────────

    public function test_le_catalogue_ne_retient_que_les_modeles_prouves_gratuits(): void
    {
        $this->fakeCatalogue([
            $this->model('vendor/gratuit-a:free', ['prompt' => '0', 'completion' => '0', 'request' => '0']),
            $this->model('vendor/gratuit-b', ['prompt' => '0', 'completion' => '0']),
            $this->model('vendor/payant', ['prompt' => '0.0000015', 'completion' => '0.000006']),
        ]);

        $libres = app(OpenRouterModelCatalog::class)->verifiedFreeModels();

        $this->assertSame(['vendor/gratuit-a:free', 'vendor/gratuit-b'], array_keys($libres));
    }

    /** Le point central : le SUFFIXE ne prouve rien. */
    public function test_un_suffixe_free_sur_un_modele_payant_ne_le_rend_pas_gratuit(): void
    {
        $this->fakeCatalogue([
            $this->model('vendor/menteur:free', ['prompt' => '0.000002', 'completion' => '0']),
        ]);

        $this->assertSame([], app(OpenRouterModelCatalog::class)->verifiedFreeModels(),
            'un `:free` qui facture le prompt n\'est pas gratuit — le nom n\'a aucune autorite');
    }

    /** Addendum §3 : `request` > 0 suffit a refuser. */
    public function test_un_request_non_nul_refuse_le_modele(): void
    {
        $this->fakeCatalogue([
            $this->model('vendor/requete-payante', ['prompt' => '0', 'completion' => '0', 'request' => '0.0001']),
        ]);

        $this->assertSame([], app(OpenRouterModelCatalog::class)->verifiedFreeModels(),
            'prompt et completion a 0 ne suffisent pas si CHAQUE REQUETE est facturee');
    }

    public function test_un_poste_de_tarif_ambigu_ou_manquant_refuse_le_modele(): void
    {
        $catalogue = app(OpenRouterModelCatalog::class);

        // Non numerique.
        $this->assertFalse($catalogue->isVerifiedFree(
            $this->model('x/y', ['prompt' => 'gratuit', 'completion' => '0'])));

        // `completion` absent : on ne sait pas ce que coute la generation.
        $this->assertFalse($catalogue->isVerifiedFree(
            $this->model('x/y', ['prompt' => '0'])));

        // Aucun tarif publie.
        $this->assertFalse($catalogue->isVerifiedFree($this->model('x/y', [])));

        // Un poste INCONNU non nul : l'inconnu refuse, il n'est pas tolere.
        $this->assertFalse($catalogue->isVerifiedFree(
            $this->model('x/y', ['prompt' => '0', 'completion' => '0', 'poste_inedit' => '0.5'])));
    }

    /**
     * CDC §3 : `openrouter/free` est GRATUIT et pourtant REFUSE.
     *
     * Ce routeur choisit lui-meme parmi les modeles gratuits a chaque appel :
     * l'affecter a un assistant ferait perdre le controle de ce qui repond, et
     * la trace du ledger nommerait le routeur plutot que le modele employe.
     * Le produit exige des slugs DETERMINISTES — ce n'est pas une question de
     * tarif.
     *
     * Trouve par la RECETTE, pas par un test : le routeur apparaissait dans le
     * selecteur de l'ecran.
     */
    public function test_le_routeur_openrouter_free_est_refuse_bien_qu_il_soit_gratuit(): void
    {
        $this->fakeCatalogue([
            $this->model('openrouter/free', ['prompt' => '0', 'completion' => '0']),
            $this->model('openrouter/auto', ['prompt' => '0', 'completion' => '0']),
            $this->model('vendor/nomme', ['prompt' => '0', 'completion' => '0']),
        ]);

        $catalogue = app(OpenRouterModelCatalog::class);
        $libres = $catalogue->verifiedFreeModels();

        // Il est bien PROUVE gratuit...
        $this->assertTrue($catalogue->isVerifiedFree(
            $this->model('openrouter/free', ['prompt' => '0', 'completion' => '0'])));

        // ... et pourtant il n'est pas selectionnable.
        $this->assertArrayNotHasKey('openrouter/free', $libres);
        $this->assertArrayNotHasKey('openrouter/auto', $libres);
        $this->assertArrayHasKey('vendor/nomme', $libres);

        // Et il ne s'enregistre pas davantage par un POST forge.
        $this->expectException(\InvalidArgumentException::class);
        app(LoopPluginAiModels::class)->assign('aperio', 'openrouter/free', $this->superAdmin);
    }

    public function test_un_modele_non_textuel_est_refuse_meme_a_zero(): void
    {
        $catalogue = app(OpenRouterModelCatalog::class);

        $image = $this->model('x/image', ['prompt' => '0', 'completion' => '0']);
        $image['architecture']['output_modalities'] = ['image'];
        $this->assertFalse($catalogue->isVerifiedFree($image));

        $mixte = $this->model('x/mixte', ['prompt' => '0', 'completion' => '0']);
        $mixte['architecture']['output_modalities'] = ['text', 'image'];
        $this->assertFalse($catalogue->isVerifiedFree($mixte),
            'une sortie image peut etre facturee a part : la sortie doit etre TEXTE et rien d\'autre');
    }

    public function test_un_releve_en_erreur_ne_declare_aucun_modele_gratuit(): void
    {
        Http::fake(['*/models' => Http::response(['error' => 'boom'], 500)]);

        $catalogue = app(OpenRouterModelCatalog::class);

        $this->assertSame([], $catalogue->verifiedFreeModels());
        $this->assertFalse($catalogue->catalogue()['ok']);
    }

    // ── 2. LA SURFACE SUPERADMIN ────────────────────────────────────────────

    public function test_le_superadmin_accede_a_la_configuration_des_modeles(): void
    {
        $this->fakeCatalogue([$this->model('vendor/a', ['prompt' => '0', 'completion' => '0'])]);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-plugins'))
            ->assertOk()
            ->assertSee('data-section="plugin-models"', false)
            ->assertSee('data-assistant-model="aperio"', false)
            ->assertSee('data-assistant-model="traverse"', false)
            ->assertSee('data-assistant-model="limen"', false);
    }

    public function test_un_non_superadmin_est_refuse_sur_la_configuration_des_modeles(): void
    {
        $this->fakeCatalogue([$this->model('vendor/a', ['prompt' => '0', 'completion' => '0'])]);

        $this->actingAs($this->membre)
            ->put(route('admin.loop-plugins.models.update', self::PLUGIN), [
                'assistant_key' => 'aperio', 'model_slug' => 'vendor/a',
            ])->assertForbidden();

        $this->actingAs($this->membre)
            ->post(route('admin.loop-plugins.models.refresh', self::PLUGIN))
            ->assertForbidden();

        $this->assertDatabaseCount('loop_plugin_ai_models', 0);
    }

    /** Les trois assistants, trois modeles differents. */
    public function test_les_trois_assistants_recoivent_trois_modeles_differents(): void
    {
        $this->fakeCatalogue([
            $this->model('vendor/a', ['prompt' => '0', 'completion' => '0']),
            $this->model('vendor/b', ['prompt' => '0', 'completion' => '0']),
            $this->model('vendor/c', ['prompt' => '0', 'completion' => '0']),
        ]);

        foreach (['aperio' => 'vendor/a', 'traverse' => 'vendor/b', 'limen' => 'vendor/c'] as $key => $slug) {
            $this->actingAs($this->superAdmin)
                ->put(route('admin.loop-plugins.models.update', self::PLUGIN), [
                    'assistant_key' => $key, 'model_slug' => $slug,
                ])->assertRedirect();
        }

        $this->assertDatabaseHas('loop_plugin_ai_models', ['assistant_key' => 'aperio', 'model_slug' => 'vendor/a']);
        $this->assertDatabaseHas('loop_plugin_ai_models', ['assistant_key' => 'traverse', 'model_slug' => 'vendor/b']);
        $this->assertDatabaseHas('loop_plugin_ai_models', ['assistant_key' => 'limen', 'model_slug' => 'vendor/c']);
        $this->assertDatabaseCount('loop_plugin_ai_models', 3);
    }

    /** Ils peuvent aussi partager le meme. */
    public function test_les_trois_assistants_peuvent_partager_le_meme_modele(): void
    {
        $this->fakeCatalogue([$this->model('vendor/commun', ['prompt' => '0', 'completion' => '0'])]);

        $service = app(LoopPluginAiModels::class);

        foreach (['aperio', 'traverse', 'limen'] as $key) {
            $service->assign($key, 'vendor/commun', $this->superAdmin);
        }

        $this->assertSame(3, LoopPluginAiModel::query()->where('model_slug', 'vendor/commun')->count());
    }

    public function test_un_modele_payant_est_refuse_a_l_enregistrement(): void
    {
        $this->fakeCatalogue([$this->model('vendor/payant', ['prompt' => '0.001', 'completion' => '0.002'])]);

        $this->actingAs($this->superAdmin)
            ->put(route('admin.loop-plugins.models.update', self::PLUGIN), [
                'assistant_key' => 'aperio', 'model_slug' => 'vendor/payant',
            ])->assertRedirect();

        $this->assertDatabaseCount('loop_plugin_ai_models', 0);
    }

    public function test_un_modele_inconnu_est_refuse_a_l_enregistrement(): void
    {
        $this->fakeCatalogue([$this->model('vendor/a', ['prompt' => '0', 'completion' => '0'])]);

        $this->expectException(\InvalidArgumentException::class);
        app(LoopPluginAiModels::class)->assign('aperio', 'vendor/inexistant', $this->superAdmin);
    }

    public function test_la_modification_est_persistee_et_relue(): void
    {
        $this->fakeCatalogue([
            $this->model('vendor/a', ['prompt' => '0', 'completion' => '0']),
            $this->model('vendor/b', ['prompt' => '0', 'completion' => '0']),
        ]);

        $service = app(LoopPluginAiModels::class);
        $service->assign('aperio', 'vendor/a', $this->superAdmin);
        $service->assign('aperio', 'vendor/b', $this->superAdmin);

        $this->assertDatabaseCount('loop_plugin_ai_models', 1);

        $aperio = collect($service->describe())->firstWhere('assistant_key', 'aperio');
        $this->assertSame('vendor/b', $aperio['model_slug']);
        $this->assertTrue($aperio['eligible']);
    }

    // ── 3. FAIL CLOSED ──────────────────────────────────────────────────────

    public function test_un_assistant_sans_modele_ne_genere_pas(): void
    {
        $this->fakeCatalogue([$this->model('vendor/a', ['prompt' => '0', 'completion' => '0'])]);

        $raison = null;
        $this->assertNull(app(LoopPluginModelGuard::class)->eligibleSlug('aperio', $raison));
        $this->assertSame(LoopPluginModelGuard::REASON_NOT_CONFIGURED, $raison);
    }

    public function test_un_modele_devenu_payant_ferme_la_garde(): void
    {
        $this->fakeCatalogue([$this->model('vendor/a', ['prompt' => '0', 'completion' => '0'])]);
        app(LoopPluginAiModels::class)->assign('aperio', 'vendor/a', $this->superAdmin);

        // Le modele devient payant, et la preuve vieillit.
        $this->perimerLaPreuve('aperio');
        $this->fakeCatalogue([$this->model('vendor/a', ['prompt' => '0.002', 'completion' => '0.004'])]);

        $raison = null;
        $this->assertNull(app(LoopPluginModelGuard::class)->eligibleSlug('aperio', $raison));
        $this->assertSame(LoopPluginModelGuard::REASON_UNAVAILABLE, $raison);
    }

    public function test_un_modele_disparu_ferme_la_garde(): void
    {
        $this->fakeCatalogue([$this->model('vendor/a', ['prompt' => '0', 'completion' => '0'])]);
        app(LoopPluginAiModels::class)->assign('aperio', 'vendor/a', $this->superAdmin);

        $this->perimerLaPreuve('aperio');
        $this->fakeCatalogue([$this->model('vendor/autre', ['prompt' => '0', 'completion' => '0'])]);

        $this->assertNull(app(LoopPluginModelGuard::class)->eligibleSlug('aperio'));
    }

    /**
     * REGRESSION — une preuve ECRITE A L'INSTANT doit etre relue comme fraiche.
     *
     * Trivial en apparence, et pourtant : la colonne etait declaree
     * `timestampTz`. PostgreSQL rendait alors la valeur dans le fuseau de la
     * SESSION (`+02:00` en CEST) pendant que `Carbon::now()` reste en UTC — la
     * preuve se lisait DEUX HEURES dans le passe, donc toujours perimee, et la
     * garde refusait tout pendant l'heure d'ete.
     *
     * SQLite ignore les fuseaux : ce test ne peut echouer qu'en PostgreSQL.
     * C'est la recette navigateur qui l'a trouve, pas la suite locale.
     */
    public function test_une_preuve_ecrite_a_l_instant_est_relue_comme_FRAICHE(): void
    {
        $this->fakeCatalogue([$this->model('vendor/a', ['prompt' => '0', 'completion' => '0'])]);

        $service = app(LoopPluginAiModels::class);
        $service->assign('aperio', 'vendor/a', $this->superAdmin);

        // Relecture DEPUIS LA BASE : c'est la que le fuseau se joue.
        $ligne = $service->lineFor('aperio');

        $this->assertNotNull($ligne?->verified_free_at);
        $this->assertLessThan(60, abs((int) $ligne->verified_free_at->diffInSeconds(Carbon::now())),
            'la preuve doit etre datee de MAINTENANT, pas decalee du fuseau de la session SQL');
        $this->assertTrue($service->proofIsFresh($ligne),
            'une preuve de quelques secondes ne peut pas etre perimee');

        $aperio = collect($service->describe())->firstWhere('assistant_key', 'aperio');
        $this->assertTrue($aperio['proof_fresh']);
        $this->assertTrue($aperio['eligible'], 'l\'ecran doit annoncer « Operationnel », pas « Preuve expiree »');
    }

    /** Addendum §4 : preuve fraiche = recevable. */
    public function test_une_preuve_fraiche_rend_l_assistant_eligible(): void
    {
        $this->fakeCatalogue([$this->model('vendor/a', ['prompt' => '0', 'completion' => '0'])]);
        app(LoopPluginAiModels::class)->assign('aperio', 'vendor/a', $this->superAdmin);

        $this->assertSame('vendor/a', app(LoopPluginModelGuard::class)->eligibleSlug('aperio'));
    }

    /** Addendum §4 : preuve expiree + releve impossible = refus. */
    public function test_une_preuve_expiree_sans_releve_possible_ferme_la_garde(): void
    {
        $this->fakeCatalogue([$this->model('vendor/a', ['prompt' => '0', 'completion' => '0'])]);
        app(LoopPluginAiModels::class)->assign('aperio', 'vendor/a', $this->superAdmin);

        $this->perimerLaPreuve('aperio');

        // Le releve echoue : OpenRouter injoignable.
        Cache::forget(OpenRouterModelCatalog::CACHE_KEY);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*/models' => Http::response(null, 503)]);

        $raison = null;
        $this->assertNull(app(LoopPluginModelGuard::class)->eligibleSlug('aperio', $raison));
        $this->assertSame(LoopPluginModelGuard::REASON_UNAVAILABLE, $raison);
    }

    /** Aucun repli : les voisins eligibles ne sauvent pas l'assistant ferme. */
    public function test_aucun_repli_vers_un_autre_modele_gratuit(): void
    {
        $this->fakeCatalogue([
            $this->model('vendor/a', ['prompt' => '0', 'completion' => '0']),
            $this->model('vendor/b', ['prompt' => '0', 'completion' => '0']),
        ]);

        $service = app(LoopPluginAiModels::class);
        $service->assign('aperio', 'vendor/a', $this->superAdmin);
        $service->assign('traverse', 'vendor/b', $this->superAdmin);

        // `vendor/a` disparait ; `vendor/b` reste gratuit.
        $this->perimerLaPreuve('aperio');
        $this->fakeCatalogue([$this->model('vendor/b', ['prompt' => '0', 'completion' => '0'])]);

        $garde = app(LoopPluginModelGuard::class);

        $this->assertNull($garde->eligibleSlug('aperio'), 'Aperio ne doit PAS basculer sur vendor/b');
        $this->assertSame('vendor/b', $garde->eligibleSlug('traverse'));
    }

    // ── 4. ECONOMIE ─────────────────────────────────────────────────────────

    public function test_loop_multi_ai_lit_l_autorite_canonique_du_ledger(): void
    {
        $this->assertArrayHasKey('loop_multi_ai', AiEconomicGuard::LEDGER_AUTHORITY_SINCE_BY_PROCESS,
            'sans cette ligne, loop_multi_ai serait lu dans ai_interactions — un registre ou il n\'ecrira jamais');
        $this->assertContains('loop_multi_ai', AiEconomicGuard::ledgerAuthorityProcesses());
    }

    /** Arbitrage produit : trace complete, mais AUCUN credit membre en V0. */
    public function test_loop_multi_ai_n_est_PAS_creditable(): void
    {
        $this->assertNotContains('loop_multi_ai', OrganizationAiEconomicUsage::CREDITABLE_PROCESSES,
            'les generations 3IA sont tracees mais ne consomment pas de credit membre pendant l\'experimentation');
    }

    public function test_un_modele_prouve_gratuit_vaut_un_cout_CONNU_de_zero(): void
    {
        $this->fakeCatalogue([$this->model('vendor/a', ['prompt' => '0', 'completion' => '0'])]);
        app(LoopPluginAiModels::class)->assign('aperio', 'vendor/a', $this->superAdmin);

        $cout = AiPricingCatalog::cost('openrouter', 'vendor/a', AiUsage::fromSdkTextTokens(100, 50));

        $this->assertTrue($cout->isKnown(), 'un modele prouve gratuit a un cout CONNU');
        $this->assertSame(0.0, $cout->costUsd);
    }

    /** Le point 16 du mandat : sans preuve, ce n'est pas 0 — c'est INCONNU. */
    public function test_un_modele_sans_preuve_n_est_PAS_a_cout_zero(): void
    {
        $cout = AiPricingCatalog::cost('openrouter', 'vendor/jamais-verifie', AiUsage::fromSdkTextTokens(100, 50));

        $this->assertFalse($cout->isKnown(),
            'un slug qu\'aucun assistant n\'utilise n\'a aucune preuve : affirmer 0 serait inventer un tarif');
    }

    public function test_une_preuve_expiree_ne_vaut_plus_un_cout_de_zero(): void
    {
        $this->fakeCatalogue([$this->model('vendor/a', ['prompt' => '0', 'completion' => '0'])]);
        app(LoopPluginAiModels::class)->assign('aperio', 'vendor/a', $this->superAdmin);

        $this->perimerLaPreuve('aperio');

        $cout = AiPricingCatalog::cost('openrouter', 'vendor/a', AiUsage::fromSdkTextTokens(100, 50));

        $this->assertFalse($cout->isKnown(), 'une preuve perimee ne vaut plus 0 : elle vaut INCONNU');
    }

    // ── 5. ADDENDUM : LE CHIFFRAGE NE SORT PAS SUR LE RESEAU ────────────────

    /**
     * Addendum §1 — l'invariant central : `cost()` est sur le chemin de la
     * COMPTABILISATION, et n'a aucune raison d'appeler OpenRouter.
     */
    public function test_le_chiffrage_ne_declenche_AUCUN_appel_reseau(): void
    {
        $this->fakeCatalogue([$this->model('vendor/a', ['prompt' => '0', 'completion' => '0'])]);
        app(LoopPluginAiModels::class)->assign('aperio', 'vendor/a', $this->superAdmin);

        // On repart d'une fabrique VIERGE : tout appel serait donc visible.
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake();

        AiPricingCatalog::cost('openrouter', 'vendor/a', AiUsage::fromSdkTextTokens(10, 10));
        AiPricingCatalog::cost('openrouter', 'vendor/inconnu', AiUsage::fromSdkTextTokens(10, 10));

        Http::assertNothingSent();
    }

    /** Addendum §3 — la source dynamique ne mute pas `config()`. */
    public function test_la_source_dynamique_ne_mute_pas_la_configuration(): void
    {
        $this->fakeCatalogue([$this->model('vendor/a', ['prompt' => '0', 'completion' => '0'])]);
        app(LoopPluginAiModels::class)->assign('aperio', 'vendor/a', $this->superAdmin);

        $avant = Config::get('ai_pricing.models.openrouter');

        AiPricingCatalog::cost('openrouter', 'vendor/a', AiUsage::fromSdkTextTokens(10, 10));

        $this->assertSame($avant, Config::get('ai_pricing.models.openrouter'),
            'le catalogue Laravel doit etre STRICTEMENT identique : aucune fusion, aucun Config::set');
        $this->assertArrayNotHasKey('vendor/a', Config::get('ai_pricing.models.openrouter') ?? []);
    }

    /**
     * Addendum §3 — deux appels successifs peuvent voir deux etats de
     * fraicheur differents. C'est la preuve que l'evaluation est PARESSEUSE :
     * une fusion au boot rendrait le second appel identique au premier.
     */
    public function test_deux_appels_voient_deux_etats_de_fraicheur_differents(): void
    {
        $this->fakeCatalogue([$this->model('vendor/a', ['prompt' => '0', 'completion' => '0'])]);
        app(LoopPluginAiModels::class)->assign('aperio', 'vendor/a', $this->superAdmin);

        $premier = AiPricingCatalog::cost('openrouter', 'vendor/a', AiUsage::fromSdkTextTokens(10, 10));
        $this->assertTrue($premier->isKnown());

        $this->perimerLaPreuve('aperio');

        $second = AiPricingCatalog::cost('openrouter', 'vendor/a', AiUsage::fromSdkTextTokens(10, 10));
        $this->assertFalse($second->isKnown(),
            'meme processus, meme slug : seul le temps a passe, et le verdict change');
    }

    /**
     * Addendum §2 — la garde est INDEPENDANTE de ce que le catalogue de prix
     * sait. Un slug tarife en configuration ne doit pas pouvoir generer si sa
     * preuve FREE est expiree.
     */
    public function test_une_entree_pricing_statique_ne_contourne_PAS_la_garde_free(): void
    {
        $this->fakeCatalogue([$this->model('vendor/tarife', ['prompt' => '0', 'completion' => '0'])]);
        app(LoopPluginAiModels::class)->assign('aperio', 'vendor/tarife', $this->superAdmin);

        // Le slug EXISTE au catalogue de prix statique, avec un vrai tarif.
        Config::set('ai_pricing.models.openrouter.vendor/tarife', [
            'input_per_1m' => 0.15, 'output_per_1m' => 0.60,
        ]);

        // Le tarif est donc CONNU...
        $cout = AiPricingCatalog::cost('openrouter', 'vendor/tarife', AiUsage::fromSdkTextTokens(1000, 1000));
        $this->assertTrue($cout->isKnown());
        $this->assertGreaterThan(0.0, (float) $cout->costUsd, 'l\'entree statique prime : ce n\'est plus 0');

        // ... et pourtant la garde REFUSE, parce que la preuve a expire.
        $this->perimerLaPreuve('aperio');
        Cache::forget(OpenRouterModelCatalog::CACHE_KEY);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*/models' => Http::response(null, 503)]);

        $raison = null;
        $this->assertNull(app(LoopPluginModelGuard::class)->eligibleSlug('aperio', $raison),
            'savoir COMBIEN ca coute n\'autorise pas a APPELER : ce sont deux questions distinctes');
        $this->assertSame(LoopPluginModelGuard::REASON_UNAVAILABLE, $raison);
    }

    // ── 6. ZERO GENERATION, ZERO SECRET ─────────────────────────────────────

    /** Point 17 du mandat : cette TASK ne genere rien. */
    public function test_aucune_generation_dans_cette_task(): void
    {
        $fichiers = [
            'app/Services/Ai/OpenRouterModelCatalog.php',
            'app/Services/Ai/LoopPluginAiModels.php',
            'app/Services/Ai/LoopPluginModelGuard.php',
            'app/Models/LoopPluginAiModel.php',
            'app/Support/Ai/Pricing/DynamicPricingSource.php',
        ];

        $racine = dirname(__DIR__, 2).'/';

        foreach ($fichiers as $fichier) {
            // Les COMMENTAIRES sont retires avant le scan, et c'est
            // indispensable : ces fichiers EXPLIQUENT pourquoi ils n'appellent
            // ni `ProviderResolver` ni le reste, et ces explications nomment
            // donc ce qu'elles interdisent. Mesurer le texte brut ferait
            // echouer le test sur sa propre documentation — la lecon
            // TASK-1614, ou la FORME comptait jusque dans un commentaire.
            $contenu = $this->codeSansCommentaires($racine.$fichier);

            foreach (['->prompt(', 'PromptRepository', 'ProviderResolver', 'AiTurnLock', 'Laravel\\Ai\\Ai'] as $interdit) {
                $this->assertStringNotContainsString($interdit, $contenu,
                    "{$fichier} ne doit contenir aucun chemin de generation ({$interdit})");
            }
        }
    }

    /** Point 18 : aucun secret OpenRouter ne part vers le navigateur. */
    public function test_aucun_secret_openrouter_n_est_expose_au_navigateur(): void
    {
        Config::set('ai.openrouter.api_key', 'sk-or-SECRET-NE-DOIT-PAS-FUIR');
        $this->fakeCatalogue([$this->model('vendor/a', ['prompt' => '0', 'completion' => '0'])]);

        $reponse = $this->actingAs($this->superAdmin)->get(route('admin.loop-plugins'));

        $reponse->assertOk();
        $reponse->assertDontSee('sk-or-SECRET-NE-DOIT-PAS-FUIR');
        $reponse->assertDontSee('api_key');
    }

    /** Point 20 : la configuration modele reste PLATEFORME. */
    public function test_la_configuration_des_modeles_n_est_jamais_un_etat_de_tenant(): void
    {
        $colonnes = \Illuminate\Support\Facades\Schema::getColumnListing('loop_plugin_ai_models');

        $this->assertNotContains('organization_id', $colonnes,
            'un modele est un reglage d\'INFRASTRUCTURE plateforme, jamais un etat de tenant');
        $this->assertNotContains('loop_id', $colonnes,
            'les POSTURES sont Loop-scoped (loop_ai_assistants) ; les MODELES ne le sont pas');
    }

    public function test_la_capability_loop_multi_ai_existe_et_ne_publie_rien(): void
    {
        $definition = app(\App\Ai\CapabilityRegistry::class)->get('loop_multi_ai');

        $this->assertSame('loop_multi_ai', $definition->id);
        $this->assertSame('loop_multi_ai', $definition->process);
        $this->assertFalse($definition->canWrite,
            'une reponse d\'assistant est PROPOSEE, jamais publiee sans action humaine (CDC §10)');
    }

    /** La source dynamique est bien celle que le conteneur sert. */
    public function test_la_source_dynamique_est_liee_au_conteneur(): void
    {
        $this->assertTrue(app()->bound(DynamicPricingSource::class));
        $this->assertInstanceOf(LoopPluginAiModels::class, app(DynamicPricingSource::class));
    }

    // ── Outils ──────────────────────────────────────────────────────────────

    /** Le CODE d'un fichier, commentaires et docblocks retires. */
    private function codeSansCommentaires(string $chemin): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents($chemin)) as $jeton) {
            if (is_array($jeton) && in_array($jeton[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($jeton) ? $jeton[1] : $jeton;
        }

        return $code;
    }

    /**
     * @param  array<int, array<string, mixed>>  $models
     */
    private function fakeCatalogue(array $models): void
    {
        Cache::forget(OpenRouterModelCatalog::CACHE_KEY);

        // La FABRIQUE est remplacee, pas seulement complétée : les doublures
        // de `Http::fake()` s'EMPILENT et le PREMIER motif pose gagne. Sans
        // cette remise a zero, un test qui appelle ce helper deux fois
        // mesurerait le catalogue du PREMIER appel — il mesurerait donc
        // l'inverse de son intention.
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*/models' => Http::response(['data' => $models], 200)]);
    }

    /**
     * @param  array<string, mixed>  $pricing
     * @return array<string, mixed>
     */
    private function model(string $id, array $pricing): array
    {
        return [
            'id' => $id,
            'name' => 'Modele '.$id,
            'context_length' => 32768,
            'pricing' => $pricing,
            'architecture' => [
                'input_modalities' => ['text'],
                'output_modalities' => ['text'],
            ],
        ];
    }

    /** Vieillir la preuve au-dela du TTL, sans toucher au reste. */
    private function perimerLaPreuve(string $assistantKey): void
    {
        LoopPluginAiModel::query()
            ->where('assistant_key', $assistantKey)
            ->update([
                'verified_free_at' => Carbon::now()->subSeconds(LoopPluginAiModels::FREE_PROOF_TTL_SECONDS + 60),
            ]);
    }
}
