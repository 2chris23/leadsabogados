<?php
/**
 * CRM Abogados - Listado de Solicitudes
 */
$tituloPagina = 'Solicitudes';
$db = Database::getInstance();

// ── Procesar acciones POST antes de cualquier output ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    CSRF::verificarOAbortar();

    $solicitudId = (int)($_POST['solicitud_id'] ?? 0);
    $accion      = $_POST['accion'];
    $motivo      = trim($_POST['motivo'] ?? '');
    $usuarioAct  = $auth->getUsuario();

    if ($accion === 'asignar' && $auth->esAdmin() && $solicitudId > 0) {
        try {
            $abogadoId = (int)($_POST['abogado_id'] ?? 0);
            $db->update('solicitudes', ['abogado_id' => $abogadoId ?: null], 'id = ?', [$solicitudId]);
            AuditLog::registrar('asignar_solicitud', 'solicitudes', $solicitudId, "Asignada al abogado #$abogadoId");
            setFlash('exito', 'Solicitud asignada correctamente al abogado');
        } catch (Exception $e) {
            setFlash('error', 'Error al asignar: ' . $e->getMessage());
        }
        header('Location: ' . APP_URL . '/index.php?page=solicitudes/ver&id=' . $solicitudId);
        exit;
    }

    if ($accion === 'eliminar' && $solicitudId > 0) {
        $db->beginTransaction();
        try {
            // Eliminar archivos físicos primero
            $archivosPortal = $db->fetchAll("SELECT * FROM solicitud_archivos WHERE solicitud_id = ?", [$solicitudId]);
            foreach ($archivosPortal as $arch) {
                // Borrar archivo físico: ruta relativa en public/
                $rutaCompleta = CRM_ROOT . '/public/' . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $arch['ruta']), DIRECTORY_SEPARATOR);
                if (file_exists($rutaCompleta) && is_file($rutaCompleta)) {
                    @unlink($rutaCompleta);
                }
            }
            
            // Eliminar registros de la base de datos (ON DELETE CASCADE debería borrar en solicitud_archivos, pero aseguramos)
            $db->query("DELETE FROM solicitud_archivos WHERE solicitud_id = ?", [$solicitudId]);
            $db->query("DELETE FROM solicitudes WHERE id = ?", [$solicitudId]);
            
            AuditLog::registrar('eliminar_solicitud', 'solicitudes', $solicitudId, "Solicitud eliminada permanentemente.");
            $db->commit();
            setFlash('exito', 'Solicitud y sus archivos eliminados permanentemente.');
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('error', 'Error al eliminar: ' . $e->getMessage());
        }
        header('Location: ' . APP_URL . '/index.php?page=solicitudes');
        exit;
    }

    $estadosValidos = ['aceptada', 'denegada', 'archivada', 'cancelada'];

    if ($solicitudId > 0 && in_array($accion, $estadosValidos)) {
        $db->beginTransaction();
        try {
            $solicitud = $db->fetchOne("SELECT * FROM solicitudes WHERE id = ?", [$solicitudId]);

            $db->update('solicitudes', [
                'estado'        => $accion,
                'motivo_estado' => $motivo,
                'procesada_por' => $usuarioAct['id'] ?? null
            ], 'id = ?', [$solicitudId]);

            if ($accion === 'aceptada') {
                $clienteExistente = $db->fetchOne("SELECT id FROM clientes WHERE email = ?", [$solicitud['email']]);

                if ($clienteExistente) {
                    $clienteId = $clienteExistente['id'];
                    if (!empty($solicitud['telefono'])) {
                        $db->update('clientes', ['telefono' => $solicitud['telefono']], 'id = ? AND (telefono IS NULL OR telefono = "")', [$clienteId]);
                    }
                    $logMsg = "Solicitud aceptada. Caso añadido a cliente existente #$clienteId.";
                } else {
                    $clienteId = $db->insert('clientes', [
                        'solicitud_id' => $solicitudId,
                        'nombre'       => $solicitud['nombre'],
                        'apellidos'    => $solicitud['apellidos'],
                        'email'        => $solicitud['email'],
                        'telefono'     => $solicitud['telefono']
                    ]);
                    $logMsg = "Solicitud aceptada. Cliente #$clienteId creado.";
                }

                // Vincular cuenta del portal con el cliente CRM
                if (!empty($solicitud['portal_cuenta_id'])) {
                    $db->update('portal_cuentas', ['cliente_id' => $clienteId, 'es_cliente' => 1], 'id = ?', [$solicitud['portal_cuenta_id']]);
                }

                // Re-leer abogado_id actualizado (puede haber sido asignado antes de aceptar)
                $abogadoActual = $db->fetchColumn("SELECT abogado_id FROM solicitudes WHERE id = ?", [$solicitudId]);

                $referencia = 'CASO-' . date('Y') . '-' . str_pad($solicitudId, 5, '0', STR_PAD_LEFT);
                $casoId = $db->insert('casos', [
                    'cliente_id'     => $clienteId,
                    'abogado_id'     => $abogadoActual ?: null,
                    'titulo'         => $solicitud['tipo_problema'] . ' - ' . $solicitud['nombre'] . ' ' . $solicitud['apellidos'],
                    'tipo_caso'      => $solicitud['tipo_problema'],
                    'descripcion'    => $solicitud['descripcion'],
                    'referencia'     => $referencia,
                    'estado'         => 'en_estudio',
                    'fecha_apertura' => date('Y-m-d')
                ]);

                // Copiar archivos del portal (solicitud_archivos) → documentos del caso
                // Copiar archivos del portal → documentos del caso (si la tabla existe)
                $tieneTablaArchivos = $db->fetchColumn("SHOW TABLES LIKE 'solicitud_archivos'");
                if ($tieneTablaArchivos) {
                    $archivosPortal = $db->fetchAll("SELECT * FROM solicitud_archivos WHERE solicitud_id = ?", [$solicitudId]);
                    foreach ($archivosPortal as $arch) {
                        $db->insert('documentos', [
                            'caso_id'        => $casoId,
                            'nombre_archivo' => $arch['nombre_archivo'],
                            'nombre_original'=> $arch['nombre_original'],
                            'ruta'           => '../portal/' . $arch['ruta'],
                            'tipo_mime'      => $arch['tipo_mime'],
                            'tamano_bytes'   => $arch['tamano_bytes'],
                            'descripcion'    => 'Documento aportado por el cliente',
                            'subido_por'     => null,
                        ]);
                    }
                }

                AuditLog::registrar('aceptar_solicitud', 'solicitudes', $solicitudId, $logMsg);

                // Notificar al cliente
                $notifSol = $db->fetchColumn("SELECT valor FROM configuracion WHERE clave = 'email_notif_solicitud'") ?? '1';
                if ($notifSol === '1' && filter_var($solicitud['email'], FILTER_VALIDATE_EMAIL)) {
                    Mailer::solicitudAceptada(
                        $solicitud['email'],
                        $solicitud['nombre'] . ' ' . $solicitud['apellidos'],
                        $referencia,
                        $solicitud['tipo_problema'],
                        APP_URL . '/../portal/index.php?page=dashboard'
                    );
                }

            } else {
                AuditLog::registrar('cambiar_estado_solicitud', 'solicitudes', $solicitudId,
                    "Estado cambiado a: $accion" . ($motivo ? " - Motivo: $motivo" : ''));
            }

            $db->commit();
            setFlash('exito', 'Solicitud procesada correctamente');
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('error', 'Error al procesar: ' . $e->getMessage());
        }

        header('Location: ' . APP_URL . '/index.php?page=solicitudes');
        exit;
    }
}

