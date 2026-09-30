<?php

namespace Tests;

use Laravel\Lumen\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Creates the application.
     *
     * @return \Laravel\Lumen\Application
     */
    public function createApplication()
    {
        $database = dirname(__DIR__).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'testing.sqlite';

        if (!file_exists($database)) {
            touch($database);
        }

        foreach ([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $database,
            'ADMIN_TOKEN' => 'test-admin',
            'VK_AUTO_REPLY' => 'false',
            'VK_CONFIRMATION_CODE' => '',
            'LLM_ENABLED' => 'false',
            'CACHE_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
        ] as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        $app = require __DIR__.'/../bootstrap/app.php';

        if ($app->make('config')->get('database.default') !== 'sqlite') {
            throw new \RuntimeException('Tests refused to start because the database is not sqlite.');
        }

        return $app;
    }
}
