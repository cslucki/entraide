<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1818 — la suggestion IA n'arrivait pas dans l'editeur visible.
 *
 * ## Le defaut
 *
 * `applySuggestion()` ecrivait `description.value = ...` — c'est-a-dire dans le
 * `<textarea name="description">` que le composant d'edition Markdown RELEGUE
 * et CACHE depuis T1773, l'editeur visible etant TipTap. La suggestion partait
 * donc au serveur sans jamais s'afficher : l'auteur voyait son texte d'origine
 * et postait autre chose. Les deux divergeaient en silence.
 *
 * ## La correction
 *
 * Le contrat deja utilise par le formulaire d'offre d'aide : un evenement
 * `set-content` ecrit DANS l'editeur, dont le `onUpdate` reecrit le textarea
 * (`emitUpdate` vaut `true` par defaut en TipTap 3). Repli direct sur le
 * textarea si l'editeur n'est pas initialise.
 *
 * ## Pourquoi un test PHP alors que le comportement est en JavaScript
 *
 * Le comportement se prouve en navigateur (Playwright, `tiptap-markdown-editor.spec.js`).
 * Ce test-ci garde ce que le navigateur ne peut PAS attraper tot : que le bloc
 * JavaScript reste bien FORME. Il vit dans un attribut HTML entre guillemets
 * doubles — un seul guillemet double dans ce bloc refermerait l'attribut et
 * ferait disparaitre les attributs suivants du DOM. C'est une regression
 * silencieuse, et c'est exactement le piege rencontre en ecrivant cette TASK.
 */
class TASK1818SuggestionIaDansTiptapTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1818',
            'is_active' => true,
            'is_public' => true,
            'ai_profiles_enabled' => true,
        ]);

        $this->membre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
            'points_balance' => 100,
        ]);

        app()->instance('current_organization', $this->organization);

        // Aucun appel fournisseur ne doit partir d'un test : une requete sortante
        // non doublee devient une erreur, pas une depense.
        Http::preventStrayRequests();
        Http::fake();
    }

    private function vue(string $chemin): string
    {
        return file_get_contents(resource_path("views/{$chemin}"));
    }

    /**
     * Le bloc de formulation est garde par la disponibilite reelle de l'IA.
     * Sans cela, la page se rend SANS lui et les gardes de forme ci-dessous
     * seraient vertes en ne mesurant rien.
     */
    private function allumerLIA(): void
    {
        OrganizationAiSetting::query()->updateOrCreate(
            ['organization_id' => $this->organization->id],
            ['is_enabled' => true, 'provider' => 'openai', 'model' => 'gpt-4o-mini', 'api_key' => 'sk-test'],
        );

        app()->forgetInstance(\App\Support\Ai\AiSurfaceGate::class);
    }

    // ────────────────────────────────────────────────────────────────────────
    // La correction, dans la source
    // ────────────────────────────────────────────────────────────────────────

    public function test_la_description_passe_par_l_editeur_et_non_par_le_textarea_cache(): void
    {
        $source = $this->vue('requests/create.blade.php');

        $this->assertStringContainsString(
            'bp:markdown-editor:set-content',
            $source,
            'La suggestion doit etre posee DANS l editeur TipTap, sinon elle part au serveur sans s afficher.'
        );
        $this->assertStringContainsString(
            'data-tiptap-initialized',
            $source,
            'Le repli doit etre conditionne a un editeur REELLEMENT initialise.'
        );
    }

    public function test_le_contrat_json_de_la_suggestion_est_inchange(): void
    {
        $source = $this->vue('requests/create.blade.php');

        $this->assertStringContainsString(
            'suggestion.description',
            $source,
            'Le contrat JSON ne doit pas bouger : le controleur et le moteur IA ne sont pas touches.'
        );
        $this->assertStringNotContainsString(
            'suggestion.description_markdown',
            $source,
            'Le formulaire de demande d aide lit `description`, pas `description_markdown` (contrat de l offre d aide).'
        );
    }

    /**
     * Le repli n'est pas decoratif : si TipTap echoue a se monter, la suggestion
     * doit malgre tout atterrir dans le champ soumis.
     */
    public function test_le_repli_sur_le_textarea_est_conserve(): void
    {
        $source = $this->vue('requests/create.blade.php');

        $this->assertMatchesRegularExpression(
            '/else if \(description\) \{\s*description\.value = this\.suggestion\.description;/',
            $source,
            'Le repli direct sur le textarea doit subsister pour le cas ou TipTap ne s initialise pas.'
        );
    }

    // ────────────────────────────────────────────────────────────────────────
    // Le piege : du JavaScript dans un attribut HTML
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Le bloc `applySuggestion()` vit DANS un attribut HTML entre guillemets
     * doubles. Un seul guillemet double dedans refermerait l'attribut : les
     * attributs suivants cesseraient d'exister dans le DOM, et le bloc entier
     * deviendrait du texte. La page ne leverait AUCUNE erreur.
     *
     * On mesure sur le DOM reellement parse, pas sur la source.
     */
    public function test_le_bloc_de_suggestion_reste_un_attribut_bien_forme(): void
    {
        $this->allumerLIA();

        $reponse = $this->actingAs($this->membre)->get('/requests/create');
        $reponse->assertOk();

        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML($reponse->getContent());
        libxml_clear_errors();

        $xpath = new \DOMXPath($document);
        $blocs = $xpath->query('//div[@data-ai-formulate-block]');

        $this->assertSame(1, $blocs->length, 'Le bloc de formulation IA doit etre present une fois.');

        $bloc = $blocs->item(0);

        // Si un guillemet double avait referme `x-data` trop tot, cet attribut-ci
        // serait avale dans sa valeur et n'existerait plus comme attribut.
        $this->assertTrue(
            $bloc->hasAttribute('data-request-ai-formulation'),
            'L attribut qui SUIT le bloc x-data a disparu du DOM : un guillemet double a referme l attribut trop tot.'
        );
        $this->assertTrue($bloc->hasAttribute('data-error-message'));

        $this->assertStringContainsString(
            'applySuggestion',
            $bloc->getAttribute('x-data'),
            'Le bloc x-data ne porte plus la methode d application : il a ete tronque.'
        );
        $this->assertStringContainsString(
            'bp:markdown-editor:set-content',
            $bloc->getAttribute('x-data'),
            'Le bloc x-data ne porte plus l ecriture dans TipTap : il a ete tronque.'
        );
    }

    // ────────────────────────────────────────────────────────────────────────
    // Les deux routes servent bien le meme bloc corrige
    // ────────────────────────────────────────────────────────────────────────

    public function test_la_route_scopee_sert_le_meme_bloc_corrige(): void
    {
        $this->allumerLIA();

        $reponse = $this->actingAs($this->membre)
            ->get(route('organization.requests.create', ['organization' => $this->organization->slug]));
        $reponse->assertOk();

        $reponse->assertSee('bp:markdown-editor:set-content', escape: false);
        $reponse->assertSee('data-tiptap-initialized', escape: false);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Le formulaire d'offre d'aide, dont on a repris le patron, n'est pas touche
    // ────────────────────────────────────────────────────────────────────────

    public function test_le_formulaire_d_offre_d_aide_garde_son_propre_patron(): void
    {
        $source = $this->vue('services/create.blade.php');

        $this->assertStringContainsString('bp:markdown-editor:set-content', $source);
        $this->assertStringContainsString(
            'suggestion.description_markdown',
            $source,
            'L offre d aide garde SON contrat JSON : cette TASK ne touche pas a ce formulaire.'
        );
    }
}
