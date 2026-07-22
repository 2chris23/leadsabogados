<?php
/**
 * CRM Abogados - Ficha de Cliente
 */
$db = Database::getInstance();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/index.php?page=clientes'); exit; }

$cliente = $db->fetchOne("SELECT * FROM clientes WHERE id = ?", [$id]);
if (!$cliente) { setFlash('error', 'Cliente no encontrado'); header('Location: ' . APP_URL . '/index.php?page=clientes'); exit; }

// Verificar acceso del abogado (debe tener al menos un caso con este cliente)
if ($auth->esAbogado()) {
    $tieneAcceso = $db->fetchOne(
        "SELECT 1 FROM casos WHERE cliente_id = ? AND abogado_id = ? LIMIT 1",
        [$id, $_SESSION['usuario_id'] ?? 0]
    );
    if (!$tieneAcceso) {
        setFlash('error', 'No tienes acceso a este cliente');
        header('Location: ' . APP_URL . '/index.php?page=clientes'); exit;
    }
}

// Procesar edición
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_cliente'])) {
    CSRF::verificarOAbortar();
    $db->update('clientes', [
        'nombre'    => trim($_POST['nombre']),
        'apellidos' => trim($_POST['apellidos']),
        'email'     => trim($_POST['email']),
        'telefono'  => trim($_POST['telefono']),
        'direccion' => trim($_POST['direccion']),
        'dni_nif'   => trim($_POST['dni_nif']),
        'notas'     => trim($_POST['notas'])
    ], 'id = ?', [$id]);
    AuditLog::registrar('editar', 'clientes', $id, 'Datos del cliente actualizados');
    setFlash('exito', 'Cliente actualizado correctamente');
    header('Location: ' . APP_URL . '/index.php?page=clientes/ver&id=' . $id);
    exit;
}

// Regenerar contraseña del portal (solo admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['regenerar_password'])) {
    RoleGuard::requireRole('admin');
    CSRF::verificarOAbortar();
    
    $newPass = substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$'), 0, 10);
    $hash = password_hash($newPass, PASSWORD_DEFAULT);
    
    $cuentaPortal = $db->fetchOne("SELECT id FROM portal_cuentas WHERE email = ?", [$cliente['email']]);
    if ($cuentaPortal) {
        $db->update('portal_cuentas', [
            'password_hash' => $hash,
            'password_plain' => $newPass
        ], 'id = ?', [$cuentaPortal['id']]);
        AuditLog::registrar('regenerar_password', 'clientes', $id, 'Contraseña del portal regenerada');
        setFlash('exito', 'Nueva contraseña generada: <strong>' . htmlspecialchars($newPass) . '</strong> — Cópiela y envíela al cliente.');
    } else {
        setFlash('error', 'El cliente no tiene cuenta en el portal.');
    }
    header('Location: ' . APP_URL . '/index.php?page=clientes/ver&id=' . $id);
    exit;
}

// Enviar link de recuperación de contraseña (solo admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enviar_recovery'])) {
    RoleGuard::requireRole('admin');
    CSRF::verificarOAbortar();
    
    $cuentaPortal = $db->fetchOne("SELECT id, nombre FROM portal_cuentas WHERE email = ?", [$cliente['email']]);
    
    // Si no existe cuenta en el portal, crearla automáticamente ahora
    if (!$cuentaPortal) {
        // Asegurar columnas en caso de que falten
        try {
            $db->query("ALTER TABLE portal_cuentas ADD COLUMN dni_nif VARCHAR(50) DEFAULT NULL, ADD COLUMN direccion TEXT DEFAULT NULL");
        } catch (\Throwable $e) {}

        // Generar una contraseña aleatoria temporal (se sobrescribirá al recuperar)
        $tempPass = bin2hex(random_bytes(8)) . 'A1!'; 
        $hash = password_hash($tempPass, PASSWORD_DEFAULT);
        
        $portalId = $db->insert('portal_cuentas', [
            'nombre'        => $cliente['nombre'],
            'apellidos'     => $cliente['apellidos'],
            'email'         => $cliente['email'],
            'password_hash' => $hash,
            'telefono'      => $cliente['telefono'] ?: null,
            'dni_nif'       => $cliente['dni_nif'] ?: null,
            'direccion'     => $cliente['direccion'] ?: null,
            'es_cliente'    => 1,
            'cliente_id'    => $cliente['id'],
            'activo'        => 1
        ]);
        
        $cuentaPortal = ['id' => $portalId, 'nombre' => $cliente['nombre']];
    }

    if ($cuentaPortal) {
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+2 hours'));
        
        $db->update('portal_cuentas', [
            'reset_token' => $token, 
            'reset_expires' => $expires
        ], 'id = ?', [$cuentaPortal['id']]);
        
        $resetLink = str_replace('/crm', '/portal', APP_URL) . '/index.php?page=reset-password&token=' . $token;
        
        require_once CRM_ROOT . '/includes/Mailer.php';
        Mailer::recuperarPasswordPortal($cliente['email'], $cuentaPortal['nombre'], $resetLink);
        
        AuditLog::registrar('enviar_recovery', 'clientes', $id, 'Enlace de recuperación enviado al cliente');
        setFlash('exito', 'Enlace de recuperación/acceso enviado al correo del cliente.');
    } else {
        setFlash('error', 'Error al crear la cuenta del portal para el cliente.');
    }
    header('Location: ' . APP_URL . '/index.php?page=clientes/ver&id=' . $id);
    exit;
}

