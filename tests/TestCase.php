<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        // Runs before RefreshDatabase: never migrate/reset a deployed connection.
        if ($app['config']->get('app.env') !== 'testing'
            || $app['config']->get('database.default') !== 'sqlite'
            || $app['config']->get('database.connections.sqlite.database') !== ':memory:'
            || $app['config']->get('database.connections.sqlite.url')) {
            throw new \LogicException('Tests require APP_ENV=testing and isolated SQLite :memory:.');
        }

        return $app;
    }
}
