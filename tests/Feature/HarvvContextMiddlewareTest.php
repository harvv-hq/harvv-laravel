<?php

declare(strict_types=1);

use Harvv\Laravel\Http\Middleware\HarvvContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/*
|--------------------------------------------------------------------------
| HarvvContext middleware
|--------------------------------------------------------------------------
| Asserts the middleware injects a signed <meta name="harvv-laravel"> tag
| into HTML responses + degrades gracefully on every documented failure
| mode (missing secret, excluded route, non-HTML response, etc.).
|
| Why these specific tests:
|   - The injection is on the HOT path of every HTML response. A regression
|     here breaks every customer's site silently. Direct assertions on the
|     emitted markup catch shape drift before it ships.
|   - Each "graceful degradation" path is documented in the middleware's
|     PHPDoc. Tests below mirror the doc exactly — if a test below stops
|     making sense, the docs probably need updating too.
*/

beforeEach(function () {
    config()->set('harvv.site_key', 'test_site_key_8c4f9a1b');
    config()->set('harvv.context.enabled', true);
    config()->set('harvv.context.hmac_secret', 'test_hmac_secret_aaaabbbbccccdddd');
    config()->set('harvv.context.excluded_routes', ['admin/*', 'horizon/*']);

    Route::middleware([HarvvContext::class])
        ->get('/page', fn () => response('<html><head><title>Test</title></head><body>hi</body></html>', 200, ['Content-Type' => 'text/html'])
            ->header('Content-Type', 'text/html'))
        ->name('test.page');

    Route::middleware([HarvvContext::class])
        ->get('/admin/dashboard', fn () => response('<html><head></head><body>admin</body></html>', 200, ['Content-Type' => 'text/html']))
        ->name('admin.dashboard');

    Route::middleware([HarvvContext::class])
        ->get('/api/v1/data', fn () => response()->json(['ok' => true]))
        ->name('api.data');
});

it('injects a signed meta tag into HTML responses', function () {
    $response = $this->get('/page');
    $response->assertStatus(200);
    $content = $response->getContent();
    expect($content)->toContain('<meta name="harvv-laravel" content="');
    // The tag sits inside <head>, BEFORE </head>
    expect($content)->toMatch('/<meta name="harvv-laravel"[^>]+>\s*<\/head>/i');
});

it('encodes the payload as base64-url-safe JSON', function () {
    $response = $this->get('/page');
    preg_match('/<meta name="harvv-laravel" content="([^"]+)"/', $response->getContent(), $m);
    expect($m[1] ?? null)->not->toBeNull();
    $encoded = $m[1];
    // base64-url alphabet only (no +, /, =).
    expect($encoded)->toMatch('/^[A-Za-z0-9_-]+$/');
    // Decodes to valid JSON with the expected keys.
    $b64 = strtr($encoded, '-_', '+/');
    $b64 .= str_repeat('=', (4 - strlen($b64) % 4) % 4);
    $json = base64_decode($b64);
    $payload = json_decode($json, true);
    expect($payload)->toHaveKeys(['site_key', 'request_id', 'ts', 'signature']);
    expect($payload['site_key'])->toBe('test_site_key_8c4f9a1b');
});

it('produces a deterministic HMAC for the same canonical input', function () {
    // Two requests in the same second with the same fields should produce
    // the same signature — confirms the canonical-input formula is stable
    // and the receiver can rederive it from the wire payload.
    $r1 = $this->withHeader('X-Request-Id', 'req-aaaa')->get('/page');
    $r2 = $this->withHeader('X-Request-Id', 'req-aaaa')->get('/page');
    preg_match('/<meta name="harvv-laravel" content="([^"]+)"/', $r1->getContent(), $m1);
    preg_match('/<meta name="harvv-laravel" content="([^"]+)"/', $r2->getContent(), $m2);
    $p1 = decodePayload($m1[1]);
    $p2 = decodePayload($m2[1]);
    if ($p1['ts'] === $p2['ts']) {
        expect($p1['signature'])->toBe($p2['signature']);
    }
});

it('honors X-Request-Id when present', function () {
    $response = $this->withHeader('X-Request-Id', '01HZZZAAAA1234567890XYZ')->get('/page');
    preg_match('/<meta name="harvv-laravel" content="([^"]+)"/', $response->getContent(), $m);
    expect(decodePayload($m[1])['request_id'])->toBe('01HZZZAAAA1234567890XYZ');
});

it('no-ops on excluded routes', function () {
    $response = $this->get('/admin/dashboard');
    expect($response->getContent())->not->toContain('harvv-laravel');
});

it('no-ops on non-HTML responses', function () {
    $response = $this->get('/api/v1/data');
    expect($response->getContent())->not->toContain('harvv-laravel');
});

it('no-ops when site_key is missing', function () {
    config()->set('harvv.site_key', '');
    $response = $this->get('/page');
    expect($response->getContent())->not->toContain('harvv-laravel');
});

it('no-ops when hmac_secret is missing', function () {
    config()->set('harvv.context.hmac_secret', '');
    $response = $this->get('/page');
    expect($response->getContent())->not->toContain('harvv-laravel');
});

it('no-ops when context.enabled is false', function () {
    config()->set('harvv.context.enabled', false);
    $response = $this->get('/page');
    expect($response->getContent())->not->toContain('harvv-laravel');
});

it('survives an HTML body without a </head> tag', function () {
    // Defensive case — some responses are HTML fragments (e.g., Livewire
    // partials, htmx swaps). The middleware should pass them through.
    Route::middleware([HarvvContext::class])
        ->get('/fragment', fn () => response('<div>fragment</div>', 200, ['Content-Type' => 'text/html']));
    $response = $this->get('/fragment');
    expect($response->getContent())->toBe('<div>fragment</div>');
});

it('omits route field when route is unnamed', function () {
    Route::middleware([HarvvContext::class])
        ->get('/unnamed', fn () => response('<html><head></head><body>x</body></html>', 200, ['Content-Type' => 'text/html']));
    $response = $this->get('/unnamed');
    preg_match('/<meta name="harvv-laravel" content="([^"]+)"/', $response->getContent(), $m);
    $payload = decodePayload($m[1]);
    expect($payload)->not->toHaveKey('route');
});

// Helper — decodes the meta-tag payload back to its native PHP array.
function decodePayload(string $encoded): array
{
    $b64 = strtr($encoded, '-_', '+/');
    $b64 .= str_repeat('=', (4 - strlen($b64) % 4) % 4);
    return json_decode(base64_decode($b64), true);
}
