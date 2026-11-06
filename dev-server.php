<?php

/**
 * Router for PHP's built-in server when previewing over a slow tunnel.
 *
 *   php -S 127.0.0.1:8000 -t public dev-server.php
 *
 * Same as `php artisan serve`, plus gzip compression and long-lived caching
 * for hashed build assets (the CSS alone drops from ~425 KB to ~45 KB).
 * Development only; production should use nginx/Apache compression.
 */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$publicPath = __DIR__.'/public';
$file = realpath($publicPath.$uri);
$acceptsGzip = str_contains($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip');

$types = [
    'css' => 'text/css; charset=utf-8',
    'js' => 'application/javascript; charset=utf-8',
    'json' => 'application/json',
    'svg' => 'image/svg+xml',
    'map' => 'application/json',
];

// Livewire / Flux scripts are normally streamed by PHP uncompressed (and
// unminified in debug mode: ~610 KB). Serve the minified dist files instead.
$vendorScripts = [
    '#^/livewire-[a-f0-9]+/livewire(\.min)?\.js$#' => __DIR__.'/vendor/livewire/livewire/dist/livewire.min.js',
    '#^/flux/flux(\.min)?\.js$#' => is_dir(__DIR__.'/vendor/livewire/flux-pro')
        ? __DIR__.'/vendor/livewire/flux/dist/flux.min.js'
        : __DIR__.'/vendor/livewire/flux/dist/flux-lite.min.js',
];

foreach ($vendorScripts as $pattern => $path) {
    if (preg_match($pattern, $uri) && is_file($path)) {
        $file = $path;
        $uri = '/build/vendor'; // treat like a versioned asset (URLs carry ?id=hash)
        break;
    }
}

// Static files inside /public (or the vendor scripts above).
if ($uri !== '/' && $file !== false && is_file($file) && (str_starts_with($file, realpath($publicPath)) || $uri === '/build/vendor')) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

    if (! isset($types[$ext])) {
        return false; // images, fonts, etc.: let the built-in server handle them
    }

    header('Content-Type: '.$types[$ext]);
    header('Vary: Accept-Encoding');
    header(str_starts_with($uri, '/build/')
        ? 'Cache-Control: public, max-age=31536000, immutable' // file names are content-hashed
        : 'Cache-Control: public, max-age=3600');

    $body = file_get_contents($file);

    if ($acceptsGzip) {
        $body = gzencode($body, 6);
        header('Content-Encoding: gzip');
    }

    header('Content-Length: '.strlen($body));
    echo $body;

    return true;
}

// Everything else goes through Laravel (pages, Livewire, Flux assets).
if ($acceptsGzip && ! headers_sent()) {
    ob_start('ob_gzhandler');
}

$_SERVER['SCRIPT_FILENAME'] = $publicPath.'/index.php';

require $publicPath.'/index.php';
