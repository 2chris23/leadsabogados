<?php
/**
 * CRM Abogados - Gestión de Permisos por Rol
 * Solo accesible para admin
 */
RoleGuard::requireRole('admin');

$db = Database::getInstance();

// ── Crear tabla si no existe y poblar valores por defecto ──
try {
    $db->query("CREATE TABLE IF NOT EXISTS role_permisos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rol ENUM('admin','abogado','gestor') NOT NULL,
        permiso VARCHAR(100) NOT NULL,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        UNIQUE KEY uk_rol_permiso (rol, permiso)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (\Throwable $e) {
    error_log("Error al crear tabla role_permisos: " . $e->getMessage());
}

// Permisos disponibles agrupados por módulo
$PERMISOS_DEFINIDOS = [
    'Solicitudes' => [
        'solicitudes.ver'            => 'Ver listado y detalle',
        'solicitudes.crear'          => 'Crear nuevas solicitudes',
        'solicitudes.editar'         => 'Editar datos de una solicitud',
        'solicitudes.eliminar'       => 'Eliminar solicitudes',
        'solicitudes.cambiar_estado' => 'Cambiar estado (aceptar, denegar, archivar)',
    ],
    'Clientes' => [
        'clientes.ver'     => 'Ver listado y ficha de cliente',
        'clientes.crear'   => 'Crear nuevos clientes',
        'clientes.editar'  => 'Editar datos del cliente',
        'clientes.eliminar'=> 'Eliminar clientes',
    ],
    'Casos' => [
        'casos.ver'     => 'Ver casos',
        'casos.crear'   => 'Abrir nuevos casos',
        'casos.editar'  => 'Editar casos',
        'casos.eliminar'=> 'Eliminar casos',
    ],
    'Pagos' => [
        'pagos.ver'      => 'Ver pagos',
        'pagos.registrar'=> 'Registrar nuevos pagos',
        'pagos.eliminar' => 'Eliminar pagos',
    ],
    'Usuarios' => [
        'usuarios.ver'      => 'Ver listado de usuarios del portal',
        'usuarios.gestionar'=> 'Crear, editar y desactivar usuarios',
    ],
    'Auditoría' => [
        'auditoria.ver' => 'Ver log de auditoría',
    ],
    'Configuración' => [
        'configuracion.ver'   => 'Ver configuración del sistema',
        'configuracion.editar'=> 'Editar configuración',
    ],
];

$ROLES = ['abogado', 'gestor'];

// Insertar permisos por defecto si no existen
$defaultsAbogado = ['solicitudes.ver', 'clientes.ver', 'clientes.editar', 'casos.ver', 'casos.crear', 'casos.editar', 'pagos.ver', 'pagos.registrar'];
$defaultsGestor  = ['solicitudes.ver', 'solicitudes.crear', 'solicitudes.editar', 'solicitudes.cambiar_estado', 'clientes.ver', 'usuarios.ver'];

foreach ($ROLES as $rol) {
    $defaults = $rol === 'abogado' ? $defaultsAbogado : $defaultsGestor;
    foreach ($PERMISOS_DEFINIDOS as $grupo => $permisos) {
        foreach (array_keys($permisos) as $clave) {
            $activoDefault = in_array($clave, $defaults) ? 1 : 0;
            try {
                $db->query(
                    "INSERT IGNORE INTO role_permisos (rol, permiso, activo) VALUES (?, ?, ?)",
                    [$rol, $clave, $activoDefault]
                );
            } catch (\Throwable $e) {}
        }
    }
}

// ── Guardar cambios (AJAX o POST normal) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_permisos'])) {
    CSRF::verificarOAbortar();

    foreach ($ROLES as $rol) {
        foreach ($PERMISOS_DEFINIDOS as $grupo => $permisos) {
            foreach (array_keys($permisos) as $clave) {
                $campo  = $rol . '__' . str_replace('.', '_', $clave);
                $activo = isset($_POST[$campo]) ? 1 : 0;
                $db->query(
                    "UPDATE role_permisos SET activo = ? WHERE rol = ? AND permiso = ?",
                    [$activo, $rol, $clave]
                );
            }
        }
    }

    // Borrar caché de permisos de todas las sesiones activas no es trivial,
    // pero al menos limpiamos la del admin actual
    RoleGuard::clearPermissionCache();

    setFlash('exito', 'Permisos actualizados correctamente. Los cambios se aplican en el próximo acceso de cada usuario.');
    header('Location: ' . APP_URL . '/index.php?page=permisos'); exit;
}

// Cargar permisos actuales
$permisosActuales = [];
$setupError = '';
try {
    $rows = $db->fetchAll("SELECT rol, permiso, activo FROM role_permisos");
    foreach ($rows as $row) {
        $permisosActuales[$row['rol']][$row['permiso']] = (bool)$row['activo'];
    }
} catch (\Throwable $e) {
    $setupError = "Error en base de datos: " . $e->getMessage();
}

$tituloPagina = 'Permisos de Roles';
include CRM_ROOT . '/templates/layout/header.php';
?>

<style>
.perm-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    overflow: hidden;
    margin-bottom: 24px;
}
.perm-card-header {
    padding: 16px 24px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    gap: 10px;
}
.perm-card-header h6 {
    margin: 0;
    font-size: .9375rem;
    font-weight: 700;
    color: #1a1a2e;
}
.perm-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}
.perm-table th {
    padding: 12px 20px;
    font-size: .6875rem;
    font-weight: 700;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: .07em;
    border-bottom: 2px solid #f1f5f9;
    text-align: left;
    background: #fafbfc;
}
.perm-table th.role-col {
    text-align: center;
    min-width: 120px;
}
.perm-table td {
    padding: 14px 20px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}