include CRM_ROOT . '/templates/layout/header.php';

// Filtro de estado
$filtroEstado = $_GET['estado'] ?? '';
$whereEstado = '';
$params = [];
if ($filtroEstado && in_array($filtroEstado, ['pendiente', 'aceptada', 'denegada', 'archivada', 'cancelada'])) {
    $whereEstado = 'WHERE s.estado = ?';
    $params[] = $filtroEstado;
}

if ($auth->esAbogado()) {
    $whereEstado .= ($whereEstado ? ' AND ' : 'WHERE ') . 's.abogado_id = ?';
    $params[] = $usuario['id'];
}

$solicitudes = $db->fetchAll(
    "SELECT s.*, u.nombre as procesada_nombre, u.apellidos as procesada_apellidos
     FROM solicitudes s
     LEFT JOIN usuarios_internos u ON s.procesada_por = u.id
     $whereEstado
     ORDER BY s.created_at DESC",
    $params
);
?>

<!-- Breadcrumb -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <h6 class="fw-semibold mb-0">Solicitudes</h6>
    <div class="d-flex align-items-center gap-16">
        <ul class="d-flex align-items-center gap-2">
            <li class="fw-medium"><a href="<?php echo APP_URL; ?>/index.php?page=dashboard" class="hover-text-primary">Dashboard</a></li>
            <li>-</li>
            <li class="fw-medium">Solicitudes</li>
        </ul>
        <?php if ($auth->esAdmin()): ?>
        <a href="<?php echo APP_URL; ?>/index.php?page=solicitudes/crear" class="btn btn-primary d-flex align-items-center gap-2 radius-8">
            <iconify-icon icon="solar:add-circle-outline"></iconify-icon> Añadir Solicitud
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Filtros -->
<div class="card radius-8 border mb-24">
    <div class="card-body p-20">
        <div class="d-flex flex-wrap align-items-center gap-3">
            <span class="fw-medium text-secondary-light">Filtrar por:</span>
            <a href="<?php echo APP_URL; ?>/index.php?page=solicitudes" class="btn btn-sm <?php echo !$filtroEstado ? 'btn-primary' : 'btn-outline-primary'; ?> radius-8">Todas</a>
            <a href="?page=solicitudes&estado=pendiente" class="btn btn-sm <?php echo $filtroEstado === 'pendiente' ? 'btn-warning' : 'btn-outline-warning'; ?> radius-8">Pendientes</a>
            <a href="?page=solicitudes&estado=aceptada" class="btn btn-sm <?php echo $filtroEstado === 'aceptada' ? 'btn-success' : 'btn-outline-success'; ?> radius-8">Aceptadas</a>
            <a href="?page=solicitudes&estado=denegada" class="btn btn-sm <?php echo $filtroEstado === 'denegada' ? 'btn-danger' : 'btn-outline-danger'; ?> radius-8">Denegadas</a>
            <a href="?page=solicitudes&estado=archivada" class="btn btn-sm <?php echo $filtroEstado === 'archivada' ? 'btn-secondary' : 'btn-outline-secondary'; ?> radius-8">Archivadas</a>
        </div>
    </div>
