<?php
/**
 * CRM Abogados - Listado de Clientes
 */
$tituloPagina = 'Clientes';
$db = Database::getInstance();

// Asegurar que solicitud_id acepte NULL (migración automática)
try {
    $db->query("ALTER TABLE clientes MODIFY COLUMN solicitud_id INT(11) DEFAULT NULL");
} catch (\Throwable $e) { /* ya está bien o no existe */ }

// Asegurar que portal_cuentas tenga dni_nif y direccion
try {
    $db->query("ALTER TABLE portal_cuentas ADD COLUMN dni_nif VARCHAR(50) DEFAULT NULL, ADD COLUMN direccion TEXT DEFAULT NULL");
} catch (\Throwable $e) { /* ya existen */ }

// ── Crear cliente manualmente ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_cliente'])) {
    CSRF::verificarOAbortar();
    $nombre    = trim($_POST['nombre'] ?? '');
    $apellidos = trim($_POST['apellidos'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $password  = trim($_POST['password'] ?? '');

    if ($nombre && $apellidos && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        try {
            $existe = $db->fetchColumn("SELECT id FROM clientes WHERE email = ?", [$email]);
            if ($existe) {
                setFlash('error', 'Ya existe un cliente con ese correo electrónico en el CRM.');
            } else {
                // 1. Crear en CRM (clientes)
                $newId = $db->insert('clientes', [
                    'nombre'       => $nombre,
                    'apellidos'    => $apellidos,
                    'email'        => $email,
                    'telefono'     => trim($_POST['telefono'] ?? '') ?: null,
                    'dni_nif'      => trim($_POST['dni_nif'] ?? '') ?: null,
                    'direccion'    => trim($_POST['direccion'] ?? '') ?: null,
                    'notas'        => trim($_POST['notas'] ?? '') ?: null,
                    'solicitud_id' => null,
                ]);

                AuditLog::registrar('crear', 'clientes', $newId, 'Cliente creado manualmente desde CRM');

                // 2. Crear cuenta en Portal — SIEMPRE (con contraseña dada o aleatoria)
                $portalCreado = false;
                try {
                    $existePortal = $db->fetchColumn("SELECT id FROM portal_cuentas WHERE email = ?", [$email]);
                    if (!$existePortal) {
                        // Usar contraseña dada o generar una temporal segura
                        $passUsada  = $password ?: bin2hex(random_bytes(8)) . 'A1!';
                        $hash       = password_hash($passUsada, PASSWORD_DEFAULT);

                        $db->insert('portal_cuentas', [
                            'nombre'        => $nombre,
                            'apellidos'     => $apellidos,
                            'email'         => $email,
                            'password_hash' => $hash,
                            'telefono'      => trim($_POST['telefono'] ?? '') ?: null,
                            'dni_nif'       => trim($_POST['dni_nif'] ?? '') ?: null,
                            'direccion'     => trim($_POST['direccion'] ?? '') ?: null,
                            'es_cliente'    => 1,
                            'cliente_id'    => $newId,
                            'activo'        => 1
                        ]);
                        $portalCreado = true;
                    } else {
                        // Vincular cuenta existente al cliente si aún no está vinculada
                        $db->update('portal_cuentas', [
                            'es_cliente' => 1,
                            'cliente_id' => $newId,
                        ], 'email = ? AND (cliente_id IS NULL OR cliente_id = 0)', [$email]);
                        $portalCreado = true;
                    }
                } catch (\Throwable $ep) {
                    error_log('Error portal para cliente #' . $newId . ': ' . $ep->getMessage());
                }

                if ($portalCreado) {
                    $msg = $password
                        ? 'Cliente y cuenta del portal creados. El cliente puede iniciar sesión con la contraseña indicada.'
                        : 'Cliente y cuenta del portal creados. Use "Enviar Link de Contraseña" desde la ficha para que el cliente establezca su acceso.';
                    setFlash('exito', $msg);
                } else {
                    setFlash('exito', 'Cliente creado. (Cuenta del portal no se pudo sincronizar — verifique la BD)');
                }
            }
        } catch (\Throwable $e) {
            // Mostrar el error real de BD para diagnóstico
            setFlash('error', 'Error al crear el cliente: ' . $e->getMessage());
        }
    } else {
        setFlash('error', 'Nombre, apellidos y correo electrónico válido son obligatorios.');
    }
    header('Location: ' . APP_URL . '/index.php?page=clientes'); exit;
}

// ── Edición rápida inline ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_cliente_quick'])) {
    CSRF::verificarOAbortar();
    $eid = (int)$_POST['cliente_id'];
    if ($eid) {
        $db->update('clientes', [
            'nombre'    => trim($_POST['nombre']),
            'apellidos' => trim($_POST['apellidos']),
            'email'     => trim($_POST['email']),
            'telefono'  => trim($_POST['telefono']) ?: null,
            'dni_nif'   => trim($_POST['dni_nif']) ?: null,
            'direccion' => trim($_POST['direccion']) ?: null,
        ], 'id = ?', [$eid]);
        AuditLog::registrar('editar', 'clientes', $eid, 'Cliente editado desde listado (quick edit)');
        setFlash('exito', 'Cliente actualizado correctamente.');
    }
    header('Location: ' . APP_URL . '/index.php?page=clientes'); exit;
}

