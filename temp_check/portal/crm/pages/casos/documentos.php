<?php
/**
 * CRM Abogados - Subida de Documentos por Caso
 */
$db = Database::getInstance();
global $usuario;
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/index.php?page=casos'); exit; }
RoleGuard::verificarAccesoCaso($id);

$caso = $db->fetchOne("SELECT * FROM casos WHERE id = ?", [$id]);
if (!$caso) { setFlash('error', 'Caso no encontrado'); header('Location: ' . APP_URL . '/index.php?page=casos'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verificarOAbortar();
    
    if (isset($_FILES['documento']) && $_FILES['documento']['error'] !== UPLOAD_ERR_NO_FILE) {
        $resultado = FileUpload::subir($_FILES['documento'], $id);
        if ($resultado['exito']) {
            $db->insert('documentos', array_merge($resultado['datos'], [
                'caso_id' => $id,
                'descripcion' => trim($_POST['descripcion'] ?? ''),
                'subido_por' => $usuario['id']
            ]));
            AuditLog::registrar('subir_documento', 'documentos', $id, 'Documento: ' . $resultado['datos']['nombre_original']);

            // Notificar al cliente si está habilitado
            $notifDoc = $db->fetchColumn("SELECT valor FROM configuracion WHERE clave = 'email_notif_documento'") ?? '1';
            if ($notifDoc === '1') {
                $clienteInfo = $db->fetchOne(
                    "SELECT cl.email, cl.nombre, cl.apellidos, c.referencia 
                     FROM casos c JOIN clientes cl ON c.cliente_id = cl.id 
                     WHERE c.id = ?", [$id]
                );
                if ($clienteInfo && filter_var($clienteInfo['email'], FILTER_VALIDATE_EMAIL)) {
                    require_once dirname(__DIR__, 2) . '/includes/Mailer.php';
                    Mailer::nuevoDocumento(
                        $clienteInfo['email'],
                        $clienteInfo['nombre'] . ' ' . $clienteInfo['apellidos'],
                        $clienteInfo['referencia'],
                        $resultado['datos']['nombre_original'],
                        APP_URL . '/../portal/index.php?page=dashboard'
                    );
                }
            }

            setFlash('exito', 'Documento subido correctamente');
        } else {
            setFlash('error', $resultado['mensaje']);
        }
    }
    
    header('Location: ' . APP_URL . '/index.php?page=casos/documentos&id=' . $id); exit;
}

// Eliminar documento
if (isset($_GET['eliminar_doc'])) {
    $docId = (int)$_GET['eliminar_doc'];
    $doc = $db->fetchOne("SELECT * FROM documentos WHERE id = ? AND caso_id = ?", [$docId, $id]);
    if ($doc) {
        FileUpload::eliminar($doc['ruta']);
        $db->delete('documentos', 'id = ?', [$docId]);
        AuditLog::registrar('eliminar_documento', 'documentos', $id, 'Documento eliminado: ' . $doc['nombre_original']);
        setFlash('exito', 'Documento eliminado');
    }
    header('Location: ' . APP_URL . '/index.php?page=casos/documentos&id=' . $id); exit;
}

$documentos = $db->fetchAll(
    "SELECT d.*, u.nombre as subido_nombre FROM documentos d LEFT JOIN usuarios_internos u ON d.subido_por = u.id WHERE d.caso_id = ? ORDER BY d.created_at DESC", [$id]
);

$tituloPagina = 'Documentos — ' . $caso['referencia'];

// Migración silenciosa: añadir columna ruta_storage si no existe
try {
    $db->query("ALTER TABLE documentos ADD COLUMN ruta_storage VARCHAR(500) DEFAULT NULL");
} catch(Exception $e) { /* ya existe */ }

include CRM_ROOT . '/templates/layout/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <h6 class="fw-semibold mb-0">Documentos — <?php echo e($caso['referencia']); ?></h6>
    <a href="<?php echo APP_URL; ?>/index.php?page=casos/ver&id=<?php echo $id; ?>" class="btn btn-sm btn-outline-primary radius-8">← Volver al Caso</a>
</div>

<div class="row gy-4">
    <div class="col-lg-4">
        <div class="card radius-8 border">
            <div class="card-body p-24">
                <h6 class="fw-semibold mb-16">Subir Documento</h6>
                <form method="POST" enctype="multipart/form-data">
                    <?php echo CSRF::campo(); ?>
                    <div class="mb-12">
                        <label class="form-label">Archivo <span class="text-danger">*</span></label>
                        <input type="file" name="documento" class="form-control radius-8" required accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.txt">
                        <small class="text-secondary-light">PDF, Word, Excel, imágenes. Máx 10MB</small>
                    </div>
                    <div class="mb-12">
                        <label class="form-label">Descripción</label>
                        <input type="text" name="descripcion" class="form-control radius-8" placeholder="Ej: Contrato firmado">
                    </div>
                    <button type="submit" class="btn btn-primary w-100 radius-8">Subir</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card radius-8 border">
            <div class="card-body p-24">
                <h6 class="fw-semibold mb-16">Documentos Adjuntos (<?php echo count($documentos); ?>)</h6>
                <?php if (empty($documentos)): ?>
                <p class="text-center text-secondary-light py-3">No hay documentos</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table bordered-table sm-table mb-0">
                        <thead><tr><th>Nombre</th><th>Descripción</th><th>Tamaño</th><th>Subido por</th><th>Fecha</th><th class="text-center">Acciones</th></tr></thead>
                        <tbody>
                            <?php foreach ($documentos as $doc): ?>
                            <tr>
                                <td><iconify-icon icon="solar:document-text-outline" class="me-1"></iconify-icon><?php echo e($doc['nombre_original']); ?></td>
                                <td class="text-sm"><?php echo e($doc['descripcion'] ?: '-'); ?></td>
                                <td class="text-sm"><?php echo FileUpload::formatearTamano($doc['tamano_bytes']); ?></td>
                                <td class="text-sm"><?php echo e($doc['subido_nombre'] ?: 'Cliente (Portal)'); ?></td>
                                <td class="text-sm"><?php echo date('d/m/Y', strtotime($doc['created_at'])); ?></td>
                                <td class="text-center">
                                    <div class="d-flex gap-10 justify-content-center">
                                        <a href="<?php echo APP_URL; ?>/index.php?page=casos/descargar&doc=<?php echo $doc['id']; ?>" class="bg-info-focus text-info-main w-32-px h-32-px d-flex justify-content-center align-items-center rounded-circle" title="Descargar"><iconify-icon icon="solar:download-minimalistic-outline"></iconify-icon></a>
                                        <a href="?page=casos/documentos&id=<?php echo $id; ?>&eliminar_doc=<?php echo $doc['id']; ?>" class="bg-danger-focus text-danger-main w-32-px h-32-px d-flex justify-content-center align-items-center rounded-circle" title="Eliminar" data-confirm="¿Eliminar este documento? Esta acción no se puede deshacer."><iconify-icon icon="fluent:delete-20-regular"></iconify-icon></a>
                                    </div>
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
</div>

<?php include CRM_ROOT . '/templates/layout/footer.php'; ?>
