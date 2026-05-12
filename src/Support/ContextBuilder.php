<?php

declare(strict_types=1);

namespace Harvv\Laravel\Support;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;

/**
 * Builds the signed Laravel-context payload that the middleware injects
 * into HTML responses as `<meta name="harvv-laravel">`.
 *
 * The wire shape (post-base64-decode, JSON):
 *
 *   {
 *     "site_key": "5abaa759db95fcf4",
 *     "route":    "checkout.show",   // Laravel route name, optional
 *     "user_id":  "ab12cd34…",       // SHA-256(user_pk + site_key) by default
 *     "request_id": "01JD0…",         // ULID — unique per request
 *     "ts": 1715520000,               // Unix seconds at render time
 *     "signature": "9f3e…"            // HMAC-SHA256 of canonical payload
 *   }
 *
 * The pixel reads this on first event flush and includes it in every
 * `/px/<key>/event` POST body as `laravel_context: {…}`. The receiver
 * validates `signature` against the per-site HMAC secret and stores the
 * fields on each event row. On signature mismatch the receiver still
 * accepts the event but drops the context (degrade-gracefully — see
 * docs/dual-theme-guideline.md is unrelated; see the LP for the design
 * intent).
 *
 * Why this lives in Support not in the middleware itself:
 *   - Same builder is used by the artisan `harvv:verify` command (Phase A
 *     Step 5 — verifies HMAC against the live receiver).
 *   - Unit-testable in isolation without a full HTTP request.
 *   - If we ever ship a server-side direct POST path again, this stays the
 *     single place that knows the payload shape.
 */
class ContextBuilder
{
    public function __construct(
        private readonly string $siteKey,
        private readonly string $hmacSecret,
        private readonly array $config,
        private readonly AuthFactory $auth,
    ) {
    }

    /**
     * Build the signed payload for the given request. Returns null when
     * the package isn't configured (missing site_key or hmac_secret) so
     * the middleware can no-op gracefully.
     *
     * @return array<string, mixed>|null
     */
    public function build(Request $request): ?array
    {
        if ($this->siteKey === '' || $this->hmacSecret === '') {
            return null;
        }
        if (! ($this->config['enabled'] ?? true)) {
            return null;
        }

        $route = $this->resolveRouteName($request);
        $userId = $this->resolveUserId();
        $requestId = $this->resolveRequestId($request);
        $ts = time();

        // Canonical signing input — pipe-delimited so a forged context can't
        // bypass by reordering fields. Empty strings (not null) for absent
        // values keep the input byte-stable. Receiver MUST canonicalize
        // identically (see lib/services/laravel-hmac.js Step 1).
        $canonical = implode('|', [
            $this->siteKey,
            $route ?? '',
            $userId ?? '',
            $requestId,
            (string) $ts,
        ]);

        $signature = hash_hmac('sha256', $canonical, $this->hmacSecret);

        $payload = [
            'site_key'   => $this->siteKey,
            'request_id' => $requestId,
            'ts'         => $ts,
            'signature'  => $signature,
        ];

        // Only include nullable fields when present — keeps the payload
        // small + avoids "null"/"" ambiguity downstream.
        if ($route !== null) {
            $payload['route'] = $route;
        }
        if ($userId !== null) {
            $payload['user_id'] = $userId;
        }

        return $payload;
    }

    /**
     * Encode the payload for the `<meta>` tag's `content` attribute.
     * Base64-url so it's HTML-safe without further escaping; the pixel
     * decodes with `atob` after a urlSafe→base64 swap.
     */
    public function encode(array $payload): string
    {
        return rtrim(
            strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), '+/', '-_'),
            '='
        );
    }

    /**
     * Route name (e.g. 'checkout.show'). Returns null when:
     *   - No matched route (fallback handlers, 404 pages, etc.)
     *   - The route is in the excluded_routes list
     *   - Config disabled include_route_name
     *   - The route is unnamed
     */
    private function resolveRouteName(Request $request): ?string
    {
        if (! ($this->config['include_route_name'] ?? true)) {
            return null;
        }
        $route = $request->route();
        if (! $route) {
            return null;
        }
        $name = method_exists($route, 'getName') ? $route->getName() : null;
        if (! $name) {
            return null;
        }
        return substr($name, 0, 120); // hard cap matches the DB column width
    }

    /**
     * Authenticated user id, optionally hashed. Returns null when:
     *   - No authenticated user
     *   - Config disabled include_user_id
     */
    private function resolveUserId(): ?string
    {
        if (! ($this->config['include_user_id'] ?? true)) {
            return null;
        }
        $guard = $this->config['auth_guard'] ?? null;
        $user = $this->auth->guard($guard)->user();
        if (! $user) {
            return null;
        }

        // method_exists check — some user models override getAuthIdentifier
        // to throw if accessed before a hydration step (a real edge case I
        // hit on a Filament install during T140 testing). Defensive null
        // beats a crash on a HOT path that runs on every HTML response.
        $rawId = method_exists($user, 'getAuthIdentifier')
            ? (string) $user->getAuthIdentifier()
            : null;
        if (! $rawId) {
            return null;
        }

        if (($this->config['hash_user_ids'] ?? true)) {
            // SHA-256(rawId + site_key). Site_key is salt — without it,
            // a rainbow table of "sha256 of an integer" trivially decodes
            // the user. Adding the site_key makes the hash site-specific.
            return hash('sha256', $rawId . $this->siteKey);
        }
        return substr($rawId, 0, 200);
    }

    /**
     * Stable per-request id. Prefer `X-Request-Id` header (set by load
     * balancers, traceparent propagation, etc.) so the Harvv issue can be
     * correlated with the customer's own request log. Fall back to a ULID
     * generated server-side when no header is present.
     */
    private function resolveRequestId(Request $request): string
    {
        $hdr = $request->headers->get('x-request-id');
        if (is_string($hdr) && $hdr !== '' && strlen($hdr) <= 64) {
            return $hdr;
        }
        // Laravel includes Str::ulid since 10.0; fall back to a random
        // 26-char base32 if not available.
        if (class_exists(\Illuminate\Support\Str::class) && method_exists(\Illuminate\Support\Str::class, 'ulid')) {
            return (string) \Illuminate\Support\Str::ulid();
        }
        return strtoupper(bin2hex(random_bytes(13)));
    }
}
