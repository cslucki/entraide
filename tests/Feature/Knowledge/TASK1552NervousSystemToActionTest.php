<?php

namespace Tests\Feature\Knowledge;

use App\Livewire\AiShell;
use App\Models\AiInteraction;
use App\Models\AiShellMessage;
use App\Models\DerivedKnowledgeNote;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Ai\AiShellResponder;
use App\Services\Loops\LoopRootDocumentService;
use App\Support\Ai\AiShellPageContext;
use App\Support\Ai\AiShellThread;
use App\Support\Ai\AiShellTurnCards;
use App\Support\Loops\HelpRequestHandoff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1552 — W2-1 : un tour Nervous System debouche sur la demande d'aide
 * EXISTANTE.
 *
 * Ce que ces tests protegent :
 *
 *  - « Qui peut m'aider ? » ne meurt plus : le tour People porte enfin sa carte
 *    de Boucle et son appel a l'action, et la carte etait DEJA construite pour
 *    les branches documentaires — elle n'atteignait simplement jamais l'ecran ;
 *  - le brouillon contient **les mots de la personne**, jamais la reponse de
 *    l'IA. C'est le seul endroit ou ce chemin pouvait mentir ;
 *  - l'ouverture est bornee par une WHITELIST DE PRODUCTEURS, pas par le
 *    statut : une reponse conversationnelle `direct_reply` ne gagne aucune
 *    carte et aucun geste, meme avec une metadata forgee ;
 *  - le Human Gate ne bouge pas : preparer n'ecrit AUCUN `ServiceRequest`, et
 *    le seul point d'ecriture du depot reste `RequestController::store()` ;
 *  - aucun second appel a `generate()` : le tour NS ne consomme aucun credit
 *    et ne laisse aucune trace de fournisseur.
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1552NervousSystemToActionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $camille;

    private User $marin;

    private Loop $aria;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'is_active' => true,
            'locale' => 'fr',
            'ai_profiles_enabled' => true,
        ]);
        app()->instance('current_organization', $this->organization);

        $this->camille = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Camille Dubreuil']);
        $this->marin = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Marin Delcourt']);

        $this->aria = $this->boucle('ARIA', [$this->marin, $this->camille]);

        MemberAiProfile::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->marin->id,
            'status' => MemberAiProfile::STATUS_PUBLISHED,
            'published_at' => now(),
            'skills' => ['charpente traditionnelle'],
            'help_types' => [],
            'problems_helped' => [],
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-tenant-1552',
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

    // ──────────────────────────── le tour People debouche enfin

    public function test_un_tour_people_porte_sa_carte_de_boucle_et_son_appel_a_l_action(): void
    {
        $tour = $this->tourPeople();

        $this->assertSame(AiShellResponder::STATUS_NON_INTERACTION, $tour->metadata['status'],
            'PREMISSE : le tour People sort bien en NON_INTERACTION');
        $this->assertSame((string) $this->aria->id, $tour->metadata['suggested_loop_id']);

        $cartes = app(AiShellTurnCards::class)->forDisplay($this->organization, $this->camille, $tour);

        // Les cartes n'ont pas ete AJOUTEES par W2-1 : `tourDePeople()` les
        // ecrivait deja, depuis `forAnsweredTurn()` et avec le MEME besoin que
        // le texte. Ce qui manquait etait qu'elles atteignent l'ecran.
        $types = array_column($cartes, 'type');
        $this->assertContains(AiShellTurnCards::TYPE_LOOP, $types);
        $this->assertContains(AiShellTurnCards::TYPE_PERSON, $types,
            'les personnes nommees par le texte ont aussi leur carte, revalidee au rendu');

        $boucle = $cartes[array_search(AiShellTurnCards::TYPE_LOOP, $types, true)];

        $this->assertSame(AiShellTurnCards::CTA_PREPARE_REQUEST, $boucle['cta']);
        $this->assertSame('ARIA', $boucle['title']);
        // Aucun modele n'a formule cette suggestion : aucune phrase d'IA ne
        // doit etre presentee comme telle.
        $this->assertNull($boucle['ai_wording']);
    }

    public function test_la_carte_du_tour_people_est_rendue_a_l_ecran(): void
    {
        $tour = $this->tourPeople();

        Livewire::actingAs($this->camille)
            ->test(AiShell::class)
            ->assertSeeHtml('data-ai-shell-cards-turn="'.$tour->id.'"')
            ->assertSeeHtml('data-ai-shell-card="'.AiShellTurnCards::TYPE_LOOP.'"');
    }

    /**
     * LE point ou ce chemin pouvait mentir.
     *
     * Un tour NS n'a pas de `message_draft`. Retomber sur le contenu du tour
     * semerait le brouillon avec la REPONSE DE L'IA — « voici les personnes
     * qui pourraient aider… » — et la personne arriverait sur le formulaire
     * avec une demande qu'elle n'a jamais ecrite.
     */
    public function test_le_brouillon_contient_les_mots_de_la_personne_jamais_la_reponse_de_l_ia(): void
    {
        $tour = $this->tourPeople();

        Livewire::actingAs($this->camille)
            ->test(AiShell::class)
            ->call('prepareRequest', $tour->id)
            ->assertRedirect();

        $draft = app(HelpRequestHandoff::class)->pullDraft($this->camille, $this->organization);

        $this->assertNotNull($draft, 'le brouillon doit avoir ete depose');
        $this->assertSame(self::QUESTION_PEOPLE, $draft['description'],
            'la description est la phrase de la personne, mot pour mot');
        $this->assertNotSame(trim((string) $tour->content), $draft['description'],
            'et surtout PAS la reponse de l IA');
        $this->assertStringNotContainsString('Marin', (string) $draft['description'],
            'aucun nom propose par le tour ne se glisse dans la demande');

        // Rien n'est devine : ni titre, ni categorie.
        $this->assertSame('', (string) $draft['title']);
        $this->assertNull($draft['category_id']);

        // La Boucle de relais est celle sur laquelle le matching a tourne.
        $this->assertSame((string) $this->aria->id, (string) $draft['relay_loop_id']);
    }

    public function test_preparer_n_ecrit_aucune_demande_le_human_gate_ne_bouge_pas(): void
    {
        $tour = $this->tourPeople();

        $avant = ServiceRequest::query()->count();

        Livewire::actingAs($this->camille)
            ->test(AiShell::class)
            ->call('prepareRequest', $tour->id)
            ->assertRedirect();

        $this->assertSame($avant, ServiceRequest::query()->count(),
            'preparer DEPOSE un brouillon ; publier reste le geste de l humain, et lui seul');
        $this->assertSame(0, ServiceRequest::query()->count());
    }

    public function test_le_tour_people_n_appelle_aucun_fournisseur_et_ne_rappelle_jamais_generate(): void
    {
        $avant = AiInteraction::query()->count();

        $tour = $this->tourPeople();

        // Un tour NS s'intercale AVANT `generate()` : aucun appel de
        // clarification, donc aucune trace ni aucun credit. `Http::preventStrayRequests()`
        // ferait echouer tout appel reel qui partirait malgre tout.
        $this->assertSame($avant, AiInteraction::query()->count(),
            'aucune trace de fournisseur pour un tour Nervous System');
        $this->assertArrayNotHasKey('ai_interaction_id', $tour->metadata);

        Livewire::actingAs($this->camille)
            ->test(AiShell::class)
            ->call('prepareRequest', $tour->id);

        $this->assertSame($avant, AiInteraction::query()->count(),
            'preparer une demande ne rappelle aucun moteur');
    }

    // ─────────────────── la whitelist, et ce qu'elle continue de refuser

    /**
     * L'invariant de T1350, re-affirme depuis l'autre cote : une reponse
     * conversationnelle n'est pas une demande et n'en devient pas une.
     */
    public function test_une_reponse_conversationnelle_ne_gagne_ni_carte_ni_geste(): void
    {
        $tour = $this->tourForge('laravel_ai_sdk', [
            // Meme avec une reference de carte ET une Boucle forgees dans la
            // metadata : le PRODUCTEUR n'est pas dans la whitelist.
            'cards' => [['type' => 'loop', 'id' => (string) $this->aria->id, 'ai_wording' => null]],
            'suggested_loop_id' => (string) $this->aria->id,
        ]);

        $this->assertSame([], app(AiShellTurnCards::class)->forDisplay($this->organization, $this->camille, $tour));

        Livewire::actingAs($this->camille)
            ->test(AiShell::class)
            ->call('prepareRequest', $tour->id)
            ->assertNoRedirect();

        $this->assertFalse(app(HelpRequestHandoff::class)->hasDraft($this->camille, $this->organization));
    }

    public function test_un_tour_bloque_ou_indisponible_ne_debouche_sur_rien(): void
    {
        foreach ([AiShellResponder::STATUS_BLOCKED, AiShellResponder::STATUS_UNAVAILABLE] as $statut) {
            $tour = $this->tourForge(AiShellResponder::PRODUCER_PEOPLE_MATCHING, [
                'cards' => [['type' => 'loop', 'id' => (string) $this->aria->id, 'ai_wording' => null]],
                'suggested_loop_id' => (string) $this->aria->id,
            ], $statut);

            $this->assertSame([], app(AiShellTurnCards::class)->forDisplay($this->organization, $this->camille, $tour),
                "aucune reponse n a eu lieu en `{$statut}` : il n y a rien a proposer");

            Livewire::actingAs($this->camille)
                ->test(AiShell::class)
                ->call('prepareRequest', $tour->id)
                ->assertNoRedirect();
        }

        $this->assertFalse(app(HelpRequestHandoff::class)->hasDraft($this->camille, $this->organization));
    }

    public function test_un_tour_non_interaction_sans_boucle_declaree_n_ouvre_aucun_geste(): void
    {
        // Producteur autorise, mais le tour n'a declare AUCUNE Boucle de
        // relais : il n'y a rien a preparer, et rien n'est devine.
        $tour = $this->tourForge(AiShellResponder::PRODUCER_DOSSIER_DISCOVERY, [
            'cards' => [['type' => 'document', 'kind' => 'dossier', 'id' => (string) $this->aria->id]],
        ]);

        Livewire::actingAs($this->camille)
            ->test(AiShell::class)
            ->call('prepareRequest', $tour->id)
            ->assertNoRedirect();

        $this->assertFalse(app(HelpRequestHandoff::class)->hasDraft($this->camille, $this->organization));
    }

    // ──────────────────────────────────────────── ACL et sabotages

    public function test_une_adhesion_revoquee_entre_la_reponse_et_le_clic_ferme_la_carte_et_le_geste(): void
    {
        $tour = $this->tourPeople();

        LoopMember::query()
            ->where('loop_id', $this->aria->id)
            ->where('user_id', $this->camille->id)
            ->update(['status' => 'removed']);

        $this->assertSame([], app(AiShellTurnCards::class)->forDisplay($this->organization, $this->camille, $tour),
            'la carte est re-resolue a CHAQUE rendu : une Boucle quittee disparait');

        Livewire::actingAs($this->camille)
            ->test(AiShell::class)
            ->call('prepareRequest', $tour->id)
            ->assertNoRedirect();

        $this->assertFalse(app(HelpRequestHandoff::class)->hasDraft($this->camille, $this->organization));
    }

    public function test_le_tour_d_un_tiers_ne_prepare_rien(): void
    {
        $tour = $this->tourPeople();

        Livewire::actingAs($this->marin)
            ->test(AiShell::class)
            ->call('prepareRequest', $tour->id)
            ->assertNoRedirect();

        $this->assertFalse(app(HelpRequestHandoff::class)->hasDraft($this->marin, $this->organization));
    }

    // ──────────────────────── le tour REPONDU, strictement inchange

    public function test_un_tour_repondu_garde_exactement_son_comportement(): void
    {
        $thread = app(AiShellThread::class);
        $trigger = $thread->appendUser($this->organization, $this->camille, 'Ma phrase a moi.');
        $tour = $thread->appendAssistant($this->organization, $this->camille, 'Contenu du tour.', $trigger, [
            'status' => AiShellResponder::STATUS_ANSWERED,
            'producer' => 'clarify_help_request',
            'title' => 'Refaire la charpente',
            'message_draft' => 'Je cherche quelqu un pour refaire la charpente du hangar.',
            'suggested_loop_id' => (string) $this->aria->id,
        ]);

        Livewire::actingAs($this->camille)
            ->test(AiShell::class)
            ->call('prepareRequest', $tour->id)
            ->assertRedirect();

        $draft = app(HelpRequestHandoff::class)->pullDraft($this->camille, $this->organization);

        $this->assertSame('Refaire la charpente', $draft['title']);
        $this->assertSame('Je cherche quelqu un pour refaire la charpente du hangar.', $draft['description'],
            'le brouillon d un tour REPONDU reste celui que la clarification a redige');
    }

    public function test_un_tour_repondu_sans_brouillon_retombe_sur_son_contenu_comme_avant(): void
    {
        $thread = app(AiShellThread::class);
        $trigger = $thread->appendUser($this->organization, $this->camille, 'Ma phrase a moi.');
        $tour = $thread->appendAssistant($this->organization, $this->camille, 'Contenu du tour.', $trigger, [
            'status' => AiShellResponder::STATUS_ANSWERED,
            'producer' => 'clarify_help_request',
            'message_draft' => '',
            'suggested_loop_id' => (string) $this->aria->id,
        ]);

        Livewire::actingAs($this->camille)
            ->test(AiShell::class)
            ->call('prepareRequest', $tour->id)
            ->assertRedirect();

        $draft = app(HelpRequestHandoff::class)->pullDraft($this->camille, $this->organization);

        $this->assertSame('Contenu du tour.', $draft['description'],
            'comportement historique conserve : `?:` retombait deja sur le contenu');
    }

    // ───────────────────────────────────────────────────── fixtures

    private const QUESTION_PEOPLE = 'Qui pourrait nous aider sur la charpente ?';

    /**
     * Un VRAI tour People, produit par le vrai responder : un enonce etablit
     * le referent, puis la question de personnes tombe sur cette Boucle.
     */
    private function tourPeople(): AiShellMessage
    {
        $this->enonce('Le chantier attend une expertise en charpente traditionnelle.');

        $referent = $this->demander('Le projet dont Marin parlait, ca avance ?');
        $this->assertFalse($referent->metadata['reference']['ambiguous'] ?? true,
            'PREMISSE : le referent doit etre etabli et non ambigu');

        $tour = $this->demander(self::QUESTION_PEOPLE);

        $this->assertSame(AiShellResponder::PRODUCER_PEOPLE_MATCHING, $tour->metadata['producer'] ?? null,
            'PREMISSE : le tour doit bien etre un tour People');

        return $tour;
    }

    /** Un tour pose A LA MAIN, pour eprouver les gardes sur un etat choisi. */
    private function tourForge(string $producer, array $extra, string $status = AiShellResponder::STATUS_NON_INTERACTION): AiShellMessage
    {
        $thread = app(AiShellThread::class);
        $trigger = $thread->appendUser($this->organization, $this->camille, 'Ma phrase a moi.');

        return $thread->appendAssistant($this->organization, $this->camille, 'Reponse du tour.', $trigger, [
            'status' => $status,
            'producer' => $producer,
        ] + $extra);
    }

    private function demander(string $prompt): AiShellMessage
    {
        $this->actingAs($this->camille);

        return app(AiShellResponder::class)->respond(
            $this->organization, $this->camille, $prompt,
            ['route' => 'dashboard', 'kind' => AiShellPageContext::KIND_OTHER],
        )['answer'];
    }

    private function enonce(string $texte): DerivedKnowledgeNote
    {
        $quand = now()->subDays(2);

        $message = LoopMessage::create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->aria->id,
            'sender_id' => $this->marin->id,
            'body' => $texte.' (dit en reunion)',
            'type' => 'user',
        ]);

        $message->forceFill(['created_at' => $quand, 'updated_at' => $quand])->save();

        return DerivedKnowledgeNote::create([
            'organization_id' => $this->organization->id,
            'source_type' => DerivedKnowledgeNote::SOURCE_LOOP_CONVERSATION,
            'kind' => DerivedKnowledgeNote::KIND_CLAIM,
            'source_loop_id' => $this->aria->id,
            'dossier_id' => Dossier::query()->where('loop_id', $this->aria->id)->value('id'),
            'subject_key' => (string) Str::uuid(),
            'content' => $texte,
            'source_fingerprint' => hash('sha256', $texte.$this->aria->id),
            'provenance' => [
                'source_loop_message_ids' => [(string) $message->id],
                'derived_by' => 'loop_conversation_knowledge',
            ],
            'observed_at' => $quand,
            'derived_at' => now(),
            'version' => 1,
            'status' => DerivedKnowledgeNote::STATUS_ACTIVE,
        ]);
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
