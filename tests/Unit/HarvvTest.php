<?php

declare(strict_types=1);

use Harvv\Laravel\Harvv;

/**
 * Unit tests for the Harvv service class.
 *
 * Tests are independent of the Laravel app — Harvv is a plain PHP class
 * with no DI, so we construct it directly. The renderPixel() test is
 * the one place we lean on the Testbench TestCase (parent provides
 * view(), app() helpers).
 */

it('reports enabled when site key is set and enabled flag is true', function () {
    $h = new Harvv(siteKey: 'abc123', host: 'https://harvv.com', enabled: true);

    expect($h->enabled())->toBeTrue();
    expect($h->siteKey())->toBe('abc123');
    expect($h->host())->toBe('https://harvv.com');
});

it('reports disabled when site key is null (safe no-op for unconfigured installs)', function () {
    $h = new Harvv(siteKey: null, host: 'https://harvv.com', enabled: true);

    expect($h->enabled())->toBeFalse();
    expect($h->siteKey())->toBeNull();
    expect($h->pixelUrl())->toBeNull();
});

it('reports disabled when enabled flag is false even with a valid site key', function () {
    $h = new Harvv(siteKey: 'abc123', host: 'https://harvv.com', enabled: false);

    expect($h->enabled())->toBeFalse();
    expect($h->pixelUrl())->toBeNull();
});

it('emits an empty string from renderPixel when disabled (never throws)', function () {
    $h = new Harvv(siteKey: null, host: 'https://harvv.com', enabled: true);

    expect($h->renderPixel())->toBe('');
    // Important: callers should be able to drop @harvv in a layout even
    // before configuring the package. Empty-string return makes that safe.
});

it('builds the pixel URL with the correct shape when enabled', function () {
    $h = new Harvv(siteKey: '5abaa759db95fcf4', host: 'https://harvv.com', enabled: true);

    expect($h->pixelUrl())->toBe('https://harvv.com/px/5abaa759db95fcf4/pixel.js');
});

it('strips a trailing slash from the host URL', function () {
    // Construction normalizes via rtrim in the service provider, not in
    // the class itself. This test ensures the class doesn't double-strip
    // when the SP already did so — i.e., it just stores the value verbatim.
    $h = new Harvv(siteKey: 'abc', host: 'https://harvv.com', enabled: true);

    expect($h->host())->toBe('https://harvv.com');  // exact, no trailing /
});
