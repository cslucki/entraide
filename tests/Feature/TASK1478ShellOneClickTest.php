<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1478 — un clic.
 *
 * ## Le clic de trop, precisement
 *
 * Le declencheur « BouclePro IA » ouvrait un panneau. Ce panneau portait un
 * bouton « Ouvrir BouclePro IA ». Ce bouton ouvrait la conversation. **Deux
 * clics pour ecrire une phrase**, et le premier n'apportait rien que
 * l'utilisateur n'aurait pas vu ensuite.
 *
 * ## Ce que ce lot deplace, et ce qu'il ne change pas
 *
 * Le panneau portait quatre choses qui, elles, avaient de la valeur : le lieu,
 * le repere d'usage, le credit IA, le lien vers les usages. **Elles descendent
 * dans le Shell.** Aucune n'est recalculee : le Shell lit le MEME tableau
 * `AiFabContext::forRequest()`, une seule fois par rendu.
 *
 * Les actions de page, elles, y etaient deja : `AiShell::actions()` delegue a
 * `AiFabContext::loopActions()` / `dossierActions()`. Et la mesure faite avant
 * d'ecrire une ligne montre que le panneau n'affichait des actions que sur
 * `dossiers.show` — les routes de Boucle sont les seules autres, et TASK-1466
 * y interdit deja le FAB. Rien n'est donc perdu.
 *
 * ## Pourquoi le panneau n'est pas simplement supprime
 *
 * `ai.shell.enabled` peut etre faux. Dans cette configuration le panneau est la
 * SEULE surface : actions et credit n'auraient plus nulle part ou vivre. Il
 * reste donc, inchange, pour ce cas — et le declencheur l'ouvre comme avant.
 *
 * Le supprimer partout aurait ete plus simple a ecrire ; ce n'aurait pas ete
 * plus vrai.
 *
 * ## Ce que ce fichier garde
 *
 * Qu'aucun second Shell n'apparaisse, que le fil survive, et que l'invariant de
 * TASK-1466 tienne : toujours pas de Shell global sur une Boucle.
 */
