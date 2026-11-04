<?php

use Illuminate\Support\Facades\URL;

if (! function_exists('wr_asset')) {
    /**
     * Asset URL that honours the reverse-proxy mount point.
     *
     * URL::to() already carries the mount point because ForceMountPath forces the
     * URL root, so the prefix must not be added a second time here.
     */
    function wr_asset(string $path): string
    {
        return URL::to(ltrim($path, '/'));
    }
}
