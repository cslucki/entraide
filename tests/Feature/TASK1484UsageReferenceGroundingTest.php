<?php

namespace Tests\Feature;

use App\Ai\Agents\HelpRequestClarifierAgent;
use App\Models\AiInteraction;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\UsageReference;
use App\Models\User;
use App\Services\Ai\AiShellResponder;
use App\Support\Ai\AiShellPageContext;
use App\Support\Ai\AiShellUsageReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Tests\TestCase;

/**
 * TASK-1484 — l'UsageReference cesse d'etre RECITEE et se met a ANCRER.
 *
 * ## Le defaut, mesure avant tout code
 *
 * `grep usage_reference app/` : cote membre, un seul consommateur —
 * `AiShell::render()`, qui alimentait un bloc Blade. **Zero occurrence dans le
 * chemin du prompt.** Le seul endroit du produit ou une UsageReference
 * atteignait reellement un modele etait `GuestPublicContextBuilder` : le Shell
 * VISITEUR.
 *
 * Le membre avait donc exactement l'inverse de ce qu'il fallait. Le texte
 * s'affichait en bloc a l'ouverture du Shell, en permanence, a quelqu'un qui
 * n'avait rien demande — et il manquait a la seule chose qui aurait pu s'en
 * servir.
 *
 * ## La consequence fonctionnelle
 *
 * `AiShellResponder::situated()` ne posait de ligne de lieu que pour
 * `loop` / `dossier` / `article` (un `match` sur le type d'objet) et pour
 * `kind === 'dashboard'`. Sur agenda, annuaire, echanges, dossiers, blog et
 * profil : rien du tout. « C'est quoi cette page ? » sur l'agenda repondait
 * depuis le seul prompt administrable generique. Ce n'etait pas une
 * hallucination : c'etait une absence d'ancrage.
 *
 * ## Ce que ce fichier mesure, et comment
 *
 * La CHAINE REELLEMENT ENVOYEE, jamais la reponse d'un modele. Le fournisseur
 * est double par `HelpRequestClarifierAgent::fake()` ; l'assertion porte sur
 * `AiInteraction::prompt` (ce que le clarifieur a recu) et, une fois, sur
 * `assertPrompted()` — qui observe la composition COMPLETE remise a l'agent, et
 * pas le sous-ensemble trace. Aucun appel provider en CI.
 *
 * ## L'invariant qui borne tout le reste
 *
 * TASK-1346 verifie a l'octet pres que sur un fil vide, sans lieu, le prompt
 * EST la question. La surface est donc lue dans `$pageContext['surface']` —
 * jamais re-derivee depuis la route. Le contexte de cet invariant
 * (`['route' => 'profile.edit', 'kind' => 'other', 'object' => null]`) ne porte
 * aucune cle `surface` ; une seconde derivation le ferait rougir, et aurait
 * cree au passage une deuxieme autorite de surface.
 */
