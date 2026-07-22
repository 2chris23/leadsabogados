<?php
// Serve manifest as JSON via PHP (InfinityFree blocks static .json)
header('Content-Type: application/manifest+json');
header('Cache-Control: public, max-age=86400');

$portalBase = '';
// Detect base URL
if (isset($_SERVER['HTTP_HOST'])) {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $portalBase = $proto . '://' . $_SERVER['HTTP_HOST'] . portalUrl();
}

echo json_encode([
    'name' => 'Portal del Cliente',
    'short_name' => 'Mi Portal',
    'description' => 'Portal de seguimiento de casos legales',
    'start_url' => './index.php?page=dashboard',
    'scope' => './',
    'display' => 'standalone',
    'orientation' => 'any',
    'background_color' => '#0f172a',
    'theme_color' => '#2e6edd',
    'lang' => 'es',
    'icons' => [
        [
            'src' => $portalBase . '/assets/icon-192.png',
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'any maskable'
        ],
        [
            'src' => $portalBase . '/assets/icon-512.png',
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'any maskable'
        ]
    ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
