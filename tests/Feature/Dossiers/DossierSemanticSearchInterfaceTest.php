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
        $response->assertSee('Rechercher dans ce Dossier');
        $response->assertSee('Posez une question sur les articles et documents de ce Dossier.');
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
            ->assertDontSee('Rechercher dans ce Dossier');
    }

    public function test_semantic_search_interface_renders_french_texts_without_technical_details(): void
    {
        [$organization, $owner, $dossier] = $this->fixture(preferredLocale: 'fr');
        $this->enableSemanticSearchFor($organization);

        $response = $this->actingAs($owner)->get($this->dossierUrl($organization, $dossier));

        $response->assertOk();
        $response->assertSee('Rechercher');
        $response->assertSee('Résultats');
        $response->assertSee('Aucun résultat pertinent trouvé dans ce Dossier.');
        // TASK-1267 volet A : le wording ne parle plus des seuls articles.
        $response->assertDontSee('dans les articles du dossier');
        $response->assertDontSee('dans les articles de ce dossier');
        $response->assertSee('La recherche est temporairement indisponible. Réessayez dans quelques instants.');
        $response->assertSee('Lire l’article');
        $response->assertSee('Ouvrir le document');
        // TASK-1315 : l'assertion portait sur la page ENTIERE. Depuis que le
        // Shell « BouclePro IA » est monte dans le layout membre, son composeur
        // y apporte legitimement son propre `wire:model` — l'assertion globale
        // ne dit donc plus rien sur cette surface-ci.
        //
        // L'invariant REEL, lui, est intact et se dit mieux : la recherche
        // semantique du Dossier est une surface Alpine, sans aller-retour
        // serveur a la frappe. On le prouve sur SON markup : le champ est lie
        // par `x-model`, et il n'y a aucun `wire:model` dans la section.
        $section = $this->semanticSearchSection($response->getContent());
        $this->assertStringContainsString('x-model="query"', $section);
        $this->assertStringNotContainsString('wire:model', $section,
            'La recherche semantique du Dossier doit rester une surface Alpine, sans liaison Livewire.');

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
            ->assertSee('Search this Folder')
            ->assertSee('Ask a question about the articles and documents in this Folder.')
            ->assertSee('Search')
            ->assertSee('No relevant result found in this Folder.')
            ->assertDontSee('folder’s articles')
            ->assertSee('Search is temporarily unavailable. Please try again in a moment.')
            ->assertSee('Read article')
            ->assertSee('Open document');
    }

    /**
     * TASK-1267 : un resultat fichier (slug/title nuls) doit s'afficher avec
     * son `filename` pour titre, « Ouvrir le document » pour lien, et une
     * cle DOM qui ne collisionne pas entre deux fichiers. Le rendu serveur ne
     * fait pas tourner Alpine : on verifie ici le cablage du template (les
     * trois bindings passent par des methodes du composant, plus jamais par
     * `result.slug` / `result.title` en dur) et la presence des deux libelles
     * dans le tableau i18n transmis a `x-data`. Le comportement des methodes
     * est prouve par `node --test tests/js/dossier-semantic-search-result.test.mjs`.
     */
    public function test_result_markup_is_bound_by_source_aware_alpine_methods(): void
    {
        [$organization, $owner, $dossier] = $this->fixture(preferredLocale: 'fr');
        $this->enableSemanticSearchFor($organization);

        $response = $this->actingAs($owner)->get($this->dossierUrl($organization, $dossier));

        $response->assertOk();
        // cle DOM robuste, sans slug obligatoire ; TASK-1271 : la liste est
        // groupee par document (`groupedResults()`), la cle est celle du document
        $response->assertSee('x-for="result in groupedResults()"', false);
        $response->assertSee(':key="documentKey(result)"', false);
        $response->assertDontSee(':key="resultKey(result)"', false);
        $response->assertDontSee('${result.slug}', false);
        // titre : filename cote fichier, title cote article
        $response->assertSee('x-text="resultTitle(result)"', false);
        $response->assertDontSee('x-text="result.title"', false);
        // libelle du lien par source
        $response->assertSee('x-text="resultLinkLabel(result)"', false);
        $response->assertSee(':data-source-type="result.source_type"', false);
        // volet B : fichier previsualisable -> bouton vers la modale d'apercu
        // existante ; sinon lien `citation_url` (telechargement / article).
        $response->assertSee('x-if="canPreviewResult(result)"', false);
        $response->assertSee('@click="openResultPreview(result)"', false);
        $response->assertSee('data-semantic-result-preview', false);
        $response->assertSee('x-if="!canPreviewResult(result)"', false);
        $response->assertSee(':href="result.citation_url"', false);
        // la modale d'apercu est bien dans la page (portee `dossierFilesCard`)
        $response->assertSee('x-if="showPreviewModal"', false);
        // les deux libelles sont transmis au composant (FR)
        $response->assertSee('Lire l’article');
        $response->assertSee('Ouvrir le document');
        $this->assertEndpointUrlPresent($response->getContent(), $organization, $dossier);
    }

    /**
     * TASK-1271 : chaque document n'apparait qu'une fois, represente par son
     * meilleur passage ; quand d'autres passages du meme document ont ete
     * retrouves, une mention discrete le dit, au pluriel correct (FR + EN).
     * Le groupement lui-meme (Q11 -> 1 document, Q15 -> 2) est prouve par
     * `node --test tests/js/dossier-semantic-search-result.test.mjs`.
     */
    public function test_result_list_is_grouped_by_document_with_other_passages_mention(): void
    {
        [$organization, $owner, $dossier] = $this->fixture(preferredLocale: 'fr');
        $this->enableSemanticSearchFor($organization);

        $response = $this->actingAs($owner)->get($this->dossierUrl($organization, $dossier));

        $response->assertOk();
        $response->assertSee('x-show="otherPassagesCount(result) > 0"', false);
        $response->assertSee('x-text="otherPassagesLabel(result)"', false);
        $response->assertSee('data-semantic-result-other-passages', false);
        // les deux gabarits (singulier / pluriel) sont transmis au composant
        $response->assertSee('+ 1 autre passage');
        $response->assertSee('+ :count autres passages');
        $response->assertDontSee('other passage');
        // le bouton d'apercu et le lien citation_url de T1267 sont toujours la
        $response->assertSee('@click="openResultPreview(result)"', false);
        $response->assertSee(':href="result.citation_url"', false);
        $this->assertEndpointUrlPresent($response->getContent(), $organization, $dossier);
    }

    public function test_other_passages_mention_is_translated_in_english(): void
    {
        [$organization, $owner, $dossier] = $this->fixture(preferredLocale: 'en');
        $this->enableSemanticSearchFor($organization);

        $this->actingAs($owner)
            ->get($this->dossierUrl($organization, $dossier))
            ->assertOk()
            ->assertSee('+ 1 other passage')
            ->assertSee('+ :count other passages')
            ->assertDontSee('autres passages');
    }

    /**
     * TASK-1273 : l'en-tete des resultats annonce les DOCUMENTS (lignes
     * affichees depuis T1271) ET les PASSAGES : « 1 document · 5 passages ».
     * Le rendu serveur ne fait pas tourner Alpine : on verifie le cablage
     * (`resultCountLabel()`) et la transmission du gabarit + des quatre
     * fragments singulier / pluriel. Les quatre combinaisons (1/1, 1/N, N/N,
     * N/M) sont prouvees par `node --test tests/js/dossier-semantic-search-result.test.mjs`.
     */
    public function test_results_count_header_announces_documents_and_passages_in_french(): void
    {
        [$organization, $owner, $dossier] = $this->fixture(preferredLocale: 'fr');
        $this->enableSemanticSearchFor($organization);

        $response = $this->actingAs($owner)->get($this->dossierUrl($organization, $dossier));

        $response->assertOk();
        $response->assertSee('x-text="resultCountLabel()"', false);
        // gabarit : point median entre les deux compteurs
        $response->assertSee(':documents · :passages');
        // quatre fragments : pluriel de chaque cote
        $response->assertSee('1 document');
        $response->assertSee(':count documents');
        $response->assertSee('1 passage');
        $response->assertSee(':count passages');
        // l'ancienne formulation a disparu
        $response->assertDontSee('passage(s) trouv');
        $response->assertDontSee('passage(s) found');
        // le groupement T1271 et le contrat T1267 ne bougent pas
        $response->assertSee('x-for="result in groupedResults()"', false);
        $response->assertSee(':href="result.citation_url"', false);
        $this->assertEndpointUrlPresent($response->getContent(), $organization, $dossier);
    }

    public function test_results_count_header_announces_documents_and_passages_in_english(): void
    {
        [$organization, $owner, $dossier] = $this->fixture(preferredLocale: 'en');
        $this->enableSemanticSearchFor($organization);

        $response = $this->actingAs($owner)->get($this->dossierUrl($organization, $dossier));

        $response->assertOk();
        $response->assertSee('x-text="resultCountLabel()"', false);
        $response->assertSee(':documents · :passages');
        $response->assertSee('1 document');
        $response->assertSee(':count documents');
        $response->assertSee('1 passage');
        $response->assertSee(':count passages');
        $response->assertDontSee('passage(s) found');
        $response->assertDontSee('passage(s) trouv');
        $this->assertEndpointUrlPresent($response->getContent(), $organization, $dossier);
    }

    public function test_result_link_labels_are_translated_in_english(): void
    {
        [$organization, $owner, $dossier] = $this->fixture(preferredLocale: 'en');
        $this->enableSemanticSearchFor($organization);

        $response = $this->actingAs($owner)->get($this->dossierUrl($organization, $dossier));

        $response->assertOk();
        $response->assertSee('Read article');
        $response->assertSee('Open document');
        $response->assertDontSee('Ouvrir le document');
        $this->assertEndpointUrlPresent($response->getContent(), $organization, $dossier);
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

    /**
     * Le markup de la SEULE recherche semantique du Dossier — de son `x-data`
     * jusqu'a la fermeture de sa `<section>`. Aucune `<section>` imbriquee dans
     * ce bloc, la premiere fermeture est donc la sienne.
     */
    private function semanticSearchSection(string $html): string
    {
        $start = strpos($html, 'dossierSemanticArticleSearch');
        $this->assertNotFalse($start, 'La section de recherche semantique est absente de la page.');

        $end = strpos($html, '</section>', $start);
        $this->assertNotFalse($end, 'La section de recherche semantique n\'est pas refermee.');

        return substr($html, $start, $end - $start);
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
                    'documentsOne' => __('dossiers.semantic_search_results_documents_one'),
                    'documentsMany' => __('dossiers.semantic_search_results_documents_many'),
                    'passagesOne' => __('dossiers.semantic_search_results_passages_one'),
                    'passagesMany' => __('dossiers.semantic_search_results_passages_many'),
                    'readArticle' => __('dossiers.semantic_search_read_article'),
                    'openDocument' => __('dossiers.semantic_search_open_document'),
                    'otherPassagesOne' => __('dossiers.semantic_search_other_passages_one'),
                    'otherPassagesMany' => __('dossiers.semantic_search_other_passages_many'),
                ],
            ])->toHtml()),
            'The server-generated semantic search endpoint URL is missing from the rendered Dossier page.'
        );
    }
}
