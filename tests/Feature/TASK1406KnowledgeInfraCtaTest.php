<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1406 — le bandeau d'infrastructure de l'Observatoire ne propose plus un
 * bouton incapable de resoudre la raison affichee.
 *
 * Le defaut : le bandeau agregeait TROIS causes de nature differente sous UN
 * SEUL bouton « Configurer l'IA », place hors de la boucle des raisons. Deux
 * de ces causes se resolvent bien sur l'ecran cible (credential, budget) ; la
 * troisieme — l'activation de la recherche semantique — est une gate
 * PLATEFORME, pilotee par variables d'environnement, qu'aucun ecran
 * d'Organization ne peut regler. L'admin suivait le bouton, arrivait sur un
 * formulaire IA correctement rempli, et n'y trouvait rien a changer.
 *
 * Les trois causes etaient DEJA distinctes dans
 * `OrganizationRagOverview::indexingAvailability()` : c'est le rendu qui les
 * confondait, pas le read model. Ce test mesure donc le HTML SERVI par le
 * fragment, jamais le tableau du read model — celui-ci etait deja juste.
 */
class TASK1406KnowledgeInfraCtaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // TASK-1369 : la famille d'embedding est CONSTRUITE, jamais heritee.
        // Le poste local renseigne `AI_EMBEDDING_PROVIDER=openrouter` ; sans
        // cette ligne, les credentials `openai` poses ici ne correspondraient
        // a aucune famille et les tests rougiraient pour une raison etrangere
        // a ce qu'ils eprouvent.
        config(['ai.default_for_embeddings' => 'openai']);
    }

    // ── 1. Gate plateforme fermee, seule ────────────────────────────────────

    public function test_the_platform_gate_states_who_enables_it_and_offers_no_button(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $this->usableCredential($organization);
        config()->set('ai.dossiers.semantic_search.enabled', false);

        $html = $this->fragment($organization, $admin);

        // La raison est bien affichee, et declaree non resoluble ici.
        $this->assertStringContainsString('data-knowledge-infra-reason="disabled"', $html);
        $this->assertStringContainsString('data-knowledge-infra-actionable="no"', $html);
        $this->assertStringContainsString(__('ai.observatory_infra_disabled'), $this->normalize($html));

        // C'est LA garde de cette TASK : aucun bouton, sous aucune forme.
        $this->assertStringNotContainsString($this->ctaLabel(), $html);
        $this->assertStringNotContainsString($this->configureUrl($organization), $html);
    }

    // ── 2. Credential absent, gate ouverte ──────────────────────────────────

    public function test_a_missing_credential_keeps_its_button(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $this->enableSemanticSearchFor($organization);
        // Aucune configuration IA du tout.

        $html = $this->fragment($organization, $admin);

        $this->assertStringContainsString('data-knowledge-infra-reason="no_credential"', $html);
        $this->assertStringContainsString('data-knowledge-infra-actionable="yes"', $html);
        $this->assertStringContainsString($this->ctaLabel(), $html);
        $this->assertStringContainsString($this->configureUrl($organization), $html);
    }

    /**
     * Le cas le plus deroutant, et la raison d'etre du message enrichi :
     * l'Organization A une configuration IA valide, mais d'une AUTRE famille
     * que celle de l'index. L'ecran de configuration lui parait correct.
     */
    public function test_a_family_mismatch_names_both_families_in_plain_words(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $this->enableSemanticSearchFor($organization);
        $this->usableCredential($organization, provider: 'openrouter');

        $texte = $this->normalize($this->fragment($organization, $admin));

        $this->assertStringContainsString(
            __('ai.observatory_infra_no_credential_family', ['expected' => 'OpenAI', 'configured' => 'OpenRouter']),
            $texte
        );
        $this->assertStringContainsString('OpenAI', $texte);
        $this->assertStringContainsString('OpenRouter', $texte);

        // Le message generique laisse la place au message circonstancie.
        $this->assertStringNotContainsString(__('ai.observatory_infra_no_credential'), $texte);
    }

    // ── 3. Plusieurs raisons simultanees ────────────────────────────────────

    public function test_a_resolvable_reason_keeps_its_button_while_the_platform_gate_gets_none(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        // Gate fermee ET credential d'une autre famille : les deux raisons.
        config()->set('ai.dossiers.semantic_search.enabled', false);
        $this->usableCredential($organization, provider: 'openrouter');

        $html = $this->fragment($organization, $admin);

        $this->assertStringContainsString('data-knowledge-infra-reason="disabled"', $html);
        $this->assertStringContainsString('data-knowledge-infra-reason="no_credential"', $html);

        // Un seul bouton, et il appartient a la raison resoluble.
        $this->assertSame(1, substr_count($html, $this->ctaLabel()));
        $this->assertSame(1, substr_count($html, 'data-knowledge-infra-actionable="yes"'));
        $this->assertSame(1, substr_count($html, 'data-knowledge-infra-actionable="no"'));

        // Et il est bien porte par le <li> du credential, jamais par celui de
        // la gate : on mesure l'ORDRE reel dans le HTML, pas une intention.
        $debutGate = strpos($html, 'data-knowledge-infra-reason="disabled"');
        $debutCredential = strpos($html, 'data-knowledge-infra-reason="no_credential"');
        $boutonPosition = strpos($html, $this->ctaLabel());

        $this->assertGreaterThan($debutGate, $debutCredential);
        $this->assertGreaterThan($debutCredential, $boutonPosition);
    }

    // ── 4. Aucune fuite ─────────────────────────────────────────────────────

    public function test_the_banner_never_leaks_the_credential_nor_the_platform_variables(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        config()->set('ai.dossiers.semantic_search.enabled', false);
        $this->usableCredential($organization, provider: 'openrouter', apiKey: 'sk-task1406-ne-doit-jamais-sortir');

        $html = $this->fragment($organization, $admin);

        $this->assertStringNotContainsString('sk-task1406-ne-doit-jamais-sortir', $html);
        $this->assertStringNotContainsString('DOSSIER_SEMANTIC_SEARCH', $html);
        $this->assertStringNotContainsString('AI_EMBEDDING_PROVIDER', $html);
        $this->assertStringNotContainsString('ai.default_for_embeddings', $html);
    }

    // ── Temoin d'instrument ─────────────────────────────────────────────────

    /**
     * Sans lui, un fragment vide, une 403 ou un bandeau qui aurait cesse de se
     * rendre passeraient TOUTES les gardes negatives ci-dessus — a commencer
     * par « aucun bouton ».
     */
    public function test_the_probe_really_renders_the_banner_and_its_button(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $this->enableSemanticSearchFor($organization);

        $html = $this->fragment($organization, $admin);

        $this->assertStringContainsString('data-knowledge-infra="unavailable"', $html);
        $this->assertStringContainsString(__('ai.observatory_infra_title'), $this->normalize($html));
        $this->assertStringContainsString($this->ctaLabel(), $html);

        // Et quand tout est reuni, le bandeau d'alerte disparait entierement :
        // la garde « aucun bouton » du test 1 doit distinguer « pas de bouton »
        // de « pas de bandeau ».
        //
        // TASK-1512 : ce temoin exigeait `data-knowledge-infra="available"`.
        // C'etait un PROXY — « available » signifiait alors « pas d'alerte ».
        // Depuis, l'etat sain se decline en trois valeurs comptees
        // (`available`, `nothing_indexed`, `no_source`), et cette Organization
        // de fixture n'a aucune source eligible : elle rend `no_source`.
        // L'intention du temoin est inchangee, on la dit maintenant
        // directement — l'ALERTE a disparu — au lieu de nommer une valeur qui
        // ne veut plus dire la meme chose.
        $this->usableCredential($organization);
        $htmlOk = $this->fragment($organization, $admin);

        $this->assertStringNotContainsString('data-knowledge-infra="unavailable"', $htmlOk, 'le bandeau d alerte doit disparaitre quand tout est reuni');
        $this->assertStringNotContainsString(__('ai.observatory_infra_title'), $this->normalize($htmlOk));
        $this->assertStringNotContainsString($this->ctaLabel(), $htmlOk);

        // Et le bandeau se rend bien : sans cette ligne, une page vide passerait.
        $this->assertMatchesRegularExpression('/data-knowledge-infra="(available|nothing_indexed|no_source)"/', $htmlOk);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /** @return array{0: Organization, 1: User} */
    private function organizationWithAdmin(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->create(['organization_id' => $organization->id]);
        $organization->update(['admin_id' => $admin->id]);

        return [$organization->fresh(), $admin];
    }

    private function enableSemanticSearchFor(Organization ...$organizations): void
    {
        config()->set('ai.dossiers.semantic_search.enabled', true);
        config()->set('ai.dossiers.semantic_search.organization_ids', array_map(
            fn (Organization $o): string => (string) $o->id,
            $organizations
        ));
    }

    private function usableCredential(
        Organization $organization,
        string $provider = 'openai',
        string $apiKey = 'sk-task1406-tenant',
    ): void {
        OrganizationAiSetting::query()->updateOrCreate(
            ['organization_id' => $organization->id],
            [
                'provider' => $provider,
                'model' => 'gpt-4o-mini',
                'api_key' => $apiKey,
                'monthly_budget_usd' => 10.00,
                'is_enabled' => true,
            ]
        );
    }

    /**
     * Le libelle du bouton TEL QU'IL APPARAIT dans le HTML servi.
     *
     * « Configurer l'IA » contient une apostrophe, que Blade echappe en
     * `&#039;`. Chercher la chaine brute dans le HTML ne trouve donc JAMAIS
     * rien : une garde negative ecrite ainsi est verte quoi qu'il arrive, et
     * une garde positive rouge quoi qu'il arrive. Les deux sens passent par
     * ici.
     */
    private function ctaLabel(): string
    {
        return e(__('ai.observatory_infra_configure'));
    }

    private function configureUrl(Organization $organization): string
    {
        return route('organization.admin.ai', ['organization' => $organization->slug]);
    }

    private function fragment(Organization $organization, User $admin): string
    {
        $reponse = $this->actingAs($admin)->get(route('organization.admin.ai-knowledge.live', [
            'organization' => $organization->slug,
        ]));

        $reponse->assertOk();

        return $reponse->getContent();
    }

    /**
     * Le texte lu par l'operateur : balises retirees, entites decodees,
     * espaces ramenes a un seul. Indispensable pour comparer une phrase
     * traduite au rendu — le message et le bouton vivent dans deux elements
     * distincts, et les apostrophes francaises sortent echappees en HTML.
     */
    private function normalize(string $html): string
    {
        $texte = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $texte));
    }
}
