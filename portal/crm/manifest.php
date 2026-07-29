<?php
ob_start();
// Serve manifest as JSON via PHP

// Detect base URL dynamically
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'app.leadsabogados.com';
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$baseUrl = $protocol . '://' . $host . $scriptDir;

$manifest = json_encode([
    'name'             => 'CRM Abogados',
    'short_name'       => 'CRM',
    'description'      => 'Sistema de Gestión para Despacho de Abogados',
    'start_url'        => $baseUrl . '/index.php?page=dashboard',
    'scope'            => $baseUrl . '/',
    'display'          => 'standalone',
    'orientation'      => 'any',
    'background_color' => '#1b2431',
    'theme_color'      => '#487fff',
    'lang'             => 'es',
    'icons'            => [
        [
            'src'     => $baseUrl . '/assets/images/icon-192.png',
            'sizes'   => '192x192',
            'type'    => 'image/png',
            'purpose' => 'any maskable'
        ],
        [
            'src'     => $baseUrl . '/assets/images/icon-512.png',
            'sizes'   => '512x512',
            'type'    => 'image/png',
            'purpose' => 'any maskable'
        ]
    ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

ob_end_clean();
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-store');
echo $manifest;
exit;
