<?php

namespace Tests\Feature;

use App\Ai\Agents\LoopKnowledgeAgent;
use App\Models\Dossier;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Dossiers\DossierInsightsService;
use App\Services\Dossiers\DossierSemanticSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * TASK-1398 — Smart Dossier Insights suit la langue de l'Organization.
 *
 * ## Le defaut, MESURE
 *
 * Sur le tenant anglais de la demonstration, Smart Dossier rendait un
 * document a titres FRANCAIS. Le francais venait de deux endroits, tous les
 * deux dans `DossierInsightsService` et nulle part ailleurs :
 *
 * | endroit | role |
 * |---|---|
 * | `presetQuestion()` | un heredoc integralement francais, qui DICTE au modele les cinq titres Markdown |
 * | cinq constantes `HEADING_*` | le PARSEUR de la reponse, et les titres REEMIS dans le markdown rendu |
 *
 * La chrome d'ecran — bouton, libelles, messages d'erreur — etait deja
 * traduite depuis TASK-1341 : ce qui restait francais, c'etait exactement la
 * partie STRUCTURANTE, celle que le lecteur prend pour le produit.
 *
 * ## Pourquoi les deux devaient bouger ensemble
 *
 * Traduire le prompt sans traduire le parseur aurait ete pire que le defaut :
 * le modele aurait rendu `## Summary`, `splitSections()` n'aurait reconnu
 * aucune rubrique, et l'ecran aurait affiche un insight VIDE au lieu d'un
 * insight francais. Les titres sont donc compares par IDENTIFIANT et non par
 * libelle, et les trois usages lisent la meme cle de langue.
 *
 * ## L'autorite
 *
 * `Organization.locale`, jamais la locale du lecteur — c'est l'arbitrage
 * generique du 04/09, deja applique par TASK-1388 et TASK-1390. Un Insight
 * est relu par tout le cercle : le faire suivre la langue de qui appuie sur le
 * bouton donnerait au meme Dossier deux langues selon le visiteur.
 *
 * Le service portait d'ailleurs deja la faute en toutes lettres :
 * `locale: str_starts_with(app()->getLocale(), 'en') ? 'en' : 'fr'` posait la
 * locale du LECTEUR dans le contexte IA.
 *
 * ## Le piege que ces gardes doivent tenir
 *
 * `APP_FALLBACK_LOCALE=fr`. Une cle anglaise manquante rendrait du francais
 * SANS erreur ni trace. Affirmer la presence de l'anglais ne suffit donc pas :
 * chaque garde affirme aussi l'ABSENCE du francais.
 */
class TASK1398SmartDossierOrganizationLocaleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organizationEn;

    private User $membre;

    private Dossier $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizationEn = Organization::factory()->create(['locale' => 'en']);
        $this->membre = User::factory()->create(['organization_id' => $this->organizationEn->id]);

        app()->instance('current_organization', $this->organizationEn);

        $this->dossier = Dossier::create([
            'organization_id' => $this->organizationEn->id,
            'owner_id' => $this->membre->id,
            'name' => 'Sonic Terrain',
            'visibility' => 'private',
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organizationEn->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-test-1398',
        ]);

        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-key',
            'ai_pricing.overrides' => [],
        ]);

        Http::preventStrayRequests();
    }

    // =====================================================================
    // Ce qui est DEMANDE au modele
    // =====================================================================

    /**
     * Sur une Organization anglaise, la question preetablie part en ANGLAIS.
     *
     * La mesure la plus en amont : elle porte sur ce qui SORT vers le
     * fournisseur. Sans elle, une correction faite sur le seul rendu
     * masquerait que le modele, lui, continue de recevoir des consignes
     * francaises — et repondrait en francais.
     */
    public function test_an_english_organization_sends_an_english_preset_question(): void
    {
        $this->corpus();
        $this->fakeAgent($this->reponseAnglaise());

        $this->actingAs($this->membre)->postJson($this->url())->assertOk();

        LoopKnowledgeAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            $this->assertStringContainsString('## Summary', $prompt->prompt);
            $this->assertStringContainsString('## Points needing attention', $prompt->prompt);
            $this->assertStringNotContainsString('## Synthèse', $prompt->prompt);
            $this->assertStringNotContainsString('Points nécessitant attention', $prompt->prompt);

            return true;
        });
    }

    // =====================================================================
    // Ce qui est RENDU au lecteur
    // =====================================================================

    /**
     * Les titres rendus sont ceux de l'Organization.
     *
     * La donnee demandee en anglais ne sert a rien si le service reecrit des
     * titres francais par-dessus : la mesure porte sur la reponse HTTP, apres
     * parsing et reemission.
     */
    public function test_an_english_organization_renders_english_headings(): void
    {
        $this->corpus();
        $this->fakeAgent($this->reponseAnglaise());

        $html = $this->html();

        $this->assertStringContainsString('Summary', $html);
        $this->assertStringContainsString('Key facts', $html);
        $this->assertStringNotContainsString('Synthèse', $html);
        $this->assertStringNotContainsString('Faits saillants', $html);
    }

    /**
     * Le parseur reconnait bien les rubriques anglaises.
     *
     * La moitie qui aurait pu passer inapercue. Un parseur reste francais
     * n'aurait fait tomber AUCUNE assertion de langue : il aurait simplement
     * ne rien reconnaitre, et rendu une reponse vide. La mesure porte donc sur
     * le CONTENU des rubriques, pas seulement sur leurs titres.
     */
    public function test_the_parser_keeps_the_grounded_lines_of_english_sections(): void
    {
        $this->corpus();
        $this->fakeAgent($this->reponseAnglaise());

        $html = $this->html();

        $this->assertStringContainsString('The protocol includes a validation step', $html);
        $this->assertNotSame('', trim($html));
    }

    /**
     * Une convergence a un seul document reste ecartee, en anglais aussi.
     *
     * La doctrine de groundedness de TASK-1341 ne doit pas etre devenue
     * dependante de la langue en devenant traduisible. Sans cette mesure, un
     * parseur qui reconnaitrait le titre anglais mais aurait perdu la regle
     * associee rendrait, sur le tenant de la demonstration, une convergence
     * que rien ne soutient.
     */
    public function test_a_single_document_convergence_is_still_discarded_in_english(): void
    {
        $this->corpus();
        $this->fakeAgent(implode("\n", [
            '## Key facts',
            '- The protocol includes a validation step [S1].',
            '',
            '## Convergences',
            '- Both notes agree on the same point [S1].',
        ]));

        $html = $this->html();

        $this->assertStringNotContainsString('Convergences', $html);
        $this->assertStringContainsString('Key facts', $html);
    }

    // =====================================================================
    // L'autorite : l'Organization, jamais le lecteur
    // =====================================================================

    /**
     * Un lecteur FRANCAIS dans une Organization anglaise lit de l'anglais.
     *
     * Le coeur de l'arbitrage. C'est la seule mesure qui distingue « suivre
     * l'Organization » de « suivre celui qui appuie sur le bouton » : les
     * deux precedentes resteraient vertes si le service se contentait de lire
     * la locale du lecteur, puisque le lecteur y est anglais par defaut.
     */
    public function test_a_french_reader_inside_an_english_organization_still_gets_english(): void
    {
        App::setLocale('fr');
        $this->membre->forceFill(['preferred_locale' => 'fr'])->save();

        $this->corpus();
        $this->fakeAgent($this->reponseAnglaise());

        $html = $this->html();

        $this->assertStringContainsString('Summary', $html);
        $this->assertStringNotContainsString('Synthèse', $html);
    }

    /**
     * Et la locale de l'application n'est pas laissee de cote.
     *
     * Le contre-exemple de la methode employee : produire l'anglais en
     * DEPLACANT la locale globale la laisserait deplacee pour la suite de la
     * requete — les messages d'erreur, les mails, le rendu de la page. La
     * langue du contenu se choisit au moment de le produire, sans toucher a
     * l'etat de l'application.
     */
    public function test_producing_english_never_moves_the_application_locale(): void
    {
        $this->corpus();
        $this->fakeAgent($this->reponseAnglaise());

        // Le service est appele DIRECTEMENT, et non par HTTP : une requete
        // traverse `SetLocale`, qui repose la locale d'apres le lecteur et
        // effacerait la mesure. Ce qu'il faut mesurer ici, c'est la trace
        // laissee par la generation elle-meme.
        App::setLocale('fr');

        $reponse = app(DossierInsightsService::class)
            ->generate($this->organizationEn, $this->dossier, $this->membre);

        $this->assertStringContainsString('Summary', $reponse->answer);
        $this->assertSame('fr', App::getLocale(), 'La locale de l\'application ne doit pas avoir bouge.');
    }

    // =====================================================================
    // Le francais n'a pas bouge
    // =====================================================================

    /**
     * Une Organization francaise se comporte EXACTEMENT comme avant.
     *
     * Le contre-exemple indispensable : une correction qui basculerait tout
     * en anglais passerait toutes les mesures precedentes en cassant le
     * parcours de production. Les treize gardes de TASK-1341 couvrent deja ce
     * cas — la colonne `locale` vaut `fr` par defaut en base et leur
     * fabrique ne la pose pas — mais elles ne le DISENT pas ; celle-ci le dit.
     */
    public function test_a_french_organization_is_unchanged(): void
    {
        $organizationFr = Organization::factory()->create(['locale' => 'fr']);
        $membreFr = User::factory()->create(['organization_id' => $organizationFr->id]);
        app()->instance('current_organization', $organizationFr);

        $dossierFr = Dossier::create([
            'organization_id' => $organizationFr->id,
            'owner_id' => $membreFr->id,
            'name' => 'Terrain sonore',
            'visibility' => 'private',
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $organizationFr->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-test-1398-fr',
        ]);

        $this->corpus($dossierFr);
        $this->fakeAgent(implode("\n", [
            '## Faits saillants',
            '- Le protocole prévoit une étape de validation [S1].',
        ]));

        $html = (string) $this->actingAs($membreFr)
            ->postJson($this->url($organizationFr, $dossierFr))
            ->assertOk()
            ->json('html');

        $this->assertStringContainsString('Faits saillants', $html);
        $this->assertStringNotContainsString('Key facts', $html);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /**
     * Le rendu, tel qu'il part vers l'ecran.
     *
     * La reponse de l'endpoint est du HTML deja rendu (`html`), pas le
     * markdown brut : mesurer la ou le lecteur lit, et non un etat
     * intermediaire.
     */
    private function html(): string
    {
        return (string) $this->actingAs($this->membre)
            ->postJson($this->url())
            ->assertOk()
            ->json('html');
    }

    private function reponseAnglaise(): string
    {
        return implode("\n", [
            '## Summary',
            'These notes describe a protocol and its review step.',
            '',
            '## Key facts',
            '- The protocol includes a validation step [S1].',
            '',
            '## Possible questions',
            '- Who owns the review step?',
        ]);
    }

    private function corpus(?Dossier $dossier = null): MockInterface
    {
        $dossier ??= $this->dossier;

        $mock = $this->mock(DossierSemanticSearchService::class);
        $mock->shouldReceive('representativeChunksAcrossDossiers')->andReturn([$this->row('A', $dossier)]);

        return $mock;
    }

    private function fakeAgent(string $text): void
    {
        LoopKnowledgeAgent::fake([
            new TextResponse($text, new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $label, Dossier $dossier): array
    {
        return [
            'chunk_id' => (string) Str::uuid(),
            'dossier_id' => $dossier->id,
            'dossier_name' => $dossier->name,
            'source_type' => 'article',
            'blog_post_id' => (string) Str::uuid(),
            'title' => 'Article '.$label,
            'slug' => 'article-'.strtolower($label),
            'dossier_file_id' => null,
            'filename' => null,
            'mime_type' => null,
            'chunk_index' => 0,
            'content' => "Note {$label}: the protocol includes a validation step.",
            'distance' => null,
        ];
    }

    private function url(?Organization $organization = null, ?Dossier $dossier = null): string
    {
        return route('organization.dossiers.insights', [
            'organization' => $organization ?? $this->organizationEn,
            'dossier' => $dossier ?? $this->dossier,
        ]);
    }
}
