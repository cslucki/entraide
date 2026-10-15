<?php

namespace Tests\Feature;

use App\Ai\ProviderResolver;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Support\Ai\AiFabContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1685 / MICRO-01 — quand l'IA est desactivee pour une Organization, le
 * produit cesse de la proposer a ses membres.
 *
 * ## Ce qui a ete mesure, avant tout code
 *
 * Recette OFSH, compte `main.member2@bouclepro.test`, `GET /membres` :
 *
 * | Fait | Mesure |
 * |---|---|
 * | `organization_ai_settings.is_enabled` de `main` | **false** |
 * | reglage SuperAdmin persiste | **OUI** — aucun defaut d'ecriture |
 * | statut de `/membres` | 200 |
 * | Shell « BouclePro IA » monte | **OUI** |
 * | `ProviderResolver::resolve()`, credential COMPLET pose, `is_enabled` faux | **DomainException** |
 *
 * La garde serveur etait donc deja fail-closed, et le reglage deja fidele. La
 * racine etait une ABSENCE : `AiFabContext` — l'autorite unique qui alimente le
 * FAB et le montage du Shell — ne lisait `OrganizationAiSetting` nulle part.
 *
 * Le produit proposait un geste que le serveur allait refuser : une poignee
 * posee sur une porte muree. Aucune capacite ne fuyait.
 *
 * ## Pourquoi `isAvailableFor()` et pas `is_enabled`
 *
 * Le drapeau seul laisserait visible le Shell d'une Organization activee mais
 * SANS credential : le premier geste echouerait, c'est-a-dire le meme defaut
 * deplace d'un cran. `ProviderResolver::isAvailableFor()` repond « `resolve()`
 * aboutirait-il ? » en lisant la MEME liste de conditions que la garde qui
 * refuse — extraite, pas recopiee. Aucun second drapeau, aucune migration :
 * `is_enabled` reste le mecanisme canonique de TASK-1212.
 *
 * ## Fixtures ISOLEES
 *
 * Aucun test ici ne touche les Organizations reelles du banc local. Chaque cas
 * cree la sienne, et l'isolation entre deux Organizations est elle-meme
 * affirmee plus bas.
 */
