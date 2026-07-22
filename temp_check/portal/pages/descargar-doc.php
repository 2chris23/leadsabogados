<?php
/**
 * Portal del Cliente — Proxy de Descarga de Documentos
 * 
 * Los documentos de un caso son accesibles para el cliente autenticado
 * que sea titular del caso. NO se sirve ningún archivo directamente.
 */

$portalId  = $_SESSION['portal_id'];
$docId     = (int)($_GET['doc'] ?? 0);

if (!$docId) {
    http_response_code(400);
    die('Solicitud inválida.');
}

// Obtener cuenta del cliente para conocer su cliente_id
$cuenta = $db->fetchOne("SELECT cliente_id, es_cliente FROM portal_cuentas WHERE id = ?", [$portalId]);
if (!$cuenta || !$cuenta['es_cliente'] || !$cuenta['cliente_id']) {
    http_response_code(403);
    die('Acceso denegado.');
}
$clienteId = (int)$cuenta['cliente_id'];

// Obtener el documento — verificar que pertenece a un caso del cliente
$doc = $db->fetchOne(
    "SELECT d.*
     FROM documentos d
     JOIN casos c ON d.caso_id = c.id
     WHERE d.id = ? AND c.cliente_id = ?",
    [$docId, $clienteId]
);

if (!$doc) {
    http_response_code(404);
    die('Documento no encontrado o sin acceso.');
}

// Resolver ruta física
$rutaGuardada = $doc['ruta_storage'] ?? $doc['ruta'] ?? '';
if (str_starts_with($rutaGuardada, 'storage/') || str_starts_with($rutaGuardada, 'uploads/')) {
    $rutaAbsoluta = CRM_ROOT . '/' . $rutaGuardada;
} else {
    http_response_code(404);
    die('Ruta de archivo inválida.');
}

$rutaAbsoluta = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rutaAbsoluta);
$rutaReal     = realpath($rutaAbsoluta);
$storageDir   = realpath(CRM_ROOT . DIRECTORY_SEPARATOR . 'storage');
$uploadsDir   = realpath(CRM_ROOT . DIRECTORY_SEPARATOR . 'uploads');

if (!$rutaReal) {
    http_response_code(404);
    die('El archivo no existe en el servidor.');
}

// Path traversal prevention
$enStorage = $storageDir && str_starts_with($rutaReal, $storageDir . DIRECTORY_SEPARATOR);
$enUploads = $uploadsDir && str_starts_with($rutaReal, $uploadsDir . DIRECTORY_SEPARATOR);

if (!$enStorage && !$enUploads) {
    http_response_code(403);
    die('Acceso no permitido.');
}

// MIME seguro
$tiposMimeSeguros = [
    'application/pdf',
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'text/plain',
];

$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mimeReal = $finfo->file($rutaReal);
$mime     = in_array($mimeReal, $tiposMimeSeguros) ? $mimeReal : 'application/octet-stream';

$nombreOriginal = $doc['nombre_original'] ?: basename($rutaReal);

while (ob_get_level()) ob_end_clean();

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . rawurlencode($nombreOriginal) . '"');
header('Content-Length: ' . filesize($rutaReal));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

readfile($rutaReal);
exit;