class TASK1478ShellOneClickTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'is_active' => true,
            'slug' => 'org-one-click',
            'name' => 'Org One Click',
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-task1478',
        ]);

        $this->member = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'preferred_locale' => 'fr',
        ]);

        app()->instance('current_organization', $this->organization);

        config([
            'ai.fab.enabled' => true,
            'ai.shell.enabled' => true,
            'ai.providers.openai.driver' => 'openai',
            'ai.providers.openai.key' => 'platform-key',
        ]);

        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // A. Un clic
    // =====================================================================

    /**
     * Le declencheur ouvre la conversation LUI-MEME. La mesure porte sur son
     * gestionnaire de clic, pas sur la presence d'une chaine ailleurs dans la
     * page : `bp-open-ai-shell` figure aussi dans l'ecouteur du Shell, et une
     * assertion sur la page entiere serait donc verte meme si le declencheur
     * n'ouvrait rien.
     */
    public function test_the_trigger_opens_the_conversation_itself(): void
    {
        $html = $this->page();

        $this->assertSame(1, preg_match('/<button[^>]*data-ai-fab-toggle[^>]*>/', $html, $button),
            'le declencheur est rendu');

        $this->assertStringContainsString('data-ai-fab-opens="shell"', $button[0]);
        $this->assertStringContainsString('bp-open-ai-shell', $button[0],
            'le clic du declencheur ouvre le Shell, sans etape intermediaire');
    }

    /** L'etape intermediaire n'existe plus : ni panneau, ni bouton « Ouvrir ». */
    public function test_the_intermediate_step_is_gone(): void
    {
        $html = $this->page();

        $this->assertStringNotContainsString('data-ai-fab-panel', $html);
        $this->assertStringNotContainsString('data-ai-fab-shell', $html, 'le bouton « Ouvrir BouclePro IA » a disparu');
        $this->assertStringNotContainsString('id="ai-fab-panel"', $html);
    }

    /**
     * Et le composeur prend le focus a l'ouverture — c'est « saisir
     * immediatement ».
     *
     * Le focus est DIFFERE, et le test l'exige. Mesure au navigateur, faite
     * apres avoir vu le focus manquer : `$refs.composer` existe, le panneau est
     * deja `display: flex`, et `focus()` appele dans le `$nextTick` echoue
     * quand meme ; le meme appel une tache plus tard reussit.
     *
     * Autrement dit ce focus n'a jamais fonctionne depuis TASK-1315. Il ne se
     * voyait pas tant qu'un second clic separait l'ouverture de la saisie.
     *
     * PHPUnit ne donne pas le focus : ce test garde la FORME differee, la
     * preuve du comportement est au navigateur et consignee dans la fiche.
     */
    public function test_the_composer_takes_focus_when_the_shell_opens(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('@bp-open-ai-shell.window="show()"', $html);

        $this->assertStringContainsString('this.$nextTick(() => this.focusComposer(12))', $html,
            'l\'ouverture demande le focus');

        $this->assertSame(1, preg_match('/focusComposer\(tries\)\s*\{(.*?)\n        \},/s', $html, $fn),
            'le focus vit dans une routine nommee, pas dans un delai devine');

        // La CONDITION est le fait mesurable : le navigateur refuse le focus
        // tant que l'element n'a pas de boite. Un `setTimeout` a delai fixe
        // serait un pari ; une hauteur non nulle est une observation.
        $this->assertStringContainsString('getBoundingClientRect().height > 0', $fn[1]);
        $this->assertStringContainsString('el.focus()', $fn[1]);

        // Borne : la routine doit s'arreter. Une attente non bornee sur un
        // element qui ne s'affichera jamais tournerait indefiniment.
        $this->assertStringContainsString('if (tries > 0)', $fn[1]);

        // Et surtout PAS de requestAnimationFrame : gele tant que
        // `document.hidden` est vrai — ce depot l'a deja paye (TASK-1244.BUG).
        $this->assertStringNotContainsString('requestAnimationFrame', $fn[1]);
    }

    // =====================================================================
    // B. Rien n'est perdu : le credit, le lieu, le repere, les usages
    // =====================================================================

    public function test_the_credit_moved_into_the_shell(): void
    {
        $html = $this->page();

        $this->assertMatchesRegularExpression('/<div[^>]*\sdata-ai-shell-credit\b/', $html, 'le bloc credit vit dans le Shell');
        $this->assertStringContainsString('data-ai-shell-credit-label', $html);
        $this->assertStringContainsString('data-ai-shell-usage-link', $html);
        $this->assertStringContainsString(e(__('ai.fab_credit_title')), $html);
        $this->assertStringContainsString(e(__('ai.fab_usage_link')), $html);
    }

    /** Le lieu et le repere y sont deja depuis TASK-1469 et TASK-1477. */
    public function test_the_place_and_its_help_are_in_the_shell(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('data-ai-shell-surface="dashboard"', $html);
        $this->assertStringContainsString(e(__('ai.shell_surface_dashboard')), $html);
        // Aucun repere publie sur ce banc : le repli neutre tient la place.
        $this->assertStringContainsString('data-ai-shell-page-help', $html);
    }

    /**
     * Le credit affiche est celui que l'autorite produit, lu UNE fois. Deux
     * lectures ne seraient pas seulement du gaspillage : `AiFabContext` calcule
     * un etat de credit, et le lire deux fois par rendu ferait diverger ce que
     * la page montre de ce que le prochain tour appliquera.
     */
    public function test_the_credit_is_read_once_and_comes_from_the_authority(): void
    {
        $source = (string) file_get_contents(app_path('Livewire/AiShell.php'));

        $this->assertSame(
            1,
            preg_match_all('/\$fab = \$this->fab\(\);/', $source),
            'une seule lecture du contexte FAB par rendu',
        );

        $html = $this->page();

        $this->assertSame(1, preg_match('/data-ai-shell-tone="([a-z]+)"/', $html, $tone));
        $this->assertContains($tone[1], ['ok', 'alert', 'exhausted']);
    }

    // =====================================================================
    // C. Aucun second Shell, aucun double montage
    // =====================================================================

    public function test_exactly_one_shell_is_mounted(): void
    {
        $html = $this->page();

        $this->assertSame(1, substr_count($html, 'data-bp-shell-mount'), 'le Shell est monte une seule fois');
        $this->assertSame(1, substr_count($html, 'data-ai-shell-panel'));
        $this->assertSame(1, substr_count($html, 'data-ai-shell-composer'));
    }

    /** Le declencheur n'ouvre pas un composeur a lui : il n'y a qu'un composeur. */
    public function test_the_trigger_does_not_carry_a_composer_of_its_own(): void
    {
        $fab = (string) file_get_contents(resource_path('views/components/ai-fab.blade.php'));

        foreach (['wire:submit', 'data-ai-shell-composer', 'wire:model'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $fab, $forbidden.' : le FAB ne devient pas un second Shell');
        }
    }

    /** Aucun envoi automatique : ouvrir n'est pas demander. */
    public function test_opening_sends_nothing(): void
    {
        $this->page();

        Http::assertNothingSent();
    }

    // =====================================================================
    // D. La configuration SANS Shell continue de fonctionner
    // =====================================================================

    /**
     * `ai.shell.enabled` a faux : le panneau redevient la seule surface, et le
     * declencheur l'ouvre comme avant. Sans cette garde, cette configuration
     * n'aurait plus AUCUNE surface — ni panneau, ni Shell.
     */
    public function test_without_a_shell_the_panel_is_still_the_surface(): void
    {
        config(['ai.shell.enabled' => false]);

        $html = $this->page();

        $this->assertStringContainsString('data-ai-fab-opens="panel"', $html);
        $this->assertStringContainsString('data-ai-fab-panel', $html);
        $this->assertStringContainsString('data-ai-fab-credit', $html);
        $this->assertStringNotContainsString('data-ai-shell-panel', $html);

        $this->assertSame(1, preg_match('/<button[^>]*data-ai-fab-toggle[^>]*>/', $html, $button));
        $this->assertStringContainsString('toggle()', $button[0]);
        $this->assertStringNotContainsString('bp-open-ai-shell', $button[0]);
    }

    // =====================================================================
    // E. L'invariant de TASK-1466
    // =====================================================================

    public function test_no_global_shell_comes_back_on_a_loop(): void
    {
        $loop = \App\Models\Loop::factory()->create(['organization_id' => $this->organization->id]);

        $this->actingAs($this->member)
            ->get(route('organization.loops.show', ['organization' => $this->organization->slug, 'loop' => $loop->id]))
            ->assertOk()
            ->assertDontSee('data-ai-fab', false)
            ->assertDontSee('data-ai-shell-panel', false)
            ->assertDontSee('data-ai-fab-opens', false);
    }

    private function page(): string
    {
        return $this->actingAs($this->member)
            ->get(route('organization.dashboard', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->getContent();
    }
}