// Eliminar cliente desde su propia ficha
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_cliente'])) {
    RoleGuard::requireRole('admin');
    CSRF::verificarOAbortar();
    
    // 1. Eliminar cuenta del portal vinculada
    $db->delete('portal_cuentas', 'cliente_id = ?', [$id]);
    
    // 2. Eliminar el cliente
    $db->delete('clientes', 'id = ?', [$id]);
    
    AuditLog::registrar('eliminar', 'clientes', $id, 'Cliente eliminado desde su ficha');
    setFlash('exito', 'Cliente eliminado correctamente');
    header('Location: ' . APP_URL . '/index.php?page=clientes');
    exit;
}

// Crear nuevo caso para este cliente
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_caso'])) {
    CSRF::verificarOAbortar();
    $titulo = trim($_POST['titulo']);
    $referencia = trim($_POST['referencia']) ?: 'EXP-' . strtoupper(bin2hex(random_bytes(3)));
    
    $nuevoCasoId = $db->insert('casos', [
        'cliente_id' => $id,
        'abogado_id' => $_POST['abogado_id'] ?: null,
        'titulo' => $titulo,
        'tipo_caso' => $_POST['tipo_caso'] ?? 'Legal',
        'referencia' => $referencia,
        'estado' => $_POST['estado'] ?? 'en_estudio',
        'honorarios_totales' => (float)$_POST['honorarios_totales'],
        'fecha_apertura' => $_POST['fecha_apertura'] ?: date('Y-m-d'),
        'descripcion' => $_POST['descripcion'] ?? ''
    ]);
    
    AuditLog::registrar('crear', 'casos', $nuevoCasoId, 'Caso creado desde ficha de cliente');
    setFlash('exito', 'Nuevo caso creado correctamente');
    header('Location: ' . APP_URL . '/index.php?page=clientes/ver&id=' . $id);
    exit;
}

// Editar caso existente
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_caso'])) {
    CSRF::verificarOAbortar();
    $casoId = (int)$_POST['caso_id'];
    
    $db->update('casos', [
        'titulo' => trim($_POST['titulo']),
        'referencia' => trim($_POST['referencia']),
        'abogado_id' => $_POST['abogado_id'] ?: null,
        'estado' => $_POST['estado'],
        'honorarios_totales' => (float)$_POST['honorarios_totales'],
        'tipo_caso' => $_POST['tipo_caso'],
        'descripcion' => $_POST['descripcion']
    ], 'id = ?', [$casoId]);
    
    AuditLog::registrar('editar', 'casos', $casoId, 'Caso editado desde ficha de cliente');
    setFlash('exito', 'Caso actualizado correctamente');
    header('Location: ' . APP_URL . '/index.php?page=clientes/ver&id=' . $id);
    exit;
}

// Eliminar caso
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_caso'])) {
    RoleGuard::requireRole('admin');
    CSRF::verificarOAbortar();
    $casoId = (int)$_POST['caso_id'];
    
    $db->delete('casos', 'id = ?', [$casoId]);
    
    AuditLog::registrar('eliminar', 'casos', $casoId, 'Caso eliminado desde ficha de cliente');
    setFlash('exito', 'Caso eliminado correctamente');
    header('Location: ' . APP_URL . '/index.php?page=clientes/ver&id=' . $id);
    exit;
}


if ($auth->esAbogado()) {
    $casos = $db->fetchAll(
        "SELECT c.*, u.nombre as abogado_nombre, u.apellidos as abogado_apellidos
         FROM casos c LEFT JOIN usuarios_internos u ON c.abogado_id = u.id 
         WHERE c.cliente_id = ? AND c.abogado_id = ? ORDER BY c.created_at DESC", 
        [$id, $_SESSION['usuario_id'] ?? 0]
    );
} else {
    $casos = $db->fetchAll(
        "SELECT c.*, u.nombre as abogado_nombre, u.apellidos as abogado_apellidos,
            COALESCE((SELECT SUM(p.cantidad) FROM pagos p WHERE p.caso_id = c.id), 0) as total_pagado
         FROM casos c LEFT JOIN usuarios_internos u ON c.abogado_id = u.id WHERE c.cliente_id = ? ORDER BY c.created_at DESC", [$id]
    );
}

$abogadosList = $db->fetchAll("SELECT id, nombre, apellidos FROM usuarios_internos WHERE rol = 'abogado' AND activo = 1 ORDER BY nombre");

$tituloPagina = $cliente['nombre'] . ' ' . $cliente['apellidos'];
include CRM_ROOT . '/templates/layout/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
        <h4 class="fw-bold mb-8">Ficha de Cliente</h4>
        <ul class="d-flex align-items-center gap-2 text-secondary-light">
            <li><a href="<?php echo APP_URL; ?>/index.php?page=clientes" class="text-primary-600 hover-text-primary text-decoration-none">Clientes</a></li>
            <li><iconify-icon icon="solar:alt-arrow-right-outline" class="text-xl"></iconify-icon></li>
            <li class="fw-medium text-neutral-800"><?php echo e($cliente['nombre']); ?></li>
        </ul>
    </div>
