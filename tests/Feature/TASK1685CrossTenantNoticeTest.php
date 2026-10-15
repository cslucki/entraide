<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1685 / Q3 — le LIBELLE d'un refus cross-tenant demontre.
 *
 * ## Ce qui ne change pas, et pourquoi
 *
 * Le statut reste **404**. La doctrine TASK-1601 -> TASK-1606 l'a tranche, et
 * le docblock de {@see TASK1606CrossTenantNotFoundTest} l'ecrit : « un 403
 * dirait "il y a quelque chose ici, mais pas pour vous", ce qui divulguerait
 * deja l'existence de la ressource ». La sortie vers SON Organization et le
 * titre portant SON nom restent ceux de T1606.
 *
 * Une premiere version de cette TASK (MICRO-02, commit `308f13e5`) convertissait
 * ces refus en 403 explique. Elle a ete REVERTEE : 13 tests sur 7 fichiers de
 * TASK affirmaient le statut, et la doctrine a raison contre un correctif
 * decide sans elle. Ce qui restait vrai du constat de recette est bien plus
 * etroit, et c'est l'objet de ce fichier : la PHRASE.
 *
 * ## Le defaut mesure
 *
 * Session HTTP reelle sur `https://test.laravel`, compte
 * `main.member1@bouclepro.test` (membre de `main`), cible `ofsh` :
 *
 * | URL | statut | message rendu |
 * |---|---|---|
 * | `/org/ofsh/agenda` | 404 | « Hmm, cette boucle semble partie en vacances… » |
 * | `/org/ofsh/loops` | 404 | idem |
 * | `/org/ofsh/membres` | 404 | idem |
 * | `/org/ofsh/explorer` | 404 | idem |
 *
 * La formule parle d'une Boucle — il n'y en a aucune en cause — et se lit comme
 * une panne alors que la personne est identifiee et a tape ce slug elle-meme.
 *
 * ## La condition, et pourquoi elle ne cree AUCUN oracle d'existence
 *
 * Trois predicats cumulatifs, dans `errors/404.blade.php` :
 *
 *  1. utilisateur AUTHENTIFIE portant une Organization (l'invite garde le
 *     comportement T1606, inchange) ;
 *  2. une route DECLAREE a ete resolue — ce qui separe « cette page existe et
 *     n'est pas la tienne » de « cette URL n'existe pas ». Sans lui, l'ecart de
 *     slug seul confondrait refus d'acces et ressource inexistante, ce que
 *     MASTER a explicitement interdit ;
 *  3. le slug de l'URL differe de celui de SON Organization, compare comme une
 *     CHAINE — **aucune lecture en base de l'Organization visee**.
 *
 * Le point 3 est l'enjeu : `/org/<slug-inexistant>/agenda` resout la meme route
 * et recoit donc EXACTEMENT le meme message que `/org/ofsh/agenda`. La phrase
 * ne distingue pas une Organization qui existe d'une qui n'existe pas, et ne
 * divulgue donc rien que le 404 d'aujourd'hui ne divulguait deja. Ce test le
 * figE.
 */
