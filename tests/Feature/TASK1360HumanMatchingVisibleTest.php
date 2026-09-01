<?php

namespace Tests\Feature;

use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use App\Services\People\RelevantPeopleService;
use App\Support\Ai\AiShellTurnCards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1360 — rendre visible ce qui etait deja livre.
 *
 * People-1 (T1323) et People-2 (T1324) sont livres, cables au Shell et
 * fonctionnels. Ils sont pourtant quasi invisibles : a l'audit du 2026-09-01,
 * 4 profils IA publies sur 57 membres, tous dans l'organisation de fixture.
 *
 * Deux defauts, tous deux d'HONNETETE, aucun de moteur :
 *
 *  A. ETAT VIDE — un tour situe dans une Boucle sans personne a proposer
 *     n'affichait RIEN. Or la doctrine maison dit qu'un refus n'est jamais un
 *     vide silencieux. On le dit desormais, et on ouvre le seul geste qui
 *     change la situation : publier son propre profil.
 *  B. HYGIENE DES RAISONS — le singulier naif s'appliquait AVANT le filtre des
 *     mots vides, donc « dans » devenait « dan », absent de la liste, et se
 *     retrouvait affiche comme motif de rapprochement.
 *
 * Ce que cette TASK ne fait PAS : elargir la portee (la Boucle COURANTE et elle
 * seule), contacter qui que ce soit, ou reveler quoi que ce soit sur les autres
 * membres — ni decompte, ni raison individuelle d'ineligibilite.
 */
