<?php

declare(strict_types=1);

namespace Harvv\Laravel\View\Components;

use Illuminate\View\Component;

/**
 * <x-harvv-pixel /> component — Blade component alternative to @harvv.
 *
 * Usage (in a layout, typically right before </body> or in <head>):
 *
 *     <x-harvv-pixel />
 *
 * The component renders nothing when Harvv is not enabled / not
 * configured. Identical behavior to the @harvv directive — both
 * delegate to Harvv::renderPixel(), so output is byte-identical.
 *
 * We don't take any props in v0.1. Future versions might accept:
 *
 *     <x-harvv-pixel :site-key="..." />    // override the global config
 *     <x-harvv-pixel :disabled="$dev" />   // per-render kill switch
 *
 * Adding those is non-breaking — components ignore unknown attributes.
 */
class HarvvPixel extends Component
{
    /**
     * Render path. We override Component::render() to return a raw
     * string (the rendered pixel) rather than a View instance. This
     * avoids a second template-resolution step — the Harvv service
     * already calls view('harvv::pixel')->render() internally.
     *
     * The Illuminate\View\Component contract accepts either a string
     * or a View; returning a string is documented + supported.
     */
    public function render(): string
    {
        return app('harvv')->renderPixel();
    }
}
