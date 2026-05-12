<?php

declare(strict_types=1);

/**
 * Feature tests for Blade rendering paths.
 *
 *   - @harvv directive emits the pixel script tag
 *   - <x-harvv-pixel /> component emits the same
 *   - Both no-op (empty string) when site_key is missing
 *
 * Uses Blade::render() to compile-and-execute a snippet inline, which is
 * the standard pattern for testing Blade extensions in a package.
 */

use Illuminate\Support\Facades\Blade;

it('the @harvv directive emits the pixel script tag', function () {
    $output = Blade::render('@harvv');

    expect(trim($output))->toContain('<script');
    expect($output)->toContain('async');
    expect($output)->toContain('https://harvv.com/px/5abaa759db95fcf4/pixel.js');
});

it('the <x-harvv-pixel /> component emits the same script tag', function () {
    $output = Blade::render('<x-harvv-pixel />');

    expect(trim($output))->toContain('<script');
    expect($output)->toContain('async');
    expect($output)->toContain('https://harvv.com/px/5abaa759db95fcf4/pixel.js');
});

it('emits nothing when site_key is empty (safe for unconfigured installs)', function () {
    config()->set('harvv.site_key', null);

    // Re-resolve the singleton — singletons persist across tests by default,
    // so we forget + re-bind to pick up the new config. Real Laravel apps
    // boot once per request, so this manual nudge mirrors a real "config
    // changed between requests" scenario.
    app()->forgetInstance('harvv');
    app()->forgetInstance(\Harvv\Laravel\Harvv::class);

    expect(trim(Blade::render('@harvv')))->toBe('');
    expect(trim(Blade::render('<x-harvv-pixel />')))->toBe('');
});

it('emits nothing when enabled is false even with a valid site_key', function () {
    config()->set('harvv.enabled', false);
    app()->forgetInstance('harvv');
    app()->forgetInstance(\Harvv\Laravel\Harvv::class);

    expect(trim(Blade::render('@harvv')))->toBe('');
});

it('renders identical output from directive and component (no drift)', function () {
    $directive = Blade::render('@harvv');
    $component = Blade::render('<x-harvv-pixel />');

    // Both paths delegate to the same renderPixel() — output must be
    // byte-identical. If a future refactor makes them diverge, this
    // test catches it.
    expect(trim($directive))->toBe(trim($component));
});
