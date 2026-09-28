<?php

namespace Tests\Feature\ScenarioManifest;

use App\Models\Loop;
use App\Models\Organization;
use App\Models\ScenarioManifestVersion;
use App\Models\ScenarioPackLoad;
use App\Models\User;
use App\Support\ScenarioManager\Persona\ScenarioPersonaAccess;
use App\Support\ScenarioManager\Persona\ScenarioPersonaRefused;
use App\Support\ScenarioManager\ScenarioLifecycleService;
use App\Support\ScenarioManifest\ScenarioManifestValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * TASK-1654 Phase 1 — entrer dans une sandbox sous l'identite d'un persona.
 *
 * ## Ce que ces tests refusent de croire
 *
 * Que le suffixe `.test` prouve quoi que ce soit. N'importe qui peut creer un
 * compte `quelquun@chose.test` dans une Organization CLIENTE : le suffixe ne
 * dit rien du tenant. La preuve doit venir du Scenario Manager — version
 * chargee, chargement vivant, Organization marquee sandbox, compte portant
 * CETTE `organization_id` — et le `.test` n'est qu'une condition de PLUS.
 *
 * Le test adversarial `test_un_compte_test_dans_une_organization_CLIENTE_est_refuse()`
 * est celui qui distingue une garde d'une apparence de garde.
 *
 * ## L'autre chose qu'ils refusent de croire
 *
 * Qu'un privilege constate a l'entree vaut encore a la sortie. Entre les deux,
 * l'acteur a pu etre desadminise — parfois precisement parce que quelqu'un a
 * decide qu'il ne devait plus avoir ces droits. Restaurer « ce qu'il avait »
 * annulerait cette decision depuis une session ouverte avant elle.
 */
