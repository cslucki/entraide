<?php

namespace Tests\Feature;

use App\Ai\ProviderResolver;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Support\Ai\AiSurfaceGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TASK-1813 correctif 01 — la porte IA relisait sa configuration a chaque
 * surface de la meme page.
 *
 * ## Le defaut, mesure avant tout code
 *
 * `AiSurfaceGate::availableFor()` portait DEJA le bon memo, clef par
 * `organizationId`. Il n'a jamais servi : la classe n'etait liee a RIEN dans
 * le conteneur, donc chaque `app()` et chaque `@inject` de Blade fabriquait
 * une instance NEUVE, au memo vide.
 *
 * | mesure, une seule requete HTTP | `/boucles` | `/dashboard` |
 * |---|---|---|
 * | instances de `AiSurfaceGate` | 2 | 4 |
 * | instances de `ProviderResolver` | 3 | 6 |
 * | lectures de `organization_ai_settings` | 3 | 3 |
 *
 * Neuf lectures sur l'espace de travail d'une Boucle.
 *
 * ## Ce que la correction fait, et ce qu'elle ne fait PAS
 *
 * Elle lie la porte en `scoped()`, et fait passer `AiFabContext` par la porte
 * plutot que par l'autorite — second chemin qui contournait le memo.
 *
 * Elle ne memoise PAS `ProviderResolver`. C'est l'autorite qui depense et qui
 * applique la configuration IA du tenant : `resolve()` relit a chaque appel.
 * **On memoise une porte d'AFFICHAGE, jamais une autorite.**
 *
 * ## Pourquoi `scoped` et pas `singleton`
 *
 * Le conteneur vide les instances `scoped` entre deux requetes HTTP ET entre
 * deux jobs de file. Un commit Livewire EST une requete HTTP : aucun verdict
 * ne lui survit. Un `singleton` aurait garde un verdict perime pendant toute
 * la vie d'un `queue:work`.
 */
class TASK1813SocleRequetesCommunesTest extends TestCase
{
    use RefreshDatabase;

