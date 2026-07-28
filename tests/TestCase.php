<?php

namespace X3Group\Bitrix24\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use X3Group\Bitrix24\Bitrix24ServiceProvider;

/**
 * База для тестов, которым нужен контейнер Laravel и БД.
 * Чистые юнит-тесты наследуют PHPUnit\Framework\TestCase напрямую.
 */
abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [Bitrix24ServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('bitrix24.client_id', 'test-client-id');
        $app['config']->set('bitrix24.client_secret', 'test-client-secret');
        $app['config']->set('bitrix24.scope', 'crm');
        $app['config']->set('bitrix24.log_max_files', 1);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