class TASK1484UsageReferenceGroundingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'is_active' => true,
            'slug' => 'org-1484',
            'name' => 'Org 1484',
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-task1484-'.$this->organization->id,
            'monthly_budget_usd' => 5.00,
        ]);

        $this->member = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'first_name' => 'Nour',
            'name' => 'Ancrage',
        ]);

        app()->instance('current_organization', $this->organization);

        config([
            'ai.fab.enabled' => true,
            'ai.shell.enabled' => true,
            'ai.clarify.enabled' => true,
            'ai.providers.openai.driver' => 'openai',
            'ai.providers.openai.key' => 'platform-key',
            'ai_pricing.overrides' => [],
        ]);

        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // A. Le coeur : la reference atteint le modele
    // =====================================================================

    /**
     * Le cas exact rapporte : l'agenda. Avant, cette page n'envoyait AUCUNE
     * indication de lieu — d'ou la reponse generique sur « les categories et
     * les boucles ».
     */
    public function test_the_agenda_reference_reaches_the_prompt(): void
    {
        $this->fakeClarifier();
        $this->publish('agenda', 'fr', 'L\'agenda', 'Vous retrouvez ici les rencontres de vos Boucles, passees et a venir.');

        $this->sendFrom('organization.events.agenda');

        $prompt = $this->lastPrompt();

        $this->assertStringContainsString('Vous retrouvez ici les rencontres de vos Boucles', $prompt);
        $this->assertStringContainsString('L\'agenda', $prompt);
        $this->assertStringContainsString('Ma question.', $prompt);
    }

    /**
     * Le meme fait, mais mesure sur la composition COMPLETE remise a l'agent.
     * « Intention du membre : » n'existe que dans `userPrompt()` : sa presence
     * prouve qu'on observe le prompt compose, et pas la colonne de trace.
     */
    public function test_the_reference_is_in_the_composed_agent_prompt_too(): void
    {
        $this->fakeClarifier();
        $this->publish('agenda', 'fr', 'L\'agenda', 'Les rencontres de vos Boucles.');

        $this->sendFrom('organization.events.agenda');

        HelpRequestClarifierAgent::assertPrompted(
            fn (AgentPrompt $prompt): bool => $prompt->contains('Intention du membre :')
                && $prompt->contains('Les rencontres de vos Boucles.'),
        );
    }

    /** Les six autres surfaces membre repondent de la meme facon. */
    public function test_every_member_surface_is_grounded_when_a_reference_exists(): void
    {
        $surfaces = [
            'dashboard' => 'organization.dashboard',
            'agenda' => 'organization.events.agenda',
            'directory' => 'organization.members.index',
            'exchanges' => 'organization.explorer',
            'dossiers' => 'organization.dossiers.index',
            'blog' => 'organization.blog.index',
            'profile' => 'organization.profile.show',
        ];

        // Premisse fermee : la table ci-dessus couvre EXACTEMENT les surfaces
        // membre declarees. Le jour ou une huitieme apparait, ce test rougit
        // ici plutot que de la laisser silencieusement non couverte.
        $declared = UsageReference::SURFACES_MEMBER;
        $covered = array_keys($surfaces);
        sort($declared);
        sort($covered);

        $this->assertSame($declared, $covered, 'une surface membre n\'est pas couverte par ce test');

        foreach ($surfaces as $surface => $route) {
            AiInteraction::query()->delete();
            $this->fakeClarifier();

            $marker = 'ANCRAGE-'.strtoupper($surface);
            $this->publish($surface, 'fr', 'Titre '.$surface, 'Ce lieu sert a ceci : '.$marker);

            $this->sendFrom($route);

            $this->assertStringContainsString($marker, $this->lastPrompt(), "[{$surface}] doit ancrer le modele");
        }
    }

    // =====================================================================
    // B. Ce qui n'ancre PAS — les gardes reprises telles quelles
    // =====================================================================

    /** Sans reference publiee, rien n'est ajoute : le prompt reste ce qu'il etait. */
    public function test_without_a_published_reference_nothing_is_added(): void
    {
        $this->fakeClarifier();

        $this->sendFrom('organization.events.agenda');

        $this->assertSame('Ma question.', $this->lastPrompt());
    }

    /** Un BROUILLON n'est pas publie : il n'ancre rien. */
    public function test_a_draft_never_grounds_the_model(): void
    {
        $this->fakeClarifier();
        $this->publish('agenda', 'fr', 'L\'agenda', 'BROUILLON-1484 jamais servi.', UsageReference::STATE_DRAFT);

        $this->sendFrom('organization.events.agenda');

        $this->assertStringNotContainsString('BROUILLON-1484', $this->lastPrompt());
    }

    /**
     * Une reference PUBLIQUE ne franchit pas vers une surface membre. C'est le
     * verrou de TASK-1477/TASK-1473 : `organization_home` porte la meme chaine
     * pour deux lieux differents. Le deplacer vers le prompt aurait ete
     * l'occasion parfaite de le perdre.
     */
    public function test_a_public_reference_never_grounds_a_member_surface(): void
    {
        $this->fakeClarifier();
        $this->publish('organization_home', 'fr', 'Accueil', 'PUBLIC-1484 reserve aux visiteurs.');

        // La route DOIT etre `organization.home` : c'est la seule qui resout la
        // surface `organization_home`, donc la seule ou le verrou est
        // reellement sollicite. Une premiere version de ce test envoyait depuis
        // `organization.dashboard` — elle passait sans jamais franchir la
        // garde, et restait verte quand on la retirait. Mesure faite par
        // sabotage : c'etait un vert pour une mauvaise raison.
        $context = app(AiShellPageContext::class)->resolve(
            $this->member,
            $this->organization,
            null,
            null,
            'organization.home',
        );

        $this->assertSame('organization_home', $context['surface'], 'premisse : le verrou est bien sollicite');

        app(AiShellResponder::class)->respond($this->organization, $this->member, 'Ma question.', $context);

        $this->assertStringNotContainsString('PUBLIC-1484', $this->lastPrompt());
    }

    /** La reference d'une AUTRE surface ne traverse pas. */
    public function test_the_reference_of_another_surface_never_crosses(): void
    {
        $this->fakeClarifier();
        $this->publish('directory', 'fr', 'L\'annuaire', 'ANNUAIRE-1484.');

        $this->sendFrom('organization.events.agenda');

        $this->assertStringNotContainsString('ANNUAIRE-1484', $this->lastPrompt());
    }

    // =====================================================================
    // C. L'invariant de TASK-1346 : le chemin nominal ne bouge pas d'un octet
    // =====================================================================

    /**
     * Le contexte de l'invariant ne porte AUCUNE cle `surface`. Si la surface
     * etait re-derivee ici depuis `route` (`profile.edit` EST dans
     * `SURFACE_ROUTES['profile']`), ce test rougirait — et le produit aurait
     * gagne une seconde autorite de surface.
     */
    public function test_the_empty_context_invariant_still_holds_to_the_byte(): void
    {
        $this->fakeClarifier();
        $this->publish('profile', 'fr', 'Le profil', 'PROFIL-1484 ne doit pas apparaitre.');

        app(AiShellResponder::class)->respond(
            $this->organization,
            $this->member,
            'Ma toute premiere question.',
            ['route' => 'profile.edit', 'kind' => 'other', 'object' => null],
        );

        $this->assertSame('Ma toute premiere question.', $this->lastPrompt());
    }

    /** Une surface inconnue n'invente pas de lieu. */
    public function test_an_unknown_surface_grounds_nothing(): void
    {
        $this->fakeClarifier();
        $this->publish('agenda', 'fr', 'L\'agenda', 'AGENDA-1484.');

        $this->sendFrom('une.route.qui.n.existe.pas');

        $this->assertStringNotContainsString('AGENDA-1484', $this->lastPrompt());
    }

    // =====================================================================
    // D. La borne : une reference longue n'evince pas le fil
    // =====================================================================

    /**
     * `UsageReference` autorise 4 000 caracteres a la redaction ; le budget de
     * contexte du Shell en vaut 4 000 au total. Injecter le maximum autorise
     * repousserait la garde de langue et le transcript hors de la fenetre —
     * exactement ce que `situated()` documente vouloir eviter en posant la
     * langue EN TETE.
     *
     * Borne mesuree, pas choisie : la plus longue reference membre reellement
     * publiee fait 513 caracteres (`agenda` FR, releve du 2026-09-09).
     */
    public function test_a_very_long_reference_is_bounded(): void
    {
        $this->fakeClarifier();

        $body = str_repeat('a', 3000).'QUEUE-1484';
        $this->publish('agenda', 'fr', 'L\'agenda', $body);

        $this->sendFrom('organization.events.agenda');

        $prompt = $this->lastPrompt();

        $this->assertStringNotContainsString('QUEUE-1484', $prompt, 'la fin d\'une reference longue ne doit pas entrer');
        $this->assertLessThan(3000, mb_strlen($prompt), 'le prompt ne doit pas heriter des 4 000 caracteres autorises');
        $this->assertStringContainsString('Ma question.', $prompt, 'la question survit toujours a l\'ancrage');
    }

    /** La borne est celle que la classe declare, et elle tient sous la fenetre du Shell. */
    public function test_the_bound_stays_under_the_shell_context_budget(): void
    {
        $this->assertLessThan(
            (int) config('ai.shell.max_context_chars'),
            AiShellUsageReference::GROUNDING_MAX_CHARS,
        );
    }

    // =====================================================================
    // E. La langue suit l'interface, comme le reste du prompt
    // =====================================================================

    public function test_the_grounding_follows_the_interface_locale(): void
    {
        $this->fakeClarifier();
        $this->publish('agenda', 'fr', 'L\'agenda', 'VERSION-FR-1484.');
        $this->publish('agenda', 'en', 'The agenda', 'VERSION-EN-1484.');

        app()->setLocale('en');
        $this->sendFrom('organization.events.agenda');

        $prompt = $this->lastPrompt();

        $this->assertStringContainsString('VERSION-EN-1484', $prompt);
        $this->assertStringNotContainsString('VERSION-FR-1484', $prompt);
    }

    // =====================================================================
    // F. L'ecran : le texte n'y est plus recite
    // =====================================================================

    /**
     * Le pendant du deplacement. Si le bloc revenait a l'ecran sans que
     * l'ancrage disparaisse, le produit aurait les deux — c'est-a-dire le
     * defaut d'origine plus un cout.
     */
    public function test_the_shell_render_no_longer_computes_the_reference(): void
    {
        $source = php_strip_whitespace(app_path('Livewire/AiShell.php'));

        $this->assertStringNotContainsString("'usage_reference' =>", $source);

        $view = file_get_contents(resource_path('views/livewire/ai-shell.blade.php'));

        $this->assertStringNotContainsString('data-ai-shell-usage-reference', $view);
        $this->assertStringContainsString('data-ai-shell-surface', $view, 'l\'en-tete « ou suis-je » reste');
        $this->assertStringContainsString('data-ai-shell-page-help', $view, 'ce que le Shell PEUT faire reste');
    }

    /**
     * Le panneau du FAB, lui, RECITE toujours — et c'est juste. Il n'existe que
     * lorsqu'aucun Shell n'est monte : il n'y a alors aucune conversation, donc
     * aucun modele a ancrer. Reciter y est le seul moyen pour ce texte
     * d'atteindre un humain.
     */
    public function test_the_fab_panel_still_recites_because_it_has_no_model_to_ground(): void
    {
        $view = file_get_contents(resource_path('views/components/ai-fab.blade.php'));

        $this->assertStringContainsString('data-ai-fab-usage-reference', $view);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function publish(string $surface, string $locale, string $title, string $content, string $state = UsageReference::STATE_PUBLISHED): void
    {
        UsageReference::query()->create([
            'surface_key' => $surface,
            'locale' => $locale,
            'title' => $title,
            'content' => $content,
            'version' => 1,
            'state' => $state,
            'published_at' => $state === UsageReference::STATE_PUBLISHED ? now() : null,
        ]);
    }

    private function sendFrom(string $routeName, string $draft = 'Ma question.'): void
    {
        $context = app(AiShellPageContext::class)->resolve(
            $this->member,
            $this->organization,
            null,
            null,
            $routeName,
        );

        app(AiShellResponder::class)->respond($this->organization, $this->member, $draft, $context);
    }

    private function lastPrompt(): string
    {
        return (string) AiInteraction::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->firstOrFail()
            ->prompt;
    }

    private function fakeClarifier(): void
    {
        $structured = [
            'title' => 'Titre',
            'clarified_request' => 'Demande clarifiee.',
            'help_type' => 'information',
            'suggested_loop_id' => '',
            'suggested_category_id' => '',
            'suggestion_reason' => '',
            'questions_for_user' => [],
            'confidence' => 0.9,
            'needs_human_review' => false,
        ];

        HelpRequestClarifierAgent::fake(fn (): StructuredTextResponse => new StructuredTextResponse(
            $structured,
            json_encode($structured, JSON_UNESCAPED_UNICODE),
            new Usage(120, 80),
            new Meta('openai', 'gpt-4o-mini'),
        ));
    }
}
