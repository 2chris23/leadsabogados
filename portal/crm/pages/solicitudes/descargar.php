<?php
/**
 * CRM — Descarga segura de archivos adjuntos de solicitudes
 * Este archivo es cargado por el router principal (crm/index.php)
 * que ya verifica autenticación, roles y sesión.
 * NO incluye su propio bootstrap para evitar conflictos de sesión.
 */

// $db, $auth, $usuario ya están disponibles desde el router.
// Se obtiene la instancia del singleton para que el IDE no marque error.
$db = Database::getInstance();
$archivoId = (int)($_GET['id'] ?? 0);
if (!$archivoId) {
    http_response_code(400);
    die('Solicitud inválida.');
}

// Obtener el archivo de la base de datos
$archivo = $db->fetchOne(
    "SELECT sa.*, s.id as solicitud_id
     FROM solicitud_archivos sa
     JOIN solicitudes s ON sa.solicitud_id = s.id
     WHERE sa.id = ?",
    [$archivoId]
);

if (!$archivo) {
    http_response_code(404);
    die('Archivo no encontrado en la base de datos.');
}

$rutaRelativa = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($archivo['ruta'], '/\\'));

if (strpos($rutaRelativa, 'storage' . DIRECTORY_SEPARATOR) === 0) {
    $rutaCompleta = CRM_ROOT . DIRECTORY_SEPARATOR . $rutaRelativa;
} elseif (strpos($rutaRelativa, 'uploads' . DIRECTORY_SEPARATOR) === 0) {
    $rutaCompleta = CRM_ROOT . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . $rutaRelativa;
} else {
    // Fallback legacy
    $rutaCompleta = CRM_ROOT . DIRECTORY_SEPARATOR . $rutaRelativa;
}

if (!file_exists($rutaCompleta) || !is_file($rutaCompleta)) {
    http_response_code(404);
    echo '<h3>Archivo no encontrado</h3>';
    echo '<p>El archivo <strong>' . htmlspecialchars($archivo['nombre_original']) . '</strong> no existe en el servidor.</p>';
    exit;
}

// Seguridad: confirmar que la ruta está dentro de los directorios permitidos
$dirStorage = realpath(CRM_ROOT . DIRECTORY_SEPARATOR . 'storage');
$dirUploads = realpath(CRM_ROOT . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads');
$rutaReal   = realpath($rutaCompleta);

if (!$rutaReal || (strpos($rutaReal, $dirStorage) !== 0 && strpos($rutaReal, $dirUploads) !== 0)) {
    http_response_code(403);
    die('Acceso no permitido.');
}

// Determinar tipo MIME
$mime = $archivo['tipo_mime'] ?: 'application/octet-stream';
if (class_exists('finfo')) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedMime = $finfo->file($rutaReal);
    if ($detectedMime) $mime = $detectedMime;
}

$nombreOriginal = $archivo['nombre_original'] ?: basename($rutaReal);
$tamano = filesize($rutaReal);

// Limpiar cualquier output previo (layout, buffers, etc.)
while (ob_get_level()) {
    ob_end_clean();
}

// Headers para forzar descarga
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . rawurlencode($nombreOriginal) . '"');
header('Content-Length: ' . $tamano);
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

readfile($rutaReal);
exit;
