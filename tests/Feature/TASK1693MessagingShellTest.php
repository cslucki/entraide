<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1693, bug AQ — le cadre de la Messagerie.
 *
 * ## Deux defauts, une meme origine
 *
 * 1. **Le rail gauche disparaissait** des qu'on ouvrait une conversation, sur
 *    ORDINATEUR. Au bug AC j'ai ajoute `&& ! $_conversationOuverte` a UNE
 *    condition qui enveloppait cinq elements ; l'intention ne portait que sur
 *    le bas de l'ecran d'un telephone, elle a emporte le rail.
 *
 * 2. **Deux fleches « < »** sur telephone : celle de la barre du haut menait au
 *    TABLEAU DE BORD — un cran de trop — et c'est ce qui rendait indispensable
 *    une seconde fleche dans l'en-tete du fil.
 *
 * Les deux se corrigent ensemble : la barre du haut remonte d'un niveau, et la
 * seconde fleche n'a plus de raison d'etre.
 */
class TASK1693MessagingShellTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    private Transaction $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1693aq',
            'is_active' => true,
            'is_public' => true,
        ]);

        $this->membre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);

        $vendeur = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
        ]);

        $service = Service::factory()->forUser($vendeur)->create([
            'organization_id' => $this->organization->id,
        ]);

        $this->conversation = Transaction::factory()
            ->forService($service)
            ->forBuyer($this->membre)
            ->create(['organization_id' => $this->organization->id]);

        app()->instance('current_organization', $this->organization);

        Http::preventStrayRequests();
        Http::fake();
    }

    /**
     * L'IA devient utilisable pour cette Organization.
     *
     * Le FAB suit deja cette disponibilite depuis TASK-1685 : sans credential
     * il n'est pas rendu DU TOUT, et les deux tests de l'assistant
     * mesureraient cette absence-la au lieu de la garde du bug AR.
     */
    private function allumerLIA(): void
    {
        \App\Models\OrganizationAiSetting::query()->updateOrCreate(
            ['organization_id' => $this->organization->id],
            ['is_enabled' => true, 'provider' => 'openai', 'model' => 'gpt-4o-mini', 'api_key' => 'sk-test'],
        );

        app()->forgetInstance(\App\Support\Ai\AiSurfaceGate::class);
    }

    private function urlConversation(): string
    {
        return route('organization.messages.show', [
            'organization' => $this->organization->slug,
            'transaction' => $this->conversation,
        ]);
    }

    private function urlListe(): string
    {
        return route('organization.messages.index', ['organization' => $this->organization->slug]);
    }

    private function page(string $url): string
    {
        return $this->actingAs($this->membre)->get($url)->assertOk()->getContent();
    }

    /** LE defaut signale : le rail reste sur une conversation ouverte. */
    public function test_the_left_rail_survives_an_open_conversation(): void
    {
        $this->assertStringContainsString('data-bp-side-nav', $this->page($this->urlConversation()));
    }

    /** Et il etait bien la sur la LISTE : la comparaison fait la preuve. */
    public function test_the_left_rail_is_there_on_the_list_too(): void
    {
        $this->assertStringContainsString('data-bp-side-nav', $this->page($this->urlListe()));
    }

    /**
     * La barre du bas reste RETIREE sur une conversation.
     *
     * C'etait l'intention du bug AC, et la separation des conditions ne doit
     * pas la defaire : sans ce test, restaurer le rail pourrait tout ramener.
     */
    public function test_the_bottom_bar_stays_away_on_a_conversation(): void
    {
        $this->assertStringContainsString(
            'data-bp-shell-bottom-nav="0"',
            $this->page($this->urlConversation()),
        );

        $this->assertStringContainsString(
            'data-bp-shell-bottom-nav="1"',
            $this->page($this->urlListe()),
        );
    }

    /**
     * TASK-1693 bug AR — l'assistant revient sur ORDINATEUR.
     *
     * Le bug AC l'avait emporte avec la barre du bas. Il est de nouveau rendu
     * sur une conversation, mais efface sur TELEPHONE par `hidden md:inline-flex` :
     * il s'y poserait au-dessus de la zone d'ecriture.
     */
    public function test_the_assistant_comes_back_on_desktop_only(): void
    {
        $this->allumerLIA();

        $conversation = $this->page($this->urlConversation());

        $this->assertStringContainsString('data-ai-fab-toggle', $conversation,
            'l\'assistant doit etre atteignable depuis une conversation');
        $this->assertMatchesRegularExpression(
            '/bp-ai-trigger[^"]*\bhidden md:inline-flex\b/',
            $conversation,
            'et efface sur telephone, ou il couvrirait la zone d\'ecriture',
        );
    }

    /** Ailleurs, il reste visible sur les DEUX tailles d'ecran. */
    public function test_the_assistant_is_not_hidden_elsewhere(): void
    {
        $this->allumerLIA();

        $liste = $this->page($this->urlListe());

        $this->assertStringContainsString('data-ai-fab-toggle', $liste);
        $this->assertDoesNotMatchRegularExpression(
            '/bp-ai-trigger[^"]*\bhidden md:inline-flex\b/',
            $liste,
        );
    }

    /** Le retour de la barre du haut mene a la LISTE, pas au tableau de bord. */
    public function test_the_top_back_goes_one_level_up(): void
    {
        $html = $this->page($this->urlConversation());

        // Viser la DESTINATION de la fleche, pas la presence de l'adresse dans
        // la page : la liste y figure aussi via le rail et les liens du fil.
        // Ma premiere version restait verte avec une fleche pointant vers le
        // tableau de bord.
        $this->assertStringContainsString('data-bp-topbar-back="'.$this->urlListe().'"', $html);
        $this->assertStringNotContainsString(
            'data-bp-topbar-back="'.route('organization.dashboard', ['organization' => $this->organization->slug]).'"',
            $html,
        );
    }

    /** Et il n'y a plus qu'UNE fleche de retour. */
    public function test_there_is_only_one_back_arrow(): void
    {
        $html = $this->page($this->urlConversation());

        $this->assertSame(0, substr_count($html, 'aria-label="'.e(__('messages.back_to_conversations')).'"'),
            'la seconde fleche, dans l\'en-tete du fil, doit avoir disparu');
    }
}