// ── Eliminar cliente ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_cliente'])) {
    CSRF::verificarOAbortar();
    RoleGuard::requireRole('admin');
    $did = (int)$_POST['cliente_id'];
    if ($did) {
        try {
            // Desactivar FK checks temporalmente para poder borrar en cascada
            $db->query("SET FOREIGN_KEY_CHECKS = 0");

            // Obtener los casos del cliente
            $casosIds = $db->fetchAll("SELECT id FROM casos WHERE cliente_id = ?", [$did]);
            foreach ($casosIds as $caso) {
                $cid = $caso['id'];
                // Borrar documentos del caso
                $db->query("DELETE FROM documentos WHERE caso_id = ?", [$cid]);
                // Borrar pagos programados del caso
                $db->query("DELETE FROM pagos_programados WHERE caso_id = ?", [$cid]);
                // Borrar pagos del caso
                $db->query("DELETE FROM pagos WHERE caso_id = ?", [$cid]);
            }
            // Borrar casos del cliente
            $db->query("DELETE FROM casos WHERE cliente_id = ?", [$did]);

            // Borrar cuenta del portal vinculada
            $db->query("DELETE FROM portal_cuentas WHERE cliente_id = ?", [$did]);

            // Borrar el cliente
            $db->query("DELETE FROM clientes WHERE id = ?", [$did]);

            // Reactivar FK checks
            $db->query("SET FOREIGN_KEY_CHECKS = 1");

            AuditLog::registrar('eliminar', 'clientes', $did, 'Cliente eliminado con todos sus casos, pagos y documentos');
            setFlash('exito', 'Cliente eliminado correctamente.');
        } catch (\Throwable $e) {
            // Asegurarse de reactivar FK checks aunque falle
            try { $db->query("SET FOREIGN_KEY_CHECKS = 1"); } catch (\Throwable $e2) {}
            setFlash('error', 'Error al eliminar el cliente: ' . $e->getMessage());
        }
    }
    header('Location: ' . APP_URL . '/index.php?page=clientes'); exit;
}


include CRM_ROOT . '/templates/layout/header.php';

if ($auth->esAbogado()) {
    $clientes = $db->fetchAll(
        "SELECT cl.*,
            (SELECT COUNT(*) FROM casos c WHERE c.cliente_id = cl.id AND c.abogado_id = ?) as total_casos,
            (SELECT COUNT(*) FROM casos c WHERE c.cliente_id = cl.id AND c.estado NOT IN ('cerrado','archivado') AND c.abogado_id = ?) as casos_activos
         FROM clientes cl
         WHERE EXISTS (SELECT 1 FROM casos c WHERE c.cliente_id = cl.id AND c.abogado_id = ?)
         ORDER BY cl.created_at DESC",
        [$usuario['id'], $usuario['id'], $usuario['id']]
    );
} else {
    $clientes = $db->fetchAll(
        "SELECT cl.*,
            (SELECT COUNT(*) FROM casos c WHERE c.cliente_id = cl.id) as total_casos,
            (SELECT COUNT(*) FROM casos c WHERE c.cliente_id = cl.id AND c.estado NOT IN ('cerrado','archivado')) as casos_activos
         FROM clientes cl ORDER BY cl.created_at DESC"
    );
}
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <h6 class="fw-semibold mb-0">Clientes</h6>
    <div class="d-flex align-items-center gap-2">
        <ul class="d-flex align-items-center gap-2 mb-0">
            <li><a href="<?php echo APP_URL; ?>/index.php?page=dashboard" class="hover-text-primary">Dashboard</a></li>
            <li>-</li><li>Clientes</li>
        </ul>
        <button class="btn btn-sm btn-primary radius-8 d-flex align-items-center gap-1 ms-3"
            data-bs-toggle="modal" data-bs-target="#crearClienteModal">
            <iconify-icon icon="ic:round-plus"></iconify-icon> Nuevo Cliente
        </button>
    </div>
