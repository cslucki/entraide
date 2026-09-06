<?php

namespace Tests\Feature;

use App\Livewire\AiShell;
use App\Models\AiShellMessage;
use App\Models\Dossier;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\LoopService;
use App\Support\Ai\AiShellPageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1365 — le bas du Shell dit ou l'on est, et se comporte comme ChatLoop.
 *
 * ## Ce qui partait
 *
 * Deux phrases permanentes. « BouclePro IA ne publie rien : vous validez avant
 * toute publication. » repetait une garantie que le code tient deja. « Le
 * contexte indique ou vous etes. Il n'ouvre aucun acces. » etait technique, et
 * ne disait justement PAS ou l'on est.
 *
 * **La garantie n'a pas bouge d'une ligne.** Seule sa repetition a l'ecran
 * disparait : c'est de la copie, pas une regle.
 *
 * ## Ce qui arrive
 *
 * Le LIEU, pris tel quel a `AiShellPageContext` — l'autorite de T1359. Aucune
 * regle de route n'est reecrite ici, aucune URL, aucun identifiant, aucun slug.
 *
 * La seule chose ajoutee est une FERMETURE : hors des quatre lieux gouvernes,
 * le libelle retombait sur le nom de l'Organization, ce qui presenterait un
 * TENANT comme un LIEU. C'est le defaut fail-open corrige dans `hereLines()` en
 * T1359 ; il ne devait pas reapparaitre dans un pied de page.
 */
