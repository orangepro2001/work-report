<?php

use Illuminate\Support\Facades\URL;

if (! function_exists('wr_asset')) {
    /**
     * Asset URL that honours the reverse-proxy mount point.
     *
     * Behind `tailscale serve --set-path /report`, the browser asks for
     * /report/assets/app.css while nginx serves /assets/app.css — so the HTML must
     * carry the prefix even though the application root does not. Deriving it from
     * config/workreport.base_path (instead of APP_URL) keeps one image usable both
     * at the root in local dev and under /report in production.
     */
    function wr_asset(string $path): string
    {
        $path = ltrim($path, '/');
        $prefix = trim((string) config('workreport.base_path'), '/');

        return URL::to(($prefix === '' ? '' : '/'.$prefix).'/'.$path);
    }
}
