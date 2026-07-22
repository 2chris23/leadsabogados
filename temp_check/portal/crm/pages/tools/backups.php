<?php
/**
 * Página: Backups de Base de Datos
 * Acceso: solo admin / superadmin
 */
if (!defined('CRM_ROOT')) die('Acceso prohibido');
// Solo admins/superadmins
if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['usuario_rol'] ?? '', ['admin', 'superadmin'])) {
    header('Location: ' . APP_URL . '/index.php?page=acceso-denegado'); exit;
}

$backupDir = CRM_ROOT . '/backups';
if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);

// Acción: crear backup ahora
if (isset($_GET['hacer_backup'])) {
    CSRF::verificarOAbortar();
    require_once CRM_ROOT . '/tools/backup.php';
    // backup.php ya redirige con flash
    exit;
}

// Acción: descargar un backup existente
if (isset($_GET['descargar'])) {
    $archivo = basename($_GET['descargar']);
    $ruta = $backupDir . '/' . $archivo;
    // Validar que el archivo es un backup legítimo
    if (!preg_match('/^backup_[\w\-]+\.sql$/', $archivo) || !file_exists($ruta)) {
        setFlash('error', 'Archivo no encontrado');
        header('Location: ' . APP_URL . '/index.php?page=tools/backups'); exit;
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $archivo . '"');
    header('Content-Length: ' . filesize($ruta));
    readfile($ruta);
    exit;
}

// Acción: eliminar un backup (POST + CSRF)
if (isset($_POST['eliminar_backup'])) {
    CSRF::verificarOAbortar();
    $archivo = basename($_POST['eliminar_backup']);
    $ruta = $backupDir . '/' . $archivo;
    if (preg_match('/^backup_[\w\-]+\.sql$/', $archivo) && file_exists($ruta)) {
        unlink($ruta);
        AuditLog::registrar('eliminar', 'backups', null, 'Backup eliminado: ' . $archivo);
        setFlash('exito', 'Backup eliminado');
    }
    header('Location: ' . APP_URL . '/index.php?page=tools/backups'); exit;
}

// Listar backups existentes
$archivos = glob($backupDir . '/backup_*.sql') ?: [];
usort($archivos, fn($a, $b) => filemtime($b) - filemtime($a)); // más recientes primero

include CRM_ROOT . '/templates/layout/header.php';
?>

<div class="dashboard-main-body">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-24">
        <h6 class="fw-semibold mb-0">🗄️ Backups de Base de Datos</h6>
        <form method="GET" action="<?php echo APP_URL; ?>/index.php" style="display:inline">
            <input type="hidden" name="page" value="tools/backups">
            <input type="hidden" name="hacer_backup" value="1">
            <?php echo CSRF::campo(); ?>
            <button type="submit" class="btn btn-primary radius-8" data-confirm="¿Crear un backup ahora? Puede tardar unos segundos.">
                <iconify-icon icon="ic:round-save" class="me-1"></iconify-icon> Crear Backup Ahora
            </button>
        </form>
    </div>

    <?php include CRM_ROOT . '/templates/partials/flash.php'; ?>

    <div class="card radius-8 border">
        <div class="card-body p-24">
            <div class="d-flex align-items-center gap-3 mb-20 p-16 bg-warning-50 radius-8">
                <iconify-icon icon="solar:info-circle-outline" class="text-warning-main text-2xl"></iconify-icon>
                <div>
                    <p class="fw-semibold mb-0 text-sm">Sobre los backups</p>
                    <p class="text-secondary-light text-xs mb-0">Se guardan los últimos <strong>30 backups</strong> localmente. Se recomienda descargar y guardar copias periódicamente en un lugar externo (Google Drive, disco duro, etc.). Los backups incluyen todos los datos del CRM.</p>
                </div>
            </div>

            <?php if (empty($archivos)): ?>
            <p class="text-center text-secondary-light py-5">No hay backups todavía. Crea uno ahora.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table bordered-table sm-table mb-0">
                    <thead>
                        <tr>
                            <th>Archivo</th>
                            <th>Fecha</th>
                            <th>Tamaño</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($archivos as $i => $ruta): ?>
                        <?php
                            $nombreArchivo = basename($ruta);
                            $fechaArchivo  = date('d/m/Y H:i', filemtime($ruta));
                            $tamañoKB      = round(filesize($ruta) / 1024, 1);
                            $esUltimo      = ($i === 0);
                        ?>
                        <tr>
                            <td>
                                <span class="fw-medium text-sm">
                                    <iconify-icon icon="solar:database-bold" class="me-1 text-primary-600"></iconify-icon>
                                    <?php echo e($nombreArchivo); ?>
                                </span>
                                <?php if ($esUltimo): ?>
                                <span class="badge bg-success-100 text-success-main ms-2" style="font-size:10px;padding:2px 8px;border-radius:20px">Más reciente</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-sm"><?php echo $fechaArchivo; ?></td>
                            <td class="text-sm"><?php echo $tamañoKB; ?> KB</td>
                            <td class="text-end">
                                <a href="<?php echo APP_URL; ?>/index.php?page=tools/backups&descargar=<?php echo urlencode($nombreArchivo); ?>"
                                   class="btn btn-sm btn-success-100 text-success-main radius-6 me-1">
                                    <iconify-icon icon="ic:round-download"></iconify-icon> Descargar
                                </a>
                                <?php if (!$esUltimo): ?>
                                <form method="POST" style="display:inline">
                                    <?php echo CSRF::campo(); ?>
                                    <input type="hidden" name="eliminar_backup" value="<?php echo e($nombreArchivo); ?>">
                                    <button type="submit" class="btn btn-sm btn-danger-100 text-danger-main radius-6"
                                        data-confirm="¿Eliminar este backup? Esta acción no se puede deshacer.">
                                        <iconify-icon icon="ic:round-delete"></iconify-icon>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include CRM_ROOT . '/templates/layout/footer.php'; ?>
