<?php
/**
 * CRM Abogados - Gestión de Usuarios (solo Admin)
 * Redesigned with modern card-based UI
 */
$db = Database::getInstance();

$filtroRol = $_GET['rol'] ?? null;
if ($filtroRol === 'abogado') {
    $tituloPagina = 'Abogados';
} else {
    $tituloPagina = 'Usuarios';
}

include CRM_ROOT . '/templates/layout/header.php';

// Procesar desactivación
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_activo'])) {
    CSRF::verificarOAbortar();
    $uid = (int)$_POST['usuario_id'];
    if ($uid !== $usuario['id']) {
        $estadoActual = $db->fetchColumn("SELECT activo FROM usuarios_internos WHERE id = ?", [$uid]);
        $db->update('usuarios_internos', ['activo' => $estadoActual ? 0 : 1], 'id = ?', [$uid]);
        AuditLog::registrar($estadoActual ? 'desactivar' : 'activar', 'usuarios_internos', $uid, 'Usuario '.($estadoActual ? 'desactivado' : 'activado'));
        setFlash('exito', 'Estado del usuario actualizado');
    }
    header('Location: ' . APP_URL . '/index.php?page=usuarios' . ($filtroRol ? '&rol='.$filtroRol : '')); exit;
}

// Procesar reset de contraseña de cliente portal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_portal_password'])) {
    CSRF::verificarOAbortar();
    $portalId = (int)$_POST['portal_id'];
    $newPass = trim($_POST['nueva_password']);
    if (strlen($newPass) >= 8 && preg_match('/[A-Z]/', $newPass) && preg_match('/[a-z]/', $newPass) && preg_match('/[0-9]/', $newPass)) {
        $hash = password_hash($newPass, PASSWORD_DEFAULT);
        $db->update('portal_cuentas', ['password_hash' => $hash], 'id = ?', [$portalId]);
        AuditLog::registrar('reset_password', 'portal_cuentas', $portalId, 'Contraseña del cliente restablecida por admin');
        setFlash('exito', 'Contraseña del cliente actualizada correctamente');
    } else {
        setFlash('error', 'La contraseña debe tener mínimo 8 caracteres, mayúsculas, minúsculas y números');
    }
    header('Location: ' . APP_URL . '/index.php?page=usuarios'); exit;
}

// Procesar enviar link de reseteo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_reset_link'])) {
    CSRF::verificarOAbortar();
    $portalId = (int)$_POST['portal_id'];
    $cuenta = $db->fetchOne("SELECT email, nombre FROM portal_cuentas WHERE id = ?", [$portalId]);
    if ($cuenta) {
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+2 hours'));
        $db->update('portal_cuentas', ['reset_token' => $token, 'reset_expires' => $expires], 'id = ?', [$portalId]);
        
        $resetLink = str_replace('/crm', '/portal', APP_URL) . '/index.php?page=reset-password&token=' . $token;
        AuditLog::registrar('reset_password_link', 'portal_cuentas', $portalId, 'Link de reseteo generado por admin');
        
        $_SESSION['generated_reset_link'] = $resetLink;
        $_SESSION['generated_reset_email'] = $cuenta['email'];
        setFlash('exito', 'Enlace de recuperación generado. Puede copiarlo y enviarlo al cliente.');
    }
    header('Location: ' . APP_URL . '/index.php?page=usuarios'); exit;
}

// Procesar edición de cuenta portal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_portal_cuenta'])) {
    CSRF::verificarOAbortar();
    $portalId = (int)$_POST['portal_id'];
    $nombre   = trim($_POST['edit_nombre'] ?? '');
    $apellidos= trim($_POST['edit_apellidos'] ?? '');
    $email    = trim($_POST['edit_email'] ?? '');
    $telefono = trim($_POST['edit_telefono'] ?? '');
    $dni_nif  = trim($_POST['edit_dni_nif'] ?? '');
    $direccion= trim($_POST['edit_direccion'] ?? '');
    $activo   = isset($_POST['edit_activo']) ? 1 : 0;

    if ($nombre && $apellidos && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        // Verificar email duplicado (excluyendo la propia cuenta)
        $existe = $db->fetchColumn("SELECT id FROM portal_cuentas WHERE email = ? AND id != ?", [$email, $portalId]);
        if ($existe) {
            setFlash('error', 'Ya existe otra cuenta con ese correo electrónico.');
        } else {
            $db->update('portal_cuentas', [
                'nombre'    => $nombre,
                'apellidos' => $apellidos,
                'email'     => $email,
                'telefono'  => $telefono ?: null,
                'dni_nif'   => $dni_nif ?: null,
                'direccion' => $direccion ?: null,
                'activo'    => $activo,
            ], 'id = ?', [$portalId]);
            AuditLog::registrar('editar', 'portal_cuentas', $portalId, 'Cuenta de cliente editada por admin');
            setFlash('exito', 'Cuenta del cliente actualizada correctamente.');
        }
    } else {
        setFlash('error', 'Complete todos los campos obligatorios con datos válidos.');
    }
    header('Location: ' . APP_URL . '/index.php?page=usuarios'); exit;
}