</div>

<!-- Tabla de solicitudes -->
<div class="card radius-8 border">
    <div class="card-body p-24">
        <div class="table-responsive scroll-sm">
            <table class="table bordered-table sm-table mb-0" id="tablaSolicitudes">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Solicitante</th>
                        <th>Email</th>
                        <th>Tipo de Problema</th>
                        <th>Estado</th>
                        <th>Fecha</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($solicitudes as $sol): ?>
                    <tr>
                        <td><strong>#<?php echo $sol['id']; ?></strong></td>
                        <td>
                            <a href="<?php echo APP_URL; ?>/index.php?page=solicitudes/ver&id=<?php echo $sol['id']; ?>" class="text-primary-600 fw-medium">
                                <?php echo e($sol['nombre'] . ' ' . $sol['apellidos']); ?>
                            </a>
                        </td>
                        <td class="text-sm"><?php echo e($sol['email']); ?></td>
                        <td><?php echo e($sol['tipo_problema']); ?></td>
                        <td>
                            <?php
                            $badgeClass = match($sol['estado']) {
                                'pendiente' => 'bg-warning-focus text-warning-main',
                                'aceptada'  => 'bg-success-focus text-success-main',
                                'denegada'  => 'bg-danger-focus text-danger-main',
                                'archivada' => 'bg-neutral-200 text-neutral-600',
                                'cancelada' => 'bg-danger-focus text-danger-main',
                                default     => 'bg-neutral-200'
                            };
                            ?>
                            <span class="badge <?php echo $badgeClass; ?> radius-4 px-8 py-4">
                                <?php echo e(ucfirst($sol['estado'])); ?>
                            </span>
                        </td>
                        <td class="text-sm"><?php echo date('d/m/Y H:i', strtotime($sol['created_at'])); ?></td>
                        <td class="text-center">
                            <div class="d-flex align-items-center gap-10 justify-content-center">
                                <a href="<?php echo APP_URL; ?>/index.php?page=solicitudes/ver&id=<?php echo $sol['id']; ?>"
                                    class="bg-info-focus text-info-main w-32-px h-32-px d-flex justify-content-center align-items-center rounded-circle"
                                    title="Ver detalle">
                                    <iconify-icon icon="iconamoon:eye-light" class="icon"></iconify-icon>
                                </a>
                                <?php if ($sol['estado'] === 'pendiente'): ?>
                                <form method="POST" style="display:inline">
                                    <?php echo CSRF::campo(); ?>
                                    <input type="hidden" name="solicitud_id" value="<?php echo $sol['id']; ?>">
                                    <input type="hidden" name="accion" value="aceptada">
                                    <button type="submit" class="bg-success-focus text-success-main w-32-px h-32-px d-flex justify-content-center align-items-center rounded-circle border-0"
                                        title="Aceptar" data-confirm="¿Aceptar esta solicitud? Se creará un cliente y un caso automáticamente.">
                                        <iconify-icon icon="ep:select" class="icon"></iconify-icon>
                                    </button>
                                </form>
                                <form method="POST" style="display:inline">
                                    <?php echo CSRF::campo(); ?>
                                    <input type="hidden" name="solicitud_id" value="<?php echo $sol['id']; ?>">
                                    <input type="hidden" name="accion" value="denegada">
                                    <button type="submit" class="bg-danger-focus text-danger-main w-32-px h-32-px d-flex justify-content-center align-items-center rounded-circle border-0"
                                        title="Denegar" data-confirm="¿Denegar esta solicitud?">
                                        <iconify-icon icon="fluent:dismiss-20-regular" class="icon"></iconify-icon>
                                    </button>
                                </form>
                                <?php endif; ?>
                                <?php if ($auth->esAdmin()): ?>
                                <a href="<?php echo APP_URL; ?>/index.php?page=solicitudes/editar&id=<?php echo $sol['id']; ?>"
                                    class="bg-warning-focus text-warning-main w-32-px h-32-px d-flex justify-content-center align-items-center rounded-circle"
                                    title="Editar">
                                    <iconify-icon icon="lucide:edit" class="icon"></iconify-icon>
                                </a>
                                <?php endif; ?>
                                <?php if ($auth->esAdmin() || $sol['estado'] === 'pendiente'): ?>
                                <form method="POST" style="display:inline">
                                    <?php echo CSRF::campo(); ?>
                                    <input type="hidden" name="solicitud_id" value="<?php echo $sol['id']; ?>">
                                    <input type="hidden" name="accion" value="eliminar">
                                    <button type="submit" class="bg-danger-focus text-danger-main w-32-px h-32-px d-flex justify-content-center align-items-center rounded-circle border-0"
                                        title="Eliminar permanentemente" data-confirm="¿Estás seguro de eliminar esta solicitud de forma permanente? Se borrarán sus archivos.">
                                        <iconify-icon icon="mingcute:delete-2-line" class="icon"></iconify-icon>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
include CRM_ROOT . '/templates/layout/footer.php';