</div>

<div class="card radius-8 border">
    <div class="card-body p-24">
        <div class="table-responsive scroll-sm">
            <table class="table bordered-table sm-table mb-0" id="tablaClientes">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Nombre</th>
                        <th>Email</th>
                        <th>Teléfono</th>
                        <th>Casos Activos</th>
                        <th>Total Casos</th>
                        <th>Fecha Alta</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clientes as $cl): ?>
                    <tr>
                        <td><strong>#<?php echo $cl['id']; ?></strong></td>
                        <td>
                            <a href="<?php echo APP_URL; ?>/index.php?page=clientes/ver&id=<?php echo $cl['id']; ?>" class="text-primary-600 fw-medium">
                                <?php echo e($cl['nombre'] . ' ' . $cl['apellidos']); ?>
                            </a>
                        </td>
                        <td class="text-sm"><?php echo e($cl['email']); ?></td>
                        <td class="text-sm"><?php echo e($cl['telefono'] ?: '-'); ?></td>
                        <td>
                            <span class="badge bg-success-focus text-success-main radius-4 px-8 py-4"><?php echo $cl['casos_activos']; ?></span>
                        </td>
                        <td><?php echo $cl['total_casos']; ?></td>
                        <td class="text-sm"><?php echo date('d/m/Y', strtotime($cl['created_at'])); ?></td>
                        <td class="text-center">
                            <div class="d-flex align-items-center justify-content-center gap-2">
                                <a href="<?php echo APP_URL; ?>/index.php?page=clientes/ver&id=<?php echo $cl['id']; ?>"
                                    class="bg-info-focus text-info-main w-32-px h-32-px d-flex justify-content-center align-items-center rounded-circle" title="Ver perfil">
                                    <iconify-icon icon="iconamoon:eye-light"></iconify-icon>
                                </a>
                                <button type="button"
                                    class="bg-warning-focus text-warning-main w-32-px h-32-px d-flex justify-content-center align-items-center rounded-circle border-0"
                                    title="Editar rápido"
                                    onclick="openQuickEdit(<?php echo $cl['id']; ?>, '<?php echo e($cl['nombre']); ?>', '<?php echo e($cl['apellidos']); ?>', '<?php echo e($cl['email']); ?>', '<?php echo e($cl['telefono'] ?? ''); ?>', '<?php echo e($cl['dni_nif'] ?? ''); ?>', '<?php echo e($cl['direccion'] ?? ''); ?>')">
                                    <iconify-icon icon="solar:pen-new-square-linear"></iconify-icon>
                                </button>
                                <button type="button"
                                    class="bg-danger-focus text-danger-main w-32-px h-32-px d-flex justify-content-center align-items-center rounded-circle border-0"
                                    title="Eliminar cliente"
                                    onclick="confirmDelete(<?php echo $cl['id']; ?>, '<?php echo e($cl['nombre'] . ' ' . $cl['apellidos']); ?>')">
                                    <iconify-icon icon="solar:trash-bin-trash-bold"></iconify-icon>
                                </button>
                            </div>
                        </td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>



