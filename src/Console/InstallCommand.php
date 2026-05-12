<?php

declare(strict_types=1);

namespace Harvv\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * `php artisan harvv:install` — interactive package setup.
 *
 * Built 2026-05-12 after Lovable's QA report flagged this as "vaporware
 * in v0.1.0" — the harvv.com/laravel landing page and README both told
 * juniors to run this command but it didn't exist. Now it does.
 *
 * What this command does, in order:
 *   1. Prompts for HARVV_SITE_KEY (or reads --site-key option)
 *   2. Generates a random 32-byte hex HMAC secret if one isn't set
 *   3. Writes both to .env (creates the keys, doesn't touch other lines)
 *   4. Offers to register the HarvvContext middleware in bootstrap/app.php
 *      (Laravel 11/12) or app/Http/Kernel.php (Laravel 10) so the per-
 *      request route/user/request-id context lands in events automatically
 *   5. Prints a verification checklist + the URL to view in browser
 *
 * Idempotent: re-running is safe. It'll skip any step already done and
 * report what's already in place. Useful for "did I configure this right?".
 *
 * Non-interactive flag (--no-interaction or -n): just writes a placeholder
 * key + HMAC secret and prints the next steps. For CI / Docker entrypoints
 * that don't have a TTY.
 */
class InstallCommand extends Command
{
    protected $signature = 'harvv:install
        {--site-key= : Your Harvv pixel key (32-char hex; from harvv.com Settings → Install)}
        {--no-middleware : Skip the HarvvContext middleware registration prompt}';

    protected $description = 'Install + configure the Harvv pixel for this Laravel app (interactive).';

    public function handle(Filesystem $files): int
    {
        $this->info('');
        $this->info('  ┌──────────────────────────────────────────────┐');
        $this->info('  │  Harvv pixel — Laravel install               │');
        $this->info('  │  Behavioral UX analytics for your customers  │');
        $this->info('  └──────────────────────────────────────────────┘');
        $this->info('');

        $envPath = $this->laravel->basePath('.env');
        if (! $files->exists($envPath)) {
            $this->warn('No .env file found at '.$envPath);
            $this->warn('Create one (copy .env.example) and re-run this command.');
            return self::FAILURE;
        }
        $envContents = $files->get($envPath);

        // ── Step 1: site key ──────────────────────────────────────────
        $siteKey = $this->option('site-key') ?: $this->extractEnvValue($envContents, 'HARVV_SITE_KEY');
        if (! $siteKey) {
            if ($this->input->isInteractive()) {
                $this->line('Step 1/3 — Site key');
                $this->line('  Grab it from <fg=cyan>https://harvv.com</> → your site → Settings → Install.');
                $this->line('  Format: 16 hex characters (e.g. <fg=gray>d1db1759f22827e4</>).');
                $siteKey = $this->ask('  HARVV_SITE_KEY');
            } else {
                $this->warn('No HARVV_SITE_KEY provided (use --site-key=… or set it in .env).');
                $this->warn('Writing a placeholder; replace it before deploying.');
                $siteKey = 'replace-with-your-site-key';
            }
        } else {
            $this->line('Step 1/3 — Site key already set ('.substr($siteKey, 0, 6).'…). Skipping.');
        }

        // ── Step 2: HMAC secret ───────────────────────────────────────
        $hmacSecret = $this->extractEnvValue($envContents, 'HARVV_HMAC_SECRET');
        if (! $hmacSecret) {
            $hmacSecret = 'hlv1_'.bin2hex(random_bytes(32));
            $this->line('Step 2/3 — Generated HMAC secret ('.substr($hmacSecret, 0, 12).'…).');
            $this->line('  Used to sign per-request context (route, hashed user id) so the');
            $this->line('  receiver can verify nothing tampered with the meta tag in flight.');
        } else {
            $this->line('Step 2/3 — HMAC secret already set ('.substr($hmacSecret, 0, 12).'…). Skipping.');
        }

        // Persist both keys to .env
        $envContents = $this->upsertEnv($envContents, 'HARVV_SITE_KEY', $siteKey);
        $envContents = $this->upsertEnv($envContents, 'HARVV_HMAC_SECRET', $hmacSecret);
        // Default ENABLED=true so the pixel renders in local dev. Lovable
        // explicitly flagged "silent no-op in local" as a footgun — this
        // makes the local install verifiable without env-var spelunking.
        if (! $this->extractEnvValue($envContents, 'HARVV_ENABLED')) {
            $envContents = $this->upsertEnv($envContents, 'HARVV_ENABLED', 'true');
            $this->line('  Set HARVV_ENABLED=true so the pixel renders in local dev too.');
        }
        $files->put($envPath, $envContents);
        $this->line('  Saved to <fg=cyan>'.$envPath.'</>.');

        // ── Step 3: middleware registration ───────────────────────────
        if ($this->option('no-middleware')) {
            $this->line('Step 3/3 — Skipping middleware registration (--no-middleware).');
        } else {
            $this->line('Step 3/3 — HarvvContext middleware');
            $this->line('  Optional. Registering this middleware tags every HTML response with a signed');
            $this->line('  context blob (route name, hashed user id, request id) so issues land in the');
            $this->line('  Harvv dashboard tied to the exact Laravel route + user that hit them.');
            if ($this->input->isInteractive() && $this->confirm('  Register HarvvContext middleware in this app?', true)) {
                $registered = $this->registerMiddleware($files);
                if ($registered === 'added') {
                    $this->line('  <fg=green>✓</> Added \Harvv\Laravel\Http\Middleware\HarvvContext::class to your app.');
                } elseif ($registered === 'already') {
                    $this->line('  <fg=yellow>•</> Already registered. Skipping.');
                } else {
                    $this->line('  <fg=yellow>•</> Could not auto-register — add manually:');
                    $this->printManualMiddlewareSnippet();
                }
            } else {
                $this->line('  Skipped. Add manually when you want per-request context:');
                $this->printManualMiddlewareSnippet();
            }
        }

        // ── Done ──────────────────────────────────────────────────────
        $this->info('');
        $this->info('  Done. Three more things:');
        $this->info('');
        $this->line('  <fg=cyan>1.</> Drop the directive in your master layout, above </head>:');
        $this->line('       <fg=gray>@harvv</>');
        $this->line('');
        $this->line('  <fg=cyan>2.</> Reload any page in your browser, View Source, and look for:');
        $this->line('       <fg=gray><script async src="https://harvv.com/px/'.substr($siteKey, 0, 6).'…/pixel.js"></></>');
        $this->line('');
        $this->line('  <fg=cyan>3.</> Run <fg=gray>php artisan harvv:verify</> any time to re-check this setup.');
        $this->info('');

        return self::SUCCESS;
    }

    /**
     * Read a key=value from a .env-shaped string. Returns null if absent.
     */
    private function extractEnvValue(string $env, string $key): ?string
    {
        if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=\s*(.*)$/m', $env, $m)) {
            $val = trim($m[1]);
            // Strip surrounding quotes if present
            if (preg_match('/^"(.*)"$/', $val, $q)) {
                $val = $q[1];
            } elseif (preg_match("/^'(.*)'$/", $val, $q)) {
                $val = $q[1];
            }
            return $val !== '' ? $val : null;
        }
        return null;
    }

    /**
     * Insert or update a key=value in a .env-shaped string. Preserves
     * comments, blank lines, and key order. Appends to the end when the
     * key is absent.
     */
    private function upsertEnv(string $env, string $key, string $value): string
    {
        // Quote values containing spaces or special chars.
        $serialized = preg_match('/[\s#"\']/', $value) ? '"'.$value.'"' : $value;
        if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=.*$/m', $env)) {
            return preg_replace('/^\s*'.preg_quote($key, '/').'\s*=.*$/m', $key.'='.$serialized, $env);
        }
        // Append. Ensure trailing newline first.
        if (! str_ends_with($env, "\n")) $env .= "\n";
        return $env.$key.'='.$serialized."\n";
    }

    /**
     * Try to register HarvvContext middleware in the host app. Returns
     * 'added', 'already', or 'manual' (caller prints the manual snippet).
     */
    private function registerMiddleware(Filesystem $files): string
    {
        // Laravel 11/12: bootstrap/app.php with ->withMiddleware()
        $bootstrap = $this->laravel->basePath('bootstrap/app.php');
        if ($files->exists($bootstrap)) {
            $contents = $files->get($bootstrap);
            if (str_contains($contents, 'Harvv\\Laravel\\Http\\Middleware\\HarvvContext')) {
                return 'already';
            }
            // Look for ->withMiddleware(function (Middleware $middleware) { ... })
            // and add $middleware->web(append: [HarvvContext::class]); inside.
            if (preg_match('/->withMiddleware\s*\(\s*function\s*\([^)]*\)\s*\{/', $contents)) {
                $injected = preg_replace(
                    '/->withMiddleware\s*\(\s*function\s*\(([^)]*)\)\s*\{/',
                    "->withMiddleware(function ($1) {\n        \$middleware->web(append: [\\Harvv\\Laravel\\Http\\Middleware\\HarvvContext::class]);",
                    $contents,
                    1
                );
                if ($injected !== $contents) {
                    $files->put($bootstrap, $injected);
                    return 'added';
                }
            }
        }
        // Laravel 10: app/Http/Kernel.php $middlewareGroups['web']
        $kernel = $this->laravel->basePath('app/Http/Kernel.php');
        if ($files->exists($kernel)) {
            $contents = $files->get($kernel);
            if (str_contains($contents, 'Harvv\\Laravel\\Http\\Middleware\\HarvvContext')) {
                return 'already';
            }
            if (preg_match("/'web'\s*=>\s*\[/", $contents)) {
                $injected = preg_replace(
                    "/'web'\s*=>\s*\[\s*/",
                    "'web' => [\n            \\Harvv\\Laravel\\Http\\Middleware\\HarvvContext::class,\n            ",
                    $contents,
                    1
                );
                if ($injected !== $contents) {
                    $files->put($kernel, $injected);
                    return 'added';
                }
            }
        }
        return 'manual';
    }

    private function printManualMiddlewareSnippet(): void
    {
        $this->line('');
        $this->line('     <fg=gray>// Laravel 11/12 — bootstrap/app.php</>');
        $this->line('     <fg=gray>->withMiddleware(function (Middleware $middleware) {</>');
        $this->line('     <fg=gray>    $middleware->web(append: [\Harvv\Laravel\Http\Middleware\HarvvContext::class]);</>');
        $this->line('     <fg=gray>})</>');
        $this->line('');
    }
}
