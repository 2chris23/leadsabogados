<?php
$file = __DIR__ . '/landing_original.zip';
if (!file_exists($file)) {
    http_response_code(404);
    die('Archivo no encontrado');
}
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="landing_leadsabogados.zip"');
header('Content-Length: ' . filesize($file));
header('Cache-Control: no-cache');
readfile($file);
exit;
