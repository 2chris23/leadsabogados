<?php
/**
 * Portal del Cliente — Mi Perfil
 * Permite al cliente actualizar sus datos (dirección, DNI, teléfono, contraseña)
 */
$portalId = $_SESSION['portal_id'];
$nombreDespacho = 'Mi Despacho de Abogados';
try {
    $nombreDespacho = $db->fetchColumn("SELECT valor FROM configuracion WHERE clave = 'nombre_despacho'") ?: $nombreDespacho;
} catch(Exception $e) {}

$crmUrl = APP_URL . '/portal/crm';

$error = '';
$exito = '';

// Obtener datos actuales
$cuenta = $db->fetchOne("SELECT * FROM portal_cuentas WHERE id = ?", [$portalId]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre'] ?? '');
    $apellidos = trim($_POST['apellidos'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $dni_nif = trim($_POST['dni_nif'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');
    $password = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';
    $current_password = $_POST['current_password'] ?? '';

    if (empty($nombre) || empty($apellidos) || empty($dni_nif) || empty($direccion)) {
        $error = 'Nombre, apellidos, DNI/NIF y dirección son obligatorios.';
    } else {
        $updates = [
            'nombre' => $nombre,
            'apellidos' => $apellidos,
            'telefono' => $telefono ?: null,
            'dni_nif' => $dni_nif,
            'direccion' => $direccion
        ];

        $cambiarPassword = false;
        if (!empty($password) || !empty($password2) || !empty($current_password)) {
            if (empty($current_password)) {
                $error = 'Debe ingresar su contraseña actual para cambiarla.';
            } elseif (!password_verify($current_password, $cuenta['password_hash'])) {
                $error = 'La contraseña actual es incorrecta.';
            } elseif (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
                $error = 'La nueva contraseña debe tener al menos 8 caracteres, incluir mayúsculas, minúsculas y números.';
            } elseif ($password !== $password2) {
                $error = 'Las contraseñas nuevas no coinciden.';
            } else {
                $updates['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                $cambiarPassword = true;
            }
        }

        if (!$error) {
            $db->update('portal_cuentas', $updates, 'id = ?', [$portalId]);
            
            $_SESSION['portal_nombre'] = $nombre;
            $_SESSION['portal_apellidos'] = $apellidos;
            
            // Actualizar variables locales para que la vista se refleje de inmediato
            $cuenta['nombre'] = $nombre;
            $cuenta['apellidos'] = $apellidos;
            $cuenta['telefono'] = $telefono;
            $cuenta['dni_nif'] = $dni_nif;
            $cuenta['direccion'] = $direccion;

            $exito = 'Perfil actualizado correctamente.' . ($cambiarPassword ? ' Contraseña cambiada.' : '');
            
            // También deberíamos actualizar los datos en el CRM si es cliente
            if ($cuenta['es_cliente'] && $cuenta['cliente_id']) {
                $db->update('clientes', [
                    'nombre' => $nombre,
                    'apellidos' => $apellidos,
                    'telefono' => $telefono ?: null,
                    'dni_nif' => $dni_nif,
                    'direccion' => $direccion
                ], 'id = ?', [$cuenta['cliente_id']]);
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo portalPwaHead(); ?>
    <title>Mi Perfil — <?php echo e($nombreDespacho); ?></title>
    <link rel="icon" type="image/png" href="crm/assets/images/logo.png?v=2">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: #f8fafc; color: #1e293b; line-height: 1.5; min-height: 100vh; }
        
        /* Topbar */
        .topbar { background: #fff; height: 70px; display: flex; align-items: center; justify-content: space-between; padding: 0 32px; border-bottom: 1px solid #e2e8f0; position: sticky; top: 0; z-index: 100; }
        .topbar-logo { display: flex; align-items: center; gap: 12px; text-decoration: none; }
        .topbar-logo img { height: 32px; width: auto; }
        .topbar-logo span { font-size: 1.125rem; font-weight: 800; color: #0f172a; letter-spacing: -0.02em; }
        .topbar-right { display: flex; align-items: center; gap: 20px; }
        
        .topbar-avatar { width: 36px; height: 36px; border-radius: 50%; background: #2e6edd; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1rem; }
        .topbar-info { display: flex; flex-direction: column; }
        .topbar-name { font-size: 0.875rem; font-weight: 600; color: #1e293b; }
        .topbar-role { font-size: 0.75rem; color: #64748b; font-weight: 500; }
        
        .btn-logout { font-size: 0.8125rem; font-weight: 600; color: #ef4444; text-decoration: none; padding: 6px 12px; border-radius: 8px; transition: background 0.2s; }
        .btn-logout:hover { background: #fef2f2; }

        .btn-nav { font-size: 0.8125rem; font-weight: 600; color: #64748b; text-decoration: none; padding: 6px 12px; border-radius: 8px; transition: all 0.2s; }
        .btn-nav:hover { background: #f1f5f9; color: #0f172a; }

        /* Main */
        .main { max-width: 800px; margin: 40px auto; padding: 0 20px; }
        
        h1 { font-size: 1.75rem; font-weight: 800; color: #0f172a; letter-spacing: -0.02em; margin-bottom: 8px; }
        p.sub { font-size: 0.9375rem; color: #64748b; margin-bottom: 32px; }

        .card { background: #fff; border-radius: 20px; border: 1px solid #e2e8f0; padding: 32px; box-shadow: 0 4px 6px -1px rgba(0,0,0,.05); margin-bottom: 24px; }
        .card-title { font-size: 1.125rem; font-weight: 700; color: #0f172a; margin-bottom: 24px; display: flex; align-items: center; gap: 8px; }
        .card-title svg { color: #64748b; }

        .err { background: #fef2f2; color: #dc2626; padding: 14px 18px; border-radius: 12px; font-size: 0.875rem; font-weight: 500; margin-bottom: 24px; border: 1px solid #fecaca; }
        .success { background: #ecfdf5; color: #059669; padding: 14px 18px; border-radius: 12px; font-size: 0.875rem; font-weight: 500; margin-bottom: 24px; border: 1px solid #a7f3d0; }

        .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        @media (max-width: 640px) { .row2 { grid-template-columns: 1fr; } }
        
        .fld { margin-bottom: 20px; }
        .fld label { display: block; font-size: 0.8125rem; font-weight: 600; color: #334155; margin-bottom: 8px; }
        .fld label span.r { color: #ef4444; }
        
        .fi { position: relative; }
        .fi svg { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #94a3b8; pointer-events: none; }
        .fi input { width: 100%; height: 48px; background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 0 16px 0 44px; font-family: inherit; font-size: 0.9375rem; color: #1e293b; outline: none; transition: all 0.2s; }
        .fi input[readonly] { background: #f1f5f9; color: #64748b; cursor: not-allowed; border-color: #e2e8f0; }
        .fi input:not([readonly]):focus { background: #fff; border-color: #2e6edd; box-shadow: 0 0 0 4px rgba(46,110,221,0.1); }
        .fi .eye { position: absolute; right: 16px; top: 50%; transform: translateY(-50%); color: #94a3b8; cursor: pointer; pointer-events: auto; display: flex; }
        .fi .eye:hover { color: #64748b; }
        .fi .eye svg { position: static; transform: none; }
        
        .btn-submit { width: auto; padding: 0 32px; height: 48px; background: #2e6edd; color: #fff; border: none; border-radius: 12px; font-family: inherit; font-size: 0.9375rem; font-weight: 600; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center; gap: 8px; }
        .btn-submit:hover { background: #1e52ab; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(46,110,221,0.2); }

        .separator { display: flex; align-items: center; gap: 12px; margin: 32px 0; font-size: 0.75rem; color: #94a3b8; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }
        .separator::before, .separator::after { content: ''; flex: 1; height: 1px; background: #e2e8f0; }

        @media (max-width: 640px) {
            .topbar { padding: 0 16px; }
            .main { padding: 0 16px; margin: 24px auto; }
            .topbar-info { display: none; }
            .card { padding: 24px; }
        }
    </style>
</head>
<body>

<div class="topbar">
    <a href="index.php?page=dashboard" class="topbar-logo">
        <img src="crm/assets/images/logo.png?v=2" alt="Logo">
        <span style="display:none" class="d-sm-inline">Portal del Cliente</span>
    </a>
    <div class="topbar-right">
        <a href="index.php?page=dashboard" class="btn-nav">← Volver al Dashboard</a>
        <div class="topbar-avatar"><?php echo strtoupper(substr($cuenta['nombre'], 0, 1)); ?></div>
        <div class="topbar-info">
            <div class="topbar-name"><?php echo e($cuenta['nombre'] . ' ' . $cuenta['apellidos']); ?></div>
            <div class="topbar-role"><?php echo $cuenta['es_cliente'] ? 'Cliente' : 'Visitante'; ?></div>
        </div>
        <a href="index.php?page=logout" class="btn-logout">Salir</a>
    </div>
</div>

<div class="main">
    <h1>Mi Perfil</h1>
    <p class="sub">Actualice sus datos personales o cambie su contraseña de acceso al portal.</p>

    <?php if ($error): ?>
    <div class="err"><?php echo $error; ?></div>
    <?php endif; ?>
    <?php if ($exito): ?>
    <div class="success"><?php echo $exito; ?></div>
    <?php endif; ?>

    <form method="POST" action="index.php?page=perfil">
        <div class="card">
            <div class="card-title">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Datos Personales
            </div>
            
            <div class="row2">
                <div class="fld">
                    <label>Nombre <span class="r">*</span></label>
                    <div class="fi">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <input type="text" name="nombre" value="<?php echo e($cuenta['nombre']); ?>" required>
                    </div>
                </div>
                <div class="fld">
                    <label>Apellidos <span class="r">*</span></label>
                    <div class="fi">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <input type="text" name="apellidos" value="<?php echo e($cuenta['apellidos']); ?>" required>
                    </div>
                </div>
            </div>

            <div class="row2">
                <div class="fld">
                    <label>Correo Electrónico (Solo Lectura)</label>
                    <div class="fi">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                        <input type="email" value="<?php echo e($cuenta['email']); ?>" readonly title="Para cambiar su correo, póngase en contacto con el despacho">
                    </div>
                </div>
                <div class="fld">
                    <label>Teléfono</label>
                    <div class="fi">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                        <input type="tel" name="telefono" value="<?php echo e($cuenta['telefono']); ?>" placeholder="+34 600 000 000">
                    </div>
                </div>
            </div>

            <div class="row2">
                <div class="fld">
                    <label>DNI / NIF <span class="r">*</span></label>
                    <div class="fi">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <input type="text" name="dni_nif" value="<?php echo e($cuenta['dni_nif']); ?>" required minlength="5">
                    </div>
                </div>
                <div class="fld">
                    <label>Dirección <span class="r">*</span></label>
                    <div class="fi">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        <input type="text" name="direccion" value="<?php echo e($cuenta['direccion']); ?>" required minlength="5">
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-title">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                Cambiar Contraseña
            </div>
            <p style="font-size:0.875rem; color:#64748b; margin-bottom:20px;">Deje estos campos en blanco si no desea cambiar su contraseña.</p>

            <div class="fld">
                <label>Contraseña Actual</label>
                <div class="fi">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    <input type="password" name="current_password" id="pw0" placeholder="Su contraseña actual">
                    <span class="eye" onclick="toggleVis('pw0')">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </span>
                </div>
            </div>

            <div class="row2">
                <div class="fld">
                    <label>Nueva Contraseña</label>
                    <div class="fi">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <input type="password" name="password" id="pw1" placeholder="Mín. 8 caracteres" pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}" title="Debe contener al menos 8 caracteres, incluyendo una mayúscula, una minúscula y un número">
                        <span class="eye" onclick="toggleVis('pw1')">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </span>
                    </div>
                </div>
                <div class="fld">
                    <label>Confirmar Nueva</label>
                    <div class="fi">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <input type="password" name="password2" id="pw2" placeholder="Repita nueva contraseña" pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}" title="Debe contener al menos 8 caracteres, incluyendo una mayúscula, una minúscula y un número">
                        <span class="eye" onclick="toggleVis('pw2')">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div style="text-align: right;">
            <button type="submit" class="btn-submit">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                Guardar Cambios
            </button>
        </div>
    </form>
</div>

<script>
function toggleVis(id){
    var i=document.getElementById(id);
    i.type=i.type==='password'?'text':'password';
}
</script>
<?php echo portalPwaScript(); ?>
</body>
</html>
