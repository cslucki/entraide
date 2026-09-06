<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Support\Ai\AiEconomicGuard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Ai\RecordsAiConsumption;
use Tests\TestCase;

/**
 * TASK-1352 — la fenetre mensuelle du credit IA se comporte pareil quel que
 * soit le jour ou la CI tourne.
 *
 * ## Ce qui s'est reellement passe
 *
 * Le 2026-09-01, treize tests sont devenus rouges sans qu'une ligne de code ait
 * change — la CI de develop etait verte la veille sur le meme arbre. La cause
 * n'etait ni le code economique ni « le 1er du mois » : c'etait le premier mois
 * entierement POSTERIEUR au cutover du ledger
 * ({@see AiEconomicGuard::LEDGER_AUTHORITY_SINCE_BY_PROCESS}, 2026-08-18).
 *
 * Tant que le mois courant CONTENAIT ce cutover, la trace `ai_interactions`
 * faisait encore autorite sur le debut de la fenetre, et des fixtures qui
 * n'ecrivaient QUE la trace suffisaient. Des septembre, la fenetre est
 * integralement sous l'autorite du ledger : ces memes fixtures ne comptaient
 * plus rien. Elles n'etaient pas « instables », elles etaient perimees — et
 * elles le seraient restees tous les jours suivants.
 *
 * ## Ce que cette suite verrouille
 *
 * Le temps est GELE a des dates explicites, jamais celui du runner : premier
 * jour du mois, milieu de mois, dernier instant du mois, et un mois qui
 * contient le cutover. A chaque date, une consommation posee une seconde AVANT
 * la fenetre reste dehors, et une posee au premier instant de la fenetre entre
 * dedans. La borne est demi-ouverte : [debut du mois, debut du mois suivant[.
 *
 * Si un jour cette suite redevient rouge, ce ne sera pas le calendrier : ce
 * sera la regle de comptage qui aura change.
 */
class TASK1352MonthlyWindowHermeticityTest extends TestCase
{
    use RecordsAiConsumption;
    use RefreshDatabase;

    private Organization $organization;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['name' => 'Org 1352']);
        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-or-task1352',
            'monthly_budget_usd' => null,
        ]);

        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
    }

    protected function tearDown(): void
    {
        // Restauration explicite : aucun test suivant ne doit heriter d'une
        // horloge figee par celui-ci.
        $this->travelBack();

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function referenceDates(): array
    {
        return [
            // Le cas qui a casse la CI : premier mois entierement apres le cutover.
            'premier jour du mois' => ['2026-09-01 06:00:00'],
            'milieu de mois' => ['2026-09-15 10:00:00'],
            'dernier instant du mois' => ['2026-09-30 23:59:59'],
            // Un mois qui CONTIENT le cutover du ledger (2026-08-18) : l'autorite
            // y est mixte, et le resultat doit pourtant etre le meme.
            'mois contenant le cutover' => ['2026-08-25 12:00:00'],
            // Une annee plus tard : la suite ne doit pas vieillir.
            'meme scenario un an plus tard' => ['2027-03-07 08:30:00'],
        ];
    }

    #[DataProvider('referenceDates')]
    public function test_the_window_is_half_open_whatever_the_day_the_suite_runs(string $reference): void
    {
        $this->travelTo(CarbonImmutable::parse($reference, 'UTC'));

        $now = CarbonImmutable::now();
        $windowStart = $now->startOfMonth();

        // Une consommation UNE SECONDE avant la fenetre : dehors.
        $this->recordAiGeneration(
            (string) $this->organization->id,
            (string) $this->member->id,
            'loop_knowledge.answer',
            'loop_knowledge_answer',
            0.01,
            $windowStart->subSecond(),
        );

        // Une consommation au PREMIER instant de la fenetre : dedans.
        $this->recordAiGeneration(
            (string) $this->organization->id,
            (string) $this->member->id,
            'loop_knowledge.answer',
            'loop_knowledge_answer',
            0.01,
            $windowStart,
        );

        $status = app(AiEconomicGuard::class)->userCreditStatus($this->organization, $this->member);

        $this->assertSame(1, $status->used, "Fenetre incorrecte au {$reference}.");
        $this->assertTrue($status->periodStart->equalTo($windowStart));
        $this->assertTrue($status->renewsAt->equalTo($windowStart->addMonth()));
    }

    #[DataProvider('referenceDates')]
    public function test_a_consumption_at_the_last_instant_of_the_window_still_counts(string $reference): void
    {
        $this->travelTo(CarbonImmutable::parse($reference, 'UTC'));

        $windowStart = CarbonImmutable::now()->startOfMonth();

        // Borne haute EXCLUE : une seconde avant le mois suivant compte encore,
        // le premier instant du mois suivant ne compte plus.
        $this->recordAiGeneration(
            (string) $this->organization->id,
            (string) $this->member->id,
            'loop_knowledge.answer',
            'loop_knowledge_answer',
            0.01,
            $windowStart->addMonth()->subSecond(),
        );

        $this->recordAiGeneration(
            (string) $this->organization->id,
            (string) $this->member->id,
            'loop_knowledge.answer',
            'loop_knowledge_answer',
            0.01,
            $windowStart->addMonth(),
        );

        $status = app(AiEconomicGuard::class)->userCreditStatus($this->organization, $this->member);

        $this->assertSame(1, $status->used, "Borne haute incorrecte au {$reference}.");
    }

    public function test_a_doctrine_sandbox_run_never_counts_toward_the_user_credit(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-01 06:00:00', 'UTC'));

        // L'essai de doctrine porte son marqueur sur l'invocation : c'est lui
        // que le ledger regarde pour l'exclure du credit.
        $this->recordAiGeneration(
            (string) $this->organization->id,
            (string) $this->member->id,
            'loop_knowledge.answer',
            \App\Services\Ai\OrganizationDoctrineSandbox::FEATURE,
            0.30,
        );

        $this->recordAiGeneration(
            (string) $this->organization->id,
            (string) $this->member->id,
            'loop_knowledge.answer',
            'loop_knowledge_answer',
            0.01,
        );

        $status = app(AiEconomicGuard::class)->userCreditStatus($this->organization, $this->member);

        $this->assertSame(1, $status->used, 'Seule la generation productive consomme le credit.');
    }
}
