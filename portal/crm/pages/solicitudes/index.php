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

    if ($accion === 'asignar_multi' && $auth->esAdmin() && $solicitudId > 0) {
        try {
            $abogados = $_POST['abogados'] ?? [];
            if(empty($abogados)) throw new Exception("Debe seleccionar al menos un abogado.");
            $valor_cliente = trim($_POST['valor_cliente'] ?? '0');
            $honorarios_abogado = trim($_POST['honorarios_abogado'] ?? '0');
            $bonificacion = trim($_POST['bonificacion'] ?? '0');
            $db->beginTransaction();
            $db->update('solicitudes', [
                'valor_cliente' => $valor_cliente,
                'honorarios_abogado' => $honorarios_abogado,
                'bonificacion' => $bonificacion,
                'estado' => 'asignada'
            ], 'id = ?', [$solicitudId]);
            foreach ($abogados as $ab_id) {
                $db->insert('solicitud_asignaciones', [
                    'solicitud_id' => $solicitudId,
                    'abogado_id' => (int)$ab_id,
                    'estado' => 'pendiente'
                ]);
            }
            $db->commit();
            AuditLog::registrar('asignar_solicitud', 'solicitudes', $solicitudId, "Propuesta enviada a ".count($abogados)." abogados (Valor: $valor_cliente €, Honorarios: $honorarios_abogado €)");
            setFlash('exito', 'Propuesta enviada a los abogados seleccionados.');
        } catch (Exception $e) {
            if($db->getPdo()->inTransaction()) $db->rollBack();
            setFlash('error', 'Error al asignar: ' . $e->getMessage());
        }
        header('Location: ' . APP_URL . '/index.php?page=solicitudes/ver&id=' . $solicitudId);
        exit;
    }

    if ($accion === 'cancelar_asignacion' && $auth->esAdmin() && $solicitudId > 0) {
        try {
            $db->beginTransaction();
            $db->update('solicitudes', ['valor_cliente' => null, 'honorarios_abogado' => null, 'estado' => 'pendiente'], 'id = ?', [$solicitudId]);
            $db->query("DELETE FROM solicitud_asignaciones WHERE solicitud_id = ?", [$solicitudId]);
            $db->commit();
            AuditLog::registrar('cancelar_asignacion', 'solicitudes', $solicitudId, "Asignaciones canceladas.");
            setFlash('exito', 'Asignación cancelada. La solicitud vuelve a estar pendiente.');
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('error', 'Error: ' . $e->getMessage());
        }
        header('Location: ' . APP_URL . '/index.php?page=solicitudes/ver&id=' . $solicitudId);
        exit;
    }

    if ($accion === 'abogado_aceptar' && $auth->esAbogado() && $solicitudId > 0) {
        $abogadoId = $usuarioAct['id'];
        $estadoActual = $db->fetchColumn("SELECT estado FROM solicitudes WHERE id = ?", [$solicitudId]);
        if ($estadoActual !== 'aceptada') {
            $db->beginTransaction();
            $db->update('solicitud_asignaciones', ['estado' => 'aceptada'], 'solicitud_id = ? AND abogado_id = ?', [$solicitudId, $abogadoId]);
            $db->query("DELETE FROM solicitud_asignaciones WHERE solicitud_id = ? AND abogado_id != ?", [$solicitudId, $abogadoId]);
            $db->update('solicitudes', ['abogado_id' => $abogadoId], 'id = ?', [$solicitudId]);
            $db->commit();
            $accion = 'aceptada';
        } else {
            setFlash('error', 'Otro abogado ya ha tomado este caso. Ha sido retirado de tu lista.');
            $db->query("DELETE FROM solicitud_asignaciones WHERE solicitud_id = ? AND abogado_id = ?", [$solicitudId, $abogadoId]);
            header('Location: ' . APP_URL . '/index.php?page=solicitudes');
            exit;
        }
    }

    if ($accion === 'abogado_rechazar' && $auth->esAbogado() && $solicitudId > 0) {
        $abogadoId = $usuarioAct['id'];
        try {
            $db->beginTransaction();
            $db->update('solicitud_asignaciones', ['estado' => 'rechazada'], 'solicitud_id = ? AND abogado_id = ?', [$solicitudId, $abogadoId]);
            
            $pendientes = $db->fetchColumn("SELECT COUNT(*) FROM solicitud_asignaciones WHERE solicitud_id = ? AND estado = 'pendiente'", [$solicitudId]);
            if ($pendientes == 0) {
                $db->update('solicitudes', ['estado' => 'rechazada por todos'], 'id = ?', [$solicitudId]);
                AuditLog::registrar('solicitud_rechazada', 'solicitudes', $solicitudId, "Todos los abogados rechazaron el caso.");
            }
            $db->commit();
            setFlash('exito', 'Has rechazado el caso.');
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('error', 'Error: ' . $e->getMessage());
        }
        header('Location: ' . APP_URL . '/index.php?page=solicitudes');
        exit;
    }

    if ($accion === 'eliminar' && $solicitudId > 0) {
        // Ejecutar ALTER TABLE fuera de la transacción porque los DDL causan implicit commit en MySQL
        try {
            $db->query("ALTER TABLE clientes MODIFY solicitud_id INT DEFAULT NULL");
        } catch (Exception $e) {}

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
            
            // Desvincular de clientes para evitar error de foreign key (constraint 1451)
            $db->query("UPDATE clientes SET solicitud_id = NULL WHERE solicitud_id = ?", [$solicitudId]);
            
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

    $estadosValidos = ['aceptada', 'denegada', 'archivada', 'cancelada', 'pendiente'];

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

                // Vincular o CREAR cuenta del portal
                if (!empty($solicitud['portal_cuenta_id'])) {
                    $db->update('portal_cuentas', ['cliente_id' => $clienteId, 'es_cliente' => 1], 'id = ?', [$solicitud['portal_cuenta_id']]);
                } else {
                    $autoPassword = substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$'), 0, 10);
                    $portalId = $db->insert('portal_cuentas', [
                        'cliente_id'    => $clienteId,
                        'es_cliente'    => 1,
                        'nombre'        => $solicitud['nombre'],
                        'apellidos'     => $solicitud['apellidos'],
                        'email'         => $solicitud['email'],
                        'telefono'      => $solicitud['telefono'] ?: null,
                        'dni_nif'       => $solicitud['dni_nif'] ?: 'No provisto',
                        'direccion'     => '',
                        'password_hash' => password_hash($autoPassword, PASSWORD_DEFAULT),
                        'password_plain'=> $autoPassword,
                        'ip_registro'   => $solicitud['ip_solicitante'] ?? ''
                    ]);
                    $db->update('solicitudes', ['portal_cuenta_id' => $portalId], 'id = ?', [$solicitudId]);
                }

                // Re-leer abogado_id actualizado (puede haber sido asignado antes de aceptar)
                $abogadoActual = $db->fetchColumn("SELECT abogado_id FROM solicitudes WHERE id = ?", [$solicitudId]);

                $referencia = 'CASO-' . date('Y') . '-' . str_pad($solicitudId, 5, '0', STR_PAD_LEFT);
                
                // Verificar si ya existe un caso asociado a esta solicitud (por ejemplo, si fue aceptada, luego denegada, y ahora re-aceptada)
                $casoExistente = $db->fetchOne("SELECT id FROM casos WHERE referencia = ?", [$referencia]);
                
                if ($casoExistente) {
                    $casoId = $casoExistente['id'];
                    $db->update('casos', [
                        'estado' => 'en_estudio',
                        'cliente_id' => $clienteId,
                        'abogado_id' => $abogadoActual ?: null
                    ], 'id = ?', [$casoId]);
                    $logMsg .= " (Caso reactivado)";
                } else {
                    $honorariosAbogadoCaso = $solicitud['honorarios_abogado'] ?? 0;
                    if ($abogadoActual) {
                        $tipoPago = $db->fetchColumn("SELECT tipo_pago_predeterminado FROM usuarios_internos WHERE id = ?", [$abogadoActual]);
                        if ($tipoPago === 'mensual') {
                            $honorariosAbogadoCaso = 0;
                        }
                    }

                    $casoId = $db->insert('casos', [
                        'cliente_id'         => $clienteId,
                        'abogado_id'         => $abogadoActual ?: null,
                        'titulo'             => $solicitud['tipo_problema'] . ' - ' . $solicitud['nombre'] . ' ' . $solicitud['apellidos'],
                        'tipo_caso'          => $solicitud['tipo_problema'],
                        'descripcion'        => $solicitud['descripcion'],
                        'referencia'         => $referencia,
                        'estado'             => 'en_estudio',
                        'fecha_apertura'     => date('Y-m-d'),
                        'honorarios_totales' => $solicitud['valor_cliente'] ?? 0,
                        'honorarios_abogado' => $honorariosAbogadoCaso,
                        'bonificacion'       => $solicitud['bonificacion'] ?? 0
                    ]);
    
                    // Copiar archivos del portal (solicitud_archivos) → documentos del caso
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
} else {
    $whereEstado = "WHERE s.estado != 'aceptada'";
}

// El sistema de permisos ya controla quién puede acceder a esta página.
// Si un abogado tiene acceso, ve todas las solicitudes (como admin/gestor).
// Si se quiere restringir a solo sus solicitudes asignadas, configurar en Permisos.

// Filtro por rol
$joinAsignaciones = "";
if ($auth->esAbogado()) {
    $abogadoId = $auth->getUsuario()['id'];
    $joinAsignaciones = "JOIN solicitud_asignaciones sa ON sa.solicitud_id = s.id AND sa.abogado_id = " . (int)$abogadoId . " AND sa.estado = 'pendiente'";
}

$solicitudes = $db->fetchAll(
    "SELECT s.*, u.nombre as procesada_nombre, u.apellidos as procesada_apellidos,
     (SELECT GROUP_CONCAT(CONCAT(ui.nombre, ' ', ui.apellidos) SEPARATOR ', ') FROM solicitud_asignaciones as2 JOIN usuarios_internos ui ON as2.abogado_id = ui.id WHERE as2.solicitud_id = s.id) as abogados_asignados
     FROM solicitudes s
     LEFT JOIN usuarios_internos u ON s.procesada_por = u.id
     $joinAsignaciones
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
                        <th>Descripción</th>
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
                        <td><?php echo e(strlen($sol['descripcion'] ?? '') > 40 ? substr($sol['descripcion'], 0, 40) . '...' : ($sol['descripcion'] ?? '')); ?></td>
                        <td>
                            <?php
                            $badgeClass = match($sol['estado']) {
                                'pendiente' => 'bg-warning-focus text-warning-main',
                                'asignada'  => 'bg-info-focus text-info-main',
                                'aceptada'  => 'bg-success-focus text-success-main',
                                'denegada'  => 'bg-danger-focus text-danger-main',
                                'archivada' => 'bg-neutral-200 text-neutral-600',
                                'cancelada' => 'bg-danger-focus text-danger-main',
                                'no aceptada' => 'bg-danger-focus text-danger-main',
                                'rechazada por todos' => 'bg-danger-focus text-danger-main',
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


