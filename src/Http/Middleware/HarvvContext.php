<?php

declare(strict_types=1);

namespace Harvv\Laravel\Http\Middleware;

use Closure;
use Harvv\Laravel\Support\ContextBuilder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HarvvContext — injects a signed `<meta name="harvv-laravel">` tag into
 * every HTML response so the Harvv pixel can attach Laravel-side context
 * (route name, hashed user id, request id) to the events it emits.
 *
 * Why a meta tag, not a server-to-Harvv POST:
 *
 *   The Component-1 design POSTed context to `/v1/context` every request.
 *   That added a synchronous HTTP call to your server's response path —
 *   PK Laravel freelancers on Forge / shared hosting noticed the 30-150 ms
 *   overhead. The meta-tag pattern is zero-overhead server-side: we render
 *   one short string into the HTML. The pixel does the network call as
 *   part of its existing /px/event POST, so no new HTTP request is needed.
 *
 * Architecture:
 *
 *   1. This middleware runs in the `web` middleware group (or `api` if
 *      you ship SSR APIs that return HTML).
 *   2. After the response is generated, we check if it's HTML. If so we
 *      inject a `<meta>` tag containing a base64-url-encoded JSON blob
 *      with the Laravel-side context fields + an HMAC signature.
 *   3. The pixel reads the meta tag on first event flush and includes the
 *      blob in every `/px/event` POST body as `laravel_context: {…}`.
 *   4. The Harvv receiver validates the HMAC against the per-site secret
 *      (see lib/services/laravel-hmac.js, Phase A Step 1) and stores the
 *      fields on each event row. On signature mismatch the receiver
 *      accepts the event but drops the context (degrade-gracefully — see
 *      docs/runbooks/laravel-context.md, Phase A Step 5).
 *
 * Failure modes & graceful degradation:
 *
 *   - Missing HARVV_SITE_KEY      → middleware no-ops (returns response as-is)
 *   - Missing HARVV_HMAC_SECRET   → no-ops (events still ingest, just no context)
 *   - Non-HTML response           → no-ops (JSON APIs, redirects, downloads pass through)
 *   - Streamed / BinaryFileResponse → no-ops (can't safely mutate streaming bodies)
 *   - Excluded route              → no-ops (config('harvv.context.excluded_routes'))
 *   - No `</head>` in body        → no-ops (something other than HTML; defensive)
 *
 *   In every failure mode the request continues normally — middleware is
 *   PURE INSPECTION on the response side, never throws into the request
 *   path. This is on purpose: Harvv-context is value-add, not load-bearing.
 *
 * Where to register:
 *
 *   Laravel 11+ (bootstrap/app.php):
 *     ->withMiddleware(function (Middleware $middleware) {
 *         $middleware->web(append: [
 *             \Harvv\Laravel\Http\Middleware\HarvvContext::class,
 *         ]);
 *     })
 *
 *   Laravel 10 (app/Http/Kernel.php):
 *     protected $middlewareGroups = [
 *         'web' => [
 *             // …existing…
 *             \Harvv\Laravel\Http\Middleware\HarvvContext::class,
 *         ],
 *     ];
 *
 *   The `php artisan harvv:install` command (Phase A Step 0+ in Component 3)
 *   offers to wire this up automatically with a "recommended" prompt.
 */
class HarvvContext
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        // Bail BEFORE building the payload if we can already tell we won't
        // inject — saves the auth/route lookup work entirely on excluded
        // paths.
        if (! $this->shouldInject($request, $response)) {
            return $response;
        }

        try {
            $builder = $this->resolveBuilder();
            if ($builder === null) {
                return $response;
            }

            $payload = $builder->build($request);
            if ($payload === null) {
                return $response;
            }

            $encoded = $builder->encode($payload);
            $tag = '<meta name="harvv-laravel" content="'.$encoded.'">';

            $content = $response->getContent();
            if (! is_string($content) || $content === '') {
                return $response;
            }

            // Inject before </head>. Use a case-insensitive match because
            // some templating engines normalize differently. Insert ONLY
            // once even if multiple </head> tags exist (shouldn't happen
            // but defensive — e.g., Livewire SSR partials).
            $injected = preg_replace(
                '/<\/head>/i',
                "    {$tag}\n</head>",
                $content,
                1,
                $count
            );

            if ($injected !== null && $count > 0) {
                $response->setContent($injected);
                // Recompute Content-Length if the response set it explicitly.
                // Most Laravel responses leave it to the SAPI, but Nginx +
                // FastCGI installs sometimes pin it via X-Sendfile equivalents.
                if ($response->headers->has('content-length')) {
                    $response->headers->set('content-length', (string) strlen($injected));
                }
            }
        } catch (\Throwable $e) {
            // Never let a context-injection error break the response.
            // Log to Laravel's logger so it's visible if it ever happens,
            // but the user's page still renders.
            if (function_exists('logger')) {
                $channel = config('harvv.log_channel');
                $log = $channel ? logger()->channel($channel) : logger();
                $log->warning('Harvv: context injection failed', [
                    'error' => $e->getMessage(),
                    'route' => $request->route()?->getName(),
                ]);
            }
        }

        return $response;
    }

    /**
     * Decide whether to inject. Reasons not to:
     *   - Not a successful HTML response (status outside 2xx-3xx OR not
     *     text/html). Pre-3xx redirects pass through unmodified — the
     *     destination page will get the tag on its own.
     *   - Streamed / Binary response (can't safely buffer + mutate).
     *   - Excluded route per config.
     *   - Context not enabled.
     */
    private function shouldInject(Request $request, Response $response): bool
    {
        if (! config('harvv.context.enabled', true)) {
            return false;
        }

        // StreamedResponse / BinaryFileResponse have no getContent() to
        // mutate — bail. Symfony's response types are detected by checking
        // for the StreamedResponse trait via class hierarchy.
        if ($response instanceof \Symfony\Component\HttpFoundation\StreamedResponse
            || $response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) {
            return false;
        }

        $contentType = $response->headers->get('content-type', '');
        if (! str_contains(strtolower($contentType), 'text/html')) {
            return false;
        }

        // Excluded routes — Laravel's pattern matcher handles glob-style
        // ('admin/*', 'horizon/*'). Match against the request path so it
        // works for closure-routes too.
        $excluded = config('harvv.context.excluded_routes', []);
        if (is_array($excluded) && ! empty($excluded)) {
            $path = ltrim($request->path(), '/');
            foreach ($excluded as $pattern) {
                if (\Illuminate\Support\Str::is($pattern, $path)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Resolve the ContextBuilder out of the container with the right
     * config slice + auth factory. Built per-request because the
     * authentication context can change between requests in queue-worker
     * scenarios, and we want a fresh user lookup each time.
     */
    private function resolveBuilder(): ?ContextBuilder
    {
        $siteKey = (string) config('harvv.site_key', '');
        $hmacSecret = (string) config('harvv.context.hmac_secret', '');
        if ($siteKey === '' || $hmacSecret === '') {
            return null;
        }

        $contextConfig = (array) config('harvv.context', []);
        $auth = app(\Illuminate\Contracts\Auth\Factory::class);

        return new ContextBuilder(
            siteKey: $siteKey,
            hmacSecret: $hmacSecret,
            config: $contextConfig,
            auth: $auth,
        );
    }
}
