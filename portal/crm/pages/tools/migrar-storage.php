<?php
/**
 * CRM Abogados — Herramienta de Migración de Archivos
 * Mueve documentos de /crm/uploads/casos/ → /crm/storage/casos/
 * y actualiza la columna ruta_storage en la BD.
 * 
 * Acceso: solo admin/superadmin
 * Ruta: ?page=tools/migrar-storage
 */
if (!defined('CRM_ROOT')) die('Acceso prohibido');
if (!in_array($_SESSION['usuario_rol'] ?? '', ['admin', 'superadmin'])) {
    header('Location: ' . APP_URL . '/index.php?page=acceso-denegado'); exit;
}

$db = Database::getInstance();

// Asegurar que la columna ruta_storage existe
try {
    $db->query("ALTER TABLE documentos ADD COLUMN ruta_storage VARCHAR(500) DEFAULT NULL");
} catch (Exception $e) { /* ya existe */ }

$tituloPagina = 'Migración de Archivos a Storage Seguro';
include CRM_ROOT . '/templates/layout/header.php';

$resultados = [];
$ejecutar   = isset($_POST['ejecutar_migracion']);

// Listar documentos sin ruta_storage todavía
$pendientes = $db->fetchAll(
    "SELECT id, caso_id, nombre_original, nombre_archivo, ruta, ruta_storage
     FROM documentos
     WHERE (ruta_storage IS NULL OR ruta_storage = '')
       AND ruta IS NOT NULL
     ORDER BY id"
);

if ($ejecutar && !empty($pendientes)) {
    CSRF::verificarOAbortar();

    foreach ($pendientes as $doc) {
        $rutaOrigen = CRM_ROOT . '/' . $doc['ruta'];
        $casoId     = (int)$doc['caso_id'];

        if (!file_exists($rutaOrigen)) {
            $resultados[] = ['id' => $doc['id'], 'estado' => 'no_encontrado', 'nombre' => $doc['nombre_original']];
            continue;
        }

        // Destino en storage/
        $dirDest  = CRM_ROOT . '/storage/casos/' . $casoId;
        if (!is_dir($dirDest)) {
            mkdir($dirDest, 0750, true);
        }
        $nombreArchivo = $doc['nombre_archivo'] ?: basename($doc['ruta']);
        $rutaDest = $dirDest . '/' . $nombreArchivo;

        if (@rename($rutaOrigen, $rutaDest)) {
            $rutaStorage = 'storage/casos/' . $casoId . '/' . $nombreArchivo;
            $db->update('documentos', [
                'ruta_storage' => $rutaStorage,
                'ruta'         => $rutaStorage,
            ], 'id = ?', [$doc['id']]);
            AuditLog::registrar('migrar_archivo', 'documentos', $doc['id'], 'Migrado a storage seguro');
            $resultados[] = ['id' => $doc['id'], 'estado' => 'ok', 'nombre' => $doc['nombre_original']];
        } else {
            $resultados[] = ['id' => $doc['id'], 'estado' => 'error', 'nombre' => $doc['nombre_original']];
        }
    }

    // Asegurar .htaccess en storage/
    $htFile = CRM_ROOT . '/storage/.htaccess';
    if (!file_exists($htFile)) file_put_contents($htFile, "Deny from all\n");
}
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <h6 class="fw-semibold mb-0">🔒 Migración a Storage Seguro</h6>
    <a href="<?php echo APP_URL; ?>/index.php?page=dashboard" class="btn btn-sm btn-outline-secondary radius-8">← Dashboard</a>
</div>

<!-- Info -->
<div class="card radius-8 border mb-24">
    <div class="card-body p-20 d-flex gap-16 align-items-start">
        <iconify-icon icon="solar:info-circle-bold" style="font-size:28px;color:#2563eb;flex-shrink:0;margin-top:2px"></iconify-icon>
        <div>
            <p class="fw-semibold mb-4">¿Qué hace esta herramienta?</p>
            <p class="text-secondary-light text-sm mb-0">
                Los documentos subidos <strong>antes de la actualización de seguridad</strong> se guardan en
                <code>crm/uploads/casos/</code> que es accesible por URL directa.
                Esta herramienta los mueve a <code>crm/storage/casos/</code> (bloqueado por <code>.htaccess</code>)
                y actualiza la base de datos para que todas las descargas pasen por el proxy autenticado.
                <strong>Los archivos nuevos ya se guardan automáticamente en el lugar correcto.</strong>
            </p>
        </div>
    </div>