class TASK1360HumanMatchingVisibleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'is_active' => true,
            'slug' => 'org-matching',
            'name' => 'Org Matching',
            'ai_profiles_enabled' => true,
        ]);

        $this->member = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'first_name' => 'Lea',
            'name' => 'Matching',
        ]);

        app()->instance('current_organization', $this->organization);

        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // B. L'hygiene des raisons — le defaut le plus visible a l'ecran
    // =====================================================================

    /**
     * 1. Un mot d'emballage n'est JAMAIS une raison de mise en relation.
     *
     * Constate en base avant correction : une carte justifiait un
     * rapprochement par `matched_terms: ["dan"]`, ne du mot « dans ». Ce test
     * echoue contre l'ancien ordre (singulier naif AVANT filtre) et passe
     * contre le nouveau.
     */
    public function test_stop_words_never_become_matching_reasons(): void
    {
        $tokens = $this->tokensOf('Je cherche quelqu\'un dans tous les cas, plus ou moins sous pression, avec vous tres vite');

        foreach (['dan', 'tou', 'plu', 'moin', 'sou', 'vou', 'tre', 'san', 'nou'] as $mutile) {
            $this->assertNotContains($mutile, $tokens, "Le mot d'emballage tronque « {$mutile} » ne doit jamais devenir un token.");
        }
    }

    /** 2. Et un VRAI pluriel reste ramene au singulier : la correction ne casse rien. */
    public function test_real_plurals_are_still_reduced_to_singular(): void
    {
        $tokens = $this->tokensOf('Relecture de budgets et de dossiers pour une association');

        $this->assertContains('budget', $tokens);
        $this->assertContains('dossier', $tokens);
        $this->assertContains('association', $tokens);
    }

    // =====================================================================
    // A. L'etat vide
    // =====================================================================

    /**
     * 3. Une Boucle sans profil publie ecrit un etat vide, pas rien.
     *
     * Et cet etat vide ne dit NI combien de membres compte la Boucle, NI
     * pourquoi telle personne n'est pas proposee.
     */
    public function test_a_loop_without_published_profiles_yields_an_honest_empty_state(): void
    {
        $loop = (new LoopService)->createLoop($this->member, 'Boucle Sans Profils');

        $cards = app(AiShellTurnCards::class)->forAnsweredTurn(
            $this->organization,
            $this->member,
            null,
            ['route' => 'loops.show', 'kind' => 'loop', 'object' => ['type' => 'loop', 'id' => (string) $loop->id, 'label' => $loop->name]],
            'Je cherche un relecteur',
            'help_request',
        );

        $empty = collect($cards)->firstWhere('type', AiShellTurnCards::TYPE_PEOPLE_EMPTY);

        $this->assertNotNull($empty, 'Un tour situe dans une Boucle sans personne ecrit un etat vide.');
        $this->assertSame((string) $loop->id, $empty['loop_id']);

        // La reference stockee ne porte QUE la Boucle : aucune raison, aucun
        // decompte, rien sur les autres membres.
        $this->assertSame(['type', 'loop_id'], array_keys($empty));
    }

    /** 4. A l'affichage, l'etat vide porte son message et son seul geste utile. */
    public function test_the_empty_state_offers_the_ai_profile_path(): void
    {
        $loop = (new LoopService)->createLoop($this->member, 'Boucle Sans Profils');

        $card = $this->displayedEmptyCard($loop->id);

        $this->assertNotNull($card);
        $this->assertSame(__('ai.shell_people_empty'), $card['label']);
        $this->assertSame(__('ai.shell_people_empty_cta'), $card['cta_label']);
        $this->assertStringContainsString('agent-ia', $card['cta_url']);
    }

    /**
     * 5. Des qu'une personne DEVIENT eligible, l'etat vide disparait.
     *
     * Il ne suffit pas qu'il soit autorise : il doit rester VRAI. Un etat vide
     * conserve alors qu'un membre a publie son profil serait un mensonge — et
     * c'est la meme discipline anti-TOCTOU que les autres cartes, appliquee a
     * une affirmation plutot qu'a un droit.
     */
    public function test_the_empty_state_disappears_once_someone_becomes_eligible(): void
    {
        $loop = (new LoopService)->createLoop($this->member, 'Boucle Qui Se Remplit');

        $this->assertNotNull($this->displayedEmptyCard($loop->id), 'Au depart, personne.');

        $other = User::factory()->complete()->create(['organization_id' => $this->organization->id]);
        (new LoopService)->addMember($loop, $other);
        MemberAiProfile::factory()->create([
            'user_id' => $other->id,
            'organization_id' => $this->organization->id,
            'status' => MemberAiProfile::STATUS_PUBLISHED,
            'skills' => ['Relecture'],
        ]);

        $this->assertNull(
            $this->displayedEmptyCard($loop->id),
            'Un etat vide qui survit a l\'arrivee d\'une personne eligible est un mensonge.'
        );
    }

    /**
     * 6. Sur une OFFRE, aucun etat vide non plus.
     *
     * T1350 avait coupe les PersonCards sur une offre : « qui peut m'aider ? »
     * n'a pas de sens quand c'est le membre qui propose. L'etat vide suit la
     * meme regle, sans quoi la coupe serait contournee par le bas.
     */
    public function test_an_offer_never_yields_a_people_empty_state(): void
    {
        $loop = (new LoopService)->createLoop($this->member, 'Boucle Offre');

        $cards = app(AiShellTurnCards::class)->forAnsweredTurn(
            $this->organization,
            $this->member,
            null,
            ['route' => 'loops.show', 'kind' => 'loop', 'object' => ['type' => 'loop', 'id' => (string) $loop->id, 'label' => $loop->name]],
            'Je peux aider en visualisation de donnees',
            AiShellTurnCards::INTENT_OFFER,
        );

        $this->assertNull(collect($cards)->firstWhere('type', AiShellTurnCards::TYPE_PEOPLE_EMPTY));
    }

    /**
     * 7. Hors de toute Boucle, aucun etat vide.
     *
     * `CURRENT_LOOP_ONLY` : sans Boucle en portee, il n'y a pas d'ensemble
     * eligible a evoquer, donc rien d'honnete a dire.
     */
    public function test_without_a_loop_in_scope_there_is_no_empty_state(): void
    {
        $cards = app(AiShellTurnCards::class)->forAnsweredTurn(
            $this->organization,
            $this->member,
            null,
            ['route' => 'dashboard', 'kind' => 'dashboard', 'object' => null],
            'Je cherche un relecteur',
            'help_request',
        );

        $this->assertNull(collect($cards)->firstWhere('type', AiShellTurnCards::TYPE_PEOPLE_EMPTY));
    }

    /**
     * 8. TENANT — une Boucle d'une autre Organization n'affiche rien du tout.
     *
     * Ni personne, ni etat vide : l'etat vide ne doit pas devenir un canal qui
     * confirme l'existence d'une Boucle etrangere.
     */
    public function test_a_foreign_loop_yields_no_card_at_all(): void
    {
        $otherOrganization = Organization::factory()->create(['is_active' => true, 'slug' => 'org-etrangere-1360']);
        $stranger = User::factory()->complete()->create(['organization_id' => $otherOrganization->id]);
        $foreignLoop = (new LoopService)->createLoop($stranger, 'Boucle Etrangere');

        $this->assertNull($this->displayedEmptyCard($foreignLoop->id));
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /** @return list<string> */
    private function tokensOf(string $text): array
    {
        $service = app(RelevantPeopleService::class);

        $tokens = (fn (string $t): array => $this->tokens($t))->call($service, $text);

        return $tokens;
    }

    /**
     * La carte telle qu'elle serait RENDUE. Une instance NEUVE a chaque appel :
     * `AiShellTurnCards` memoise l'eligibilite par Boucle, et le test 5 mesure
     * precisement un changement d'eligibilite entre deux rendus.
     *
     * @return array<string, mixed>|null
     */
    private function displayedEmptyCard(string $loopId): ?array
    {
        $organization = $this->organization;
        $user = $this->member;

        return (fn (): ?array => $this->peopleEmptyCard($organization, $user, ['loop_id' => $loopId]))
            ->call(app()->make(AiShellTurnCards::class));
    }
}
