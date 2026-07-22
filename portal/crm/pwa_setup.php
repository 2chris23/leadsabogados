<?php
/**
 * Copiar iconos PWA a sus ubicaciones correctas
 * Ejecutar una vez: http://localhost/portal/crm/pwa_setup.php
 */
define('CRM_ROOT', __DIR__);

$src = 'C:/Users/Windows/.gemini/antigravity/brain/39567e8c-7b10-4c9c-9085-097d134e5550/';

// CRM icon
$crmIcon = $src . 'crm_app_icon_1778286027915.png';
$portalIcon = $src . 'portal_app_icon_1778286039425.png';

$targets = [
    $crmIcon => [
        CRM_ROOT . '/assets/images/icon-192.png',
        CRM_ROOT . '/assets/images/icon-512.png',
    ],
    $portalIcon => [
        dirname(CRM_ROOT) . '/portal/assets/icon-192.png',
        dirname(CRM_ROOT) . '/portal/assets/icon-512.png',
    ]
];

// Create dirs
@mkdir(dirname(CRM_ROOT) . '/portal/assets', 0755, true);

foreach ($targets as $source => $dests) {
    foreach ($dests as $dest) {
        if (file_exists($source)) {
            // Resize for 192px version
            if (strpos($dest, '192') !== false && extension_loaded('gd')) {
                $img = imagecreatefrompng($source);
                $w = imagesx($img);
                $h = imagesy($img);
                $dst = imagecreatetruecolor(192, 192);
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
                imagefilledrectangle($dst, 0, 0, 192, 192, $transparent);
                imagecopyresampled($dst, $img, 0, 0, 0, 0, 192, 192, $w, $h);
                imagepng($dst, $dest, 8);
                echo "<p>✅ Created 192px: $dest</p>";
            } else {
                copy($source, $dest);
                echo "<p>✅ Copied 512px: $dest</p>";
            }
        } else {
            echo "<p>❌ Source not found: $source</p>";
        }
    }
}
echo "<h3>PWA icons setup complete!</h3>";
