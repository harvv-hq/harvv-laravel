<?php

declare(strict_types=1);

namespace Harvv\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * `php artisan harvv:verify` — print a self-check of the install state.
 *
 * Lovable's QA report flagged this as the "single highest-ROI DX item" —
 * juniors can't tell whether the package is wired up correctly without a
 * dashboard account, and "is it working?" turns into a 30-minute
 * investigation. This command answers that in 2 seconds.
 *
 * Five checks, each pass/fail/skip:
 *   1. HARVV_SITE_KEY set + 16-hex shape
 *   2. HARVV_HMAC_SECRET set + hlv1_ prefix
 *   3. config('harvv.enabled') resolves to true
 *   4. @harvv directive available (Blade compiler has it)
 *   5. HarvvContext middleware registered in the web group
 *
 * Exits 0 when everything passes; non-zero if any required check failed,
 * so CI can gate deploys on `php artisan harvv:verify`.
 */
class VerifyCommand extends Command
{
    protected $signature = 'harvv:verify';

    protected $description = 'Verify the Harvv pixel + middleware are installed correctly.';

    public function handle(Filesystem $files): int
    {
        $this->info('');
        $this->info('  Harvv install verification');
        $this->info('');

        $checks = [];

        // 1. Site key
        $siteKey = config('harvv.site_key');
        if (! $siteKey) {
            $checks[] = ['fail', 'Site key', 'HARVV_SITE_KEY is empty. Run: php artisan harvv:install'];
        } elseif (! preg_match('/^[a-f0-9]{16}$/i', $siteKey)) {
            $checks[] = ['warn', 'Site key', 'HARVV_SITE_KEY is set but doesn\'t look like 16 hex chars (got '.strlen($siteKey).' chars).'];
        } else {
            $checks[] = ['ok', 'Site key', substr($siteKey, 0, 6).'… (16 hex)'];
        }

        // 2. HMAC secret (only required if you want per-request context)
        $hmac = env('HARVV_HMAC_SECRET') ?: (config('harvv.context.hmac_secret') ?? null);
        if (! $hmac) {
            $checks[] = ['warn', 'HMAC secret', 'HARVV_HMAC_SECRET is empty. Per-request context (route, user, request id) will not be signed. Optional but recommended.'];
        } elseif (! str_starts_with($hmac, 'hlv1_')) {
            $checks[] = ['warn', 'HMAC secret', 'HARVV_HMAC_SECRET is set but missing the hlv1_ prefix.'];
        } else {
            $checks[] = ['ok', 'HMAC secret', substr($hmac, 0, 12).'…'];
        }

        // 3. Enabled
        $enabled = (bool) config('harvv.enabled');
        if (! $enabled) {
            $checks[] = ['fail', 'Enabled', 'config("harvv.enabled") is false. Set HARVV_ENABLED=true in .env to render the pixel.'];
        } else {
            $checks[] = ['ok', 'Enabled', 'true'];
        }

        // 4. Blade directive registered
        // The directive is added in HarvvServiceProvider::boot(). We can
        // poke the Blade compiler's directive list to confirm it landed.
        try {
            $blade = $this->laravel['blade.compiler'] ?? null;
            $directives = $blade ? $blade->getCustomDirectives() : [];
            if (array_key_exists('harvv', $directives)) {
                $checks[] = ['ok', '@harvv directive', 'registered'];
            } else {
                $checks[] = ['fail', '@harvv directive', 'not registered. Re-run composer dump-autoload or check that HarvvServiceProvider is loaded.'];
            }
        } catch (\Throwable $e) {
            $checks[] = ['skip', '@harvv directive', 'could not introspect Blade compiler: '.$e->getMessage()];
        }

        // 5. Middleware registered
        $middlewareRegistered = false;
        $bootstrap = $this->laravel->basePath('bootstrap/app.php');
        $kernel = $this->laravel->basePath('app/Http/Kernel.php');
        if ($files->exists($bootstrap) && str_contains($files->get($bootstrap), 'Harvv\\Laravel\\Http\\Middleware\\HarvvContext')) {
            $middlewareRegistered = true;
            $checks[] = ['ok', 'Middleware', 'HarvvContext registered in bootstrap/app.php'];
        } elseif ($files->exists($kernel) && str_contains($files->get($kernel), 'Harvv\\Laravel\\Http\\Middleware\\HarvvContext')) {
            $middlewareRegistered = true;
            $checks[] = ['ok', 'Middleware', 'HarvvContext registered in app/Http/Kernel.php'];
        } else {
            // 2026-05-12 — Lovable QA pass #2: hint used to say "Add via
            // php artisan harvv:install" which re-runs the whole flow.
            // Now points at the targeted --middleware-only flag.
            $checks[] = ['warn', 'Middleware', 'HarvvContext not registered. Pixel still works without it — but you lose per-request route/user context on events. Add it with: php artisan harvv:install --middleware-only'];
        }

        // Print
        $failed = 0;
        foreach ($checks as [$status, $label, $detail]) {
            $tag = match ($status) {
                'ok'   => '<fg=green>✓</>',
                'warn' => '<fg=yellow>•</>',
                'fail' => '<fg=red>✗</>',
                'skip' => '<fg=gray>·</>',
                default => ' ',
            };
            $this->line('  '.$tag.'  <fg=white>'.str_pad($label, 18).'</> '.$detail);
            if ($status === 'fail') $failed++;
        }
        $this->info('');

        if ($failed > 0) {
            $this->error('  '.$failed.' check(s) failed. Run php artisan harvv:install to repair.');
            return self::FAILURE;
        }
        // The actual rendered pixel URL — useful for grep + curl tests
        if ($siteKey) {
            $host = config('harvv.host', 'https://harvv.com');
            $this->line('  Pixel URL on this site: <fg=cyan>'.rtrim($host, '/').'/px/'.$siteKey.'/pixel.js</>');
            $this->line('  View any page → View Source → look for this URL. That\'s your verification.');
            $this->info('');
        }
        // 2026-05-12 — Lovable QA pass #2: surface the vendor:publish path
        // so power users know how to tune beyond env vars. Only mention if
        // config/harvv.php hasn't already been published (no need to nag).
        if (! file_exists($this->laravel->basePath('config/harvv.php'))) {
            $this->line('  <fg=gray>Tip: run `php artisan vendor:publish --tag=harvv-config` to publish config/harvv.php for advanced tuning.</>');
            $this->info('');
        }
        return self::SUCCESS;
    }
}
