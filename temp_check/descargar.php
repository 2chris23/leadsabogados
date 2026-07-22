<?php
/**
 * Descarga segura de archivos — Script standalone
 * Ubicado fuera del router del CRM para evitar interceptación del Service Worker
 * Gestiona su propia sesión con el nombre correcto 'crm_abogados'
 */

// Iniciar sesión con el mismo nombre que el CRM
if (session_status() === PHP_SESSION_NONE) {
    session_name('crm_abogados');
    session_start();
}

// Bootstrap mínimo
define('CRM_ROOT', __DIR__ . '/crm');
require_once CRM_ROOT . '/includes/config.php';
require_once CRM_ROOT . '/includes/Database.php';
require_once CRM_ROOT . '/includes/Auth.php';

// Verificar autenticación
$auth = new Auth();
if (!$auth->estaLogueado()) {
    http_response_code(403);
    die('Acceso denegado. <a href="/portal/crm/index.php?page=login">Iniciar sesión</a>');
}

$archivoId = (int)($_GET['id'] ?? 0);
if (!$archivoId) {
    http_response_code(400);
    die('Parámetro inválido.');
}

$db = Database::getInstance();

$archivo = $db->fetchOne(
    "SELECT sa.* FROM solicitud_archivos sa
     JOIN solicitudes s ON sa.solicitud_id = s.id
     WHERE sa.id = ?",
    [$archivoId]
);

if (!$archivo) {
    http_response_code(404);
    die('Archivo no encontrado.');
}

// Construir ruta absoluta
$rutaBase    = __DIR__ . DIRECTORY_SEPARATOR . 'portal' . DIRECTORY_SEPARATOR;
$rutaRelativa = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $archivo['ruta']);
$rutaCompleta = $rutaBase . $rutaRelativa;

if (!file_exists($rutaCompleta)) {
    http_response_code(404);
    die('El archivo no existe en el servidor: ' . htmlspecialchars($rutaCompleta));
}

// Protección path traversal
$dirUploads = realpath($rutaBase . 'uploads' . DIRECTORY_SEPARATOR . 'solicitudes');
$rutaReal   = realpath($rutaCompleta);
if (!$rutaReal || !$dirUploads || strpos($rutaReal, $dirUploads) !== 0) {
    http_response_code(403);
    die('Acceso no permitido.');
}

// MIME type
$mime = 'application/octet-stream';
if (class_exists('finfo')) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detected = $finfo->file($rutaReal);
    if ($detected) $mime = $detected;
}

$nombreOriginal = $archivo['nombre_original'] ?: basename($rutaReal);
$tamano = filesize($rutaReal);

// Limpiar todos los buffers
while (ob_get_level()) ob_end_clean();

// Headers de descarga
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . rawurlencode($nombreOriginal) . '"');
header('Content-Length: ' . $tamano);
header('Content-Transfer-Encoding: binary');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($rutaReal);
exit;
