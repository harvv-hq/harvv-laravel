<?php

declare(strict_types=1);

namespace Harvv\Laravel;

use Illuminate\Contracts\View\View;

/**
 * Harvv main service class.
 *
 * Resolved as a singleton on `app('harvv')` and via the Harvv facade.
 * Owns one job for Component 1: rendering the pixel <script> tag with
 * safe no-op fallbacks. Component 2 extends this class with the HMAC
 * client + context-resolver methods.
 *
 * Design rule: every public method is safe to call unconditionally.
 * Callers (Blade directives, components, middleware) should never have
 * to check "is Harvv configured?" — the service answers correctly when
 * it's not. That's what makes `@harvv` safe to ship in a layout before
 * the user finishes setup.
 */
class Harvv
{
    public function __construct(
        protected ?string $siteKey,
        protected string $host,
        protected bool $enabled,
    ) {
    }

    /**
     * Render the pixel <script> tag.
     *
     * Returns an empty string (NOT null) when:
     *   - the package is disabled (config('harvv.enabled') === false)
     *   - HARVV_SITE_KEY is missing
     *   - we're running under `php artisan` (CLI — pixel makes no sense)
     *
     * The empty-string return means @harvv emits literally nothing in
     * those cases, which is what you want — your layout stays valid
     * HTML even if the package is misconfigured.
     *
     * The $options array is reserved for future per-render overrides
     * (e.g., a one-off route name for a custom-rendered page). Today
     * it's accepted-and-ignored to keep the public signature stable.
     */
    public function renderPixel(array $options = []): string
    {
        if (! $this->enabled()) {
            return '';
        }

        // NOTE: we deliberately do NOT short-circuit on app()->runningInConsole().
        // Earlier drafts had that check, but it false-positives during
        // Testbench/Pest test runs (Pest is a CLI process — runningInConsole()
        // returns true even though we're rendering a real test response).
        // Calling renderPixel() from tinker or artisan returns a string; no
        // harm done. The script tag only fires when actually rendered to
        // an HTTP response anyway.

        return view('harvv::pixel', [
            'siteKey' => $this->siteKey,
            'host' => $this->host,
        ])->render();
    }

    /**
     * The configured site key, or null if missing. Used by the install
     * verify command + the optional middleware that signs context POSTs.
     */
    public function siteKey(): ?string
    {
        return $this->siteKey;
    }

    /**
     * The receiver host (no trailing slash). Defaults to https://harvv.com.
     * Used by the middleware to construct /v1/context URLs.
     */
    public function host(): string
    {
        return $this->host;
    }

    /**
     * Whether the pixel will render under current conditions.
     *
     * True only when:
     *   - config('harvv.enabled') is truthy
     *   - HARVV_SITE_KEY is set + non-empty
     *
     * Used by:
     *   - renderPixel() as its primary gate
     *   - Verify command to print "Harvv is enabled and ready"
     *   - Middleware to short-circuit context POSTs when there's no
     *     valid site_key to attribute them to
     */
    public function enabled(): bool
    {
        return $this->enabled && ! empty($this->siteKey);
    }

    /**
     * The full pixel URL — only used for diagnostic output (e.g., the
     * harvv:verify artisan command and the LaravelInstallStatus admin
     * widget). Never log this to user-visible places because the
     * site_key is in the path and shouldn't appear in screenshots.
     */
    public function pixelUrl(): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        return "{$this->host}/px/{$this->siteKey}/pixel.js";
    }
}