class TASK1365ShellComposerUxTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'is_active' => true,
            'slug' => 'org-composer-ux',
            'name' => 'Org Composer UX',
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-task1365-'.$this->organization->id,
            'monthly_budget_usd' => 5.00,
        ]);

        $this->member = User::factory()->complete()->create(['organization_id' => $this->organization->id]);

        app()->instance('current_organization', $this->organization);

        config([
            'ai.fab.enabled' => true,
            'ai.shell.enabled' => true,
            'ai.chatloop.enabled' => true,
            'ai.providers.openai.driver' => 'openai',
            'ai.providers.openai.key' => 'platform-key',
        ]);

        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // A. Les deux phrases sont parties
    // =====================================================================

    /** 1. Les deux notes permanentes ne sont plus rendues, ni en FR ni en EN. */
    public function test_the_two_permanent_notes_are_gone(): void
    {
        foreach (['fr', 'en'] as $locale) {
            app()->setLocale($locale);

            $html = $this->shellHtml();

            $this->assertStringNotContainsString('data-ai-shell-no-publication', $html);
            $this->assertStringNotContainsString('data-ai-shell-context-note', $html);
            $this->assertStringNotContainsString(__('ai.shell_no_publication_note'), $html);
            $this->assertStringNotContainsString(__('ai.shell_context_note'), $html);
        }
    }

    // =====================================================================
    // B. Le lieu, et seulement s'il en est un
    // =====================================================================

    /** 2. Sur une Boucle autorisee, le lieu est nomme. */
    public function test_an_authorised_loop_page_shows_its_place(): void
    {
        $loop = (new LoopService)->createLoop($this->member, 'Boucle Composer');

        $html = $this->pageHtml(route('loops.show', $loop));

        $this->assertStringContainsString('data-ai-shell-here', $html);
        $this->assertStringContainsString(e(__('ai.shell_context_loop', ['name' => 'Boucle Composer'])), $html);
    }

    /** 3. Sur un Dossier autorise aussi. */
    public function test_an_authorised_dossier_page_shows_its_place(): void
    {
        $dossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->member->id,
            'name' => 'Dossier Composer',
        ]);

        $html = $this->pageHtml(route('organization.dossiers.show', ['organization' => $this->organization->slug, 'dossier' => $dossier]));

        $this->assertStringContainsString('data-ai-shell-here', $html);
        $this->assertStringContainsString('Dossier Composer', $html);
    }

    /** 4. Le tableau de bord est un lieu, avec son libelle statique. */
    public function test_the_dashboard_is_a_place(): void
    {
        $html = $this->pageHtml(route('dashboard'));

        $this->assertStringContainsString('data-ai-shell-here', $html);
        $this->assertStringContainsString(
            e(__('ai.shell_context_dashboard', ['name' => $this->organization->name])),
            $html,
        );
    }

    /**
     * 5. Une page NON gouvernee n'affiche AUCUN lieu.
     *
     * Et surtout pas le nom de l'Organization : un tenant n'est pas un lieu.
     * Ce test garde le meme fail-open que `hereLines()` en T1359.
     */
    public function test_an_ungoverned_page_shows_no_place_at_all(): void
    {
        $html = $this->pageHtml(route('profile.edit'));

        $this->assertStringNotContainsString('data-ai-shell-here', $html);
    }

    /**
     * 6. Une Boucle REFUSEE n'affiche aucun lieu.
     *
     * Le contexte de page est re-resolu : un objet qui ne passe pas sa garde
     * donne `other`, donc aucun lieu — jamais le nom de l'objet refuse.
     */
    public function test_a_refused_object_shows_no_place(): void
    {
        $otherOrganization = Organization::factory()->create(['is_active' => true, 'slug' => 'org-composer-etrangere']);
        $stranger = User::factory()->complete()->create(['organization_id' => $otherOrganization->id]);
        $foreignLoop = (new LoopService)->createLoop($stranger, 'Boucle Etrangere Composer');

        // La page elle-meme refuse : le Shell ne peut donc rien nommer, et
        // c'est exactement le point — la garde de la page precede le Shell.
        $this->actingAs($this->member)
            ->get(route('loops.show', $foreignLoop))
            ->assertNotFound();

        $html = $this->pageHtml(route('dashboard'));

        $this->assertStringNotContainsString('Boucle Etrangere Composer', $html);
    }

    /** 7. Le lieu ne transporte ni URL, ni chemin, ni identifiant, ni slug. */
    public function test_the_place_carries_no_raw_route_data(): void
    {
        $loop = (new LoopService)->createLoop($this->member, 'Boucle Sans Identifiant');

        $fragment = $this->hereFragment(route('loops.show', $loop));

        $this->assertNotSame('', $fragment);
        $this->assertStringNotContainsString((string) $loop->id, $fragment);
        $this->assertStringNotContainsString($this->organization->slug, $fragment);
        $this->assertStringNotContainsString('http', $fragment);
        $this->assertStringNotContainsString('/loops', $fragment);
    }

    /** 8. FR et EN rendent le lieu dans la langue de l'interface. */
    public function test_the_place_follows_the_interface_language(): void
    {
        $loop = (new LoopService)->createLoop($this->member, 'Boucle Bilingue');

        $this->member->forceFill(['preferred_locale' => 'fr'])->saveQuietly();
        $french = $this->hereFragment(route('loops.show', $loop));

        $this->member->forceFill(['preferred_locale' => 'en'])->saveQuietly();
        $english = $this->hereFragment(route('loops.show', $loop));

        // Les attendus viennent des fichiers de langue, jamais d'une chaine
        // recopiee a la main : une TASK qui reformule le libelle ne doit pas
        // faire passer ce test pour une mauvaise raison.
        app()->setLocale('fr');
        $expectedFrench = e(__('ai.shell_context_loop', ['name' => 'Boucle Bilingue']));
        app()->setLocale('en');
        $expectedEnglish = e(__('ai.shell_context_loop', ['name' => 'Boucle Bilingue']));

        $this->assertNotSame($expectedFrench, $expectedEnglish, 'Le pre-requis du test : les deux libelles different.');

        $this->assertStringContainsString($expectedFrench, $french);
        $this->assertStringContainsString($expectedEnglish, $english);
        $this->assertStringNotContainsString($expectedEnglish, $french);
    }

    /**
     * 9. Le lieu est affiche UNE SEULE FOIS.
     *
     * Il vivait dans l'en-tete du panneau depuis T1359. Le poser aussi sous le
     * composer aurait affiche la meme chaine deux fois dans un panneau de
     * 26rem. Il descend donc la ou Cyril l'a demande, et il n'y a plus qu'un
     * seul endroit ou il apparait.
     */
    public function test_the_place_is_shown_exactly_once(): void
    {
        $loop = (new LoopService)->createLoop($this->member, 'Boucle Unique');

        // La langue de la PAGE vient de la personne, pas du processus de test :
        // on la fixe, puis on construit l'attendu dans la meme langue.
        $this->member->forceFill(['preferred_locale' => 'fr'])->saveQuietly();

        $html = $this->pageHtml(route('loops.show', $loop));

        // On compte les MARQUEURS DE RENDU, pas les occurrences de la chaine :
        // l'instantane Livewire serialise `context.label` et `here` dans le
        // HTML, donc un substr_count sur le libelle compterait des donnees, pas
        // des affichages. Mesure faite : 3 occurrences pour UN seul rendu.
        $this->assertSame(1, substr_count($html, 'data-ai-shell-here'), 'Le lieu doit etre rendu exactement une fois.');
        $this->assertStringNotContainsString('data-ai-shell-context-label', $html);

        app()->setLocale('fr');
        $this->assertStringContainsString(
            e(__('ai.shell_context_loop', ['name' => 'Boucle Unique'])),
            $html,
        );
    }

    // =====================================================================
    // C. Le composer ressemble et reagit comme celui du ChatLoop
    // =====================================================================

    /**
     * 9. Le contrat clavier est celui du ChatLoop, MOT POUR MOT.
     *
     * On ne compare pas a une chaine ecrite a la main : on lit l'expression
     * dans le composer partage et on exige la meme, a l'appel Livewire pres.
     * Si quelqu'un change le ChatLoop demain, ce test le dit.
     */
    public function test_the_keyboard_contract_is_the_chatloop_one(): void
    {
        $chatloop = file_get_contents(resource_path('views/components/conversation/composer.blade.php'));

        $this->assertStringContainsString(
            '@keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); $wire.sendMessage() }"',
            $chatloop,
            'Le contrat de reference a change dans le ChatLoop : ce test doit etre relu, pas contourne.',
        );

        $shell = file_get_contents(resource_path('views/livewire/ai-shell.blade.php'));

        $this->assertStringContainsString(
            '@keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); $wire.send() }"',
            $shell,
        );
    }

    /** 10. L'icone d'envoi du Shell est celle du ChatLoop. */
    public function test_the_send_icon_is_the_chatloop_one(): void
    {
        $path = 'M12 19V5m0 0l-7 7m7-7l7 7';

        $this->assertStringContainsString(
            $path,
            file_get_contents(resource_path('views/components/conversation/composer.blade.php')),
        );

        $html = $this->shellHtml();

        $this->assertStringContainsString($path, $html);
        // L'avion en papier d'avant ne doit plus etre nulle part.
        $this->assertStringNotContainsString('m5 12 14-7-4 7 4 7-14-7Z', $html);
    }

    /**
     * 11. Les DEUX boutons d'envoi prennent la couleur au theme.
     *
     * Le token `--bp-primary` est « Action principale » de /admin/themes. Un
     * indigo en dur survivrait au changement de theme : c'est precisement ce
     * qu'on retire.
     */
    public function test_both_send_buttons_take_their_colour_from_the_theme(): void
    {
        $shell = file_get_contents(resource_path('views/livewire/ai-shell.blade.php'));
        $chatloop = file_get_contents(resource_path('views/components/conversation/composer.blade.php'));

        foreach (['shell' => $shell, 'chatloop' => $chatloop] as $name => $source) {
            $this->assertStringContainsString('var(--bp-primary)', $source, $name);
        }

        $this->assertStringNotContainsString('bg-indigo-600', $chatloop);

        $html = $this->shellHtml();

        $this->assertMatchesRegularExpression(
            '/data-ai-shell-send[^>]*background-color:\s*var\(--bp-primary\)/s',
            $html,
        );
    }

    /** 12. L'etat desactive reste desactive pendant l'envoi. */
    public function test_the_send_button_keeps_its_disabled_contract(): void
    {
        $html = $this->shellHtml();

        $this->assertMatchesRegularExpression('/data-ai-shell-send[^>]*/s', $html);
        $this->assertStringContainsString('wire:loading.attr="disabled"', $html);
        $this->assertStringContainsString('disabled:opacity-50', $html);
    }

    /**
     * 13. Un message vide n'est pas envoye — le contrat SERVEUR est intact.
     *
     * Le brouillon est vide avant tout travail (anti-double-envoi de T1311), et
     * un prompt vide sort immediatement : le clavier ne peut donc pas fabriquer
     * un tour a partir de rien, quoi qu'il envoie.
     */
    public function test_an_empty_message_is_not_sent(): void
    {
        Livewire::actingAs($this->member)->test(AiShell::class)
            ->set('draft', '   ')
            ->call('send')
            ->assertSet('draft', '');

        $this->assertSame(0, AiShellMessage::query()->count());
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function shellHtml(): string
    {
        return Livewire::actingAs($this->member)->test(AiShell::class)->html();
    }

    /**
     * Le HTML d'une VRAIE page.
     *
     * `AiShell::mount()` derive son contexte de la requete via `forRequest()`,
     * et `$contextKind` est `#[Locked]` — a juste titre. Il n'existe donc aucun
     * moyen honnete de simuler un lieu : on visite la page, comme un humain.
     */
    private function pageHtml(string $url): string
    {
        $response = $this->actingAs($this->member)->get($url);

        $response->assertOk();

        return $response->getContent();
    }

    /** Le fragment du pied de page qui porte le lieu, ou une chaine vide. */
    private function hereFragment(string $url): string
    {
        $html = $this->pageHtml($url);

        if (! preg_match('/<p[^>]*data-ai-shell-here[^>]*>(.*?)<\/p>/s', $html, $matches)) {
            return '';
        }

        return $matches[1];
    }
}
