<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| InstallCommand registerMiddleware() regex
|--------------------------------------------------------------------------
| Built 2026-05-12 after Lovable's pass #3 QA: the regex driving auto-
| registration of HarvvContext middleware into a host app's
| bootstrap/app.php silently failed on a stock `laravel new` 13.x install
| because the Laravel 13 default uses `function (Middleware $middleware): void { // }`
| — the `: void` return-type hint between `)` and `{`. The old pattern
| matched only `)\s*\{`, so it fell through to "manual" and `--with-middleware`
| became a silent no-op in CI.
|
| These tests fixture-test the regex itself against every shape Laravel 11
| (no return type), 12 (no return type), and 13 (`: void`) ship by default.
| Adding a fixture for a new framework version is one new test below — far
| cheaper than a full `laravel new` integration test, and catches the
| specific bug class the regex is guarding against.
*/

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/harvv-install-test-' . uniqid();
    File::makeDirectory($this->tmpDir . '/bootstrap', 0755, true);
    File::put($this->tmpDir . '/.env', "APP_NAME=Test\n");
    $this->app->setBasePath($this->tmpDir);
});

afterEach(function () {
    if (isset($this->tmpDir) && File::exists($this->tmpDir)) {
        File::deleteDirectory($this->tmpDir);
    }
});

it('auto-registers middleware on Laravel 13 default (function (...): void { // })', function () {
    $bootstrap = <<<'PHP'
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php')
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
PHP;
    File::put($this->tmpDir . '/bootstrap/app.php', $bootstrap);

    $this->artisan('harvv:install', ['--site-key' => 'deadbeefcafef00d', '--with-middleware' => true, '--no-interaction' => true])
        ->assertExitCode(0);

    $after = File::get($this->tmpDir . '/bootstrap/app.php');
    expect($after)->toContain('Harvv\Laravel\Http\Middleware\HarvvContext');
    expect($after)->toContain(': void'); // must preserve the return-type hint
});

it('auto-registers middleware on Laravel 11/12 default (function (...) { // })', function () {
    $bootstrap = <<<'PHP'
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withMiddleware(function (Middleware $middleware) {
        //
    })->create();
PHP;
    File::put($this->tmpDir . '/bootstrap/app.php', $bootstrap);

    $this->artisan('harvv:install', ['--site-key' => 'deadbeefcafef00d', '--with-middleware' => true, '--no-interaction' => true])
        ->assertExitCode(0);

    expect(File::get($this->tmpDir . '/bootstrap/app.php'))
        ->toContain('Harvv\Laravel\Http\Middleware\HarvvContext');
});

it('is idempotent — re-running --middleware-only does not duplicate the entry', function () {
    $bootstrap = <<<'PHP'
<?php

use Illuminate\Foundation\Configuration\Middleware;

return ->withMiddleware(function (Middleware $middleware): void {
    //
})->create();
PHP;
    File::put($this->tmpDir . '/bootstrap/app.php', $bootstrap);

    $this->artisan('harvv:install', ['--site-key' => 'deadbeefcafef00d', '--with-middleware' => true, '--no-interaction' => true])
        ->assertExitCode(0);
    $this->artisan('harvv:install', ['--middleware-only' => true, '--no-interaction' => true])
        ->assertExitCode(0);

    $contents = File::get($this->tmpDir . '/bootstrap/app.php');
    $occurrences = substr_count($contents, 'Harvv\Laravel\Http\Middleware\HarvvContext');
    expect($occurrences)->toBe(1);
});

it('exits non-zero when --with-middleware is set but auto-register fails', function () {
    // A bootstrap with no withMiddleware() at all — pattern can't match.
    File::put($this->tmpDir . '/bootstrap/app.php', "<?php\nreturn 'no-middleware-here';\n");

    $this->artisan('harvv:install', ['--site-key' => 'deadbeefcafef00d', '--with-middleware' => true, '--no-interaction' => true])
        ->assertFailed();
});

it('does NOT rotate HMAC on re-run without --rotate-hmac', function () {
    // First run — generates a secret.
    File::put($this->tmpDir . '/bootstrap/app.php', "<?php\nreturn 'noop';\n");
    $this->artisan('harvv:install', ['--site-key' => 'deadbeefcafef00d', '--no-middleware' => true, '--no-interaction' => true]);
    $env1 = File::get($this->tmpDir . '/.env');
    preg_match('/HARVV_HMAC_SECRET=(\S+)/', $env1, $m1);
    $secret1 = $m1[1] ?? null;
    expect($secret1)->toStartWith('hlv1_');

    // Second run — must keep the same secret (idempotent).
    $this->artisan('harvv:install', ['--site-key' => 'deadbeefcafef00d', '--no-middleware' => true, '--no-interaction' => true]);
    $env2 = File::get($this->tmpDir . '/.env');
    preg_match('/HARVV_HMAC_SECRET=(\S+)/', $env2, $m2);
    expect($m2[1] ?? null)->toBe($secret1);
});

it('DOES rotate HMAC when --rotate-hmac is passed', function () {
    File::put($this->tmpDir . '/bootstrap/app.php', "<?php\nreturn 'noop';\n");
    $this->artisan('harvv:install', ['--site-key' => 'deadbeefcafef00d', '--no-middleware' => true, '--no-interaction' => true]);
    preg_match('/HARVV_HMAC_SECRET=(\S+)/', File::get($this->tmpDir . '/.env'), $m1);
    $secret1 = $m1[1] ?? null;

    $this->artisan('harvv:install', ['--site-key' => 'deadbeefcafef00d', '--no-middleware' => true, '--rotate-hmac' => true, '--no-interaction' => true]);
    preg_match('/HARVV_HMAC_SECRET=(\S+)/', File::get($this->tmpDir . '/.env'), $m2);
    expect($m2[1] ?? null)->not->toBe($secret1);
    expect($m2[1] ?? null)->toStartWith('hlv1_');
});