// Procesar eliminación de cuenta portal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_portal_cuenta'])) {
    CSRF::verificarOAbortar();
    $portalId = (int)$_POST['portal_id'];
    if ($portalId) {
        try {
            // No iniciamos transacción con $pdo directamente para evitar problemas si falla
            $db->beginTransaction();

            // Borrar la cuenta
            $db->query("DELETE FROM portal_cuentas WHERE id = ?", [$portalId]);

            $db->commit();
            AuditLog::registrar('eliminar', 'portal_cuentas', $portalId, 'Cuenta de cliente eliminada por admin');
            setFlash('exito', 'Cuenta eliminada correctamente.');
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('error', 'No se pudo eliminar la cuenta. Error: ' . $e->getMessage());
        }
    }
    header('Location: ' . APP_URL . '/index.php?page=usuarios'); exit;
}

if ($filtroRol) {
    $usuarios = $db->fetchAll("SELECT * FROM usuarios_internos WHERE rol = ? ORDER BY created_at DESC", [$filtroRol]);
} else {
    $usuarios = $db->fetchAll("SELECT * FROM usuarios_internos ORDER BY created_at DESC");
}

// Cuentas del portal (clientes)
$portalCuentas = $db->fetchAll(
    "SELECT pc.*, cl.nombre as cliente_nombre, cl.apellidos as cliente_apellidos 
     FROM portal_cuentas pc 
     LEFT JOIN clientes cl ON pc.cliente_id = cl.id 
     ORDER BY pc.created_at DESC"
);

// Stats
$totalUsuarios = count($usuarios);
$totalActivos = count(array_filter($usuarios, fn($u) => $u['activo']));
$totalAdmins = count(array_filter($usuarios, fn($u) => $u['rol'] === 'admin'));
$totalAbogados = count(array_filter($usuarios, fn($u) => $u['rol'] === 'abogado'));
?>

