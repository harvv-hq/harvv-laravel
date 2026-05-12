<?php

declare(strict_types=1);

namespace Harvv\Laravel;

use Harvv\Laravel\View\Components\HarvvPixel as HarvvPixelComponent;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

/**
 * Harvv\Laravel ServiceProvider.
 *
 * Auto-discovered via composer.json `extra.laravel.providers`. Users never
 * register this provider manually — Laravel's package discovery picks it
 * up on `composer require`.
 *
 * What this provider does:
 *
 *   - merges the package's default config so the package works without
 *     `vendor:publish` (Laravel pulls config/harvv.php into config('harvv.*'))
 *
 *   - binds the Harvv service as a singleton on the `harvv` alias so both
 *     `app('harvv')` and the Harvv facade resolve to the same instance
 *
 *   - registers the @harvv() Blade directive globally. The directive
 *     is a no-op (empty string) until HARVV_SITE_KEY is set, so it's
 *     safe to ship layouts with @harvv in them before configuring.
 *
 *   - registers <x-harvv-pixel /> component as an alternative to the
 *     directive, for teams that prefer Blade components
 *
 *   - publishes config + registers artisan commands only when running
 *     in console (skip during HTTP requests for a leaner autoload graph)
 *
 *   - loads views from resources/views as the 'harvv' namespace so the
 *     pixel.blade.php template renders via view('harvv::pixel')
 *
 * NO middleware registration here. Middleware is opt-in per the architecture
 * decision documented in Component 2 — users add HarvvContext to their stack
 * via bootstrap/app.php (Laravel 11+) or Kernel.php (Laravel 10), and the
 * `harvv:install` artisan command offers to do it for them with a
 * "recommended" prompt.
 */
class HarvvServiceProvider extends ServiceProvider
{
    /**
     * Service-container registration. Runs before boot(). No access to
     * other services here — everything wired up below must be self-contained.
     */
    public function register(): void
    {
        // Default config — merged into config('harvv.*') so a fresh install
        // (no vendor:publish) still has every key available. Publishing
        // overrides selectively.
        $this->mergeConfigFrom(
            __DIR__.'/../config/harvv.php',
            'harvv'
        );

        // Bind the Harvv service as a singleton. Singleton (not bind) because:
        //   1. Multiple resolves in the same request share request_id state
        //      once the middleware is in play (Component 2)
        //   2. Construction is cheap but config reads via Laravel's container
        //      are non-trivial — caching the resolved config helps
        $this->app->singleton(Harvv::class, function (Application $app) {
            $config = $app['config']->get('harvv');

            return new Harvv(
                siteKey: $config['site_key'] ?? null,
                host: rtrim($config['host'] ?? 'https://harvv.com', '/'),
                enabled: (bool) ($config['enabled'] ?? true),
            );
        });

        // Alias so `app('harvv')` works as a string-based resolution path.
        // Used by the Blade directive (which can't import the FQCN cleanly
        // from a string-emit context) and by the facade's getFacadeAccessor().
        $this->app->alias(Harvv::class, 'harvv');
    }

    /**
     * Boot-phase wiring. Runs after register(), with the full container
     * available. Anything that touches Blade, Console, or other services
     * goes here.
     */
    public function boot(): void
    {
        // ─── Views ─────────────────────────────────────────────────────────
        // Load resources/views as the 'harvv' Blade namespace. The pixel
        // template renders via view('harvv::pixel', [...]).
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'harvv');

        // ─── Blade directive: @harvv() ─────────────────────────────────────
        // Auto-registered globally. Emits the rendered pixel <script> tag
        // (or an empty string when site_key is missing — safe no-op).
        //
        // We deliberately emit PHP source code (not the rendered HTML)
        // because Blade compiles directives ahead of view rendering — we
        // can't resolve the service at compile-time, only runtime.
        Blade::directive('harvv', function (string $expression): string {
            // No arguments are accepted today, but supporting them in the
            // compiled output keeps the directive forward-compatible
            // (e.g., @harvv(['route' => 'special']) for per-page overrides
            // in a future minor release).
            return "<?php echo app('harvv')->renderPixel({$expression}); ?>";
        });

        // ─── Blade component: <x-harvv-pixel /> ───────────────────────────
        // For teams that prefer component syntax over directives. Same
        // output, same code path (HarvvPixelComponent::render delegates
        // to the singleton).
        Blade::component('harvv-pixel', HarvvPixelComponent::class);

        // ─── Console-only wiring ──────────────────────────────────────────
        if ($this->app->runningInConsole()) {
            // Publishable config — for users who want to customize beyond
            // .env. Tag-gated so `vendor:publish --tag=harvv-config` only
            // ships the config, not unrelated assets.
            $this->publishes([
                __DIR__.'/../config/harvv.php' => config_path('harvv.php'),
            ], 'harvv-config');

            // Console commands. These FQCNs land in Component 3 — guarded
            // with class_exists so users running an outdated install
            // (provider updated but src not refreshed) don't crash at boot.
            $commands = array_filter([
                class_exists(Console\InstallCommand::class) ? Console\InstallCommand::class : null,
                class_exists(Console\VerifyCommand::class) ? Console\VerifyCommand::class : null,
            ]);
            if (! empty($commands)) {
                $this->commands($commands);
            }
        }
    }
}
