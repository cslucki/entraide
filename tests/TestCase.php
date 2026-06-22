<?php

namespace Tests;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
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
    }
}