class TASK1654PersonaAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        App::setLocale('fr');

        $this->superAdmin = User::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'is_admin' => true,
            'preferred_locale' => 'fr',
        ]);
    }

    // =====================================================================
    // 1. La porte est atteignable — sinon tout le reste est theorique
    // =====================================================================

    public function test_la_fiche_d_une_version_CHARGEE_mene_a_l_ecran_des_personas(): void
    {
        // Leçon T1651 : un ecran livre ET teste peut n'etre lie de NULLE PART.
        // Les tests ci-dessous appellent `route()` en direct ; celui-ci est le
        // seul qui prouve qu'une poignee y mene.
        $version = $this->versionChargee();

        $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios.show', $version))
            ->assertOk()
            ->assertSee(route('admin.outils.scenarios.personas', $version), escape: false);
    }

    public function test_l_ecran_des_personas_ne_montre_QUE_des_personas_empruntables(): void
    {
        $version = $this->versionChargee();
        $sandbox = $this->sandboxDe($version);

        $reel = User::factory()->create([
            'organization_id' => $sandbox->id,
            'email' => 'personne.reelle@gmail.com',
            'name' => 'Aaa Reelle',
        ]);

        $reponse = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios.personas', $version))
            ->assertOk();

        $reponse->assertDontSee($reel->email);
        $reponse->assertSee('Voir en tant que');
    }

    public function test_une_sandbox_EN_CORBEILLE_ne_propose_plus_d_entrer(): void
    {
        $version = $this->versionChargee();
        $this->sandboxDe($version)->delete();

        $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios.show', $version))
            ->assertOk()
            ->assertDontSee(route('admin.outils.scenarios.personas', $version), escape: false);
    }

    // =====================================================================
    // 2. Eligibilite
    // =====================================================================

    public function test_un_SuperAdmin_entre_sous_un_persona_de_la_sandbox(): void
    {
        $version = $this->versionChargee();
        $persona = $this->unPersona($version);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.scenarios.personas.enter', $version), ['persona_id' => $persona->id])
            ->assertRedirect('/');

        $this->assertSame($persona->id, Auth::id(), 'Le persona doit etre REELLEMENT authentifie.');
    }

    public function test_l_administrateur_du_TENANT_est_empruntable(): void
    {
        // `organizations.admin_id` n'est PAS le predicat de plateforme. Un
        // persona est souvent l'admin de sa propre sandbox, et c'est meme le
        // seul moyen de voir le produit de ce point de vue.
        $version = $this->versionChargee();
        $sandbox = $this->sandboxDe($version);
        $persona = $this->unPersona($version);

        $sandbox->forceFill(['admin_id' => $persona->id])->save();
        $this->assertFalse((bool) $persona->fresh()->is_admin);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.scenarios.personas.enter', $version), ['persona_id' => $persona->id])
            ->assertRedirect('/');

        $this->assertSame($persona->id, Auth::id());
    }

    public function test_un_compte_NON_ADMIN_ne_peut_pas_ouvrir_l_ecran(): void
    {
        $version = $this->versionChargee();
        $quelquun = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        $this->actingAs($quelquun)
            ->get(route('admin.outils.scenarios.personas', $version))
            ->assertForbidden();

        $this->actingAs($quelquun)
            ->post(route('admin.outils.scenarios.personas.enter', $version), ['persona_id' => $this->unPersona($version)->id])
            ->assertForbidden();
    }

    public function test_une_version_DRAFT_est_refusee(): void
    {
        $version = ScenarioManifestVersion::query()->create([
            'scenario_key' => 'brouillon',
            'name' => 'Brouillon',
            'version' => '0.1.0',
            'usage' => ScenarioManifestVersion::USAGE_DOGFOODING,
            'origin' => ScenarioManifestVersion::ORIGIN_NEW,
            'json_source' => '{}',
            'created_by' => $this->superAdmin->id,
        ]);

        $this->attendreRefus(ScenarioPersonaRefused::VERSION_NOT_LOADED, fn () => app(ScenarioPersonaAccess::class)->sandboxVivante($version->fresh()));
    }

    public function test_une_version_VALIDE_mais_JAMAIS_CHARGEE_est_refusee(): void
    {
        // `state = valid` ne suffit pas : une version approuvee mais jamais
        // chargee n'a pas de monde ou entrer.
        $version = $this->versionApprouveeNonChargee();

        $this->assertTrue($version->isValid());
        $this->assertFalse($version->isLoaded());

        $this->attendreRefus(ScenarioPersonaRefused::VERSION_NOT_LOADED, fn () => app(ScenarioPersonaAccess::class)->sandboxVivante($version));
    }

    public function test_un_compte_test_dans_une_organization_CLIENTE_est_refuse(): void
    {
        // LE test adversarial. La preuve de sandbox vient du Scenario Manager,
        // jamais du suffixe de l'adresse. Sans cette distinction, toute
        // Organization cliente contenant un compte `.test` serait empruntable.
        $version = $this->versionChargee();
        $client = Organization::factory()->create();
        $leurre = User::factory()->create([
            'organization_id' => $client->id,
            'email' => 'faux.persona@client.test',
        ]);

        $this->assertNull($client->scenario_sandbox_created_at);

        $this->attendreRefus(
            ScenarioPersonaRefused::NOT_IN_SANDBOX,
            fn () => app(ScenarioPersonaAccess::class)->exigerUnPersonaEligible($version, $leurre->id)
        );

        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.scenarios.personas.enter', $version), ['persona_id' => $leurre->id])
            ->assertSessionHas('error');

        $this->assertSame($this->superAdmin->id, Auth::id(), "L'identite NE DOIT PAS avoir bascule.");
    }

    public function test_un_persona_d_une_AUTRE_sandbox_est_refuse(): void
    {
        $versionA = $this->versionChargee();
        $versionB = $this->versionChargee('amt-bis');
        $personaB = $this->unPersona($versionB);

        $this->attendreRefus(
            ScenarioPersonaRefused::NOT_IN_SANDBOX,
            fn () => app(ScenarioPersonaAccess::class)->exigerUnPersonaEligible($versionA, $personaB->id)
        );
    }

    public function test_une_adresse_REELLE_est_refusee(): void
    {
        $version = $this->versionChargee();
        $persona = $this->unPersona($version);
        $persona->forceFill(['email' => 'vraie.personne@gmail.com'])->save();

        $this->attendreRefus(
            ScenarioPersonaRefused::NOT_FICTIONAL,
            fn () => app(ScenarioPersonaAccess::class)->exigerUnPersonaEligible($version, $persona->id)
        );
    }

    public function test_un_administrateur_de_PLATEFORME_est_refuse_meme_dans_la_sandbox(): void
    {
        $version = $this->versionChargee();
        $persona = $this->unPersona($version);
        $persona->forceFill(['is_admin' => true])->save();

        $this->attendreRefus(
            ScenarioPersonaRefused::PLATFORM_ADMIN,
            fn () => app(ScenarioPersonaAccess::class)->exigerUnPersonaEligible($version, $persona->id)
        );
    }

    public function test_un_persona_BANNI_est_refuse(): void
    {
        $version = $this->versionChargee();
        $persona = $this->unPersona($version);
        $persona->forceFill(['banned_at' => now()])->save();

        $this->attendreRefus(
            ScenarioPersonaRefused::BANNED,
            fn () => app(ScenarioPersonaAccess::class)->exigerUnPersonaEligible($version, $persona->id)
        );
    }

    public function test_un_persona_CREE_APRES_le_chargement_est_empruntable(): void
    {
        // La sandbox est VIVANTE : l'eligibilite se juge sur l'etat present,
        // jamais sur le manifeste source.
        $version = $this->versionChargee();
        $sandbox = $this->sandboxDe($version);

        $nouveau = User::factory()->create([
            'organization_id' => $sandbox->id,
            'email' => 'ajoute.apres@'.$sandbox->slug.'.amt-demo.test',
        ]);

        $eligibles = app(ScenarioPersonaAccess::class)->personasEligibles($version);

        $this->assertTrue(
            $eligibles->contains(fn (User $u) => $u->id === $nouveau->id),
            'Un persona ajoute apres le Load doit etre empruntable.'
        );
    }

    // =====================================================================
    // 3. Session
    // =====================================================================

    public function test_l_acteur_d_origine_est_memorise_et_aucun_credential_ne_l_est(): void
    {
        $version = $this->versionChargee();
        $persona = $this->unPersona($version);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.scenarios.personas.enter', $version), ['persona_id' => $persona->id]);

        $contexte = session(ScenarioPersonaAccess::SESSION_KEY);

        $this->assertSame($this->superAdmin->id, $contexte['original_admin_id']);
        $this->assertSame($persona->id, $contexte['persona_id']);
        $this->assertSame($this->sandboxDe($version)->id, $contexte['sandbox_organization_id']);
        $this->assertSame($version->id, $contexte['scenario_version_id']);
        $this->assertNotEmpty($contexte['started_at']);

        // Aucun credential : ni mot de passe, ni jeton, ni empreinte. Le test
        // porte sur les CLEFS et sur les VALEURS — une empreinte glissee sous
        // un nom anodin passerait le premier controle seul.
        $interdits = ['password', 'token', 'secret', 'hash', 'remember', 'credential', 'api_key'];
        foreach (array_keys($contexte) as $clef) {
            foreach ($interdits as $interdit) {
                $this->assertStringNotContainsString($interdit, strtolower($clef));
            }
        }
        $this->assertNotContains(
            $persona->password,
            array_values($contexte),
            "L'empreinte du mot de passe ne doit JAMAIS transiter par la session."
        );
    }

    public function test_l_identifiant_de_session_CHANGE_a_la_bascule(): void
    {
        $version = $this->versionChargee();
        $persona = $this->unPersona($version);

        $this->actingAs($this->superAdmin)->get(route('admin.outils.scenarios.personas', $version));
        $avant = session()->getId();

        $this->post(route('admin.outils.scenarios.personas.enter', $version), ['persona_id' => $persona->id]);

        $this->assertNotSame($avant, session()->getId(), 'La session doit etre regeneree : fixation de session.');
    }

    /**
     * Admin -> Alice -> Bob est refuse — et par QUI, exactement.
     *
     * Trouve en sabotage : ce test reste VERT quand on retire la garde
     * d'imbrication de `ScenarioPersonaSwitch`. Ce n'est pas elle qui refuse
     * ici, c'est `AdminMiddleware` : en mode persona, `Auth::user()` n'est plus
     * administrateur, donc la route d'entree est inatteignable.
     *
     * Le test garde toute sa valeur — il prouve que l'imbrication est
     * IMPOSSIBLE par le produit — mais il ne prouve pas MA garde. C'est
     * {@see test_la_garde_d_imbrication_refuse_meme_appelee_EN_DIRECT()} qui
     * s'en charge. Les nommer separement evite le piege exact que T1644 a paye :
     * deux gardes au meme resultat rendent un test vert sur une garde absente.
     */
    public function test_l_IMBRICATION_est_interdite_par_le_produit(): void
    {
        $version = $this->versionChargee();
        $premier = $this->unPersona($version);
        $second = app(ScenarioPersonaAccess::class)->personasEligibles($version)
            ->firstWhere(fn (User $u) => $u->id !== $premier->id);

        $this->assertNotNull($second, 'Il faut deux personas pour prouver le refus.');

        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.scenarios.personas.enter', $version), ['persona_id' => $premier->id]);
        $this->assertSame($premier->id, Auth::id());

        // Admin -> Alice -> Bob : NON.
        $this->post(route('admin.outils.scenarios.personas.enter', $version), ['persona_id' => $second->id])
            ->assertForbidden();

        $this->assertSame($premier->id, Auth::id(), "L'identite NE DOIT PAS avoir change.");
    }

    public function test_la_garde_d_imbrication_refuse_meme_appelee_EN_DIRECT(): void
    {
        // La garde de `ScenarioPersonaSwitch`, interrogee sans passer par la
        // route : c'est le seul test qui rougit quand on la retire.
        $version = $this->versionChargee();
        $persona = $this->unPersona($version);
        $autre = app(ScenarioPersonaAccess::class)->personasEligibles($version)
            ->firstWhere(fn (User $u) => $u->id !== $persona->id);

        $this->assertNotNull($autre);

        $bascule = app(\App\Support\ScenarioManager\Persona\ScenarioPersonaSwitch::class);
        $bascule->entrer($this->superAdmin, $persona, $version);

        $this->attendreRefus(
            ScenarioPersonaRefused::ALREADY_IMPERSONATING,
            fn () => $bascule->entrer($this->superAdmin, $autre, $version)
        );

        $this->assertSame($persona->id, Auth::id(), "L'identite NE DOIT PAS avoir change.");
    }

    public function test_l_imbrication_est_refusee_aussi_depuis_l_impersonation_GENERIQUE(): void
    {
        // La porte la plus ancienne (`admin_original_id`, /admin/users)
        // rouvrirait ce que la nouvelle interdit si on ne la lisait pas.
        $version = $this->versionChargee();
        $persona = $this->unPersona($version);

        session()->put(ScenarioPersonaAccess::LEGACY_IMPERSONATION_KEY, $this->superAdmin->id);

        $this->attendreRefus(
            ScenarioPersonaRefused::ALREADY_IMPERSONATING,
            fn () => app(\App\Support\ScenarioManager\Persona\ScenarioPersonaSwitch::class)
                ->entrer($this->superAdmin, $persona, $version)
        );
    }

    // =====================================================================
    // 4. Tenant
    // =====================================================================

    public function test_apres_la_bascule_le_monde_du_persona_est_la_SANDBOX(): void
    {
        $version = $this->versionChargee();
        $sandbox = $this->sandboxDe($version);
        $persona = $this->unPersona($version);
        $tenantDeDepart = $this->superAdmin->organization_id;

        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.scenarios.personas.enter', $version), ['persona_id' => $persona->id]);

        // MESURE, et elle a corrige ma premisse. `/` est une route GLOBALE de
        // plateforme (`ResolveUrlOrganization::$platformGlobalExact`) : aucun
        // tenant n'y est lie, pour personne. Et sur une route courte comme
        // `/loops`, le produit REDIRIGE l'utilisateur connecte vers la forme
        // canonique de SON Organization avant tout liage.
        //
        // C'est donc cette redirection qui porte la preuve : le monde d'un
        // persona est la sandbox, deduit de son identite, et non le tenant que
        // le SuperAdmin regardait une seconde plus tot.
        $this->get('/loops')->assertRedirect('/org/'.$sandbox->slug.'/loops');

        // Et sur cette forme canonique, le tenant lie EST la sandbox. Le tenant
        // est une instance de CONTENEUR resolue par requete, jamais une valeur
        // de session : c'est pour cela qu'aucun second resolveur n'a ete ecrit.
        $this->get('/org/'.$sandbox->slug.'/loops')->assertOk();

        $courant = app()->bound('current_organization') ? app('current_organization') : null;

        $this->assertNotNull($courant);
        $this->assertSame($sandbox->id, $courant->id, 'Le tenant courant doit etre la sandbox.');
        $this->assertNotSame($tenantDeDepart, $courant->id, "L'ancien tenant du SuperAdmin doit avoir disparu.");
    }

    public function test_le_persona_ne_voit_pas_l_administration(): void
    {
        $version = $this->versionChargee();
        $persona = $this->unPersona($version);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.scenarios.personas.enter', $version), ['persona_id' => $persona->id]);

        // Une page /admin rafraichie en mode persona : refus normal.
        $this->get(route('admin.outils.scenarios'))->assertForbidden();
    }

    // =====================================================================
    // 5. Le bandeau
    // =====================================================================

    public function test_le_bandeau_est_present_et_porte_la_sortie(): void
    {
        $version = $this->versionChargee();
        $sandbox = $this->sandboxDe($version);
        $persona = $this->unPersona($version);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.scenarios.personas.enter', $version), ['persona_id' => $persona->id]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Mode persona')
            ->assertSee($sandbox->name)
            ->assertSee(route('admin.outils.scenarios.personas.exit'), escape: false);
    }

    // =====================================================================
    // 6. Sortie et restauration
    // =====================================================================

    public function test_la_sortie_restaure_le_SuperAdmin_et_purge_l_etat(): void
    {
        $version = $this->versionChargee();
        $persona = $this->unPersona($version);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.scenarios.personas.enter', $version), ['persona_id' => $persona->id]);

        $this->post(route('admin.outils.scenarios.personas.exit'))
            ->assertRedirect(route('admin.outils.scenarios'));

        $this->assertSame($this->superAdmin->id, Auth::id());
        $this->assertNull(session(ScenarioPersonaAccess::SESSION_KEY), "L'etat persona doit avoir disparu.");
    }

    public function test_la_sortie_NE_RESTAURE_PAS_un_acteur_qui_n_est_plus_administrateur(): void
    {
        // Le privilege n'est pas conserve, il est REVERIFIE. Quelqu'un a retire
        // ses droits pendant la session : les lui rendre annulerait cette
        // decision depuis une session ouverte avant elle.
        $version = $this->versionChargee();
        $persona = $this->unPersona($version);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.scenarios.personas.enter', $version), ['persona_id' => $persona->id]);

        $this->superAdmin->forceFill(['is_admin' => false])->save();

        $this->post(route('admin.outils.scenarios.personas.exit'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull(session(ScenarioPersonaAccess::SESSION_KEY));
    }

    public function test_la_sortie_deconnecte_si_l_acteur_a_DISPARU(): void
    {
        $version = $this->versionChargee();
        $persona = $this->unPersona($version);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.scenarios.personas.enter', $version), ['persona_id' => $persona->id]);

        User::query()->whereKey($this->superAdmin->id)->delete();

        $this->post(route('admin.outils.scenarios.personas.exit'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_la_sortie_exige_un_POST(): void
    {
        $this->actingAs($this->superAdmin)
            ->get('/admin/outils/scenarios/persona/sortir')
            ->assertStatus(405);
    }

    // =====================================================================
    // 7. Contexte perime — la garde par requete
    // =====================================================================

    public function test_un_persona_SUPPRIME_ferme_le_mode(): void
    {
        // Le persona est CREE POUR CE TEST, et c'est une contrainte du schema,
        // pas une commodite : un persona du manifeste porte du contenu, et les
        // cles etrangeres RESTRICT interdisent sa suppression. Le cas
        // reellement atteignable est donc celui d'un compte sans contenu.
        $version = $this->versionChargee();
        $sandbox = $this->sandboxDe($version);
        $persona = User::factory()->create([
            'organization_id' => $sandbox->id,
            'email' => 'sans.contenu@'.$sandbox->slug.'.amt-demo.test',
        ]);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.scenarios.personas.enter', $version), ['persona_id' => $persona->id])
            ->assertRedirect('/');

        User::query()->whereKey($persona->id)->delete();

        $this->get('/')->assertRedirect(route('admin.outils.scenarios'));
        $this->assertSame($this->superAdmin->id, Auth::id());
        $this->assertNull(session(ScenarioPersonaAccess::SESSION_KEY));
    }

    public function test_un_persona_DEPLACE_vers_un_vrai_tenant_ferme_le_mode(): void
    {
        [, $persona] = $this->enMode();

        $persona->forceFill(['organization_id' => Organization::factory()->create()->id])->save();

        $this->get('/')->assertRedirect(route('admin.outils.scenarios'));
        $this->assertSame($this->superAdmin->id, Auth::id());
    }

    public function test_une_adresse_devenue_REELLE_ferme_le_mode(): void
    {
        [, $persona] = $this->enMode();

        $persona->forceFill(['email' => 'devenue.reelle@gmail.com'])->save();

        $this->get('/')->assertRedirect(route('admin.outils.scenarios'));
        $this->assertSame($this->superAdmin->id, Auth::id());
    }

    public function test_un_persona_PROMU_administrateur_de_plateforme_ferme_le_mode(): void
    {
        [, $persona] = $this->enMode();

        $persona->forceFill(['is_admin' => true])->save();

        $this->get('/')->assertRedirect(route('admin.outils.scenarios'));
        $this->assertSame($this->superAdmin->id, Auth::id());
    }

    public function test_une_sandbox_MISE_EN_CORBEILLE_ferme_le_mode(): void
    {
        [$version] = $this->enMode();

        $this->sandboxDe($version)->delete();

        $this->get('/')->assertRedirect(route('admin.outils.scenarios'));
        $this->assertSame($this->superAdmin->id, Auth::id());
    }

    public function test_un_CHARGEMENT_devenu_incoherent_ferme_le_mode(): void
    {
        [$version] = $this->enMode();

        // Un Reset suivi d'un rechargement fabrique un monde neuf : l'identite
        // du persona d'hier n'y signifie plus rien.
        $version->forceFill(['scenario_pack_load_id' => null])->save();

        $this->get('/')->assertRedirect(route('admin.outils.scenarios'));
        $this->assertSame($this->superAdmin->id, Auth::id());
    }

    public function test_un_contexte_de_session_TRONQUE_ne_vaut_pas_un_demi_mode(): void
    {
        [, $persona] = $this->enMode();

        $contexte = session(ScenarioPersonaAccess::SESSION_KEY);
        unset($contexte['original_admin_id']);
        session()->put(ScenarioPersonaAccess::SESSION_KEY, $contexte);

        // Fail-closed sur la FORME : plus de mode persona du tout, donc plus de
        // bandeau — et non un mode dont on ne saurait pas sortir.
        $this->assertFalse(ScenarioPersonaAccess::actif());
        $this->get('/')->assertOk()->assertDontSee('Mode persona');
    }

    public function test_le_mode_perime_deconnecte_si_l_acteur_n_est_plus_administrateur(): void
    {
        [, $persona] = $this->enMode();

        $this->superAdmin->forceFill(['is_admin' => false])->save();
        $persona->forceFill(['is_admin' => true])->save();

        $this->get('/')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    // =====================================================================
    // 8. Les ecritures du persona lui sont attribuees
    // =====================================================================

    public function test_un_message_ecrit_en_mode_persona_porte_l_identite_du_PERSONA(): void
    {
        [$version, $persona] = $this->enMode();

        $boucle = Loop::query()
            ->where('organization_id', $this->sandboxDe($version)->id)
            ->whereHas('members', fn ($q) => $q->where('user_id', $persona->id)->where('status', 'active'))
            ->first();

        $this->assertNotNull($boucle, 'Le persona doit appartenir a au moins une Boucle.');

        // La route org-prefixee, celle que le produit emploie hors Organization
        // par defaut : `$_loopRoute()` la choisit dans la vue des qu'on est sur
        // `organization.*`. La route courte `loops.messages.store` resout, elle,
        // l'Organization PAR DEFAUT — semantique deliberee et defendue par 8
        // tests (voir la note TASK-1601 dans `ResolveUrlOrganization`) — et
        // rendrait 404 pour tout membre d'un autre tenant, persona ou non.
        $this->post(
            route('organization.loops.messages.store', ['organization' => $this->sandboxDe($version)->slug, 'loop' => $boucle]),
            ['body' => $texte = 'Message ecrit en mode persona '.uniqid().'.']
        )->assertRedirect()->assertSessionHasNoErrors();

        // Le message est retrouve par son CONTENU, jamais par « le plus
        // recent » : AMT seme deja des messages, `created_at` est a la SECONDE,
        // et un tri sans discriminant aurait designe n'importe lequel d'entre
        // eux — le test aurait alors juge l'attribution d'une ecriture qu'il
        // n'a pas faite.
        $message = \App\Models\LoopMessage::query()
            ->where('loop_id', $boucle->id)
            ->where('body', $texte)
            ->first();

        $this->assertNotNull($message, 'Le message ecrit doit exister en base.');

        $this->assertSame(
            $persona->id,
            $message->sender_id,
            "L'ecriture doit etre attribuee au PERSONA, jamais a l'administrateur d'origine."
        );
        $this->assertNotSame($this->superAdmin->id, $message->sender_id);
    }

    // =====================================================================
    // Harnais
    // =====================================================================

    /**
     * Entre en mode persona et rend la version et le persona.
     *
     * @return array{ScenarioManifestVersion, User}
     */
    private function enMode(): array
    {
        $version = $this->versionChargee();
        $persona = $this->unPersona($version);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.scenarios.personas.enter', $version), ['persona_id' => $persona->id])
            ->assertRedirect('/');

        return [$version, $persona];
    }

    private function unPersona(ScenarioManifestVersion $version): User
    {
        $persona = app(ScenarioPersonaAccess::class)->personasEligibles($version)->first();

        $this->assertNotNull($persona, 'La sandbox AMT doit contenir au moins un persona empruntable.');

        return $persona;
    }

    private function sandboxDe(ScenarioManifestVersion $version): Organization
    {
        $load = ScenarioPackLoad::query()->findOrFail($version->fresh()->scenario_pack_load_id);

        return Organization::query()->withTrashed()->findOrFail($load->organization_id);
    }

    private function attendreRefus(string $raisonAttendue, \Closure $geste): void
    {
        try {
            $geste();
        } catch (ScenarioPersonaRefused $refus) {
            $this->assertSame($raisonAttendue, $refus->reason, 'La RAISON du refus doit etre celle attendue.');

            return;
        }

        $this->fail("Aucun refus leve, alors que « {$raisonAttendue} » etait attendu.");
    }

    private function versionApprouveeNonChargee(): ScenarioManifestVersion
    {
        $version = $this->versionValide();
        app(ScenarioLifecycleService::class)->approve($version, $this->superAdmin);

        return $version->fresh();
    }

    /**
     * Une version VALIDE de la fixture AMT.
     *
     * `$clef` change AUSSI l'`id` du manifeste, et ce n'est pas cosmetique :
     * l'identite du MONDE est `pack_id`, derive de `manifest->id()`. Deux
     * versions du meme JSON viseraient donc la MEME sandbox, et le moteur
     * refuserait le second chargement — « une version proprietaire par sandbox
     * vivante » (T1650). Pour obtenir deux sandboxes il faut deux manifestes.
     */
    private function versionValide(string $clef = 'amt-formation-ia'): ScenarioManifestVersion
    {
        $json = file_get_contents(base_path('tests/Fixtures/ScenarioManifest/amt-formation-ia.json'));

        if ($clef !== 'amt-formation-ia') {
            $decode = json_decode($json, true);
            $decode['id'] = $clef;
            $decode['name'] = 'Jumelle '.$clef;
            $json = json_encode($decode, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $resultat = app(ScenarioManifestValidator::class)->validate($json);
        $this->assertTrue($resultat->isValid(), 'La fixture AMT doit etre valide.');

        $version = new ScenarioManifestVersion([
            'scenario_key' => $clef,
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

        return $version->fresh();
    }

    private function versionChargee(string $clef = 'amt-formation-ia'): ScenarioManifestVersion
    {
        $version = $this->versionValide($clef);

        $service = app(ScenarioLifecycleService::class);
        $service->approve($version, $this->superAdmin);
        $service->load($version->fresh());

        return $version->fresh();
    }
}