class TASK1685OrganizationAiAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    /** Une Organization dont l'IA est pleinement utilisable. */
    private function organizationWithAi(string $slug): Organization
    {
        $organization = Organization::factory()->create([
            'slug' => $slug,
            'loops_enabled' => true,
            'is_public' => true,
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-test-'.$slug,
            'is_enabled' => true,
        ]);

        return $organization;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function organizationWithSetting(string $slug, array $overrides): Organization
    {
        $organization = Organization::factory()->create([
            'slug' => $slug,
            'loops_enabled' => true,
            'is_public' => true,
        ]);

        OrganizationAiSetting::factory()->create(array_merge([
            'organization_id' => $organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-test-'.$slug,
            'is_enabled' => true,
        ], $overrides));

        return $organization;
    }

    // ────────────────────────────────────────────────────────────────────────
    // Le defaut constate, et sa correction
    // ────────────────────────────────────────────────────────────────────────

    public function test_the_shell_is_absent_when_ai_is_disabled_for_the_organization(): void
    {
        $organization = $this->organizationWithSetting('t1685-off', ['is_enabled' => false]);
        $user = User::factory()->create(['organization_id' => $organization->id]);

        $response = $this->actingAs($user)->get('/membres');

        $response->assertOk();
        $response->assertDontSee('ai-shell');
        $response->assertDontSee(__('ai.fab_label'));
    }

    public function test_the_shell_is_present_when_ai_is_enabled_and_configured(): void
    {
        $organization = $this->organizationWithAi('t1685-on');
        $user = User::factory()->create(['organization_id' => $organization->id]);

        $response = $this->actingAs($user)->get('/membres');

        $response->assertOk();
        $response->assertSee('ai-shell', false);
    }

    /**
     * La contre-epreuve du predicat choisi : activee MAIS sans credential, le
     * Shell ne doit pas apparaitre non plus. C'est tout l'ecart entre
     * `is_enabled` seul et `isAvailableFor()` — sans cette assertion, le
     * correctif laisserait un Shell qui echoue au premier geste.
     */
    public function test_an_enabled_organization_without_credential_offers_no_shell(): void
    {
        $organization = $this->organizationWithSetting('t1685-nokey', [
            'is_enabled' => true,
            'api_key' => null,
        ]);
        $user = User::factory()->create(['organization_id' => $organization->id]);

        $response = $this->actingAs($user)->get('/membres');

        $response->assertOk();
        $response->assertDontSee('ai-shell');
    }

    public function test_an_organization_without_any_ai_setting_offers_no_shell(): void
    {
        $organization = Organization::factory()->create([
            'slug' => 't1685-norow',
            'loops_enabled' => true,
            'is_public' => true,
        ]);
        $user = User::factory()->create(['organization_id' => $organization->id]);

        $response = $this->actingAs($user)->get('/membres');

        $response->assertOk();
        $response->assertDontSee('ai-shell');
    }

    // ────────────────────────────────────────────────────────────────────────
    // Le FAB et le Shell s'eteignent par le MEME geste
    // ────────────────────────────────────────────────────────────────────────

    public function test_the_fab_and_the_shell_are_governed_by_the_same_authority(): void
    {
        $off = $this->organizationWithSetting('t1685-both-off', ['is_enabled' => false]);
        $offUser = User::factory()->create(['organization_id' => $off->id]);

        $this->actingAs($offUser)->get('/membres');

        $context = app(AiFabContext::class);
        $this->assertNull(
            $context->forRequest(request(), $offUser),
            'Aucun contexte IA ne doit etre construit pour une Organization sans IA.'
        );
        $this->assertFalse($context->shouldRenderFab(request(), $offUser));
        $this->assertFalse($context->shouldMountShell(request(), $offUser));
    }

    // ────────────────────────────────────────────────────────────────────────
    // Isolation entre Organizations
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Les deux membres passent par la route ORG-PREFIXEE de LEUR Organization.
     *
     * Et c'est deliberé : `/membres` sans prefixe sert l'Organization par
     * DEFAUT de la plateforme, et `EnsureOrganizationMember` y refuse en 404 un
     * membre d'une autre Organization. Mesure faite en ecrivant ce test — la
     * premiere version, posee sur `/membres`, recevait 404 pour le second
     * membre et accusait a tort le correctif. Le defaut etait dans le test.
     */
    public function test_disabling_one_organization_leaves_another_untouched(): void
    {
        $off = $this->organizationWithSetting('t1685-iso-off', ['is_enabled' => false]);
        $on = $this->organizationWithAi('t1685-iso-on');

        $offUser = User::factory()->create(['organization_id' => $off->id]);
        $onUser = User::factory()->create(['organization_id' => $on->id]);

        $this->actingAs($offUser)
            ->get(route('organization.members.index', ['organization' => $off->slug]))
            ->assertOk()
            ->assertDontSee('ai-shell');

        // Session distincte : le second membre ne doit rien heriter du premier.
        $this->flushSession();

        $this->actingAs($onUser)
            ->get(route('organization.members.index', ['organization' => $on->slug]))
            ->assertOk()
            ->assertSee('ai-shell', false);
    }

    public function test_the_resolver_predicate_is_scoped_to_one_organization(): void
    {
        $off = $this->organizationWithSetting('t1685-pred-off', ['is_enabled' => false]);
        $on = $this->organizationWithAi('t1685-pred-on');

        $resolver = app(ProviderResolver::class);

        $this->assertFalse($resolver->isAvailableFor((string) $off->id));
        $this->assertTrue($resolver->isAvailableFor((string) $on->id));
    }

    // ────────────────────────────────────────────────────────────────────────
    // Le SuperAdmin ne CONTOURNE pas la desactivation du tenant
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Consigne MASTER : « preserver les acces SuperAdmin legitimes sans
     * contourner la desactivation tenant ».
     *
     * `isAvailableFor()` ne porte AUCUNE exemption `is_admin`, et c'est
     * deliberé : la desactivation est une decision de l'Organization sur SES
     * fonctions IA, pas un controle d'acces. Un SuperAdmin qui verrait le Shell
     * la ou les membres ne l'ont pas se verrait proposer une action que
     * `ProviderResolver` refuserait de toute facon — et il lirait l'ecran comme
     * si l'IA etait active pour ce tenant.
     *
     * Ses autres acces, eux, ne bougent pas : la surface reste atteignable.
     */
    public function test_a_super_admin_gets_no_shell_on_an_organization_without_ai(): void
    {
        $organization = $this->organizationWithSetting('t1685-sa-off', ['is_enabled' => false]);
        $superAdmin = User::factory()->create([
            'organization_id' => $organization->id,
            'is_admin' => true,
        ]);

        $response = $this->actingAs($superAdmin)->get('/membres');

        $response->assertOk();
        $response->assertDontSee('ai-shell');
    }

    public function test_a_super_admin_keeps_the_shell_where_ai_is_available(): void
    {
        $organization = $this->organizationWithAi('t1685-sa-on');
        $superAdmin = User::factory()->create([
            'organization_id' => $organization->id,
            'is_admin' => true,
        ]);

        $this->actingAs($superAdmin)->get('/membres')->assertOk()->assertSee('ai-shell', false);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Le Shell PUBLIC n'a pas bouge
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Le Shell Welcome (invite, public) lisait DEJA `isUsable()` via
     * `GuestShellPolicyService` — c'est le precedent canonique dont MICRO-01
     * s'inspire, pas une surface qu'il modifie. On le fige ici : son autorite
     * reste la sienne, et elle n'est pas passee par `AiFabContext`.
     */
    public function test_the_public_guest_shell_keeps_its_own_authority(): void
    {
        $source = file_get_contents(app_path('Services/GuestShell/GuestShellPolicyService.php'));

        $this->assertStringContainsString(
            'isUsable()',
            $source,
            'Le Shell public garde sa propre lecture de la configuration.'
        );
        $this->assertStringNotContainsString(
            'AiFabContext',
            $source,
            'Le Shell public ne doit pas etre rebranche sur l\'autorite du Shell membre.'
        );
    }

    /**
     * Et une Organization dont l'IA est coupee rend un etat GUEST explicite,
     * NOMME — pas une page cassee, pas un ACTIVE. On n'affirme pas lequel des
     * etats fermes sort : il depend de la politique Guest de l'Organization
     * (`DISABLED`, `MISCONFIGURED`, `NO_CREDENTIAL`…), et figer ici une valeur
     * dependant d'un defaut de factory serait une assertion fragile qui
     * casserait au premier changement de ce defaut, sans rien protegeer de
     * MICRO-01.
     *
     * Ce que ce test protege est exact : l'IA coupee n'ouvre JAMAIS le Shell
     * public, et le motif reste dit.
     */
    public function test_the_public_guest_shell_still_refuses_cleanly_without_ai(): void
    {
        $organization = $this->organizationWithSetting('t1685-guest', ['is_enabled' => false]);

        $state = app(\App\Services\GuestShell\GuestShellPolicyService::class)->state($organization);

        $this->assertNotSame(
            \App\Support\GuestShell\GuestShellState::ACTIVE,
            $state->status,
            'Une Organization sans IA ne doit jamais rendre un Shell public ACTIF.'
        );
        $this->assertNotEmpty($state->reasons, 'Le Shell public nomme son motif au lieu de disparaitre.');
    }

    // ────────────────────────────────────────────────────────────────────────
    // Le predicat ne mesure pas la SANTE du fournisseur
    // ────────────────────────────────────────────────────────────────────────

    /**
     * MASTER : « ne pas confondre indisponibilite transitoire du fournisseur et
     * configuration IA inutilisable ». Le resolveur ne contacte aucun provider
     * — il lit de la configuration. Cette assertion le FIGE : une Organization
     * correctement configuree reste disponible meme si aucun appel sortant ne
     * peut aboutir sur le banc (aucun `Http::fake()` n'est pose ici, et aucun
     * appel n'est emis).
     */
    public function test_a_configured_organization_stays_available_without_any_provider_call(): void
    {
        $organization = $this->organizationWithAi('t1685-health');

        $this->assertTrue(app(ProviderResolver::class)->isAvailableFor((string) $organization->id));
    }

    // ────────────────────────────────────────────────────────────────────────
    // Les gardes backend restent intactes
    // ────────────────────────────────────────────────────────────────────────

    public function test_the_backend_guard_still_refuses_a_disabled_organization(): void
    {
        $organization = $this->organizationWithSetting('t1685-backend', ['is_enabled' => false]);

        $capability = app(\App\Ai\CapabilityRegistry::class)->all()[0]->id;

        $contexte = new \App\Ai\ContexteIa(
            organizationId: (string) $organization->id,
            userId: null,
            loopId: null,
            locale: 'fr',
            capability: $capability,
            correlationId: (string) \Illuminate\Support\Str::uuid(),
        );

        $this->expectException(\DomainException::class);

        app(ProviderResolver::class)->resolve($capability, $contexte);
    }

    /**
     * Masquer une surface n'est pas une garde : la porte reste fermee pour une
     * requete forgee, qui ne passe par aucune vue.
     */
    public function test_a_forged_loop_ai_request_is_still_refused(): void
    {
        $organization = $this->organizationWithSetting('t1685-forged', ['is_enabled' => false]);
        $user = User::factory()->create(['organization_id' => $organization->id]);

        $loop = Loop::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $user->id,
        ]);
        LoopMember::factory()->create([
            'loop_id' => $loop->id,
            'user_id' => $user->id,
            'status' => 'active',
            'role' => 'owner',
        ]);

        $response = $this->actingAs($user)->post(route('loops.ai', $loop), [
            'question' => 'Une question qui ne doit atteindre aucun provider.',
        ]);

        $this->assertNotEquals(
            200,
            $response->status(),
            'Une requete forgee ne doit pas aboutir quand l\'IA est desactivee.'
        );
    }

    // ────────────────────────────────────────────────────────────────────────
    // Les surfaces NON-IA ne sont pas masquees
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Le perimetre est borne : seules les surfaces qui APPELLENT un provider
     * disparaissent. L'ecran Dossiers, le depot de fichiers et la navigation
     * sont des gestes humains ; les eteindre parce que l'IA est coupee serait
     * une regression, pas une garde.
     */
    public function test_non_ai_surfaces_remain_reachable_when_ai_is_disabled(): void
    {
        $organization = $this->organizationWithSetting('t1685-nonai', ['is_enabled' => false]);
        $user = User::factory()->create(['organization_id' => $organization->id]);

        // `/dossiers` n'existe PAS en route non prefixee : seule la forme
        // org-prefixee est servie. Mesure faite en ecrivant ce test.
        $this->actingAs($user)->get('/membres')->assertOk();
        $this->actingAs($user)->get('/loops')->assertOk();
        $this->actingAs($user)->get('/explorer')->assertOk();
        $this->actingAs($user)
            ->get(route('organization.dossiers.index', ['organization' => $organization->slug]))
            ->assertOk();
    }

    // ────────────────────────────────────────────────────────────────────────
    // Le message rendu au membre
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Le bandeau du Shell ne doit plus porter un diagnostic d'exploitation.
     * Mesure : « AI is not configured for Organization [<uuid>] : no enabled
     * provider/model » etait affichable a un membre.
     */
    public function test_the_member_notice_carries_no_technical_message_and_no_uuid(): void
    {
        $notice = __('ai.shell_unavailable_notice');

        $this->assertStringNotContainsString('Organization [', $notice);
        $this->assertStringNotContainsString('provider/model', $notice);
        $this->assertDoesNotMatchRegularExpression(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
            $notice
        );
        $this->assertNotSame('ai.shell_unavailable_notice', $notice, 'La clef doit etre traduite.');
    }

    /**
     * Le message DEJA destine au membre, lui, ne doit pas avoir ete avale par
     * la neutralisation : `AiTurnLock` leve une RuntimeException traduite, et
     * elle passe inchangee. Sans cette assertion, le correctif aurait pu
     * remplacer « une reponse est deja en cours » par un message d'echec.
     */
    public function test_the_already_in_progress_message_is_still_shown_verbatim(): void
    {
        $source = file_get_contents(app_path('Livewire/AiShell.php'));

        $this->assertStringContainsString(
            'catch (RuntimeException $exception)',
            $source,
            'Les RuntimeException doivent garder un catch distinct.'
        );
        $this->assertMatchesRegularExpression(
            '/catch \(RuntimeException \$exception\)\s*\{(?:(?!catch).)*\$this->notice = \$exception->getMessage\(\);/s',
            $source,
            'Le message traduit de RuntimeException doit rester rendu tel quel.'
        );
    }

    // ────────────────────────────────────────────────────────────────────────
    // Aucune seconde autorite
    // ────────────────────────────────────────────────────────────────────────

    /**
     * La regle de disponibilite ne doit exister qu'a UN endroit. Si un futur
     * correctif recopiait `is_enabled` dans une surface membre, ce test le dit.
     */
    public function test_the_member_surfaces_hold_no_second_availability_rule(): void
    {
        foreach ([
            app_path('Support/Ai/AiFabContext.php'),
            app_path('Livewire/LoopChat.php'),
            app_path('Livewire/AiShell.php'),
        ] as $file) {
            $source = file_get_contents($file);

            // Les commentaires citent `is_enabled` pour expliquer le defaut :
            // on cherche une LECTURE, pas une mention.
            $this->assertStringNotContainsString(
                '->is_enabled',
                $source,
                basename($file).' ne doit pas relire le drapeau : il passe par ProviderResolver.'
            );
            $this->assertStringNotContainsString(
                '->isUsable()',
                $source,
                basename($file).' ne doit pas reecrire la condition du resolveur.'
            );
        }
    }

    /**
     * Et la condition partagee doit bien etre PARTAGEE : `resolve()` et
     * `isAvailableFor()` passent tous deux par `tenantConfiguration()`.
     */
    public function test_the_resolver_shares_one_single_condition_list(): void
    {
        $source = file_get_contents(app_path('Ai/ProviderResolver.php'));

        $this->assertSame(
            1,
            preg_match_all('/private function tenantConfiguration\(/', $source),
            'La liste des conditions doit vivre dans UNE methode.'
        );
        $this->assertSame(
            1,
            preg_match_all('/no enabled provider\/model/', $source),
            'Le refus de configuration ne doit etre ecrit qu\'une fois.'
        );
    }
}
