{{-- ============================================================
     Harvv pixel — behavioral UX analytics.

     Rendered by:
       - @harvv             (Blade directive — most common path)
       - <x-harvv-pixel />  (component syntax — equivalent)
       - Harvv::renderPixel() (programmatic — least common)

     Single async script tag. Loads in parallel with the page so it
     never blocks first contentful paint. The pixel itself is
     ~15.6KB gzipped — under the budget cap documented at
     harvv.com/trust.

     Inputs (passed from Harvv::renderPixel):
       $siteKey  — the site's pixel key, e.g. '5abaa759db95fcf4'
       $host     — receiver host, e.g. 'https://harvv.com'
                   (no trailing slash; Harvv::__construct rtrims it)

     This template is rendered ONLY when both inputs are valid —
     the Harvv service short-circuits to '' when site_key is missing
     or enabled is false. So the markup below assumes both inputs
     are present and non-empty.
     ============================================================ --}}

<script async src="{{ $host }}/px/{{ $siteKey }}/pixel.js"></script>
