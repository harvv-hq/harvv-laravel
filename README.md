# harvv/laravel

Behavioral UX analytics for Laravel. Detects rage clicks, dead clicks, form
abandonment, scroll friction, and Core Web Vitals issues across every page.
Server-side context (route name, hashed user ID, request ID) means each
detected issue lands in your dashboard tied to the Laravel route + user that
hit it — not just `/checkout`.

> **v0.1.0 — early access.** Stable for production traffic; API may still
> shift before v1.0. See the bake criteria at the bottom of this README.

**Full developer docs at [docs.harvv.com/laravel](https://docs.harvv.com/laravel)** — this README is the install summary; the docs site has troubleshooting, manual paths, verify procedures, and the broader Harvv overview.

## Quick install — pre-Packagist (today)

Packagist publish is in progress. Until the listing lands, install via
Composer's VCS repository in one line:

```bash
composer config repositories.harvv vcs https://github.com/AxiomState/harvv-laravel
composer require harvv/laravel:dev-main
php artisan harvv:install
```

That's the entire install. The `harvv:install` command prompts for your
site key, generates an HMAC secret, writes both to `.env`, and offers to
register the `HarvvContext` middleware automatically. Re-running is safe
(idempotent — skips already-done steps).

`dev-main` tracks the `main` branch; tag releases (`v0.1.0`, `v0.1.1`, …)
will land as semver becomes meaningful. Pin to a tag in production:

```bash
composer require harvv/laravel:^0.1
```

Once `harvv/laravel` is live on Packagist, the VCS step disappears — the
install reduces to `composer require harvv/laravel` then `php artisan harvv:install`.

## Quickstart (2 minutes)

> Once Packagist publish completes, this is the only install path you need.
> Until then, see [Quick install — pre-Packagist](#quick-install--pre-packagist-today).

```bash
composer require harvv/laravel
php artisan harvv:install
```

Grab your site key from your site's Settings → Install panel
([Studio dashboard](https://harvv.com/site.html#/app), or read the
[install doc](https://docs.harvv.com/laravel)). The `harvv:install` command
prompts you for it and writes `HARVV_SITE_KEY` (plus `HARVV_HMAC_SECRET`,
`HARVV_ENABLED=true`) to your `.env`.

Then drop the pixel into your layout — either Blade directive:

```blade
{{-- resources/views/layouts/app.blade.php --}}
<body>
    {{-- ... your layout ... --}}

    @harvv
</body>
```

Or component syntax:

```blade
<body>
    {{-- ... your layout ... --}}

    <x-harvv-pixel />
</body>
```

Both render the same `<script async src="...">` tag. The pixel is ~15.6KB
gzipped and loads in parallel with your page.

## Server-side context (the wedge)

Out of the box, the pixel captures behavior client-side. Add the optional
middleware to send the Laravel context Harvv needs to make issues actionable:

```php
// bootstrap/app.php  (Laravel 11+)
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: [
        \Harvv\Laravel\Http\Middleware\HarvvContext::class,
    ]);
})
```

```php
// app/Http/Kernel.php  (Laravel 10 and earlier)
protected $middlewareGroups = [
    'web' => [
        // ...
        \Harvv\Laravel\Http\Middleware\HarvvContext::class,
    ],
];
```

The install command offers to do this for you with a "Recommended — adds
server-side context to your Harvv issues. Add now? [Y/n]" prompt.

What you get with the middleware:

> **Without:** "Rage click on `/checkout`"
> **With:** "Rage click on `checkout.show` route, user `<hashed>`, on third attempt this session"

User IDs are SHA-256 hashed with your site key by default — Harvv never sees
your primary keys. Override with `HARVV_CONTEXT_UNHASHED=true` only if your
user IDs aren't sensitive AND you've reviewed the implications (the install
command prints a warning when it detects this flag).

## Compatibility

| Laravel | PHP        | Status                                |
| ------- | ---------- | ------------------------------------- |
| 13.x    | 8.3, 8.4   | ✅ Supported (current)                |
| 12.x    | 8.2, 8.3   | ✅ Supported                          |
| 11.x    | 8.2, 8.3   | ✅ Supported (security-only — upgrade recommended) |
| 10.x    | 8.1, 8.2   | ⚠️ Use the generic JS snippet instead |

The CI matrix runs every PR against every valid combination above. See
[`/.github/workflows/tests.yml`](./.github/workflows/tests.yml).

## What this is NOT

- **Not a Sentry replacement.** Harvv is browser-side behavior analytics, not
  server-side error tracking. Use Sentry for your `Throwable`s.
- **Not session replay.** No DOM mutation recording, no video, no
  reconstruction. We detect issues and suggest fixes instead.
- **Not a Microsoft Clarity replacement** — though they coexist cleanly. See
  [docs/integrations.md](https://harvv.com/docs/laravel/integrations) for
  details on running both.

## Verifying your install

```bash
php artisan harvv:verify
```

Runs five checks: site key set + shape-correct (16 hex), HMAC secret set
+ `hlv1_` prefix, `config('harvv.enabled') = true`, the `@harvv` Blade
directive is registered, the `HarvvContext` middleware is registered.
Exits non-zero on any required failure so CI can gate deploys on
`php artisan harvv:verify`.

Also prints the rendered pixel URL on this site — visit any page,
View Source, look for that exact URL. That's your second-source verification.

Once events flow, the site's Settings page in your Harvv dashboard shows a
"Laravel package detected ✓" badge with the version you're running and the
last event timestamp.

## Troubleshooting

Full troubleshooting at
[docs.harvv.com/laravel#troubleshooting](https://docs.harvv.com/laravel#troubleshooting).
Top hits:

- **`@harvv` renders empty in view-source.** Either `HARVV_SITE_KEY` is empty or
  `HARVV_ENABLED=false`. Run `php artisan harvv:verify`. On pre-v0.1.1 installs,
  `APP_ENV=local` also disabled the pixel — upgrade.
- **`composer require harvv/laravel` — Package not found.** Packagist publish
  hasn't completed yet. Use the VCS one-liner at the top of this README.
- **Events show up but no Laravel route attached.** `HarvvContext` middleware
  isn't in the stack, OR the `HARVV_HMAC_SECRET` in `.env` doesn't match the
  one in Studio. Rotate from Studio → Settings.
- **`php artisan harvv:install` — command not found.** Old install. Run
  `composer update harvv/laravel`. The command shipped in v0.1.1 (2026-05-12).
- **Telescope/Pulse noise from our outbound POSTs.** Filter `X-Harvv-Internal: 1`
  requests in your watchers.
- **Filament admin showing pixel.** Add `admin/*` to
  `config('harvv.context.excluded_routes')` (already in the default list).

## Uninstall

```bash
composer remove harvv/laravel
```

Then remove `HARVV_SITE_KEY`, `HARVV_HMAC_SECRET`, and any `HARVV_*` lines
from `.env`. If you registered the middleware manually, remove it from
`bootstrap/app.php` or `Kernel.php`.

## License

MIT. See [LICENSE](./LICENSE).

## Pre-v1.0 bake criteria

This package is `v0.1.0` until ALL four are true:

- 30 days minimum since first publish
- 10+ real installs in production
- Zero P0 issues reported
- At least one unanticipated bug report (proves real-world stress-testing)

Track progress at [harvv.com/laravel/status](https://harvv.com/laravel/status).
