<?php

namespace Tests\Feature;

use App\Services\Dossiers\DossierArticleIndexingDispatcher;
use App\Services\Dossiers\DossierFileIndexingDispatcher;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * TASK-1511 — l'indexation RAG etait dispatchee mais JAMAIS consommee.
 *
 * Aucun worker ne tournait sur ce banc : les jobs dormaient indefiniment sur
 * leur queue dediee, et un fichier depose restait « non indexe » sans le
 * moindre signal — ni erreur, ni echec, ni trace. Le cron `schedule:run`
 * tournait pourtant deja chaque minute.
 *
 * Ces tests lisent la ligne EFFECTIVEMENT PLANIFIEE — pas une constante, pas
 * un commentaire — et gardent deux choses :
 *
 *  1. les deux queues d'indexation sont consommees, chaque minute, par un
 *     worker BORNE (il sort quand la file est vide, et de toute facon au bout
 *     d'un temps fixe) ;
 *  2. `default` n'y figure JAMAIS. Elle porte 207 jobs historiques mis en
 *     quarantaine le 23/08/2026 : ils se rapportent, ils ne se consomment pas.
 *
 * Le troisieme test est celui qui a servi : `'--stop-when-empty' => true`
 * rendait `--stop-when-empty='1'`, que Symfony refuse. La commande planifiee
 * aurait echoue chaque minute, EN SILENCE — le meme genre de panne muette que
 * cette TASK repare.
 */
class TASK1511SchedulerIndexingTest extends TestCase
{
    private function indexingEvent(): Event
    {
        $events = array_values(array_filter(
            app(Schedule::class)->events(),
            fn (Event $event) => str_contains((string) $event->command, 'queue:work'),
        ));

        $this->assertCount(1, $events, 'exactement une ligne `queue:work` doit etre planifiee');

        return $events[0];
    }

    public function test_the_two_indexing_queues_are_drained_every_minute(): void
    {
        $event = $this->indexingEvent();
        $command = (string) $event->command;

        $this->assertSame('* * * * *', $event->expression, 'un depot doit etre indexe dans la minute, pas dans l heure');

        foreach ([DossierFileIndexingDispatcher::DEDICATED_QUEUE, DossierArticleIndexingDispatcher::DEDICATED_QUEUE] as $queue) {
            $this->assertStringContainsString($queue, $command, "la queue {$queue} doit etre consommee");
        }
    }

    /**
     * La garde qui compte. `default` porte des jobs historiques en
     * quarantaine : les consommer declencherait des centaines d'indexations
     * d'un coup, sur des documents qui ont pu changer depuis.
     */
    public function test_the_scheduler_never_consumes_the_quarantined_default_queue(): void
    {
        $command = (string) $this->indexingEvent()->command;

        $this->assertSame(1, preg_match("/--queue='([^']+)'/", $command, $m), 'la liste de queues doit etre explicite');

        $queues = array_map('trim', explode(',', $m[1]));

        $this->assertNotContains('default', $queues, 'la queue `default` est en quarantaine depuis le 23/08/2026');
        $this->assertSame(
            [DossierFileIndexingDispatcher::DEDICATED_QUEUE, DossierArticleIndexingDispatcher::DEDICATED_QUEUE],
            $queues,
            'la liste est une allowlist fermee : y ajouter une queue est une decision, pas un detail'
        );
    }

    /**
     * Un worker non borne survivrait a la minute suivante et s'empilerait ;
     * un worker qui n'accepte pas ses propres options echoue en silence.
     */
    public function test_the_worker_is_bounded_and_its_options_are_actually_accepted(): void
    {
        $command = (string) $this->indexingEvent()->command;

        $this->assertStringContainsString('--stop-when-empty', $command, 'le worker doit sortir des que la file est vide');
        $this->assertStringNotContainsString('--stop-when-empty=', $command, "Symfony refuse une valeur sur ce drapeau : la commande echouerait chaque minute, en silence");
        $this->assertSame(1, preg_match('/--max-time=(\d+)/', $command, $m), 'une borne dure de temps est exigee');
        $this->assertGreaterThan(0, (int) $m[1]);
        $this->assertLessThanOrEqual(60, (int) $m[1], 'la borne doit tenir dans la minute du cron');
    }

    /** Deux minutes consecutives ne doivent pas se marcher dessus. */
    public function test_two_consecutive_minutes_cannot_overlap(): void
    {
        $this->assertNotEmpty($this->indexingEvent()->withoutOverlapping, 'sans ce garde-fou, les workers s empilent');
    }
}