</div>

<div class="row gy-4">
    <!-- Panel Izquierdo: Perfil del Cliente -->
    <div class="col-xl-4 col-lg-5">
        <div class="card radius-12 border-0 shadow-sm overflow-hidden">
            <div class="position-relative" style="height: 120px; background: linear-gradient(135deg, #2e6edd 0%, #1e40af 100%);">
                <!-- Decorative background shapes -->
                <div class="position-absolute w-100 h-100 top-0 start-0 opacity-25" style="background-image: radial-gradient(circle at 20% 50%, rgba(255,255,255,0.4) 0%, transparent 50%);"></div>
            </div>
            
            <div class="card-body px-24 pb-32 pt-0 position-relative text-center" style="margin-top: -50px;">
                <div class="w-100-px h-100-px rounded-circle bg-white shadow-sm d-flex justify-content-center align-items-center mx-auto mb-16 p-1 position-relative z-1 border border-4 border-white">
                    <div class="w-100 h-100 rounded-circle bg-primary-50 d-flex justify-content-center align-items-center text-primary-600 fw-bold fs-2">
                        <?php echo strtoupper(substr($cliente['nombre'],0,1).substr($cliente['apellidos'],0,1)); ?>
                    </div>
                </div>
                
                <h5 class="fw-bold text-neutral-800 mb-4"><?php echo e($cliente['nombre'] . ' ' . $cliente['apellidos']); ?></h5>
                <p class="text-secondary-light mb-24 d-flex align-items-center justify-content-center gap-2">
                    <iconify-icon icon="solar:letter-outline"></iconify-icon> <?php echo e($cliente['email']); ?>
                </p>

                <div class="d-flex flex-column gap-3 mb-32 text-start">
                    <div class="d-flex align-items-start gap-12 p-16 radius-8 bg-neutral-50 border border-neutral-100 hover-bg-neutral-100 transition-2">
                        <div class="w-40-px h-40-px rounded-circle bg-white shadow-sm d-flex justify-content-center align-items-center text-primary-600 flex-shrink-0">
                            <iconify-icon icon="solar:phone-calling-outline" class="text-xl"></iconify-icon>
                        </div>
                        <div>
                            <span class="d-block text-xs text-secondary-light fw-medium text-uppercase tracking-wider mb-2">Teléfono</span>
                            <span class="d-block fw-semibold text-neutral-800"><?php echo e($cliente['telefono'] ?: 'No especificado'); ?></span>
                        </div>
                    </div>
                    
                    <div class="d-flex align-items-start gap-12 p-16 radius-8 bg-neutral-50 border border-neutral-100 hover-bg-neutral-100 transition-2">
                        <div class="w-40-px h-40-px rounded-circle bg-white shadow-sm d-flex justify-content-center align-items-center text-primary-600 flex-shrink-0">
                            <iconify-icon icon="solar:card-outline" class="text-xl"></iconify-icon>
                        </div>
                        <div>
                            <span class="d-block text-xs text-secondary-light fw-medium text-uppercase tracking-wider mb-2">DNI / NIF</span>
                            <span class="d-block fw-semibold text-neutral-800"><?php echo e($cliente['dni_nif'] ?: 'No especificado'); ?></span>
                        </div>
                    </div>
                    
                    <div class="d-flex align-items-start gap-12 p-16 radius-8 bg-neutral-50 border border-neutral-100 hover-bg-neutral-100 transition-2">
                        <div class="w-40-px h-40-px rounded-circle bg-white shadow-sm d-flex justify-content-center align-items-center text-primary-600 flex-shrink-0">
                            <iconify-icon icon="solar:map-point-outline" class="text-xl"></iconify-icon>
                        </div>
                        <div>
                            <span class="d-block text-xs text-secondary-light fw-medium text-uppercase tracking-wider mb-2">Dirección</span>
                            <span class="d-block fw-semibold text-neutral-800"><?php echo e($cliente['direccion'] ?: 'No especificada'); ?></span>
                        </div>
                    </div>
                    
                    <div class="d-flex align-items-start gap-12 p-16 radius-8 bg-neutral-50 border border-neutral-100 hover-bg-neutral-100 transition-2">
                        <div class="w-40-px h-40-px rounded-circle bg-white shadow-sm d-flex justify-content-center align-items-center text-primary-600 flex-shrink-0">
                            <iconify-icon icon="solar:calendar-date-outline" class="text-xl"></iconify-icon>
                        </div>
                        <div>
                            <span class="d-block text-xs text-secondary-light fw-medium text-uppercase tracking-wider mb-2">Fecha de Alta</span>
                            <span class="d-block fw-semibold text-neutral-800"><?php echo date('d/m/Y', strtotime($cliente['created_at'])); ?></span>
                        </div>
                    </div>
                </div>

                <div class="d-flex flex-column gap-3">
                    <button class="btn btn-primary d-flex align-items-center justify-content-center gap-2 w-100 radius-8 py-12 fw-semibold shadow-sm hover-shadow-lg transition-2" data-bs-toggle="modal" data-bs-target="#editarClienteModal">
                        <iconify-icon icon="solar:pen-new-square-outline" class="text-lg"></iconify-icon> Editar Información
                    </button>
                    
                    <?php if ($auth->esAdmin()): ?>
                    <button type="button" class="btn bg-warning-50 text-warning-600 border border-warning-200 hover-bg-warning-100 d-flex align-items-center justify-content-center gap-2 w-100 radius-8 py-12 fw-semibold transition-2" data-bs-toggle="modal" data-bs-target="#confirmRecoveryModal">
                        <iconify-icon icon="solar:key-minimalistic-square-outline" class="text-lg"></iconify-icon> Enviar Link de Contraseña
                    </button>

                    <button type="button" class="btn bg-info-50 text-info-600 border border-info-200 hover-bg-info-100 d-flex align-items-center justify-content-center gap-2 w-100 radius-8 py-12 fw-semibold transition-2" data-bs-toggle="modal" data-bs-target="#regenerarPasswordModal">
                        <iconify-icon icon="solar:refresh-outline" class="text-lg"></iconify-icon> Regenerar Contraseña
                    </button>
                    
                    <!-- Modal Confirmación de Enlace -->
                    <div class="modal fade" id="confirmRecoveryModal" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered" style="max-width: 400px;">
                            <div class="modal-content radius-12 border-0 shadow-lg text-center p-24">
                                <div class="w-64-px h-64-px rounded-circle bg-warning-50 text-warning-500 d-flex justify-content-center align-items-center mx-auto mb-16 mt-8">
                                    <iconify-icon icon="solar:key-minimalistic-square-outline" class="text-3xl"></iconify-icon>
                                </div>
                                <h5 class="fw-bold text-neutral-800 mb-12">¿Enviar enlace de acceso?</h5>
                                <p class="text-secondary-light text-sm mb-24 px-12">Se enviará un enlace seguro al correo del cliente para que pueda establecer una nueva contraseña y acceder a su portal.</p>
                                
                                <form id="form-recovery" method="POST" class="w-100 d-flex gap-12">
                                    <?php echo CSRF::campo(); ?>
                                    <input type="hidden" name="enviar_recovery" value="1">
                                    <button type="button" class="btn btn-outline-secondary radius-8 fw-semibold flex-grow-1" data-bs-dismiss="modal">Cancelar</button>
                                    <button type="submit" class="btn btn-warning radius-8 fw-semibold flex-grow-1 text-white shadow-sm hover-shadow-lg transition-2" style="background-color: #f59e0b; border-color: #f59e0b;">Sí, enviar enlace</button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <button type="button" class="btn bg-danger-50 text-danger-600 border border-danger-200 hover-bg-danger-100 d-flex align-items-center justify-content-center gap-2 w-100 radius-8 py-12 fw-semibold transition-2" onclick="confirmDeleteClient()">
                        <iconify-icon icon="solar:trash-bin-trash-outline" class="text-lg"></iconify-icon> Eliminar Cliente
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($auth->esAdmin()):
            $cuentaPortalPw = $db->fetchOne("SELECT password_plain FROM portal_cuentas WHERE email = ?", [$cliente['email']]);
            $passwordActual = $cuentaPortalPw['password_plain'] ?? '(no disponible)';
        ?>
        <div class="card radius-12 border-0 shadow-sm mt-16">
            <div class="card-body p-16">
                <h6 class="fw-bold text-neutral-800 mb-12 d-flex align-items-center gap-2">
                    <iconify-icon icon="solar:key-minimalistic-square-outline" class="text-lg text-info-600"></iconify-icon>
                    Contraseña del Portal
                </h6>
                <div class="d-flex align-items-center gap-8 bg-neutral-50 border border-neutral-200 radius-8 p-12 mb-12">
                    <code id="passwordDisplay" class="flex-grow-1 fw-semibold text-neutral-800" style="font-size: 1rem; letter-spacing: 1px;"><?php echo htmlspecialchars($passwordActual); ?></code>
                    <button type="button" class="btn btn-sm btn-outline-primary radius-6" onclick="navigator.clipboard.writeText(document.getElementById('passwordDisplay').textContent); this.innerHTML='✓ Copiada'; setTimeout(()=>this.innerHTML='Copiar', 2000);">Copiar</button>
                </div>
                <div class="modal fade" id="regenerarPasswordModal" tabindex="-1">
                    <div class="modal-dialog modal-dialog-centered" style="max-width: 400px;">
                        <div class="modal-content radius-12 border-0 shadow-lg text-center p-24">
                            <div class="w-64-px h-64-px rounded-circle bg-info-50 text-info-500 d-flex justify-content-center align-items-center mx-auto mb-16 mt-8">
                                <iconify-icon icon="solar:refresh-outline" class="text-3xl"></iconify-icon>
                            </div>
                            <h5 class="fw-bold text-neutral-800 mb-12">¿Regenerar contraseña?</h5>
                            <p class="text-secondary-light text-sm mb-24 px-12">Se generará una nueva contraseña aleatoria. La anterior dejará de funcionar. Deberá copiarla y enviársela al cliente.</p>
                            <form method="POST" class="w-100 d-flex gap-12">
                                <?php echo CSRF::campo(); ?>
                                <input type="hidden" name="regenerar_password" value="1">
                                <button type="button" class="btn btn-outline-secondary radius-8 fw-semibold flex-grow-1" data-bs-dismiss="modal">Cancelar</button>
                                <button type="submit" class="btn btn-info radius-8 fw-semibold flex-grow-1 text-white shadow-sm">Sí, regenerar</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if(!empty($cliente['notas'])): ?>
        <div class="card radius-12 border-0 shadow-sm mt-24">
            <div class="card-header bg-white border-bottom border-neutral-100 py-16 px-24 d-flex align-items-center gap-2">
                <iconify-icon icon="solar:document-text-outline" class="text-primary-600 text-xl"></iconify-icon>
                <h6 class="fw-semibold mb-0">Notas Internas</h6>
            </div>
            <div class="card-body p-24">
                <p class="text-secondary-light mb-0" style="white-space: pre-line; line-height: 1.6;"><?php echo e($cliente['notas']); ?></p>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Panel Derecho: Expedientes -->
    <div class="col-xl-8 col-lg-7">
        <div class="card radius-12 border-0 shadow-sm h-100">
            <div class="card-header bg-white border-bottom border-neutral-100 py-20 px-24 d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center gap-2">
                    <div class="w-40-px h-40-px rounded-circle bg-primary-50 d-flex justify-content-center align-items-center text-primary-600">
                        <iconify-icon icon="solar:folder-with-files-outline" class="text-xl"></iconify-icon>
                    </div>
                    <div>
                        <h6 class="fw-bold mb-0">Expedientes del Cliente</h6>
                        <span class="text-xs text-secondary-light">Historial completo de casos gestionados</span>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <button class="btn btn-sm btn-primary radius-8 d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#nuevoCasoModal">
                        <iconify-icon icon="solar:add-circle-outline"></iconify-icon> Nuevo Expediente
                    </button>
                    <span class="badge bg-primary-50 text-primary-600 px-12 py-6 radius-8 fw-semibold"><?php echo count($casos); ?> Expedientes</span>
                </div>
            </div>
            
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 text-nowrap border-0">
                        <thead class="bg-neutral-50 text-uppercase text-xs tracking-wider fw-semibold text-secondary-light">
                            <tr>
                                <th class="ps-24 py-16 border-bottom border-neutral-100">Referencia</th>
                                <th class="py-16 border-bottom border-neutral-100">Asunto</th>
                                <th class="py-16 border-bottom border-neutral-100">Abogado Asignado</th>
                                <th class="py-16 border-bottom border-neutral-100">Estado</th>
                                <?php if($auth->esAdmin()): ?><th class="py-16 border-bottom border-neutral-100">Honorarios</th><?php endif; ?>
                                <th class="pe-24 py-16 border-bottom border-neutral-100 text-end">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($casos)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-48">
                                    <div class="d-flex flex-column align-items-center justify-content-center">
                                        <iconify-icon icon="solar:folder-cross-outline" class="text-neutral-300 text-6xl mb-16"></iconify-icon>
                                        <h6 class="text-neutral-800 fw-semibold mb-4">No hay expedientes</h6>
                                        <p class="text-secondary-light text-sm mb-0">Este cliente aún no tiene casos registrados.</p>
                                    </div>
                                </td>
                            </tr>
                            <?php else: foreach ($casos as $caso):
                                $estadoBadge = match($caso['estado']) {
                                    'en_estudio' => 'bg-primary-50 text-primary-600 border border-primary-200',
                                    'en_proceso' => 'bg-warning-50 text-warning-600 border border-warning-200',
                                    'en_tramitacion' => 'bg-info-50 text-info-600 border border-info-200',
                                    'pendiente_juicio' => 'bg-danger-50 text-danger-600 border border-danger-200',
                                    'cerrado' => 'bg-success-50 text-success-600 border border-success-200',
                                    'archivado' => 'bg-neutral-100 text-neutral-600 border border-neutral-200',
                                    default => 'bg-neutral-100 text-neutral-600 border border-neutral-200'
                                };
                            ?>
                            <tr class="hover-bg-neutral-50 transition-2">
                                <td class="ps-24 py-16 border-bottom border-neutral-100">
                                    <span class="text-sm fw-bold text-neutral-800"><?php echo e($caso['referencia']); ?></span>
                                </td>
                                <td class="py-16 border-bottom border-neutral-100">
                                    <a href="<?php echo APP_URL; ?>/index.php?page=casos/ver&id=<?php echo $caso['id']; ?>" class="text-primary-600 hover-text-primary-800 fw-semibold text-decoration-none d-flex align-items-center gap-2">
                                        <?php echo e($caso['titulo']); ?>
                                    </a>
                                </td>
                                <td class="py-16 border-bottom border-neutral-100">
                                    <?php if ($caso['abogado_nombre']): ?>
                                        <div class="d-flex align-items-center gap-8">
                                            <div class="w-24-px h-24-px rounded-circle bg-primary-100 text-primary-600 d-flex justify-content-center align-items-center text-xs fw-bold">
                                                <?php echo strtoupper(substr($caso['abogado_nombre'],0,1)); ?>
                                            </div>
                                            <span class="text-sm fw-medium text-neutral-700"><?php echo e($caso['abogado_nombre'] . ' ' . $caso['abogado_apellidos']); ?></span>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge bg-neutral-100 text-neutral-500 radius-4 fw-medium border border-neutral-200">Sin asignar</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-16 border-bottom border-neutral-100">
                                    <span class="badge <?php echo $estadoBadge; ?> radius-6 px-12 py-6 fw-medium d-inline-flex align-items-center gap-1">
                                        <span class="w-6-px h-6-px rounded-circle bg-current"></span>
                                        <?php echo e(ucfirst(str_replace('_',' ',$caso['estado']))); ?>
                                    </span>
                                </td>
                                <?php if($auth->esAdmin()): ?>
                                <td class="py-16 border-bottom border-neutral-100">
                                    <div class="d-flex flex-column gap-1">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="text-xs text-secondary-light">Pagado:</span>
                                            <span class="text-sm fw-bold <?php echo $caso['total_pagado'] >= $caso['honorarios_totales'] ? 'text-success-600' : 'text-neutral-800'; ?>">€<?php echo number_format($caso['total_pagado'],2,',','.'); ?></span>
                                        </div>
                                        <div class="w-100 bg-neutral-100 rounded-pill overflow-hidden my-4" style="height: 6px;">
                                            <?php 
                                            $porcentaje = $caso['honorarios_totales'] > 0 ? min(100, ($caso['total_pagado'] / $caso['honorarios_totales']) * 100) : 0;
                                            $bgProgress = $porcentaje == 100 ? 'bg-success-500' : 'bg-primary-500';
                                            $badgeClass = $porcentaje == 100 ? 'bg-success-50 text-success-600 border border-success-200' : 'bg-primary-50 text-primary-600 border border-primary-200';
                                            ?>
                                            <div class="<?php echo $bgProgress; ?> h-100 rounded-pill" style="width: <?php echo $porcentaje; ?>%"></div>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="text-xs text-secondary-light fw-medium">de €<?php echo number_format($caso['honorarios_totales'],2,',','.'); ?></span>
                                            <span class="badge <?php echo $badgeClass; ?> radius-4 px-8 py-2"><?php echo round($porcentaje); ?>%</span>
                                        </div>
                                    </div>
                                </td>
                                <?php endif; ?>
                                <td class="pe-24 py-16 border-bottom border-neutral-100 text-end">
                                    <div class="d-flex align-items-center justify-content-end gap-2">
                                        <a href="<?php echo APP_URL; ?>/index.php?page=casos/ver&id=<?php echo $caso['id']; ?>" class="btn btn-sm bg-info-50 text-info-600 hover-bg-info-100 border border-info-200 transition-2 d-inline-flex align-items-center justify-content-center radius-8" style="width:32px; height:32px;" title="Ver Expediente">
                                            <iconify-icon icon="solar:eye-outline" class="text-lg"></iconify-icon>
                                        </a>
                                        <button type="button" class="btn btn-sm bg-warning-50 text-warning-600 hover-bg-warning-100 border border-warning-200 transition-2 d-inline-flex align-items-center justify-content-center radius-8" style="width:32px; height:32px;" title="Editar" 
                                            onclick="openEditCaso(<?php echo htmlspecialchars(json_encode($caso)); ?>)">
                                            <iconify-icon icon="solar:pen-outline" class="text-lg"></iconify-icon>
                                        </button>
                                        <?php if($auth->esAdmin()): ?>
                                        <button type="button" class="btn btn-sm bg-danger-50 text-danger-600 hover-bg-danger-100 border border-danger-200 transition-2 d-inline-flex align-items-center justify-content-center radius-8" style="width:32px; height:32px;" title="Eliminar" 
                                            onclick="confirmDeleteCaso(<?php echo $caso['id']; ?>, '<?php echo e($caso['titulo']); ?>')">
                                            <iconify-icon icon="solar:trash-bin-trash-outline" class="text-lg"></iconify-icon>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
