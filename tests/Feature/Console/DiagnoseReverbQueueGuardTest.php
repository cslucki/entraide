<?php

namespace Tests\Feature\Console;

use Tests\TestCase;

/**
 * TASK-1850 — commande de DIAGNOSTIC seule, jamais appelee
 * automatiquement : voir le docblock de `DiagnoseReverbQueueGuard`.
 */
class DiagnoseReverbQueueGuardTest extends TestCase
{
    public function test_queue_connection_sync_ne_remonte_aucun_risque(): void
    {
        config(['queue.default' => 'sync']);

        $this->artisan('reverb:diagnose-queue-guard')
            ->expectsOutputToContain("QUEUE_CONNECTION='sync'")
            ->assertExitCode(0);
    }

    public function test_queue_connection_asynchrone_avertit_du_risque_default(): void
    {
        config(['queue.default' => 'database']);

        $this->artisan('reverb:diagnose-queue-guard')
            ->expectsOutputToContain('ShouldBroadcast')
            ->expectsOutputToContain('default')
            ->assertExitCode(1);
    }
}