<style>
.usr-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
.usr-stat-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 20px 24px; display: flex; align-items: center; gap: 16px; }
.usr-stat-icon { width: 48px; height: 48px; border-radius: 14px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.usr-stat-icon iconify-icon { font-size: 22px; }
.usr-stat-info .usr-stat-num { font-size: 1.5rem; font-weight: 800; color: #1a1a2e; line-height: 1; }
.usr-stat-info .usr-stat-label { font-size: .75rem; color: #94a3b8; font-weight: 600; margin-top: 2px; }

.usr-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 16px; }
.usr-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 24px; transition: all .2s; position: relative; overflow: hidden; }
.usr-card:hover { border-color: #cbd5e1; box-shadow: 0 4px 24px rgba(0,0,0,.05); }
.usr-card-top { display: flex; align-items: center; gap: 14px; margin-bottom: 16px; }
.usr-avatar { width: 52px; height: 52px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.125rem; font-weight: 800; color: #fff; flex-shrink: 0; }
.usr-name { font-size: .9375rem; font-weight: 700; color: #1a1a2e; }
.usr-email { font-size: .8125rem; color: #64748b; margin-top: 2px; }
.usr-meta { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 16px; }
.usr-meta-item { background: #f8fafc; border-radius: 10px; padding: 10px 12px; }
.usr-meta-item label { font-size: .625rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .06em; display: block; }
.usr-meta-item span { font-size: .8125rem; font-weight: 600; color: #1a1a2e; }
.usr-actions { display: flex; gap: 8px; border-top: 1px solid #f1f5f9; padding-top: 14px; }
.usr-btn { padding: 8px 14px; border-radius: 10px; font-size: .75rem; font-weight: 700; border: none; cursor: pointer; display: flex; align-items: center; gap: 5px; transition: all .15s; text-decoration: none; }
.usr-btn-edit { background: #eff6ff; color: #2563eb; }
.usr-btn-edit:hover { background: #dbeafe; }
.usr-btn-toggle { background: #fef2f2; color: #dc2626; }
.usr-btn-toggle.activate { background: #f0fdf4; color: #059669; }
.usr-btn-toggle:hover { filter: brightness(.95); }

.portal-section { margin-top: 32px; }
.portal-table { width: 100%; border-collapse: separate; border-spacing: 0; }
.portal-table th { font-size: .6875rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .08em; padding: 12px 16px; border-bottom: 2px solid #f1f5f9; text-align: left; }
.portal-table td { padding: 14px 16px; border-bottom: 1px solid #f8fafc; font-size: .875rem; vertical-align: middle; }
.portal-table tr:hover td { background: #fafbfc; }
.portal-name { font-weight: 600; color: #1a1a2e; }
.portal-email { color: #64748b; font-size: .8125rem; }

/* Modal custom (sin usar select nativo) */
.modal-reset .modal-content { border-radius: 20px; border: none; box-shadow: 0 24px 64px rgba(0,0,0,.15); }
.modal-reset .modal-header { border-bottom: 1px solid #f1f5f9; padding: 20px 24px; }
.modal-reset .modal-body { padding: 24px; }
.modal-reset .modal-footer { border-top: 1px solid #f1f5f9; padding: 16px 24px; }
</style>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <h6 class="fw-semibold mb-0">Gestión de Usuarios</h6>
    <a href="<?php echo APP_URL; ?>/index.php?page=usuarios/crear" class="btn btn-sm btn-primary radius-8" style="display:inline-flex;align-items:center;gap:6px">
        <iconify-icon icon="ic:round-plus"></iconify-icon> Nuevo Usuario
    </a>
</div>

<?php if (isset($_SESSION['generated_reset_link'])): ?>
    <div class="alert alert-success d-flex align-items-center gap-3 mb-24 radius-8 shadow-sm" style="background:#f0fdf4; border:1px solid #bbf7d0; color:#166534">
        <iconify-icon icon="solar:link-circle-bold" style="font-size:32px"></iconify-icon>
        <div style="flex:1">
            <strong>Enlace generado para <?php echo e($_SESSION['generated_reset_email']); ?>:</strong><br>
            <a href="<?php echo $_SESSION['generated_reset_link']; ?>" target="_blank" style="word-break: break-all; color:#166534; font-weight:600"><?php echo $_SESSION['generated_reset_link']; ?></a>
        </div>
        <button class="btn btn-sm" style="background:#166534; color:#fff; font-weight:bold; border-radius:8px" onclick="navigator.clipboard.writeText('<?php echo $_SESSION['generated_reset_link']; ?>'); this.innerText='¡Copiado!'; setTimeout(()=>this.innerText='Copiar Link', 2000);">Copiar Link</button>
    </div>
    <?php 
        unset($_SESSION['generated_reset_link']);
        unset($_SESSION['generated_reset_email']);
    ?>
<?php endif; ?>

<!-- Stats -->
<div class="usr-stats">
    <div class="usr-stat-card">
        <div class="usr-stat-icon" style="background:#eff6ff;color:#2563eb">
            <iconify-icon icon="solar:users-group-rounded-bold"></iconify-icon>
        </div>
        <div class="usr-stat-info"><div class="usr-stat-num"><?php echo $totalUsuarios; ?></div><div class="usr-stat-label">Total Usuarios</div></div>
    </div>
    <div class="usr-stat-card">
        <div class="usr-stat-icon" style="background:#f0fdf4;color:#059669">
            <iconify-icon icon="solar:check-circle-bold"></iconify-icon>
        </div>
        <div class="usr-stat-info"><div class="usr-stat-num"><?php echo $totalActivos; ?></div><div class="usr-stat-label">Activos</div></div>
    </div>
    <div class="usr-stat-card">
        <div class="usr-stat-icon" style="background:#fef2f2;color:#dc2626">
            <iconify-icon icon="solar:shield-keyhole-bold"></iconify-icon>
        </div>
        <div class="usr-stat-info"><div class="usr-stat-num"><?php echo $totalAdmins; ?></div><div class="usr-stat-label">Administradores</div></div>
    </div>
    <div class="usr-stat-card">
        <div class="usr-stat-icon" style="background:#fff7ed;color:#d97706">
            <iconify-icon icon="solar:user-id-bold"></iconify-icon>
        </div>
        <div class="usr-stat-info"><div class="usr-stat-num"><?php echo $totalAbogados; ?></div><div class="usr-stat-label">Abogados</div></div>
    </div>
</div>

<!-- Users Grid -->
<div class="usr-grid">
    <?php foreach ($usuarios as $u):
        $rolColor = match($u['rol']) {
            'admin'   => '#dc2626',
            'abogado' => '#2563eb',
            'gestor'  => '#0891b2',
            default   => '#64748b'
        };
        $avatarBg = match($u['rol']) {
            'admin'   => 'linear-gradient(135deg, #dc2626, #b91c1c)',
            'abogado' => 'linear-gradient(135deg, #2563eb, #1d4ed8)',
            'gestor'  => 'linear-gradient(135deg, #0891b2, #0e7490)',
            default   => 'linear-gradient(135deg, #64748b, #475569)'
        };
        $rolLabel = match($u['rol']) {
            'admin'   => 'Administrador',
            'abogado' => 'Abogado',
            'gestor'  => 'Gestor / Recepcionista',
            default   => ucfirst($u['rol'])
        };
    ?>
    <div class="usr-card">
        <div class="usr-card-top">
            <div class="usr-avatar" style="background:<?php echo $avatarBg; ?>"><?php echo strtoupper(substr($u['nombre'],0,1).substr($u['apellidos'],0,1)); ?></div>
            <div>
                <div class="usr-name"><?php echo e($u['nombre'].' '.$u['apellidos']); ?></div>
                <div class="usr-email"><?php echo e($u['email']); ?></div>
            </div>
        </div>
        <div class="usr-meta">
            <div class="usr-meta-item">
                <label>Rol</label>
                <span style="color:<?php echo $rolColor; ?>"><?php echo $rolLabel; ?></span>
            </div>
            <div class="usr-meta-item">
                <label>Estado</label>
                <span style="color:<?php echo $u['activo'] ? '#059669' : '#dc2626'; ?>"><?php echo $u['activo'] ? 'Activo' : 'Inactivo'; ?></span>
            </div>
            <div class="usr-meta-item">
                <label>Último acceso</label>
                <span><?php echo $u['ultimo_login'] ? date('d/m/Y H:i', strtotime($u['ultimo_login'])) : 'Nunca'; ?></span>
            </div>
            <div class="usr-meta-item">
                <label>Creado</label>
                <span><?php echo date('d/m/Y', strtotime($u['created_at'])); ?></span>
            </div>
        </div>
        <div class="usr-actions">
            <a href="<?php echo APP_URL; ?>/index.php?page=usuarios/editar&id=<?php echo $u['id']; ?>" class="usr-btn usr-btn-edit">
                <iconify-icon icon="solar:pen-new-square-linear"></iconify-icon> Editar
            </a>
            <?php if ($u['id'] !== $usuario['id']): ?>
            <form method="POST" style="display:inline">
                <?php echo CSRF::campo(); ?>
                <input type="hidden" name="usuario_id" value="<?php echo $u['id']; ?>">
                <input type="hidden" name="toggle_activo" value="1">
                <button type="submit" class="usr-btn usr-btn-toggle <?php echo $u['activo'] ? '' : 'activate'; ?>"
                    data-confirm="<?php echo $u['activo'] ? '¿Desactivar' : '¿Activar'; ?> este usuario?">
                    <iconify-icon icon="<?php echo $u['activo'] ? 'solar:close-circle-linear' : 'solar:check-circle-linear'; ?>"></iconify-icon>
                    <?php echo $u['activo'] ? 'Desactivar' : 'Activar'; ?>
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Portal Clients Section -->
<div class="portal-section">
    <div class="card radius-16 border">
        <div class="card-body p-24">
            <div class="d-flex align-items-center justify-content-between mb-20">
                <div>
                    <h6 class="fw-semibold mb-4">Cuentas del Portal (Clientes)</h6>
                    <p class="text-sm text-secondary-light mb-0">Gestione las credenciales de acceso de los clientes al portal</p>
                </div>
                <span style="background:#eff6ff;color:#2563eb;padding:6px 14px;border-radius:10px;font-size:.75rem;font-weight:700"><?php echo count($portalCuentas); ?> cuentas</span>
            </div>
            <?php if (empty($portalCuentas)): ?>
            <p class="text-center text-secondary-light py-4">No hay cuentas de portal registradas</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="portal-table">
                    <thead><tr><th>Cliente</th><th>Email</th><th>Estado</th><th>Creada</th><th class="text-center">Acciones</th></tr></thead>
                    <tbody>
                        <?php foreach ($portalCuentas as $pc): ?>
                        <tr>
                            <td><span class="portal-name"><?php echo e(($pc['cliente_nombre'] ?? $pc['nombre']).' '.($pc['cliente_apellidos'] ?? $pc['apellidos'])); ?></span></td>
                            <td><span class="portal-email"><?php echo e($pc['email']); ?></span></td>
                            <td>
                                <span style="padding:4px 10px;border-radius:8px;font-size:.6875rem;font-weight:700;background:<?php echo $pc['activo'] ? '#f0fdf4' : '#fef2f2'; ?>;color:<?php echo $pc['activo'] ? '#059669' : '#dc2626'; ?>">
                                    <?php echo $pc['activo'] ? 'Activo' : 'Inactivo'; ?>
                                </span>
                            </td>
                            <td class="text-sm"><?php echo date('d/m/Y', strtotime($pc['created_at'])); ?></td>
                            <td class="text-center">
                                <div style="display:flex;align-items:center;justify-content:center;gap:6px;flex-wrap:wrap">
                                    <button type="button" class="usr-btn usr-btn-edit" onclick="openEditModal(<?php echo $pc['id']; ?>, '<?php echo e($pc['nombre']); ?>', '<?php echo e($pc['apellidos']); ?>', '<?php echo e($pc['email']); ?>', '<?php echo e($pc['telefono'] ?? ''); ?>', '<?php echo e($pc['dni_nif'] ?? ''); ?>', '<?php echo e($pc['direccion'] ?? ''); ?>', <?php echo $pc['activo'] ? 1 : 0; ?>)">
                                        <iconify-icon icon="solar:pen-linear"></iconify-icon> Editar
                                    </button>
                                    <button type="button" class="usr-btn usr-btn-edit" style="background:#fef2f2;color:#dc2626;border-color:#fecaca" onclick="openResetModal(<?php echo $pc['id']; ?>, '<?php echo e($pc['email']); ?>')">
                                        <iconify-icon icon="solar:key-linear"></iconify-icon> Contraseña
                                    </button>
                                    <button type="button" class="usr-btn" style="background:#fef2f2;color:#dc2626;border-color:#fecaca;padding:6px 12px" onclick="openDeleteModal(<?php echo $pc['id']; ?>, '<?php echo e($pc['nombre'].' '.$pc['apellidos']); ?>')">
                                        <iconify-icon icon="solar:trash-bin-trash-linear"></iconify-icon>
                                    </button>
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

<!-- Modal Reset Password -->
<div class="modal fade modal-reset" id="resetPasswordModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <form method="POST" class="modal-content">
            <?php echo CSRF::campo(); ?>
            <input type="hidden" name="reset_portal_password" value="1">
            <input type="hidden" name="portal_id" id="resetPortalId">
            <div class="modal-header">
                <h6 class="modal-title fw-semibold" style="font-size:.9375rem">Restablecer Contraseña</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-sm text-secondary-light mb-16">Cuenta: <strong id="resetEmailDisplay"></strong></p>
                <label style="font-size:.75rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:8px">Nueva Contraseña</label>
                <input type="password" name="nueva_password" class="form-control radius-12" style="height:48px" required minlength="8" placeholder="Mínimo 8 caracteres">
            </div>
            <div class="modal-footer d-flex justify-content-between">
                <button type="submit" name="send_reset_link" class="btn btn-sm btn-outline-primary radius-8 d-flex align-items-center gap-1">
                    <iconify-icon icon="solar:link-bold"></iconify-icon> Generar Link
                </button>
                <div>
                    <button type="button" class="btn btn-sm btn-outline-secondary radius-8" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-sm btn-primary radius-8">Forzar Cambio</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Modal Editar Cuenta Portal -->
<div class="modal fade" id="editPortalModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <?php echo CSRF::campo(); ?>
            <input type="hidden" name="edit_portal_cuenta" value="1">
            <input type="hidden" name="portal_id" id="editPortalId">
            <div class="modal-header">
                <h6 class="modal-title fw-semibold" style="font-size:.9375rem;display:flex;align-items:center;gap:8px">
                    <iconify-icon icon="solar:pen-linear"></iconify-icon>Editar Cuenta de Cliente
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold text-sm">Nombre <span class="text-danger">*</span></label>
                        <input type="text" name="edit_nombre" id="editNombre" class="form-control radius-12" style="height:44px" required placeholder="Nombre">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold text-sm">Apellidos <span class="text-danger">*</span></label>
                        <input type="text" name="edit_apellidos" id="editApellidos" class="form-control radius-12" style="height:44px" required placeholder="Apellidos">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold text-sm">Correo Electrónico <span class="text-danger">*</span></label>
                        <input type="email" name="edit_email" id="editEmail" class="form-control radius-12" style="height:44px" required placeholder="correo@ejemplo.com">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold text-sm">Teléfono <span class="text-secondary-light fw-normal">(opcional)</span></label>
                        <input type="tel" name="edit_telefono" id="editTelefono" class="form-control radius-12" style="height:44px" placeholder="+34 600 000 000" pattern="[\+0-9\s\-]+">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold text-sm">DNI / NIF</label>
                        <input type="text" name="edit_dni_nif" id="editDniNif" class="form-control radius-12" style="height:44px" placeholder="12345678A">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold text-sm">Dirección</label>
                        <input type="text" name="edit_direccion" id="editDireccion" class="form-control radius-12" style="height:44px" placeholder="Calle Ejemplo">
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="edit_activo" id="editActivo" style="width:42px;height:22px">
                            <label class="form-check-label fw-semibold text-sm" for="editActivo" style="margin-left:8px">Cuenta activa</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary radius-8" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-sm btn-primary radius-8" style="display:flex;align-items:center;gap:6px">
                    <iconify-icon icon="solar:floppy-disk-linear"></iconify-icon>Guardar Cambios
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Eliminar Cuenta Portal -->
<div class="modal fade" id="deletePortalModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <form method="POST" class="modal-content">
            <?php echo CSRF::campo(); ?>
            <input type="hidden" name="delete_portal_cuenta" value="1">
            <input type="hidden" name="portal_id" id="deletePortalId">
            <div class="modal-header" style="border-bottom:1px solid #fecaca">
                <h6 class="modal-title fw-semibold" style="font-size:.9375rem;color:#dc2626;display:flex;align-items:center;gap:8px">
                    <iconify-icon icon="solar:trash-bin-trash-linear"></iconify-icon>Eliminar Cuenta
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center" style="padding:28px 24px">
                <div style="width:64px;height:64px;border-radius:50%;background:#fef2f2;display:flex;align-items:center;justify-content:center;margin:0 auto 16px">
                    <iconify-icon icon="solar:danger-triangle-linear" style="font-size:28px;color:#dc2626"></iconify-icon>
                </div>
                <p class="fw-semibold mb-4" style="color:#1a1a2e">¿Eliminar esta cuenta?</p>
                <p class="text-sm text-secondary-light mb-0">Se eliminará la cuenta de <strong id="deletePortalName"></strong> y todas sus solicitudes. <span style="color:#dc2626;font-weight:600">Esta acción no se puede deshacer.</span></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary radius-8" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-sm btn-danger radius-8" style="display:flex;align-items:center;gap:6px">
                    <iconify-icon icon="solar:trash-bin-trash-linear"></iconify-icon>Eliminar
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openResetModal(id, email) {
    document.getElementById('resetPortalId').value = id;
    document.getElementById('resetEmailDisplay').textContent = email;
    new bootstrap.Modal(document.getElementById('resetPasswordModal')).show();
}
function openEditModal(id, nombre, apellidos, email, telefono, dni, direccion, activo) {
    document.getElementById('editPortalId').value = id;
    document.getElementById('editNombre').value = nombre;
    document.getElementById('editApellidos').value = apellidos;
    document.getElementById('editEmail').value = email;
    document.getElementById('editTelefono').value = telefono;
    document.getElementById('editDniNif').value = dni;
    document.getElementById('editDireccion').value = direccion;
    document.getElementById('editActivo').checked = activo === 1;
    new bootstrap.Modal(document.getElementById('editPortalModal')).show();
}
function openDeleteModal(id, nombre) {
    document.getElementById('deletePortalId').value = id;
    document.getElementById('deletePortalName').textContent = nombre;
    new bootstrap.Modal(document.getElementById('deletePortalModal')).show();
}
</script>

<?php include CRM_ROOT . '/templates/layout/footer.php'; ?>
