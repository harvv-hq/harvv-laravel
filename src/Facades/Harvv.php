<?php

declare(strict_types=1);

namespace Harvv\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Harvv facade — convenience accessor for the Harvv service.
 *
 * Usage:
 *
 *     use Harvv\Laravel\Facades\Harvv;
 *
 *     Harvv::renderPixel();   // returns the <script> tag (or '')
 *     Harvv::enabled();       // true when site_key is set + enabled
 *     Harvv::siteKey();       // the configured key, or null
 *
 * Auto-registered alias (per composer.json `extra.laravel.aliases`):
 *
 *     Harvv::renderPixel();   // works without the use-statement above
 *
 * @method static string renderPixel(array $options = [])
 * @method static ?string siteKey()
 * @method static string host()
 * @method static bool enabled()
 * @method static ?string pixelUrl()
 *
 * @see \Harvv\Laravel\Harvv
 */
class Harvv extends Facade
{
    /**
     * The container binding the facade resolves to. Matches the alias set
     * in HarvvServiceProvider::register() — both the facade AND the
     * `app('harvv')` string-resolution path point at the same singleton.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'harvv';
    }
}
