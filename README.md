# harvv/laravel

Behavioral UX analytics for Laravel. Detects rage clicks, dead clicks, form
abandonment, scroll friction, and Core Web Vitals issues across every page.
Server-side context (route name, hashed user ID, request ID) means each
detected issue lands in your dashboard tied to the Laravel route + user that
hit it — not just `/checkout`.

> **v0.1.0 — early access.** Stable for production traffic; API may still
> shift before v1.0. See the bake criteria at the bottom of this README.

## Quick Install — Pre-Packagist (today)

Packagist publish is in progress. Until the listing lands (later today),
install via Composer's VCS repository against this GitHub repo:

```json
// composer.json
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/AxiomState/harvv-laravel" }
  ]
}
```

```bash
composer require harvv/laravel:dev-main
php artisan harvv:install
```

`dev-main` tracks the `main` branch; we tag releases (`v0.1.0`, `v0.1.1`, …)
as semver becomes meaningful. Pin to a tag in production:

```bash
composer require harvv/laravel:^0.1
```

Once `harvv/laravel` is live on Packagist, the **VCS block and the `:dev-main`
suffix are no longer required** — the install becomes the standard one-liner
shown in [Quickstart](#quickstart-2-minutes) below. Star this repo for the
Packagist-live notification, or check back here — this section will be
removed when the listing is live.

## Quickstart (2 minutes)

> Once Packagist publish completes, this is the only install path you need.
> Until then, see [Quick Install — Pre-Packagist](#quick-install--pre-packagist-today).

```bash
composer require harvv/laravel
php artisan harvv:install
```

The install command prompts you for your site key (from
[harvv.com/site/.../settings/install](https://harvv.com/docs/laravel#site-key))
and writes `HARVV_SITE_KEY` to your `.env`. That's it.

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

Output shows: ServiceProvider booted ✓, middleware registered ✓, site key
configured ✓, last successful pixel event received at \<timestamp\>.

Or visit your site's settings page in the Harvv dashboard — when the package
is detected, a "Laravel package detected ✓" badge appears with the version
you're running and the last event timestamp.

## Troubleshooting

See [harvv.com/docs/laravel/troubleshooting](https://harvv.com/docs/laravel/troubleshooting)
for the top issues and fixes. Common ones:

- **Pixel not loading?** Check `HARVV_SITE_KEY` is set in `.env` and the
  config cache is cleared (`php artisan config:clear`).
- **Middleware not adding context?** Confirm it's registered AFTER your
  CSRF middleware but BEFORE your auth-redirect middleware.
- **Telescope/Pulse noise from our outbound POSTs?** Filter out
  `X-Harvv-Internal: 1` requests in your watchers.
- **Filament admin showing pixel?** Add `admin/*` to
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