    private function organizationWithAi(string $slug, bool $enabled = true): Organization
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
            'is_enabled' => $enabled,
        ]);

        return $organization;
    }

    // ────────────────────────────────────────────────────────────────────────
    // La correction elle-meme
    // ────────────────────────────────────────────────────────────────────────

    /**
     * La garde qui empeche la regression SILENCIEUSE.
     *
     * Le defaut corrige ici ne se voyait par AUCUN test fonctionnel : la page
     * rendait la bonne chose, elle la payait simplement trois fois. Seule
     * l'identite d'instance le rend visible.
     */
    public function test_la_porte_ia_est_la_meme_instance_dans_une_requete(): void
    {
        $this->assertSame(
            app(AiSurfaceGate::class),
            app(AiSurfaceGate::class),
            'AiSurfaceGate doit etre lie en scoped() : sans cela son memo, pourtant ecrit et correctement clef, ne sert a aucun appelant.'
        );
    }

    /**
     * `scoped` et pas `singleton` : le verdict ne doit pas traverser deux
     * requetes. On simule la frontiere comme le conteneur la pose.
     */
    public function test_le_verdict_ne_survit_pas_a_la_frontiere_d_une_requete(): void
    {
        $premiere = app(AiSurfaceGate::class);

        app()->forgetScopedInstances();

        $this->assertNotSame(
            $premiere,
            app(AiSurfaceGate::class),
            'Un verdict qui survit a la frontiere d une requete est un verdict perime en puissance.'
        );
    }

    // ────────────────────────────────────────────────────────────────────────
    // SABOTAGE — Organization = Tenant
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Le memo est clef par `organizationId`. S'il ne l'etait pas, la seconde
     * Organization heriterait du verdict de la premiere — et une Organization
     * dont l'IA est ETEINTE verrait la porte ouverte.
     *
     * C'est la garde bloquante de cette TASK : elle doit rougir si la clef
     * disparait.
     */
    public function test_le_memo_de_la_porte_ne_traverse_jamais_deux_organizations(): void
    {
        $avecIa = $this->organizationWithAi('t1813-avec-ia', enabled: true);
        $sansIa = $this->organizationWithAi('t1813-sans-ia', enabled: false);

        $porte = app(AiSurfaceGate::class);

        // L'ordre compte : on remplit d'abord le memo avec un verdict VRAI.
        $this->assertTrue(
            $porte->availableFor((string) $avecIa->id),
            'Precondition : la premiere Organization doit avoir une IA utilisable.'
        );

        // La MEME instance, interrogee pour une AUTRE Organization.
        $this->assertFalse(
            $porte->availableFor((string) $sansIa->id),
            'FUITE INTER-TENANT : la seconde Organization a herite du verdict de la premiere.'
        );

        // Et le sens inverse, pour que la garde ne tienne pas par hasard.
        $this->assertTrue(
            $porte->availableFor((string) $avecIa->id),
            'Le verdict de la premiere Organization a ete ecrase par celui de la seconde.'
        );
    }

    /**
     * Une Organization inconnue ne doit rien heriter non plus.
     */
    public function test_une_organization_sans_reglage_n_herite_d_aucun_verdict(): void
    {
        $avecIa = $this->organizationWithAi('t1813-herite-pas', enabled: true);
        $nue = Organization::factory()->create(['slug' => 't1813-nue']);

        $porte = app(AiSurfaceGate::class);
        $porte->availableFor((string) $avecIa->id);

        $this->assertFalse(
            $porte->availableFor((string) $nue->id),
            'Une Organization sans reglage IA ne doit hériter d aucun verdict memoise.'
        );
    }

    // ────────────────────────────────────────────────────────────────────────
    // L'autorite n'est PAS memoisee
    // ────────────────────────────────────────────────────────────────────────

    /**
     * `ProviderResolver` reste neuf a chaque resolution : aucune liaison n'a
     * ete posee sur lui. C'est ce qui garantit que la configuration IA du
     * tenant est relue par la garde qui depense.
     */
    public function test_l_autorite_qui_depense_n_est_pas_partagee(): void
    {
        $this->assertNotSame(
            app(ProviderResolver::class),
            app(ProviderResolver::class),
            'ProviderResolver ne doit etre ni scoped ni singleton : resolve() doit relire la configuration a chaque appel.'
        );
    }

    // ────────────────────────────────────────────────────────────────────────
    // Le cout, en nombre de requetes
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Le cout de LA PORTE ne suit plus le nombre de surfaces rendues.
     *
     * L'assertion porte sur les lectures qui passent par `AiSurfaceGate`, et
     * non sur toutes les lectures de la table. La distinction n'est pas un
     * confort : `organization_ai_settings` porte aussi le mode de credit, que
     * `AiEconomicGuard` lit par `AiUserCreditSettings` pour une question
     * DIFFERENTE, avec sa propre autorite. Le credit n'est pas memoise, et ne
     * doit pas l'etre — il n'est pas dans le perimetre de cette TASK.
     *
     * Mesure avant correction : trois lectures par la porte sur une seule
     * page, pour une seule Organization.
     */
    public function test_la_porte_ne_lit_qu_une_fois_par_organization(): void
    {
        $organization = $this->organizationWithAi('t1813-cout');
        $user = User::factory()->create(['organization_id' => $organization->id]);

        $parLaPorte = [];
        DB::listen(function ($requete) use (&$parLaPorte): void {
            if (! str_contains($requete->sql, 'organization_ai_settings')) {
                return;
            }

            // On ne retient que ce qui vient de la porte : le reste de la
            // table appartient a d'autres autorites.
            $vientDeLaPorte = false;
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 60) as $cadre) {
                if (($cadre['class'] ?? null) === \App\Support\Ai\AiSurfaceGate::class) {
                    $vientDeLaPorte = true;
                    break;
                }
            }

            if ($vientDeLaPorte) {
                $parLaPorte[] = (string) ($requete->bindings[0] ?? '?');
            }
        });

        $this->actingAs($user)->get('/boucles')->assertOk();

        $this->assertSame(
            count(array_unique($parLaPorte)),
            count($parLaPorte),
            'La porte a relu la meme Organization plusieurs fois sur une seule page : son memo ne sert pas. '
            .'Lectures par la porte = '.count($parLaPorte).'.'
        );

        $this->assertLessThanOrEqual(
            1,
            count($parLaPorte),
            'Une seule Organization est consultee sur cette page : la porte ne doit la lire qu une fois.'
        );
    }
}
