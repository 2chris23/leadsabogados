<?php
ob_start();
// Serve manifest as JSON via PHP (InfinityFree blocks static .json)

$crmBase = '';
if (isset($_SERVER['HTTP_HOST'])) {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $crmBase = $proto . '://' . $_SERVER['HTTP_HOST'] . '/portal/crm';
}

$manifest = json_encode([
    'name'             => 'CRM Abogados',
    'short_name'       => 'CRM',
    'description'      => 'Sistema de Gestión para Despacho de Abogados',
    'start_url'        => './index.php?page=dashboard',
    'scope'            => './',
    'display'          => 'standalone',
    'orientation'      => 'any',
    'background_color' => '#1b2431',
    'theme_color'      => '#487fff',
    'lang'             => 'es',
    'icons'            => [
        [
            'src'     => $crmBase . '/assets/images/icon-192.png',
            'sizes'   => '192x192',
            'type'    => 'image/png',
            'purpose' => 'any maskable'
        ],
        [
            'src'     => $crmBase . '/assets/images/icon-512.png',
            'sizes'   => '512x512',
            'type'    => 'image/png',
            'purpose' => 'any maskable'
        ]
    ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

ob_end_clean();
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=86400');
echo $manifest;
exit;