.perm-table tr:last-child td { border-bottom: none; }
.perm-table tr:hover td { background: #fafbfc; }
.perm-name { font-size: .875rem; font-weight: 600; color: #334155; }
.perm-desc { font-size: .75rem; color: #94a3b8; margin-top: 2px; }
.perm-toggle-cell { text-align: center; }

/* Toggle switch */
.toggle-wrap { display: inline-flex; align-items: center; justify-content: center; }
.toggle-input { display: none; }
.toggle-label {
    width: 44px; height: 24px;
    background: #e2e8f0;
    border-radius: 12px;
    cursor: pointer;
    position: relative;
    transition: background .2s;
}
.toggle-label::after {
    content: '';
    width: 18px; height: 18px;
    background: #fff;
    border-radius: 50%;
    position: absolute;
    top: 3px; left: 3px;
    transition: transform .2s;
    box-shadow: 0 1px 4px rgba(0,0,0,.15);
}
.toggle-input:checked + .toggle-label { background: #6366f1; }
.toggle-input:checked + .toggle-label::after { transform: translateX(20px); }

.admin-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: #fef9c3;
    color: #92400e;
    border-radius: 8px;
    padding: 4px 12px;
    font-size: .75rem;
    font-weight: 700;
}

.role-header-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: .8125rem;
    font-weight: 700;
    color: #1a1a2e;
}
.role-dot {
    width: 10px; height: 10px;
    border-radius: 50%;
}
.role-dot.abogado { background: #6366f1; }
.role-dot.gestor  { background: #f59e0b; }
</style>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
        <h6 class="fw-semibold mb-4" style="font-size:1.125rem">Permisos de Roles</h6>
        <p class="text-secondary-light mb-0" style="font-size:.875rem">
            Configura qué puede hacer cada rol. El rol <strong>Administrador</strong> siempre tiene acceso total.
        </p>
    </div>
    <div class="admin-badge">
        <iconify-icon icon="solar:shield-check-outline"></iconify-icon>
        Admin: acceso total garantizado
    </div>
</div>

<?php if ($setupError): ?>
<div class="alert alert-danger d-flex align-items-center gap-2 mb-24 radius-12" role="alert">
    <iconify-icon icon="solar:danger-triangle-outline"></iconify-icon>
    <?php echo htmlspecialchars($setupError); ?>
</div>
<?php endif; ?>

<?php
$flash = getFlash();
if ($flash):
?>
<div class="alert alert-<?php echo $flash['tipo'] === 'exito' ? 'success' : 'danger'; ?> d-flex align-items-center gap-2 mb-24 radius-12" role="alert">
    <iconify-icon icon="<?php echo $flash['tipo'] === 'exito' ? 'solar:check-circle-outline' : 'solar:danger-triangle-outline'; ?>"></iconify-icon>
    <?php echo htmlspecialchars($flash['mensaje']); ?>
</div>
<?php endif; ?>

<form method="POST">
    <?php echo CSRF::campo(); ?>
    <input type="hidden" name="guardar_permisos" value="1">

    <?php foreach ($PERMISOS_DEFINIDOS as $grupo => $permisos): ?>
    <div class="perm-card">
        <div class="perm-card-header">
            <iconify-icon icon="solar:shield-keyhole-outline" style="font-size:18px;color:#6366f1"></iconify-icon>
            <h6><?php echo htmlspecialchars($grupo); ?></h6>
        </div>
        <table class="perm-table">
            <thead>
                <tr>
                    <th style="width:50%">Permiso</th>
                    <th class="role-col">
                        <span class="role-header-badge">
                            <span class="role-dot abogado"></span>Abogado
                        </span>
                    </th>
                    <th class="role-col">
                        <span class="role-header-badge">
                            <span class="role-dot gestor"></span>Gestor
                        </span>
                    </th>
                    <th class="role-col">
                        <span class="role-header-badge">
                            <iconify-icon icon="solar:crown-outline" style="color:#f59e0b"></iconify-icon>Admin
                        </span>
                    </th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($permisos as $clave => $descripcion):
                    $campoAbogado = 'abogado__' . str_replace('.', '_', $clave);
                    $campoGestor  = 'gestor__'  . str_replace('.', '_', $clave);
                    $activoAbogado = $permisosActuales['abogado'][$clave] ?? false;
                    $activoGestor  = $permisosActuales['gestor'][$clave]  ?? false;
                    $idAbogado = 'perm_' . str_replace(['.'], '_', $clave) . '_abogado';
                    $idGestor  = 'perm_' . str_replace(['.'], '_', $clave) . '_gestor';
                ?>
                <tr>
                    <td>
                        <div class="perm-name"><?php echo htmlspecialchars($descripcion); ?></div>
                        <div class="perm-desc"><code style="font-size:.7rem;color:#6366f1"><?php echo htmlspecialchars($clave); ?></code></div>
                    </td>
                    <td class="perm-toggle-cell">
                        <div class="toggle-wrap">
                            <input type="checkbox" class="toggle-input" id="<?php echo $idAbogado; ?>"
                                name="<?php echo $campoAbogado; ?>"
                                <?php echo $activoAbogado ? 'checked' : ''; ?>>
                            <label class="toggle-label" for="<?php echo $idAbogado; ?>"></label>
                        </div>
                    </td>
                    <td class="perm-toggle-cell">
                        <div class="toggle-wrap">
                            <input type="checkbox" class="toggle-input" id="<?php echo $idGestor; ?>"
                                name="<?php echo $campoGestor; ?>"
                                <?php echo $activoGestor ? 'checked' : ''; ?>>
                            <label class="toggle-label" for="<?php echo $idGestor; ?>"></label>
                        </div>
                    </td>
                    <td class="perm-toggle-cell">
                        <!-- Admin siempre activo -->
                        <div class="toggle-wrap">
                            <span class="badge bg-success-focus text-success-main px-8 py-4 radius-8" style="font-size:.7rem">
                                <iconify-icon icon="solar:lock-keyhole-bold"></iconify-icon> Siempre
                            </span>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>

    <div class="d-flex justify-content-end gap-3 mb-32">
        <a href="<?php echo APP_URL; ?>/index.php?page=usuarios" class="btn btn-outline-secondary radius-12 px-24">
            Cancelar
        </a>
        <button type="submit" class="btn btn-primary radius-12 px-32 d-flex align-items-center gap-2">
            <iconify-icon icon="solar:floppy-disk-linear"></iconify-icon>
            Guardar Permisos
        </button>
    </div>
</form>

<?php include CRM_ROOT . '/templates/layout/footer.php'; ?>
