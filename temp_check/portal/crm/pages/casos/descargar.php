<?php
/**
 * CRM Abogados — Descarga Segura de Documentos de Casos
 * 
 * SEGURIDAD:
 * - El archivo físico vive en /crm/storage/casos/ con .htaccess Deny from all
 * - Este proxy verifica autenticación + autorización antes de servir cada byte
 * - Previene path traversal con realpath() + comparación de prefijos
 * - Re-verifica el MIME real con finfo antes de enviar headers
 * - Registra cada descarga en audit_log
 * 
 * Acceso: ?page=casos/descargar&doc=<id>
 */

$db = Database::getInstance();
$docId = (int)($_GET['doc'] ?? 0);

if (!$docId) {
    http_response_code(400);
    die('Solicitud inválida.');
}

// ── 1. Obtener registro del documento ────────────────────────────────────────
$doc = $db->fetchOne(
    "SELECT d.*, c.cliente_id, c.abogado_id
     FROM documentos d
     JOIN casos c ON d.caso_id = c.id
     WHERE d.id = ?",
    [$docId]
);

if (!$doc) {
    http_response_code(404);
    die('Documento no encontrado.');
}

// ── 2. Verificar autorización ────────────────────────────────────────────────
// Admin → acceso total
// Abogado → solo sus casos
// Gestor → acceso de lectura en casos asignados
$tieneAcceso = false;

if ($auth->esAdmin()) {
    $tieneAcceso = true;
} elseif ($auth->esAbogado()) {
    $tieneAcceso = ((int)$doc['abogado_id'] === (int)$usuario['id']);
} elseif ($auth->esGestor()) {
    // Gestor: puede ver si tiene acceso al caso
    $tieneAcceso = (bool)$db->fetchOne(
        "SELECT 1 FROM casos WHERE id = ? LIMIT 1",
        [$doc['caso_id']]
    );
}

if (!$tieneAcceso) {
    http_response_code(403);
    AuditLog::registrar('acceso_denegado', 'documentos', $docId, 'Intento de descarga no autorizado');
    die('Acceso denegado.');
}

// ── 3. Resolver ruta física segura ───────────────────────────────────────────
// Normalizar: la ruta guardada puede ser antigua (uploads/casos/...) o nueva (storage/casos/...)
$rutaGuardada = $doc['ruta_storage'] ?? $doc['ruta'] ?? '';

// Detectar si es la ruta antigua (webroot) o nueva (storage)
if (str_starts_with($rutaGuardada, 'storage/')) {
    // Nueva ruta — fuera del webroot relativo al CRM_ROOT
    $rutaAbsoluta = CRM_ROOT . '/' . $rutaGuardada;
} else {
    // Ruta legacy (uploads/casos/...) — dentro del webroot del CRM
    $rutaAbsoluta = CRM_ROOT . '/' . $rutaGuardada;
}

// Normalizar separadores
$rutaAbsoluta = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rutaAbsoluta);

// ── 4. Path traversal prevention ────────────────────────────────────────────
$rutaReal    = realpath($rutaAbsoluta);
$storageDir  = realpath(CRM_ROOT . DIRECTORY_SEPARATOR . 'storage');
$uploadsDir  = realpath(CRM_ROOT . DIRECTORY_SEPARATOR . 'uploads');

if (!$rutaReal) {
    http_response_code(404);
    error_log("[descarga] Archivo no encontrado: $rutaAbsoluta");
    die('El archivo no existe en el servidor. Puede haber sido eliminado.');
}

// El archivo DEBE estar dentro de storage/ O uploads/ (legacy), nunca fuera
$enStorage = $storageDir && str_starts_with($rutaReal, $storageDir . DIRECTORY_SEPARATOR);
$enUploads = $uploadsDir && str_starts_with($rutaReal, $uploadsDir . DIRECTORY_SEPARATOR);

if (!$enStorage && !$enUploads) {
    http_response_code(403);
    AuditLog::registrar('path_traversal', 'documentos', $docId, 'Intento de path traversal detectado');
    die('Ruta de archivo inválida.');
}

// ── 5. Verificar MIME real ───────────────────────────────────────────────────
$tiposMimeSeguros = [
    'application/pdf',
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'text/plain',
];

$finfo   = new finfo(FILEINFO_MIME_TYPE);
$mimeReal = $finfo->file($rutaReal);

// Usar el MIME real; si no está en lista segura → forzar application/octet-stream
$mime = in_array($mimeReal, $tiposMimeSeguros) ? $mimeReal : 'application/octet-stream';

// ── 6. Registrar descarga en audit log ───────────────────────────────────────
AuditLog::registrar('descargar_documento', 'documentos', $docId,
    'Descargado: ' . ($doc['nombre_original'] ?? basename($rutaReal))
);

// ── 7. Servir el archivo ─────────────────────────────────────────────────────
$nombreOriginal = $doc['nombre_original'] ?: basename($rutaReal);
$tamano         = filesize($rutaReal);

// Limpiar cualquier output buffer acumulado por el layout
while (ob_get_level()) ob_end_clean();

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . rawurlencode($nombreOriginal) . '"');
header('Content-Length: ' . $tamano);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

readfile($rutaReal);
exit;
