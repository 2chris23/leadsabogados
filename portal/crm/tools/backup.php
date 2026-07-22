<?php
/**
 * CRM Abogados — Script de Backup con envío por Email
 * 
 * Uso:
 *   - CLI/cron diario: php backup.php
 *   - Desde UI admin:  /index.php?page=tools/backups&hacer_backup=1
 * 
 * Genera el .sql, lo envía por email como adjunto y borra el backup anterior.
 * Solo conserva el backup más reciente en disco.
 */

$esCLI = (php_sapi_name() === 'cli');

if (!$esCLI) {
    if (!defined('CRM_ROOT')) {
        require_once __DIR__ . '/../includes/config.php';
        require_once __DIR__ . '/../includes/Database.php';
        require_once __DIR__ . '/../includes/Auth.php';
        session_start();
    }
    if (!isset($_SESSION['usuario_id'])) { http_response_code(403); die('Acceso denegado'); }
    if (!in_array($_SESSION['rol'] ?? '', ['admin', 'superadmin'])) { http_response_code(403); die('Solo admins'); }
} else {
    define('CRM_ROOT', dirname(__DIR__));
    require_once CRM_ROOT . '/includes/config.php';
}

require_once CRM_ROOT . '/includes/Mailer.php';

// ── Directorio de backups ────────────────────────────────────────────────────
$backupDir = CRM_ROOT . '/backups';
if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);

// ── Generar el archivo .sql ──────────────────────────────────────────────────
$fecha    = date('Y-m-d_H-i-s');
$dbName   = DB_NAME;
$fileName = "backup_{$dbName}_{$fecha}.sql";
$filePath = $backupDir . '/' . $fileName;

$mysqldump = 'c:\\xampp\\mysql\\bin\\mysqldump.exe';
if (!file_exists($mysqldump)) $mysqldump = 'mysqldump'; // Linux/producción

$passArg = !empty(DB_PASS) ? '-p' . escapeshellarg(DB_PASS) : '';
$cmd = sprintf(
    '%s -h %s -u %s %s --single-transaction --routines --triggers %s > %s 2>&1',
    escapeshellcmd($mysqldump),
    escapeshellarg(DB_HOST),
    escapeshellarg(DB_USER),
    $passArg,
    escapeshellarg($dbName),
    escapeshellarg($filePath)
);

exec($cmd, $output, $exitCode);

if ($exitCode !== 0 || !file_exists($filePath) || filesize($filePath) < 100) {
    $msg = "Error al generar el backup. Código: $exitCode";
    error_log("[BACKUP] $msg");
    if ($esCLI) { echo "[BACKUP ERROR] $msg\n"; exit(1); }
    setFlash('error', $msg);
    header('Location: ' . APP_URL . '/index.php?page=tools/backups'); exit;
}

$tamKB = round(filesize($filePath) / 1024, 1);

// ── Enviar por email como adjunto ────────────────────────────────────────────
$emailDestino = SMTP_USER; // El mismo correo configurado en .env
$fechaFormato = date('d/m/Y H:i');
$cuerpoHtml   = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;padding:30px 0;">
    <tr><td align="center">
      <table width="520" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 16px rgba(0,0,0,.08);">
        <tr><td style="background:linear-gradient(135deg,#1d4ed8,#4338ca);padding:24px 32px;">
          <h1 style="color:#fff;margin:0;font-size:20px;">🗄️ Backup Automático — CRM Abogados</h1>
          <p style="color:rgba(255,255,255,.8);margin:6px 0 0;font-size:13px;">{$fechaFormato}</p>
        </td></tr>
        <tr><td style="padding:28px 32px;">
          <p style="color:#374151;font-size:14px;margin:0 0 16px;">Se adjunta el backup automático de la base de datos del CRM:</p>
          <table width="100%" cellpadding="8" cellspacing="0" style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;font-size:13px;margin-bottom:16px;">
            <tr><td style="color:#6b7280;width:120px;">Base de datos</td><td style="font-weight:600;color:#111827;">{$dbName}</td></tr>
            <tr style="border-top:1px solid #e5e7eb;"><td style="color:#6b7280;">Archivo</td><td style="font-weight:600;color:#111827;">{$fileName}</td></tr>
            <tr style="border-top:1px solid #e5e7eb;"><td style="color:#6b7280;">Tamaño</td><td style="font-weight:600;color:#111827;">{$tamKB} KB</td></tr>
            <tr style="border-top:1px solid #e5e7eb;"><td style="color:#6b7280;">Fecha</td><td style="font-weight:600;color:#111827;">{$fechaFormato}</td></tr>
          </table>
          <p style="color:#6b7280;font-size:12px;margin:0;">💡 Guarda este archivo en un lugar seguro (Google Drive, disco duro externo). Este backup reemplaza al anterior.</p>
        </td></tr>
        <tr><td style="background:#f8fafc;padding:12px 32px;border-top:1px solid #e5e7eb;text-align:center;">
          <p style="margin:0;font-size:11px;color:#9ca3af;">CRM Abogados — Backup automático</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

$enviado = Mailer::enviarConAdjunto(
    $emailDestino,
    "🗄️ Backup BD {$dbName} — {$fechaFormato}",
    $cuerpoHtml,
    $filePath,
    $fileName
);

if ($enviado) {
    error_log("[BACKUP] Enviado a $emailDestino — $fileName ({$tamKB} KB)");
} else {
    error_log("[BACKUP] AVISO: backup creado pero el email falló — $fileName");
}

// ── Eliminar backups anteriores (solo conservar el más reciente) ─────────────
$anteriores = glob($backupDir . '/backup_*.sql');
usort($anteriores, fn($a, $b) => filemtime($b) - filemtime($a)); // más recientes primero
foreach (array_slice($anteriores, 1) as $viejo) {
    unlink($viejo);
}

// ── Respuesta ────────────────────────────────────────────────────────────────
if ($esCLI) {
    echo "[BACKUP OK] $fileName ({$tamKB} KB)" . ($enviado ? " — Email enviado a $emailDestino" : " — Email falló") . "\n";
    exit(0);
}

$msg = "✅ Backup creado ({$tamKB} KB)" . ($enviado ? " y enviado a $emailDestino" : " (email falló, revisa logs)");
setFlash('exito', $msg);
header('Location: ' . APP_URL . '/index.php?page=tools/backups'); exit;
