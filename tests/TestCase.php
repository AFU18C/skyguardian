<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Bootstrap the application and stop immediately if PHPUnit resolved the
     * production environment or the production SQLite database.
     */
    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $environment = (string) $app->environment();
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        $productionDatabase = realpath(database_path('database.sqlite'))
            ?: database_path('database.sqlite');
        $resolvedDatabase = $database === ':memory:'
            ? ':memory:'
            : (realpath($database) ?: $database);

        if (
            $environment !== 'testing'
            || $connection !== 'sqlite'
            || $resolvedDatabase !== ':memory:'
            || $resolvedDatabase === $productionDatabase
        ) {
            throw new RuntimeException(sprintf(
                'Tests aborted: unsafe environment detected (APP_ENV=%s, DB_CONNECTION=%s, DB_DATABASE=%s). Clear the Laravel configuration cache and use an isolated testing database.',
                $environment,
                $connection,
                $database,
            ));
        }

        return $app;
    }
}
