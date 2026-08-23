<?php

namespace Tests;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase {
        refreshDatabase as protected refreshDatabaseTrait;
    }

    public function refreshDatabase()
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if ($connection === 'pgsql' && $database !== 'bouclepro_test') {
            throw new RuntimeException("Refusing to run RefreshDatabase on pgsql database [{$database}]. Use [bouclepro_test], never the local runtime database.");
        }

        if ($database === 'bouclepro') {
            throw new RuntimeException('Refusing to run RefreshDatabase on local runtime database [bouclepro].');
        }

        $this->refreshDatabaseTrait();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);

        // TASK-1280 (P0) — isolation reseau des tests, fail-closed.
        //
        // Incident de reference : `Http::fake(['api.openai.com/*' => ...])`
        // sur un banc configure OpenRouter — le motif ne matche jamais, et
        // `Http::fake()` avec motif LAISSE PARTIR vers le reseau reel ce
        // qu'il n'apparie pas (~13 generations reellement facturees).
        //
        // Toute requete sortante non doublee jette desormais
        // StrayRequestException AVANT d'atteindre le reseau. Un test qui veut
        // un provider le double explicitement (`Http::fake`). AUCUNE
        // allowlist, aucun contournement par configuration — un test qui
        // aurait besoin de trafic reel est un test mal concu.
        //
        // Recensement TASK-1280 : tout l'egress de `app/` passe par la
        // facade Http (zero SDK provider, zero Guzzle direct, zero curl).
        // Le tripwire tests/Unit/Architecture/HttpTransportIsolationTest.php
        // fige ce constat : la garde couvre donc TOUS les transports.
        Http::preventStrayRequests();
    }
}
