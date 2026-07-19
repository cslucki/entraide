<?php

namespace Tests\Feature\Dossiers;

use App\Models\Dossier;
use App\Models\DossierMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Tests\TestCase;

class DossierSemanticSearchInterfaceTest extends TestCase
{
    public function test_pilot_owner_sees_semantic_search_interface(): void
    {
        [$organization, $owner, $dossier] = $this->fixture(preferredLocale: 'fr');
        $this->enableSemanticSearchFor($organization);

        $response = $this->actingAs($owner)->get($this->dossierUrl($organization, $dossier));

        $response->assertOk();
        $response->assertSee('Rechercher dans les articles du dossier');
        $response->assertSee('Décrivez une idée, un besoin ou un sujet. BouclePro retrouvera les passages les plus proches dans les articles de ce dossier.');
        $response->assertSee('dossierSemanticArticleSearch', false);
        $response->assertSee('x-model="query"', false);
        $response->assertSee('minlength="2"', false);
        $response->assertSee('maxlength="500"', false);
        $response->assertSee('autocomplete="off"', false);
        $this->assertEndpointUrlPresent($response->getContent(), $organization, $dossier);
    }

    public function test_pilot_reader_member_sees_semantic_search_interface(): void
    {
        [$organization, $owner, $dossier] = $this->fixture();
        $reader = $this->user($organization);
        $this->addMember($organization, $dossier, $reader, DossierMember::ROLE_READER, $owner);
        $this->enableSemanticSearchFor($organization);

        $this->actingAs($reader)
            ->get($this->dossierUrl($organization, $dossier))
            ->assertOk()
            ->assertSee('dossierSemanticArticleSearch', false);
    }

    public function test_pilot_editor_member_sees_semantic_search_interface(): void
    {
        [$organization, $owner, $dossier] = $this->fixture();
        $editor = $this->user($organization);
        $this->addMember($organization, $dossier, $editor, DossierMember::ROLE_EDITOR, $owner);
        $this->enableSemanticSearchFor($organization);

        $this->actingAs($editor)
            ->get($this->dossierUrl($organization, $dossier))
            ->assertOk()
            ->assertSee('dossierSemanticArticleSearch', false);
    }

    public function test_non_allowlisted_organization_does_not_see_semantic_search_interface(): void
    {
        [$organization, $owner, $dossier] = $this->fixture();
        $otherOrganization = Organization::factory()->create(['slug' => 'org-'.Str::uuid(), 'is_active' => true]);
        $this->enableSemanticSearchFor($otherOrganization);

        $this->actingAs($owner)
            ->get($this->dossierUrl($organization, $dossier))
            ->assertOk()
            ->assertDontSee('dossierSemanticArticleSearch', false)
            ->assertDontSee('Rechercher dans les articles du dossier');
    }

    public function test_semantic_search_interface_renders_french_texts_without_technical_details(): void
    {
        [$organization, $owner, $dossier] = $this->fixture(preferredLocale: 'fr');
        $this->enableSemanticSearchFor($organization);

        $response = $this->actingAs($owner)->get($this->dossierUrl($organization, $dossier));

        $response->assertOk();
        $response->assertSee('Rechercher');
        $response->assertSee('Résultats');
        $response->assertSee('Aucun passage pertinent trouvé dans les articles de ce dossier.');
        $response->assertSee('La recherche est temporairement indisponible. Réessayez dans quelques instants.');
        $response->assertSee('Lire l’article');
        $response->assertDontSee('wire:model', false);
        $response->assertDontSee('Page X');
        $response->assertDontSee('distance');
        $response->assertDontSee('provider');
        $response->assertDontSee('embedding');
    }

    public function test_semantic_search_interface_renders_english_texts(): void
    {
        [$organization, $owner, $dossier] = $this->fixture(preferredLocale: 'en');
        $this->enableSemanticSearchFor($organization);

        $this->actingAs($owner)
            ->get($this->dossierUrl($organization, $dossier))
            ->assertOk()
            ->assertSee('Search this folder’s articles')
            ->assertSee('Describe an idea, a need, or a topic. BouclePro will retrieve the closest passages from this folder’s articles.')
            ->assertSee('Search')
            ->assertSee('No relevant passage found in this folder’s articles.')
            ->assertSee('Search is temporarily unavailable. Please try again in a moment.')
            ->assertSee('Read article');
    }

    public function test_unauthorized_user_cannot_see_dossier_or_semantic_search_interface(): void
    {
        [$organization, , $dossier] = $this->fixture();
        $stranger = $this->user($organization);
        $this->enableSemanticSearchFor($organization);

        $this->actingAs($stranger)
            ->get($this->dossierUrl($organization, $dossier))
            ->assertForbidden()
            ->assertDontSee('dossierSemanticArticleSearch', false);
    }

    private function enableSemanticSearchFor(Organization $organization): void
    {
        config()->set('ai.dossiers.semantic_search.enabled', true);
        config()->set('ai.dossiers.semantic_search.organization_ids', [$organization->id]);
    }

    /**
     * @return array{0: Organization, 1: User, 2: Dossier}
     */
    private function fixture(string $preferredLocale = 'fr'): array
    {
        $organization = Organization::factory()->create([
            'slug' => 'org-'.Str::uuid(),
            'is_active' => true,
        ]);
        $owner = $this->user($organization, $preferredLocale);

        $dossier = Dossier::create([
            'organization_id' => $organization->id,
            'owner_id' => $owner->id,
            'name' => 'Semantic dossier',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        return [$organization, $owner, $dossier];
    }

    private function user(Organization $organization, string $preferredLocale = 'fr'): User
    {
        return User::factory()->create([
            'organization_id' => $organization->id,
            'preferred_locale' => $preferredLocale,
        ]);
    }

    private function addMember(Organization $organization, Dossier $dossier, User $member, string $role, User $owner): void
    {
        DossierMember::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'user_id' => $member->id,
            'role' => $role,
            'added_by' => $owner->id,
        ]);

        $dossier->syncVisibility();
    }

    private function dossierUrl(Organization $organization, Dossier $dossier): string
    {
        return route('organization.dossiers.show', [
            'organization' => $organization,
            'dossier' => $dossier,
        ]);
    }

    private function assertEndpointUrlPresent(string $html, Organization $organization, Dossier $dossier): void
    {
        $endpoint = route('organization.dossiers.semantic-search', [
            'organization' => $organization,
            'dossier' => $dossier,
        ]);

        $this->assertTrue(
            str_contains($html, Js::from([
                'endpoint' => $endpoint,
                'i18n' => [
                    'validationTooShort' => __('dossiers.semantic_search_validation_too_short'),
                    'unavailable' => __('dossiers.semantic_search_unavailable'),
                    'genericError' => __('dossiers.semantic_search_generic_error'),
                    'passage' => __('dossiers.semantic_search_passage'),
                    'resultsCount' => __('dossiers.semantic_search_results_count'),
                ],
            ])->toHtml()),
            'The server-generated semantic search endpoint URL is missing from the rendered Dossier page.'
        );
    }
}
