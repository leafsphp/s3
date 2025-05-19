<?php

declare(strict_types=1);

if (!function_exists('withBucket')) {
    /**
     * Switch to bucket storage
     */
    function withBucket(string $path, ?string $bucket = null): string
    {
        if ($bucket === null) {
            $bucket = \Leaf\Config::getStatic('storage.default') ?? 's3';
        }

        return "$bucket://$path";
    }
}