class TASK1685CrossTenantNoticeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $mine;

    private Organization $other;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mine = Organization::factory()->create([
            'slug' => 't1685q3-mine',
            'name' => 'Mon Organization Q3',
            'is_active' => true,
            'is_public' => true,
            'loops_enabled' => true,
        ]);

        $this->other = Organization::factory()->create([
            'slug' => 't1685q3-other',
            'name' => 'Organization Etrangere Q3',
            'is_active' => true,
            'is_public' => true,
            'loops_enabled' => true,
        ]);

        $this->member = User::factory()->create([
            'organization_id' => $this->mine->id,
            'is_admin' => false,
        ]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Le defaut corrige
    // ────────────────────────────────────────────────────────────────────────

    public function test_a_demonstrated_cross_tenant_refusal_gets_the_neutral_message(): void
    {
        foreach ([
            "/org/{$this->other->slug}/agenda",
            "/org/{$this->other->slug}/loops",
            "/org/{$this->other->slug}/membres",
            "/org/{$this->other->slug}/explorer",
        ] as $path) {
            $response = $this->actingAs($this->member)->get($path);

            $response->assertNotFound();
            $response->assertSee('data-error-page="cross-tenant"', false);
            $response->assertSee(e(__('errors.404_cross_tenant_title')), false);
            $response->assertSee(e(__('errors.404_cross_tenant_body')), false);
            $response->assertSee(e(__('errors.404_cross_tenant_hint')), false);

            // Le grand « 404 » a disparu, et le cadenas l'a remplace.
            $response->assertSee('data-error-icon="lock"', false);
            $response->assertDontSee(__('errors.404_message'), false);
        }
    }

    /** Le statut ne bouge PAS : c'est la doctrine T1601-T1606. */
    public function test_the_status_stays_404_and_never_becomes_403(): void
    {
        foreach ([
            "/org/{$this->other->slug}/agenda",
            "/org/{$this->other->slug}/loops",
        ] as $path) {
            $this->actingAs($this->member)->get($path)->assertStatus(404);
        }
    }

    /** La sortie de T1606 reste : vers SON Organization, jamais l'etrangere. */
    public function test_the_way_back_still_points_at_the_members_own_organization(): void
    {
        $response = $this->actingAs($this->member)
            ->get("/org/{$this->other->slug}/agenda");

        // MASTER : le bouton mene au TABLEAU DE BORD de son Organization.
        $response->assertSee(
            route('organization.dashboard', ['organization' => $this->mine->slug]),
            false
        );
        $response->assertDontSee($this->other->slug, false);
        // Sans `false` : le gabarit rend `{{ … }}`, donc « Retour à l'accueil »
        // sort en `l&#039;accueil`. Viser la forme NON echappee faisait rougir
        // cette assertion a tort — l'apostrophe, encore.
        $response->assertSee(e(__('errors.404_cross_tenant_back')), false);
    }

    /** Et le titre porte le nom de la SIENNE, pas celui de l'etrangere (T1606). */
    public function test_the_title_still_carries_the_members_own_organization_name(): void
    {
        $response = $this->actingAs($this->member)
            ->get("/org/{$this->other->slug}/agenda");

        $response->assertSee($this->mine->name, false);
        $response->assertDontSee($this->other->name, false);
    }

    // ────────────────────────────────────────────────────────────────────────
    // AUCUN oracle d'existence — le coeur de la garantie
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Le test qui compte. Un slug qui n'existe PAS doit rendre le message
     * IDENTIQUE a celui d'une Organization qui existe : sinon la phrase
     * deviendrait un detecteur d'existence, et c'est precisement ce que T1606 a
     * refuse en gardant le 404.
     */
    public function test_a_nonexistent_slug_yields_exactly_the_same_message(): void
    {
        $existing = $this->actingAs($this->member)
            ->get("/org/{$this->other->slug}/agenda");

        $nonexistent = $this->actingAs($this->member)
            ->get('/org/t1685q3-aucune-org/agenda');

        $this->assertSame(404, $existing->status());
        $this->assertSame(404, $nonexistent->status());

        foreach ([$existing, $nonexistent] as $response) {
            $response->assertSee('data-error-page="cross-tenant"', false);
            $response->assertSee(e(__('errors.404_cross_tenant_title')), false);
        }

        // Et pas seulement le marqueur : les deux CORPS de page sont identiques
        // une fois le slug de l'URL neutralise. Un ecart, meme cosmetique,
        // redeviendrait un signal.
        $strip = static fn (string $html): string => preg_replace(
            ['/t1685q3-other|t1685q3-aucune-org/', '/\s+/'],
            ['SLUG', ' '],
            $html
        );
        $this->assertSame($strip($existing->getContent()), $strip($nonexistent->getContent()));
    }

    /**
     * Et la condition ne lit JAMAIS l'Organization visee : la garantie
     * ci-dessus doit tenir par CONSTRUCTION, pas par coincidence. Un futur
     * correctif qui ajouterait un `Organization::where('slug', …)` a la
     * decision ferait rougir ce test.
     */
    public function test_the_decision_never_looks_up_the_targeted_organization(): void
    {
        $source = file_get_contents(resource_path('views/errors/404.blade.php'));

        $this->assertSame(
            1,
            preg_match_all('/\$crossTenantRefusal = /', $source),
            'La decision doit etre prise en UN endroit.'
        );

        // Le bloc de decision, isole : de `$ownOrg` a l'affectation finale.
        $start = strpos($source, '$ownOrg = auth()');
        $end = strpos($source, '$errorMessage =');
        $decision = substr($source, $start, $end - $start);

        $this->assertStringNotContainsString(
            'Organization::where',
            $decision,
            'La decision ne doit pas interroger l\'Organization visee.'
        );
        $this->assertStringNotContainsString(
            'findBySlug',
            $decision,
            'La decision ne doit pas resoudre le slug de l\'URL.'
        );
    }

    // ────────────────────────────────────────────────────────────────────────
    // Refus d'acces et ressource inexistante ne sont PAS confondus
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Consigne MASTER : « ne pas utiliser le seul ecart entre deux slugs comme
     * preuve suffisante si cela peut confondre acces refuse et ressource
     * inexistante ». C'est le predicat « une route declaree a ete resolue » qui
     * le garantit, et voici sa contre-epreuve.
     */
    public function test_an_url_that_matches_no_route_keeps_the_generic_message(): void
    {
        foreach ([
            "/org/{$this->other->slug}/cette-page-nexiste-pas",
            '/cette-page-nexiste-pas-du-tout',
        ] as $path) {
            $response = $this->actingAs($this->member)->get($path);

            $response->assertNotFound();
            $response->assertSee(__('errors.404_message'), false);
            $response->assertSee('data-error-page="generic"', false);
            $response->assertSee('404', false);
            $response->assertDontSee(e(__('errors.404_cross_tenant_title')), false);
        }
    }

    /**
     * Dans SON PROPRE espace, une ressource manquante reste « introuvable » :
     * c'est bien la ressource qui manque, et le dire autrement serait faux.
     */
    public function test_a_missing_resource_in_the_members_own_space_keeps_the_generic_message(): void
    {
        $response = $this->actingAs($this->member)
            ->get("/org/{$this->mine->slug}/loops/01a00000-0000-7000-0000-000000000000");

        $response->assertNotFound();
        $response->assertSee(__('errors.404_message'), false);
        $response->assertSee('data-error-page="generic"', false);
        $response->assertDontSee(e(__('errors.404_cross_tenant_title')), false);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Ce qui n'etait pas concerne ne bouge pas
    // ────────────────────────────────────────────────────────────────────────

    /** T1606 a laisse l'invite inchange : on ne lui invente rien. */
    public function test_a_guest_keeps_the_generic_message(): void
    {
        $response = $this->get("/org/{$this->other->slug}/cette-page-nexiste-pas");

        $response->assertNotFound();
        $response->assertSee(__('errors.404_message'), false);
        $response->assertSee('data-error-page="generic"', false);
    }

    /** Un membre dans son espace, sur une page qui existe : rien a voir ici. */
    public function test_a_member_still_reaches_their_own_surfaces(): void
    {
        $this->actingAs($this->member)
            ->get("/org/{$this->mine->slug}/agenda")
            ->assertSuccessful();
    }

    /** Le SuperAdmin garde son acces d'inspection (doctrine T1658). */
    public function test_the_super_admin_still_inspects_the_other_organization(): void
    {
        $superAdmin = User::factory()->create([
            'organization_id' => $this->mine->id,
            'is_admin' => true,
        ]);

        Loop::factory()->create([
            'organization_id' => $this->other->id,
            'created_by' => $superAdmin->id,
        ]);

        $this->actingAs($superAdmin)
            ->get("/org/{$this->other->slug}/agenda")
            ->assertSuccessful();
    }

    // ────────────────────────────────────────────────────────────────────────
    // Les deux libelles existent et disent ce qu'ils doivent dire
    // ────────────────────────────────────────────────────────────────────────

    public function test_both_messages_are_translated_and_distinct(): void
    {
        foreach (['fr', 'en'] as $locale) {
            $this->app->setLocale($locale);

            foreach ([
                '404_cross_tenant_title',
                '404_cross_tenant_body',
                '404_cross_tenant_hint',
                '404_cross_tenant_back',
            ] as $key) {
                $this->assertNotSame(
                    'errors.'.$key,
                    __('errors.'.$key),
                    "clef [{$key}] non traduite en [{$locale}]"
                );
            }

            $this->assertNotSame(
                __('errors.404_message'),
                __('errors.404_cross_tenant_body'),
                "les deux messages doivent differer en [{$locale}]"
            );
        }

        $this->app->setLocale('fr');

        // Les textes ne parlent plus d'une Boucle, et ne nomment aucune
        // Organization — ni la sienne, ni l'etrangere.
        foreach (['404_cross_tenant_title', '404_cross_tenant_body', '404_cross_tenant_hint'] as $key) {
            $text = __('errors.'.$key);
            $this->assertStringNotContainsStringIgnoringCase('boucle', $text);
            $this->assertStringNotContainsString($this->other->name, $text);
            $this->assertStringNotContainsString($this->other->slug, $text);
        }
    }
}
