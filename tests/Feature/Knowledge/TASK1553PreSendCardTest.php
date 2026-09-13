<?php

namespace Tests\Feature\Knowledge;

use App\Livewire\AiShell;
use App\Models\AiShellMessage;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Ai\AiShellResponder;
use App\Services\Loops\LoopRootDocumentService;
use App\Support\Ai\AiShellThread;
use App\Support\Ai\AiShellTurnCards;
use App\Support\Loops\HelpRequestHandoff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1553 — W2-2 : la demande preparee dit sur quoi elle se fonde AVANT
 * l'envoi.
 *
 * Ce que ces tests protegent :
 *
 *  - la carte MONTRE le fondement au lieu de le compter : aucun compteur n'est
 *    invente sur une donnee unique et invariable (arbitrage Cockpit §2 bis) ;
 *  - les deux niveaux ne se confondent jamais — le FAIT etabli par le serveur,
 *    et la FORMULATION du modele, qui n'est jamais une preuve ;
 *  - la provenance ne devient JAMAIS une saisie : elle sort du brouillon avant
 *    `flashInput()`, sinon elle repartirait dans `old()` comme si la personne
 *    l'avait tapee ;
 *  - la carte ne promet RIEN : aucun mot de sauvegarde, aucune restauration ;
 *  - le Human Gate ne bouge pas : aucune `ServiceRequest` n'existe apres tout
 *    le parcours, et `store()` n'est pas touche ;
 *  - le compte de membres ne s'affiche que pour une Boucle dont la personne est
 *    membre ACTIVE.
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1553PreSendCardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $camille;

    private User $marin;

    private Loop $aria;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['is_active' => true, 'locale' => 'fr']);
        app()->instance('current_organization', $this->organization);

        $this->camille = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Camille Dubreuil']);
        $this->marin = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Marin Delcourt']);

        $this->aria = $this->boucle('ARIA', [$this->camille, $this->marin]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-tenant-1553',
        ]);

        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-should-not-be-used',
            'ai.fab.enabled' => true,
            'ai.shell.enabled' => true,
            'ai.clarify.enabled' => true,
            'ai_pricing.overrides' => [],
        ]);

        Http::preventStrayRequests();
    }

    // ────────────────────────── la carte dit ce qui est vrai

    public function test_la_carte_nomme_la_destination_et_le_fait_verifie(): void
    {
        $this->deposerDepuisLeShell();

        $html = $this->actingAs($this->camille)->get($this->creerUrl())
            ->assertOk()
            ->assertSee('data-request-presend-card', false)
            ->assertSee('data-request-presend-destination', false)
            ->assertSee('data-request-presend-verified', false)
            ->assertSee(__('requests.presend_destination'))
            ->assertSee('ARIA')
            ->assertSee(__('loops.help_request_suggested_loop_verified_active_membership'))
            ->getContent();

        // Le compte de membres : deux membres actifs, dit comme tel.
        $this->assertStringContainsString('data-request-presend-members="2"', $html);
        $this->assertStringContainsString(trans_choice('requests.presend_members', 2), $html);
    }

    public function test_la_carte_nomme_l_origine_du_brouillon(): void
    {
        $this->deposerDepuisLeShell();

        $this->actingAs($this->camille)->get($this->creerUrl())
            ->assertOk()
            ->assertSee('data-request-presend-origin="shell"', false)
            ->assertSee(__('requests.presend_origin_shell'));
    }

    /**
     * Arbitrage Cockpit §2 bis : le compteur suit la structure, jamais
     * l'inverse. Sur ce chemin, le seul fondement structure et denombrable est
     * unique et invariable — « fondee sur 1 element » serait decoratif.
     */
    public function test_aucun_compteur_de_fondements_n_est_invente(): void
    {
        $this->deposerDepuisLeShell();

        $texte = strip_tags($this->actingAs($this->camille)->get($this->creerUrl())->getContent());

        foreach (['Fondée sur 1', 'Fondee sur 1', '1 élément', '1 element'] as $interdit) {
            $this->assertStringNotContainsString($interdit, $texte,
                'un compteur sur une donnee unique et invariable est un compteur decoratif');
        }
    }

    public function test_la_carte_ne_promet_aucune_sauvegarde(): void
    {
        $this->deposerDepuisLeShell();

        $texte = strip_tags($this->actingAs($this->camille)->get($this->creerUrl())->getContent());

        foreach (['sauvegardé automatiquement', 'enregistré automatiquement', 'restauré', 'brouillon enregistré'] as $promesse) {
            $this->assertStringNotContainsString($promesse, $texte,
                'le brouillon est un relais ephemere : rien ne doit laisser croire a une sauvegarde');
        }

        // Ce qu'elle dit, en revanche : rien n'est publie sans confirmation.
        $this->assertStringContainsString(
            strip_tags(__('requests.presend_note')),
            html_entity_decode($texte, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        );
    }

    // ─────────────────── la provenance n'est JAMAIS une saisie

    public function test_la_provenance_ne_devient_jamais_une_ancienne_saisie(): void
    {
        $this->deposerDepuisLeShell();

        $this->actingAs($this->camille)->get($this->creerUrl())->assertOk();

        $this->assertNull(old('provenance'),
            '`flashInput()` verserait la provenance dans la saisie, a cote des champs du formulaire');
        // Les VRAIS champs du brouillon, eux, sont bien pre-remplis.
        $this->assertSame((string) $this->aria->id, old('relay_loop_id'));
    }

    public function test_sans_brouillon_aucune_carte_n_apparait(): void
    {
        $this->actingAs($this->camille)->get($this->creerUrl())
            ->assertOk()
            ->assertDontSee('data-request-presend-card', false);
    }

    // ───────────────────── les deux niveaux ne se confondent pas

    public function test_une_formulation_de_modele_est_dite_comme_telle_et_jamais_comme_une_preuve(): void
    {
        app(HelpRequestHandoff::class)->storeDraft($this->camille, $this->organization, [
            'title' => 'Refaire la charpente',
            'description' => 'Je cherche quelqu un pour la charpente.',
            'relay_loop_id' => (string) $this->aria->id,
            'category_id' => null,
            'provenance' => [
                'origin' => 'loop_clarification',
                'verified' => [['type' => 'active_membership', 'loop_id' => (string) $this->aria->id]],
                'ai_wording' => ['text' => 'Cette Boucle reunit les gens du chantier.', 'verified' => false],
            ],
        ]);

        $this->actingAs($this->camille)->get($this->creerUrl())
            ->assertOk()
            ->assertSee('data-request-presend-ai-wording', false)
            ->assertSee('Cette Boucle reunit les gens du chantier.')
            // Le libelle qui la DECLARE non verifiee est obligatoire : sans lui
            // une phrase de modele passerait pour un fait.
            ->assertSee(__('loops.help_request_suggested_loop_ai_wording'))
            ->assertSee(__('loops.help_request_suggested_loop_verified_active_membership'));
    }

    public function test_un_chemin_sans_ia_n_affiche_aucune_formulation(): void
    {
        app(HelpRequestHandoff::class)->storeDraft($this->camille, $this->organization, [
            'title' => '',
            'description' => 'Mes mots a moi.',
            'relay_loop_id' => (string) $this->aria->id,
            'category_id' => null,
            'provenance' => [
                'origin' => 'loop_clarification_unavailable',
                'verified' => [['type' => 'active_membership', 'loop_id' => (string) $this->aria->id]],
                'ai_wording' => null,
            ],
        ]);

        $this->actingAs($this->camille)->get($this->creerUrl())
            ->assertOk()
            ->assertSee('data-request-presend-verified', false)
            ->assertDontSee('data-request-presend-ai-wording', false)
            ->assertSee(__('requests.presend_origin_loop_clarification_unavailable'));
    }

    // ──────────────────────────────────── ACL et Human Gate

    public function test_le_compte_de_membres_ne_sort_pas_d_une_boucle_non_autorisee(): void
    {
        $autre = $this->boucle('BOUCLE FERMEE', [$this->marin]);

        app(HelpRequestHandoff::class)->storeDraft($this->camille, $this->organization, [
            'title' => '', 'description' => 'Ma demande.',
            'relay_loop_id' => (string) $autre->id, 'category_id' => null,
            'provenance' => [
                'origin' => 'shell',
                'verified' => [['type' => 'active_membership', 'loop_id' => (string) $autre->id]],
                'ai_wording' => null,
            ],
        ]);

        // Camille n'est pas membre : la Boucle n'entre pas dans `relayLoops`,
        // donc la carte ne peut ni la nommer ni compter ses membres.
        $html = $this->actingAs($this->camille)->get($this->creerUrl())->assertOk()->getContent();

        $this->assertStringNotContainsString('BOUCLE FERMEE', $html,
            'une Boucle dont on n est pas membre ne se nomme pas');
        $this->assertStringNotContainsString('data-request-presend-members', $html,
            'et ses membres ne se comptent pas');
    }

    public function test_le_parcours_entier_ne_publie_rien(): void
    {
        $this->deposerDepuisLeShell();

        $this->actingAs($this->camille)->get($this->creerUrl())->assertOk();

        $this->assertSame(0, ServiceRequest::query()->count(),
            'preparer, afficher et relire une proposition n ecrit AUCUNE demande');
    }

    // ───────────────────────────────────────────────────── fixtures

    /** Depose un brouillon par le VRAI chemin Shell de W2-1. */
    private function deposerDepuisLeShell(): AiShellMessage
    {
        $thread = app(AiShellThread::class);
        $trigger = $thread->appendUser($this->organization, $this->camille, 'Qui pourrait nous aider sur la charpente ?');
        $tour = $thread->appendAssistant($this->organization, $this->camille, 'Voici ce que je vois.', $trigger, [
            'status' => AiShellResponder::STATUS_NON_INTERACTION,
            'producer' => AiShellResponder::PRODUCER_PEOPLE_MATCHING,
            'suggested_loop_id' => (string) $this->aria->id,
            'cards' => [['type' => AiShellTurnCards::TYPE_LOOP, 'id' => (string) $this->aria->id, 'ai_wording' => null]],
        ]);

        Livewire::actingAs($this->camille)
            ->test(AiShell::class)
            ->call('prepareRequest', $tour->id)
            ->assertRedirect();

        return $tour;
    }

    private function creerUrl(): string
    {
        return route('organization.requests.create', ['organization' => $this->organization->slug]);
    }

    /** @param  list<User>  $membres */
    private function boucle(string $nom, array $membres): Loop
    {
        $loop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->marin->id,
            'name' => $nom,
            'visibility' => 'private',
        ]);

        foreach ($membres as $membre) {
            LoopMember::create([
                'organization_id' => $this->organization->id,
                'loop_id' => $loop->id,
                'user_id' => $membre->id,
                'role' => 'member',
                'status' => 'active',
                'joined_at' => now(),
            ]);
        }

        app(LoopRootDocumentService::class)->ensureRootDossier($loop->fresh());

        return $loop->fresh();
    }
}
