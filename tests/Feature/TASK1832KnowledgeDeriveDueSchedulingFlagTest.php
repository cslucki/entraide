<?php

namespace Tests\Feature;

use App\Services\Knowledge\LoopConversationKnowledgeDispatcher;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1832 — `knowledge:derive-due` dispatche des jobs qui, sous
 * `QUEUE_CONNECTION=sync` (PROD aujourd'hui), s'executent EN LIGNE dans le
 * processus du Scheduler : aucune strategie asynchrone validee pour ce
 * traitement (contrairement aux notifications, T1829/T1830). Mesure en PROD
 * le 15/11/2026 : 12 Boucles eligibles au premier passage, sous le plafond
 * `MAX_PAR_BALAYAGE=25` du dispatcher — mais rien ne garantit que ce nombre
 * reste petit avant qu'une strategie async soit validee.
 *
 * `config('knowledge.derive_due_scheduled_enabled')` (FAUX par defaut) gouverne
 * uniquement la ligne PLANIFIEE. L'executable manuel reste intact.
 *
 * `routes/console.php` ne s'execute qu'au BOOT : changer `config()` en cours
 * de test ne change rien a ce qui est deja planifie. `refreshApplication()`
 * reboote une application neuve, qui relit l'environnement — idiome deja
 * employe par ce depot (T1782, T1367). Le marqueur est pose dans les TROIS
 * sources que `env()` consulte (`putenv`, `$_ENV`, `$_SERVER`), et restaure a
 * l'identique en `tearDown()` : la lecon de T1367 est qu'oublier l'une des
 * trois laisse fuiter une valeur dans les tests suivants.
 */
class TASK1832KnowledgeDeriveDueSchedulingFlagTest extends TestCase
{
    use RefreshDatabase;

    private const FLAG = 'KNOWLEDGE_DERIVE_DUE_SCHEDULED_ENABLED';

    /** @var array{env: string|false, superglobal_env: string|null, superglobal_server: string|null} */
    private array $original;

    protected function setUp(): void
    {
        parent::setUp();

        $this->original = [
            'env' => getenv(self::FLAG),
            'superglobal_env' => $_ENV[self::FLAG] ?? null,
            'superglobal_server' => $_SERVER[self::FLAG] ?? null,
        ];
    }

    protected function tearDown(): void
    {
        $value = $this->original['env'];
        $value === false ? putenv(self::FLAG) : putenv(self::FLAG.'='.$value);

        if ($this->original['superglobal_env'] === null) {
            unset($_ENV[self::FLAG]);
        } else {
            $_ENV[self::FLAG] = $this->original['superglobal_env'];
        }

        if ($this->original['superglobal_server'] === null) {
            unset($_SERVER[self::FLAG]);
        } else {
            $_SERVER[self::FLAG] = $this->original['superglobal_server'];
        }

        parent::tearDown();
    }

    private function declareFlag(bool $valeur): void
    {
        $chaine = $valeur ? 'true' : 'false';

        putenv(self::FLAG.'='.$chaine);
        $_ENV[self::FLAG] = $chaine;
        $_SERVER[self::FLAG] = $chaine;

        $this->refreshApplication();
    }

    /** @return list<Event> */
    private function scheduledDeriveDueEvents(): array
    {
        return array_values(array_filter(
            app(Schedule::class)->events(),
            fn (Event $event) => str_contains((string) $event->command, 'knowledge:derive-due'),
        ));
    }

    public function test_sans_drapeau_knowledge_derive_due_n_est_jamais_planifie(): void
    {
        $this->declareFlag(false);

        $this->assertFalse((bool) config('knowledge.derive_due_scheduled_enabled'));
        $this->assertCount(
            0,
            $this->scheduledDeriveDueEvents(),
            'knowledge:derive-due ne doit JAMAIS etre planifie sans strategie asynchrone validee'
        );
    }

    public function test_variable_environnement_absente_desactive_la_planification(): void
    {
        putenv(self::FLAG);
        unset($_ENV[self::FLAG], $_SERVER[self::FLAG]);
        $this->refreshApplication();

        $this->assertFalse(
            (bool) config('knowledge.derive_due_scheduled_enabled'),
            'une variable ABSENTE ne doit jamais allumer cette planification'
        );
        $this->assertCount(0, $this->scheduledDeriveDueEvents());
    }

    public function test_avec_le_drapeau_knowledge_derive_due_est_planifie_toutes_les_dix_minutes(): void
    {
        $this->declareFlag(true);

        $events = $this->scheduledDeriveDueEvents();

        $this->assertCount(1, $events, 'exactement une ligne doit etre planifiee quand le drapeau est pose');
        $this->assertSame('*/10 * * * *', $events[0]->expression, 'dix minutes, pas une minute : la fenetre d\'inactivite est de dix minutes');
        $this->assertNotEmpty($events[0]->withoutOverlapping, 'deux passages consecutifs ne doivent pas se marcher dessus');
    }

    /**
     * L'executable ne lit jamais ce drapeau — il gouverne uniquement la ligne
     * PLANIFIEE. `config()->set()` suffit donc ici : nul besoin de reboot, et
     * la connexion sqlite `:memory:` migree par `RefreshDatabase` reste intacte.
     */
    public function test_l_execution_manuelle_reste_disponible_sans_le_drapeau(): void
    {
        config(['knowledge.derive_due_scheduled_enabled' => false]);

        $this->artisan('knowledge:derive-due')->assertExitCode(0);
    }

    public function test_l_execution_manuelle_reste_disponible_avec_le_drapeau(): void
    {
        config(['knowledge.derive_due_scheduled_enabled' => true]);

        $this->artisan('knowledge:derive-due')->assertExitCode(0);
    }

    /**
     * La file DEDIEE reste drainee par le worker planifie (T1511/T1539) quel
     * que soit ce drapeau : ce n'est pas elle qui pose le risque — c'est le
     * balayage qui PRODUIT le travail, pas le worker qui le CONSOMME.
     */
    public function test_la_file_dediee_reste_dans_l_allowlist_du_worker_quel_que_soit_le_drapeau(): void
    {
        foreach ([false, true] as $drapeau) {
            $this->declareFlag($drapeau);

            $evenement = collect(app(Schedule::class)->events())
                ->first(fn (Event $e) => str_contains((string) $e->command, 'queue:work'));

            $this->assertNotNull($evenement);
            $this->assertStringContainsString(
                LoopConversationKnowledgeDispatcher::DEDICATED_QUEUE,
                (string) $evenement->command,
                'le worker planifie doit toujours pouvoir drainer cette file, drapeau pose ou non'
            );
        }
    }
}
