<?php

declare(strict_types=1);

/**
 * Pest bootstrap.
 *
 * Tells Pest to use our Orchestra Testbench-backed TestCase for every
 * test file in tests/Feature and tests/Unit. Tests can then use the
 * full Laravel app helpers (view(), config(), Route::, etc.) without
 * a `extends` line on every spec.
 *
 * See https://pestphp.com/docs/configuring-tests for details.
 */

use Harvv\Laravel\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');
