<?php
// Serve manifest as JSON via PHP
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'leadsabogados.com';
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$baseUrl = $protocol . '://' . $host . $scriptDir;

header('Content-Type: application/manifest+json');
header('Cache-Control: no-store');

echo json_encode([
    'name' => 'Portal del Cliente',
    'short_name' => 'Mi Portal',
    'description' => 'Portal de seguimiento de casos legales',
    'start_url' => $baseUrl . '/index.php?page=dashboard',
    'scope' => $baseUrl . '/',
    'display' => 'standalone',
    'orientation' => 'any',
    'background_color' => '#0f172a',
    'theme_color' => '#2e6edd',
    'lang' => 'es',
    'icons' => [
        [
            'src' => $baseUrl . '/assets/icon-192.png',
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'any maskable'
        ],
        [
            'src' => $baseUrl . '/assets/icon-512.png',
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'any maskable'
        ]
    ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