<?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal editar cliente -->
<div class="modal fade" id="editarClienteModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" class="modal-content radius-12 border-0 shadow-lg">
            <?php echo CSRF::campo(); ?>
            <input type="hidden" name="editar_cliente" value="1">
            <div class="modal-header border-bottom border-neutral-100 py-16 px-24">
                <h5 class="modal-title fw-bold d-flex align-items-center gap-2">
                    <div class="w-32-px h-32-px rounded-circle bg-primary-50 text-primary-600 d-flex justify-content-center align-items-center">
                        <iconify-icon icon="solar:user-id-outline"></iconify-icon>
                    </div>
                    Editar Información del Cliente
                </h5>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-24">
                <div class="row gy-20">
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold text-neutral-800">Nombre <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-neutral-50 border-end-0 text-secondary-light"><iconify-icon icon="solar:user-outline"></iconify-icon></span>
                            <input type="text" name="nombre" class="form-control border-start-0 ps-0" value="<?php echo e($cliente['nombre']); ?>" required>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold text-neutral-800">Apellidos <span class="text-danger">*</span></label>
                        <input type="text" name="apellidos" class="form-control" value="<?php echo e($cliente['apellidos']); ?>" required>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold text-neutral-800">Email <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-neutral-50 border-end-0 text-secondary-light"><iconify-icon icon="solar:letter-outline"></iconify-icon></span>
                            <input type="email" name="email" class="form-control border-start-0 ps-0" value="<?php echo e($cliente['email']); ?>" required>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold text-neutral-800">Teléfono</label>
                        <div class="input-group">
                            <span class="input-group-text bg-neutral-50 border-end-0 text-secondary-light"><iconify-icon icon="solar:phone-outline"></iconify-icon></span>
                            <input type="text" name="telefono" class="form-control border-start-0 ps-0" value="<?php echo e($cliente['telefono']); ?>">
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold text-neutral-800">DNI / NIF</label>
                        <div class="input-group">
                            <span class="input-group-text bg-neutral-50 border-end-0 text-secondary-light"><iconify-icon icon="solar:card-outline"></iconify-icon></span>
                            <input type="text" name="dni_nif" class="form-control border-start-0 ps-0" value="<?php echo e($cliente['dni_nif']); ?>">
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold text-neutral-800">Dirección</label>
                        <div class="input-group">
                            <span class="input-group-text bg-neutral-50 border-end-0 text-secondary-light"><iconify-icon icon="solar:map-point-outline"></iconify-icon></span>
                            <input type="text" name="direccion" class="form-control border-start-0 ps-0" value="<?php echo e($cliente['direccion']); ?>">
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold text-neutral-800 d-flex justify-content-between">
                            <span>Notas Internas</span>
                            <span class="text-xs text-secondary-light fw-normal">Solo visibles para administradores y abogados</span>
                        </label>
                        <textarea name="notas" class="form-control" rows="4" placeholder="Observaciones sobre el cliente..."><?php echo e($cliente['notas']); ?></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-top border-neutral-100 p-24 d-flex justify-content-between bg-neutral-50 radius-bottom-12">
                <button type="button" class="btn btn-outline-secondary radius-8 px-24 fw-semibold" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary radius-8 px-32 fw-semibold d-flex align-items-center gap-2 shadow-sm hover-shadow-lg transition-2">
                    <iconify-icon icon="solar:diskette-outline" class="text-lg"></iconify-icon> Guardar Cambios
                </button>
            </div>
        </form>
    </div>
