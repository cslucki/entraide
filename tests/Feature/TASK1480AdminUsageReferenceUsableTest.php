<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\UsageReference;
use App\Models\User;
use App\Services\UsageReference\UsageReferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1480 — `/admin/usage-references` devient utilisable.
 *
 * ## Le defaut n'etait pas le vocabulaire
 *
 * Le mot « brouillon » est juste, et il reste. Ce qui rendait l'ecran
 * incomprehensible tient en une phrase, mesuree avant tout code :
 *
 * **on ne pouvait pas relire ce qui etait publie.**
 *
 * `edit` etait la SEULE vue du texte, et elle `abort(404)` sur une version
 * publiee — a raison, puisqu'une version publiee est immuable. Mais aucune
 * autre route ne montrait le contenu. Un SuperAdmin voyait donc une table de
 * versions dont il ne pouvait ouvrir aucune.
 *
 * Consequence directe : faire evoluer un repere publie demandait de deviner
 * « Nouveau brouillon », puis de RETAPER un texte que rien n'affichait.
 *
 * ## Les trois gestes ajoutes
 *
 * | Geste | Ce qu'il resout |
 * |---|---|
 * | `show` | lire une version — publiee, brouillon ou retiree |
 * | `create?from=` | « Modifier » ouvre un brouillon v+1 PRE-REMPLI |
 * | `<details>` | l'historique se replie au lieu de noyer l'etat courant |
 *
 * La previsualisation n'est pas une quatrieme chose : previsualiser un
 * brouillon, c'est le LIRE tel que le Shell le recevra. Une seconde mise en
 * page « pour l'apercu » serait une deuxieme verite.
 *
 * ## Ce qui n'a pas bouge
 *
 * Aucune migration, aucune table, aucun CMS, aucun redesign de
 * l'administration. Une version publiee reste immuable, et la publication reste
 * un geste humain explicite : `?from=` ne cree RIEN, il pre-remplit un
 * formulaire.
 */
class TASK1480AdminUsageReferenceUsableTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private UsageReferenceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = Organization::factory()->create(['is_active' => true, 'slug' => 'org-1480']);

        $this->superAdmin = User::factory()->complete()->create([
            'organization_id' => $organization->id,
            'is_admin' => true,
            'preferred_locale' => 'fr',
        ]);

        $this->service = app(UsageReferenceService::class);

        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // A. Le geste qui manquait : LIRE
    // =====================================================================

    /**
     * Le coeur de la tranche. Avant, ce contenu n'etait affiche nulle part une
     * fois publie.
     */
    public function test_a_published_reference_can_finally_be_read(): void
    {
        $published = $this->publish('agenda', 'fr', 'L\'agenda', 'Vous retrouvez ici les rencontres de vos Boucles.');

        $html = $this->read($published);

        $this->assertStringContainsString(e('L\'agenda'), $html);
        $this->assertStringContainsString(e('Vous retrouvez ici les rencontres de vos Boucles.'), $html);
        $this->assertStringContainsString('data-usage-reference-show-content', $html);
        $this->assertStringContainsString('data-usage-reference-state="published"', $html);
    }

    /**
     * Et `edit` continue de refuser une version publiee : l'immuabilite n'a pas
     * ete echangee contre la lisibilite.
     */
    public function test_reading_did_not_make_a_published_version_editable(): void
    {
        $published = $this->publish('agenda', 'fr', 'L\'agenda', 'Contenu publie.');

        $this->actingAs($this->superAdmin)
            ->get(route('admin.usage-references.edit', $published))
            ->assertNotFound();
    }

    /** La meme vue previsualise un brouillon — et dit qu'il n'est PAS servi. */
    public function test_previewing_a_draft_says_it_is_not_live(): void
    {
        $draft = $this->service->createDraft('agenda', 'fr', 'Brouillon', 'Texte en attente.', $this->superAdmin);

        $html = $this->read($draft);

        $this->assertStringContainsString(e('Texte en attente.'), $html);
        $this->assertStringContainsString('data-usage-reference-not-live', $html);
        $this->assertStringContainsString(e(__('admin.usage_reference_draft_not_live')), $html);
    }

    /** Une version retiree se relit aussi : l'historique doit rester consultable. */
    public function test_a_retired_version_can_still_be_read(): void
    {
        $published = $this->publish('agenda', 'fr', 'L\'agenda', 'Texte retire ensuite.');
        $this->service->retire($published, $this->superAdmin);

        $html = $this->read($published->fresh());

        $this->assertStringContainsString(e('Texte retire ensuite.'), $html);
        $this->assertStringContainsString('data-usage-reference-state="retired"', $html);
    }

    // =====================================================================
    // B. « Modifier » n'est plus une page blanche
    // =====================================================================

    public function test_editing_a_published_reference_opens_a_prefilled_draft(): void
    {
        $published = $this->publish('agenda', 'fr', 'L\'agenda', 'Le texte en ligne, qu\'il ne faut pas retaper.');

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.usage-references.create', [
                'surface' => 'agenda', 'locale' => 'fr', 'from' => $published->id,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(e('Le texte en ligne, qu\'il ne faut pas retaper.'), $html);
        $this->assertStringContainsString('value="'.e('L\'agenda').'"', $html);

        // Et l'ecran DIT d'ou vient ce texte : sans cela, on croirait editer la
        // version publiee elle-meme.
        $this->assertStringContainsString('data-usage-reference-from="'.$published->id.'"', $html);
    }

    /**
     * `?from=` ne cree RIEN. C'est un pre-remplissage de formulaire : le
     * brouillon n'existe qu'apres un `store()`, et la version publiee reste en
     * ligne jusqu'a une publication humaine.
     */
    public function test_prefilling_creates_nothing_and_the_published_version_stays_live(): void
    {
        $published = $this->publish('agenda', 'fr', 'L\'agenda', 'Toujours en ligne.');
        $before = UsageReference::query()->count();

        $this->actingAs($this->superAdmin)
            ->get(route('admin.usage-references.create', ['from' => $published->id]))
            ->assertOk();

        $this->assertSame($before, UsageReference::query()->count(), 'ouvrir un formulaire ne cree pas de version');
        $this->assertTrue($published->fresh()->isPublished());
        $this->assertSame('Toujours en ligne.', app(\App\Services\UsageReference\UsageReferenceResolver::class)->resolve('agenda', 'fr')?->content);
    }

    /** Un `from` inconnu n'explose pas : le formulaire s'ouvre vide. */
    public function test_an_unknown_source_opens_an_empty_form(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('admin.usage-references.create', ['from' => '01a00000-0000-7000-8000-000000000000']))
            ->assertOk()
            ->assertDontSee('data-usage-reference-from=', false);
    }

    // =====================================================================
    // C. L'ecran repond a « qu'est-ce qui est en ligne ? »
    // =====================================================================

    public function test_the_index_states_what_is_live_and_what_is_waiting(): void
    {
        $this->publish('agenda', 'fr', 'L\'agenda v1', 'Version en ligne.');
        $draft = $this->service->createDraft('agenda', 'fr', 'L\'agenda v2', 'Version proposee.', $this->superAdmin);

        $html = $this->index();

        $this->assertStringContainsString('data-usage-reference-live="agenda:fr" data-usage-reference-live-version="1"', $html);
        $this->assertStringContainsString('data-usage-reference-draft="agenda:fr"', $html);

        // Le patron demande : Publie v1 · Brouillon v2 → modifier, previsualiser, publier.
        $this->assertStringContainsString('data-usage-reference-edit-draft="'.$draft->id.'"', $html);
        $this->assertStringContainsString('data-usage-reference-preview="'.$draft->id.'"', $html);
        $this->assertStringContainsString('data-usage-reference-publish="'.$draft->id.'"', $html);
    }

    /** Publie seul : Voir, Modifier, Historique — et rien qui vise un brouillon absent. */
    public function test_a_published_reference_alone_offers_view_and_amend(): void
    {
        $published = $this->publish('agenda', 'fr', 'L\'agenda', 'En ligne.');

        $html = $this->index();

        $this->assertStringContainsString('data-usage-reference-view="'.$published->id.'"', $html);
        $this->assertStringContainsString('data-usage-reference-amend="'.$published->id.'"', $html);
        $this->assertStringNotContainsString('data-usage-reference-edit-draft=', $html);
        $this->assertStringNotContainsString('data-usage-reference-preview=', $html);
    }

    /**
     * Quand un brouillon existe, « Modifier » sur la version publiee n'est PAS
     * propose : il creerait un second brouillon concurrent pour le meme couple,
     * et l'ecran ne saurait plus lequel « Publier » designe.
     */
    public function test_amending_is_not_offered_while_a_draft_is_pending(): void
    {
        $published = $this->publish('agenda', 'fr', 'L\'agenda v1', 'En ligne.');
        $this->service->createDraft('agenda', 'fr', 'L\'agenda v2', 'En attente.', $this->superAdmin);

        $html = $this->index();

        $this->assertStringNotContainsString('data-usage-reference-amend="'.$published->id.'"', $html);
    }

    /** Une surface sans rien dit « aucun repere publie » et propose de le creer. */
    public function test_an_empty_surface_says_so_and_offers_to_create(): void
    {
        $html = $this->index();

        $this->assertStringContainsString('data-usage-reference-none="agenda:fr"', $html);
        $this->assertStringContainsString('data-usage-reference-create="agenda:fr"', $html);
        $this->assertStringContainsString(e(__('admin.usage_reference_none')), $html);
    }

    /** L'historique existe, se replie, et n'est plus melange a l'etat courant. */
    public function test_the_history_is_present_and_folded(): void
    {
        $this->publish('agenda', 'fr', 'v1', 'Premiere.');
        $this->publish('agenda', 'fr', 'v2', 'Deuxieme.');

        $html = $this->index();

        $this->assertMatchesRegularExpression('/<details[^>]*data-usage-reference-history="agenda:fr"/', $html);

        // Replie : aucun `open` sur ce bloc.
        $this->assertSame(1, preg_match('/<details([^>]*)data-usage-reference-history="agenda:fr"([^>]*)>/', $html, $m));
        $this->assertStringNotContainsString('open', $m[1].$m[2]);
    }

    // =====================================================================
    // D. Ce que la tranche n'a pas change
    // =====================================================================

    /** La publication reste un geste humain : aucune generation, aucun automatisme. */
    public function test_publication_stays_an_explicit_human_gesture(): void
    {
        $draft = $this->service->createDraft('agenda', 'fr', 'Brouillon', 'Texte.', $this->superAdmin);

        // Le simple fait de regarder ne publie rien.
        $this->read($draft);
        $this->index();

        $this->assertTrue($draft->fresh()->isDraft());
        $this->assertNull(app(\App\Services\UsageReference\UsageReferenceResolver::class)->resolve('agenda', 'fr'));

        // Il faut un POST, avec CSRF, sur une action nommee.
        $this->actingAs($this->superAdmin)->post(route('admin.usage-references.publish', $draft))->assertRedirect();
        $this->assertTrue($draft->fresh()->isPublished());
    }

    /** Aucune generation IA n'entre dans cet ecran. */
    public function test_no_model_is_called_anywhere_in_this_screen(): void
    {
        $published = $this->publish('agenda', 'fr', 'L\'agenda', 'Texte.');

        $this->index();
        $this->read($published);
        $this->actingAs($this->superAdmin)->get(route('admin.usage-references.create', ['from' => $published->id]))->assertOk();

        Http::assertNothingSent();
    }

    /** Aucune migration : la tranche ne fait qu'ajouter une lecture. */
    public function test_no_migration_ships_with_this_slice(): void
    {
        $this->assertSame([], glob(database_path('migrations/*usage_reference*admin*.php')) ?: []);
        $this->assertSame([], glob(database_path('migrations/*usage_references_*2026_09_09*.php')) ?: []);
    }

    /** La zone reste reservee au SuperAdmin : lire n'a ouvert aucune porte. */
    public function test_reading_is_still_reserved_to_the_super_admin(): void
    {
        $published = $this->publish('agenda', 'fr', 'L\'agenda', 'Texte reserve.');

        $simple = User::factory()->complete()->create(['is_admin' => false]);

        $response = $this->actingAs($simple)->get(route('admin.usage-references.show', $published));

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('Texte reserve.', (string) $response->getContent());
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function publish(string $surface, string $locale, string $title, string $content): UsageReference
    {
        return $this->service->publish(
            $this->service->createDraft($surface, $locale, $title, $content, $this->superAdmin),
            $this->superAdmin,
        );
    }

    private function index(): string
    {
        return $this->actingAs($this->superAdmin)->get(route('admin.usage-references'))->assertOk()->getContent();
    }

    private function read(UsageReference $reference): string
    {
        return $this->actingAs($this->superAdmin)
            ->get(route('admin.usage-references.show', $reference))
            ->assertOk()
            ->getContent();
    }
}
