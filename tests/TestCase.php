<?php

declare(strict_types=1);

namespace Harvv\Laravel\Tests;

use Harvv\Laravel\HarvvServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base test case for the package.
 *
 * Uses Orchestra Testbench, which spins up a minimal Laravel application
 * inside the test process — same container, same Blade compiler, same
 * service-resolution rules as a real Laravel app, without the cost of
 * a full app skeleton. Standard pattern for testing Laravel packages.
 */
abstract class TestCase extends Orchestra
{
    /**
     * Register the package's service provider in the test app.
     * Testbench auto-discovers this via the providers list — same path
     * a real Laravel app would use to load the package after
     * `composer require`.
     */
    protected function getPackageProviders($app): array
    {
        return [
            HarvvServiceProvider::class,
        ];
    }

    /**
     * Default test-app config. Each test can override these on its own
     * Testbench instance via `config()->set(...)` in setUp() or inline.
     * Defaults here mirror what a fresh install sees after running
     * `harvv:install` — keeps test scenarios realistic.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('harvv.site_key', '5abaa759db95fcf4');  // test key — matches the dogfood site
        $app['config']->set('harvv.host', 'https://harvv.com');
        $app['config']->set('harvv.enabled', true);
    }
}