</div>

<style>
/* Utility classes for the premium design */
.hover-text-primary-800:hover { color: #1e40af !important; }
.transition-2 { transition: all 0.2s ease-in-out; }
.hover-bg-neutral-100:hover { background-color: #f1f5f9 !important; }
.hover-bg-neutral-50:hover { background-color: #f8fafc !important; }
.hover-shadow-lg:hover { box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05) !important; }
.tracking-wider { letter-spacing: 0.05em; }
.radius-12 { border-radius: 12px !important; }
.radius-bottom-12 { border-bottom-left-radius: 12px; border-bottom-right-radius: 12px; }
.bg-current { background-color: currentColor; }
.text-current { color: currentColor; }
.bg-opacity-10 { --bs-bg-opacity: 0.1; }
</style>

<!-- Modal: Nuevo Caso -->
<div class="modal fade" id="nuevoCasoModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" class="modal-content radius-12 border-0 shadow-lg">
            <?php echo CSRF::campo(); ?>
            <input type="hidden" name="crear_caso" value="1">
            <div class="modal-header border-bottom border-neutral-100 py-16 px-24">
                <h5 class="modal-title fw-bold">Nuevo Expediente</h5>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-24">
                <div class="row gy-3">
                    <div class="col-sm-12">
                        <label class="form-label fw-semibold">Título del Asunto <span class="text-danger">*</span></label>
                        <input type="text" name="titulo" class="form-control" placeholder="Ej: Divorcio Contencioso" required>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold">Referencia <span class="text-xs text-secondary-light">(Auto-generada si vacío)</span></label>
                        <input type="text" name="referencia" class="form-control" placeholder="EXP-001">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold">Tipo de Caso</label>
                        <select name="tipo_caso" class="form-select">
                            <option value="Civil">Civil</option>
                            <option value="Penal">Penal</option>
                            <option value="Laboral">Laboral</option>
                            <option value="Familia">Familia</option>
                            <option value="Administrativo">Administrativo</option>
                            <option value="Otros">Otros</option>
                        </select>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold">Abogado Asignado</label>
                        <select name="abogado_id" class="form-select">
                            <option value="">Sin asignar</option>
                            <?php foreach ($abogadosList as $ab): ?>
                            <option value="<?php echo $ab['id']; ?>"><?php echo e($ab['nombre'] . ' ' . $ab['apellidos']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold">Estado Inicial</label>
                        <select name="estado" class="form-select">
                            <option value="en_estudio">En estudio</option>
                            <option value="en_proceso">En proceso</option>
                            <option value="en_tramitacion">En tramitación</option>
                        </select>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold">Honorarios Totales (€)</label>
                        <input type="number" step="0.01" name="honorarios_totales" class="form-control" value="0.00">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold">Fecha de Apertura</label>
                        <input type="date" name="fecha_apertura" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Descripción / Detalles</label>
                        <textarea name="descripcion" class="form-control" rows="3"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-neutral-50 radius-bottom-12">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Crear Expediente</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Editar Caso -->
<div class="modal fade" id="editarCasoModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" class="modal-content radius-12 border-0 shadow-lg">
            <?php echo CSRF::campo(); ?>
            <input type="hidden" name="editar_caso" value="1">
            <input type="hidden" name="caso_id" id="editCasoId">
            <div class="modal-header border-bottom border-neutral-100 py-16 px-24">
                <h5 class="modal-title fw-bold">Editar Expediente</h5>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-24">
                <div class="row gy-3">
                    <div class="col-sm-12">
                        <label class="form-label fw-semibold">Título <span class="text-danger">*</span></label>
                        <input type="text" name="titulo" id="editCasoTitulo" class="form-control" required>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold">Referencia</label>
                        <input type="text" name="referencia" id="editCasoReferencia" class="form-control">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold">Tipo de Caso</label>
                        <input type="text" name="tipo_caso" id="editCasoTipo" class="form-control">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold">Abogado Asignado</label>
                        <select name="abogado_id" id="editCasoAbogado" class="form-select">
                            <option value="">Sin asignar</option>
                            <?php foreach ($abogadosList as $ab): ?>
                            <option value="<?php echo $ab['id']; ?>"><?php echo e($ab['nombre'] . ' ' . $ab['apellidos']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold">Estado</label>
                        <select name="estado" id="editCasoEstado" class="form-select">
                            <option value="en_estudio">En estudio</option>
                            <option value="en_proceso">En proceso</option>
                            <option value="en_tramitacion">En tramitación</option>
                            <option value="pendiente_juicio">Pendiente de juicio</option>
                            <option value="cerrado">Cerrado</option>
                            <option value="archivado">Archivado</option>
                        </select>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold">Honorarios Totales (€)</label>
                        <input type="number" step="0.01" name="honorarios_totales" id="editCasoHonorarios" class="form-control">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Descripción</label>
                        <textarea name="descripcion" id="editCasoDescripcion" class="form-control" rows="3"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-neutral-50 radius-bottom-12">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar Cambios</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Eliminar Caso -->
<div class="modal fade" id="eliminarCasoModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 400px;">
        <form method="POST" class="modal-content radius-12 border-0 shadow-lg text-center p-24">
            <?php echo CSRF::campo(); ?>
            <input type="hidden" name="eliminar_caso" value="1">
            <input type="hidden" name="caso_id" id="deleteCasoId">
            <div class="w-64-px h-64-px rounded-circle bg-danger-50 text-danger-600 d-flex justify-content-center align-items-center mx-auto mb-16 mt-8">
                <iconify-icon icon="solar:trash-bin-trash-bold" class="text-3xl"></iconify-icon>
            </div>
            <h5 class="fw-bold text-neutral-800 mb-12">¿Eliminar expediente?</h5>
            <p class="text-secondary-light text-sm mb-24 px-12">¿Estás seguro de que deseas eliminar el expediente <strong id="deleteCasoNombre"></strong>? Esta acción no se puede deshacer.</p>
            <div class="d-flex gap-12">
                <button type="button" class="btn btn-outline-secondary radius-8 fw-semibold flex-grow-1" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-danger radius-8 fw-semibold flex-grow-1">Sí, eliminar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Eliminar Cliente (Confirmación Final) -->
<div class="modal fade" id="eliminarClienteFichaModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 400px;">
        <form method="POST" class="modal-content radius-12 border-0 shadow-lg text-center p-24">
            <?php echo CSRF::campo(); ?>
            <input type="hidden" name="eliminar_cliente" value="1">
            <div class="w-64-px h-64-px rounded-circle bg-danger-50 text-danger-600 d-flex justify-content-center align-items-center mx-auto mb-16 mt-8">
                <iconify-icon icon="solar:trash-bin-trash-bold" class="text-3xl"></iconify-icon>
            </div>
            <h5 class="fw-bold text-neutral-800 mb-12">¿Eliminar cliente?</h5>
            <p class="text-secondary-light text-sm mb-24 px-12">Estás a punto de eliminar a <strong><?php echo e($cliente['nombre'] . ' ' . $cliente['apellidos']); ?></strong> de forma permanente. Se perderá todo su acceso al portal.</p>
            <div class="d-flex gap-12">
                <button type="button" class="btn btn-outline-secondary radius-8 fw-semibold flex-grow-1" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-danger radius-8 fw-semibold flex-grow-1">Sí, eliminar todo</button>
            </div>
        </form>
    </div>
</div>

<script>
function confirmDeleteClient() {
    bootstrap.Modal.getOrCreateInstance(document.getElementById('eliminarClienteFichaModal')).show();
}

function openEditCaso(caso) {
    document.getElementById('editCasoId').value = caso.id;
    document.getElementById('editCasoTitulo').value = caso.titulo;
    document.getElementById('editCasoReferencia').value = caso.referencia;
    document.getElementById('editCasoTipo').value = caso.tipo_caso;
    document.getElementById('editCasoAbogado').value = caso.abogado_id || '';
    document.getElementById('editCasoEstado').value = caso.estado;
    document.getElementById('editCasoHonorarios').value = caso.honorarios_totales;
    document.getElementById('editCasoDescripcion').value = caso.descripcion || '';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('editarCasoModal')).show();
}

function confirmDeleteCaso(id, titulo) {
    document.getElementById('deleteCasoId').value = id;
    document.getElementById('deleteCasoNombre').innerText = titulo;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('eliminarCasoModal')).show();
}
</script>

<?php include CRM_ROOT . '/templates/layout/footer.php'; ?>