</div>

<!-- Resultado de migración -->
<?php if ($ejecutar && !empty($resultados)): ?>
<div class="card radius-8 border mb-24">
    <div class="card-body p-24">
        <h6 class="fw-semibold mb-16">Resultado de la Migración</h6>
        <?php
        $ok  = count(array_filter($resultados, fn($r) => $r['estado'] === 'ok'));
        $err = count(array_filter($resultados, fn($r) => $r['estado'] === 'error'));
        $nf  = count(array_filter($resultados, fn($r) => $r['estado'] === 'no_encontrado'));
        ?>
        <div class="d-flex gap-3 mb-16 flex-wrap">
            <span class="badge bg-success-focus text-success-main px-12 py-8 radius-8">✓ Migrados: <?php echo $ok; ?></span>
            <?php if ($err): ?><span class="badge bg-danger-focus text-danger-main px-12 py-8 radius-8">✗ Errores: <?php echo $err; ?></span><?php endif; ?>
            <?php if ($nf):  ?><span class="badge bg-warning-focus text-warning-main px-12 py-8 radius-8">⚠ No encontrados: <?php echo $nf; ?></span><?php endif; ?>
        </div>
        <div class="table-responsive">
            <table class="table bordered-table sm-table mb-0">
                <thead><tr><th>ID</th><th>Archivo</th><th>Estado</th></tr></thead>
                <tbody>
                <?php foreach ($resultados as $r): ?>
                <tr>
                    <td><?php echo $r['id']; ?></td>
                    <td class="text-sm"><?php echo e($r['nombre']); ?></td>
                    <td>
                        <?php if ($r['estado'] === 'ok'): ?>
                            <span class="badge bg-success-focus text-success-main radius-4">Migrado ✓</span>
                        <?php elseif ($r['estado'] === 'error'): ?>
                            <span class="badge bg-danger-focus text-danger-main radius-4">Error al mover</span>
                        <?php else: ?>
                            <span class="badge bg-warning-focus text-warning-main radius-4">Archivo no encontrado</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Panel principal -->
<div class="card radius-8 border">
    <div class="card-body p-24">
        <?php if (empty($pendientes) && !$ejecutar): ?>
            <div class="text-center py-5">
                <iconify-icon icon="solar:check-circle-bold" style="font-size:48px;color:#059669"></iconify-icon>
                <p class="fw-semibold mt-12 mb-4">¡Todo en orden!</p>
                <p class="text-secondary-light text-sm">No hay documentos pendientes de migrar.</p>
            </div>
        <?php elseif (!empty($pendientes) && !$ejecutar): ?>
            <h6 class="fw-semibold mb-4"><?php echo count($pendientes); ?> documento(s) pendiente(s) de migrar</h6>
            <p class="text-secondary-light text-sm mb-20">Estos archivos se moverán a la carpeta segura <code>storage/</code>.</p>
            <div class="table-responsive mb-20">
                <table class="table bordered-table sm-table mb-0">
                    <thead><tr><th>ID</th><th>Caso</th><th>Archivo</th><th>Ruta actual</th></tr></thead>
                    <tbody>
                    <?php foreach ($pendientes as $d): ?>
                    <tr>
                        <td><?php echo $d['id']; ?></td>
                        <td class="text-sm">#<?php echo $d['caso_id']; ?></td>
                        <td class="text-sm"><?php echo e($d['nombre_original']); ?></td>
                        <td class="text-sm text-secondary-light" style="font-family:monospace"><?php echo e($d['ruta']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <form method="POST">
                <?php echo CSRF::campo(); ?>
                <button type="submit" name="ejecutar_migracion" value="1"
                    class="btn btn-primary radius-8 d-flex align-items-center gap-2"
                    data-confirm="¿Mover todos los archivos a la carpeta segura? Los documentos seguirán accesibles a través del proxy.">
                    <iconify-icon icon="solar:shield-check-bold"></iconify-icon>
                    Ejecutar Migración Ahora
                </button>
            </form>
        <?php elseif ($ejecutar && empty($pendientes)): ?>
            <div class="text-center py-4">
                <p class="text-secondary-light">No había nada pendiente de migrar.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include CRM_ROOT . '/templates/layout/footer.php'; ?>