<!-- Modal: Crear Cliente -->
<div class="modal fade" id="crearClienteModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content radius-8">
            <?php echo CSRF::campo(); ?>
            <input type="hidden" name="crear_cliente" value="1">
            <div class="modal-header">
                <h6 class="modal-title d-flex align-items-center gap-2">
                    <iconify-icon icon="solar:user-plus-rounded-linear"></iconify-icon> Nuevo Cliente
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row gy-3">
                    <div class="col-sm-6"><label class="form-label">Nombre <span class="text-danger">*</span></label><input type="text" name="nombre" class="form-control radius-8" required placeholder="Nombre"></div>
                    <div class="col-sm-6"><label class="form-label">Apellidos <span class="text-danger">*</span></label><input type="text" name="apellidos" class="form-control radius-8" required placeholder="Apellidos"></div>
                    <div class="col-sm-6"><label class="form-label">Email <span class="text-danger">*</span></label><input type="email" name="email" class="form-control radius-8" required placeholder="correo@ejemplo.com"></div>
                    <div class="col-sm-6">
                        <label class="form-label d-flex justify-content-between">Contraseña <span class="text-xs text-secondary-light fw-normal">(Opcional)</span></label>
                        <input type="password" name="password" class="form-control radius-8" placeholder="Para acceso al portal">
                    </div>
                    <div class="col-sm-6"><label class="form-label">Teléfono</label><input type="tel" name="telefono" class="form-control radius-8" placeholder="+34 600 000 000"></div>
                    <div class="col-sm-6"><label class="form-label">DNI / NIF</label><input type="text" name="dni_nif" class="form-control radius-8" placeholder="12345678A"></div>
                    <div class="col-12"><label class="form-label">Dirección</label><input type="text" name="direccion" class="form-control radius-8" placeholder="Calle Ejemplo, Ciudad"></div>
                    <div class="col-12"><label class="form-label">Notas internas</label><textarea name="notas" class="form-control radius-8" rows="2" placeholder="Información adicional..."></textarea></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary radius-8" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary radius-8 d-flex align-items-center gap-1">
                    <iconify-icon icon="solar:floppy-disk-linear"></iconify-icon> Crear Cliente
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edición Rápida -->
<div class="modal fade" id="quickEditClienteModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content radius-8">
            <?php echo CSRF::campo(); ?>
            <input type="hidden" name="editar_cliente_quick" value="1">
            <input type="hidden" name="cliente_id" id="qeClienteId">
            <div class="modal-header">
                <h6 class="modal-title d-flex align-items-center gap-2">
                    <iconify-icon icon="solar:pen-new-square-linear"></iconify-icon> Editar Cliente
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row gy-3">
                    <div class="col-sm-6"><label class="form-label">Nombre <span class="text-danger">*</span></label><input type="text" name="nombre" id="qeNombre" class="form-control radius-8" required></div>
                    <div class="col-sm-6"><label class="form-label">Apellidos <span class="text-danger">*</span></label><input type="text" name="apellidos" id="qeApellidos" class="form-control radius-8" required></div>
                    <div class="col-sm-6"><label class="form-label">Email <span class="text-danger">*</span></label><input type="email" name="email" id="qeEmail" class="form-control radius-8" required></div>
                    <div class="col-sm-6"><label class="form-label">Teléfono</label><input type="tel" name="telefono" id="qeTelefono" class="form-control radius-8" placeholder="+34 600 000 000"></div>
                    <div class="col-sm-6"><label class="form-label">DNI / NIF</label><input type="text" name="dni_nif" id="qeDniNif" class="form-control radius-8"></div>
                    <div class="col-sm-6"><label class="form-label">Dirección</label><input type="text" name="direccion" id="qeDireccion" class="form-control radius-8"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary radius-8" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary radius-8 d-flex align-items-center gap-1">
                    <iconify-icon icon="solar:floppy-disk-linear"></iconify-icon> Guardar Cambios
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openQuickEdit(id, nombre, apellidos, email, telefono, dni, direccion) {
    document.getElementById('qeClienteId').value  = id;
    document.getElementById('qeNombre').value     = nombre;
    document.getElementById('qeApellidos').value  = apellidos;
    document.getElementById('qeEmail').value      = email;
    document.getElementById('qeTelefono').value   = telefono;
    document.getElementById('qeDniNif').value     = dni;
    document.getElementById('qeDireccion').value  = direccion;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('quickEditClienteModal')).show();
}
function confirmDelete(id, nombre) {
    document.getElementById('deleteClienteId').value = id;
    document.getElementById('deleteClienteNombre').innerText = nombre;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('eliminarClienteModal')).show();
}
</script>

<!-- Modal: Eliminar Cliente -->
<div class="modal fade" id="eliminarClienteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 400px;">
        <form method="POST" class="modal-content radius-12 border-0 shadow-lg text-center p-24">
            <?php echo CSRF::campo(); ?>
            <input type="hidden" name="eliminar_cliente" value="1">
            <input type="hidden" name="cliente_id" id="deleteClienteId">
            <div class="w-64-px h-64-px rounded-circle bg-danger-50 text-danger-600 d-flex justify-content-center align-items-center mx-auto mb-16 mt-8">
                <iconify-icon icon="solar:trash-bin-trash-bold" class="text-3xl"></iconify-icon>
            </div>
            <h5 class="fw-bold text-neutral-800 mb-12">¿Eliminar cliente?</h5>
            <p class="text-secondary-light text-sm mb-24 px-12">
                ¿Estás seguro de que deseas eliminar a <strong id="deleteClienteNombre"></strong>? Esta acción no se puede deshacer y eliminará también su acceso al portal.
            </p>
            <div class="d-flex gap-12">
                <button type="button" class="btn btn-outline-secondary radius-8 fw-semibold flex-grow-1" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-danger radius-8 fw-semibold flex-grow-1 shadow-sm hover-shadow-lg transition-2">Sí, eliminar</button>
            </div>
        </form>
    </div>
</div>

<?php include CRM_ROOT . '/templates/layout/footer.php'; ?>
