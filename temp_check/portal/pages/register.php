<?php
/**
 * Portal del Cliente — Registro
 * Crea cuenta en portal_cuentas (sin solicitud).
 * El cliente crea solicitudes desde el dashboard.
 */

$error = '';
$nombreDespacho = 'Mi Despacho de Abogados';
try {
    $nombreDespacho = $db->fetchColumn("SELECT valor FROM configuracion WHERE clave = 'nombre_despacho'") ?: $nombreDespacho;
} catch(Exception $e) {}

$crmUrl = APP_URL . '/portal/crm';

try {
    $db->query("ALTER TABLE portal_cuentas ADD COLUMN dni_nif VARCHAR(50) DEFAULT NULL, ADD COLUMN direccion TEXT DEFAULT NULL");
} catch(Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre    = trim($_POST['nombre'] ?? '');
    $apellidos = trim($_POST['apellidos'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $telefono  = trim($_POST['telefono'] ?? '');
    $dni_nif   = trim($_POST['dni_nif'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');
    $password  = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';

    if (empty($nombre) || empty($apellidos) || empty($email) || empty($password) || empty($dni_nif) || empty($direccion)) {
        $error = 'Nombre, apellidos, DNI/NIF, dirección, correo y contraseña son obligatorios.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'El correo electrónico no es válido.';
    } elseif (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $error = 'La contraseña debe tener al menos 8 caracteres, incluir mayúsculas, minúsculas y números.';
    } elseif ($password !== $password2) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        $existe = $db->fetchOne("SELECT id FROM portal_cuentas WHERE email = ?", [$email]);
        if ($existe) {
            $error = 'Ya existe una cuenta con este correo. <a href="index.php?page=login" style="color:#2e6edd;font-weight:600">Iniciar sesión</a>';
        } else {
            $nuevoId = $db->insert('portal_cuentas', [
                'nombre'        => $nombre,
                'apellidos'     => $apellidos,
                'email'         => $email,
                'telefono'      => $telefono ?: null,
                'dni_nif'       => $dni_nif,
                'direccion'     => $direccion,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'ip_registro'   => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);

            // Auto-login
            session_regenerate_id(true);
            $_SESSION['portal_id']        = $nuevoId;
            $_SESSION['portal_nombre']    = $nombre;
            $_SESSION['portal_apellidos'] = $apellidos;
            // Enviar correo de bienvenida si está habilitado
            $notifRegistro = $db->fetchColumn("SELECT valor FROM configuracion WHERE clave = 'email_notif_registro'") ?? '1';
            if ($notifRegistro === '1') {
                require_once dirname(__DIR__, 2) . '/crm/includes/Mailer.php';
                Mailer::bienvenidaCliente($email, $nombre, portalUrl());
            }

            header('Location: ' . portalUrl() . '/index.php?page=dashboard&welcome=1');
            exit;
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
    <title>Crear Cuenta — <?php echo e($nombreDespacho); ?></title>
    <link rel="icon" type="image/png" href="crm/assets/images/logo.png?v=2">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; min-height: 100vh; background: #0f172a; }

        .rp { display: grid; grid-template-columns: 1fr 1fr; min-height: 100vh; }
        @media (max-width: 991px) { .rp { grid-template-columns: 1fr; } .rp-visual { display: none; } }

        .rp-visual { position: relative; overflow: hidden; }
        .rp-visual > img { width: 100%; height: 100%; object-fit: cover; filter: brightness(0.35); }
        .rp-visual-overlay { position: absolute; inset: 0; display: flex; flex-direction: column; justify-content: space-between; padding: 48px; color: #fff; }
        .rp-logo { display: flex; align-items: center; gap: 12px; }
        .rp-logo img { width: 44px; height: 44px; object-fit: contain; filter: drop-shadow(0 2px 8px rgba(0,0,0,.3)); }
        .rp-logo span { font-size: 1.125rem; font-weight: 700; }
        .rp-hero h2 { font-size: 2.5rem; font-weight: 900; line-height: 1.15; letter-spacing: -.03em; margin-bottom: 16px; }
        .rp-hero h2 em { color: #6ba3ff; font-style: normal; }
        .rp-hero p { color: rgba(255,255,255,.6); font-size: 1rem; line-height: 1.6; max-width: 420px; }
        .rp-steps { display: flex; flex-direction: column; gap: 16px; }
        .rp-step { display: flex; align-items: flex-start; gap: 14px; }
        .rp-step-n { width: 32px; height: 32px; background: rgba(46,110,221,.4); border: 1px solid rgba(46,110,221,.6); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: .8125rem; font-weight: 700; color: #6ba3ff; flex-shrink: 0; }
        .rp-step strong { display: block; font-size: .875rem; font-weight: 700; margin-bottom: 2px; }
        .rp-step span { font-size: .8125rem; color: rgba(255,255,255,.5); }

        .rp-form { display: flex; flex-direction: column; justify-content: center; padding: 60px; background: #fff; color: #1a1a2e; }
        @media (max-width: 991px) { .rp-form { padding: 40px 24px; min-height: 100vh; } }
        .rp-inner { max-width: 420px; width: 100%; margin: 0 auto; }
        .badge-new { display: inline-flex; align-items: center; gap: 6px; background: #e8f0fe; color: #2e6edd; font-size: .75rem; font-weight: 700; padding: 6px 14px; border-radius: 99px; margin-bottom: 28px; letter-spacing: .04em; text-transform: uppercase; }
        .rp-inner h1 { font-size: 2rem; font-weight: 800; letter-spacing: -.03em; margin-bottom: 8px; }
        .rp-inner .sub { color: #64748b; font-size: .9375rem; line-height: 1.5; margin-bottom: 32px; }

        .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        @media (max-width: 480px) { .row2 { grid-template-columns: 1fr; } }
        .fld { margin-bottom: 16px; }
        .fld label { display: block; font-size: .8125rem; font-weight: 600; color: #374151; margin-bottom: 5px; }
        .fld label .r { color: #dc2626; }
        .fi { position: relative; }
        .fi > svg { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #94a3b8; pointer-events: none; display: block; }
        .fi input { width: 100%; padding: 13px 14px 13px 44px; border: 2px solid #e2e8f0; border-radius: 14px; font-size: .9375rem; font-weight: 500; color: #1a1a2e; background: #f8fafc; transition: all .2s; outline: none; font-family: 'Inter', sans-serif; }
        .fi input:focus { border-color: #2e6edd; box-shadow: 0 0 0 4px rgba(46,110,221,.1); background: #fff; }
        .fi input::placeholder { color: #94a3b8; font-weight: 400; }
        .fi input.pw { padding-right: 44px; }
        .fi .eye { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #94a3b8; display: flex; align-items: center; justify-content: center; line-height: 0; z-index: 2; }
        .fi .eye svg { position: static; transform: none; pointer-events: none; }
        .fi .eye:hover { color: #2e6edd; }

        .btn-submit { width: 100%; padding: 14px; background: #2e6edd; color: #fff !important; border: none; border-radius: 14px; font-size: .9375rem; font-weight: 700; cursor: pointer; transition: all .25s; font-family: 'Inter', sans-serif; margin-top: 8px; }
        .btn-submit:hover { background: #1e52ab; box-shadow: 0 12px 32px rgba(46,110,221,.25); transform: translateY(-2px); }
        .btn-submit:active { transform: translateY(0); }

        .err { padding: 12px 16px; border-radius: 12px; background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; font-size: .8125rem; font-weight: 500; margin-bottom: 20px; }
        .foot { margin-top: 24px; text-align: center; font-size: .8125rem; color: #94a3b8; }
        .foot a { color: #2e6edd; font-weight: 600; text-decoration: none; }
        .foot a:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="rp">
    <div class="rp-visual">
        <img src="crm/assets/images/steptodown.com552020.jpg?v=2" alt="">
        <div class="rp-visual-overlay">
            <div class="rp-logo">
                <img src="crm/assets/images/logo.png?v=2" alt="Logo">
                <span><?php echo e($nombreDespacho); ?></span>
            </div>
            <div>
                <div class="rp-hero" style="margin-bottom: 48px;">
                    <h2>Comience hoy,<br><em>sin compromiso.</em></h2>
                    <p>Cree su cuenta en segundos y acceda al portal para enviar su primera consulta legal.</p>
                </div>
                <div class="rp-steps">
                    <div class="rp-step"><div class="rp-step-n">1</div><div><strong>Cree su cuenta</strong><span>Solo datos personales y contraseña</span></div></div>
                    <div class="rp-step"><div class="rp-step-n">2</div><div><strong>Inicie sesión</strong><span>Acceda a su panel privado</span></div></div>
                    <div class="rp-step"><div class="rp-step-n">3</div><div><strong>Envíe su consulta</strong><span>Describa su caso y le asignamos un abogado</span></div></div>
                </div>
            </div>
        </div>
    </div>

    <div class="rp-form">
        <div class="rp-inner">
            <div class="badge-new">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
                Nuevo Cliente
            </div>
            <h1>Crear Cuenta</h1>
            <p class="sub">Rellene sus datos. Podrá enviar su consulta una vez dentro del portal.</p>

            <?php if ($error): ?>
            <div class="err"><?php echo $error; ?></div>
            <?php endif; ?>

            <form method="POST" action="<?php echo portalUrl(); ?>/index.php?page=register">
                <div class="row2">
                    <div class="fld"><label>Nombre <span class="r">*</span></label><div class="fi"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg><input type="text" name="nombre" placeholder="Su nombre" value="<?php echo e($_POST['nombre'] ?? ''); ?>" required></div></div>
                    <div class="fld"><label>Apellidos <span class="r">*</span></label><div class="fi"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg><input type="text" name="apellidos" placeholder="Sus apellidos" value="<?php echo e($_POST['apellidos'] ?? ''); ?>" required></div></div>
                </div>
                <div class="row2">
                    <div class="fld"><label>Correo Electrónico <span class="r">*</span></label><div class="fi"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg><input type="email" name="email" placeholder="correo@ejemplo.com" value="<?php echo e($_POST['email'] ?? ''); ?>" required></div></div>
                    <div class="fld"><label>Teléfono <span style="color:#94a3b8;font-weight:400">(opcional)</span></label><div class="fi"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg><input type="tel" name="telefono" placeholder="+34 600 000 000" value="<?php echo e($_POST['telefono'] ?? ''); ?>" pattern="[\+0-9\s\-]+"></div></div>
                </div>
                <div class="row2">
                    <div class="fld"><label>DNI / NIF <span class="r">*</span></label><div class="fi"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg><input type="text" name="dni_nif" placeholder="12345678A" value="<?php echo e($_POST['dni_nif'] ?? ''); ?>" required minlength="5"></div></div>
                    <div class="fld"><label>Dirección <span class="r">*</span></label><div class="fi"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg><input type="text" name="direccion" placeholder="Calle Ejemplo, Ciudad" value="<?php echo e($_POST['direccion'] ?? ''); ?>" required minlength="5"></div></div>
                </div>
                <div class="row2">
                    <div class="fld"><label>Contraseña <span class="r">*</span></label><div class="fi"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg><input type="password" name="password" id="pw1" placeholder="Mín. 8 caracteres" class="pw" minlength="8" pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}" title="Debe contener al menos 8 caracteres, incluyendo una mayúscula, una minúscula y un número" required><span class="eye" onclick="toggleVis('pw1')"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></span></div></div>
                    <div class="fld"><label>Confirmar <span class="r">*</span></label><div class="fi"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg><input type="password" name="password2" id="pw2" placeholder="Repita" class="pw" required minlength="8" pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}" title="Debe contener al menos 8 caracteres, incluyendo una mayúscula, una minúscula y un número"><span class="eye" onclick="toggleVis('pw2')"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></span></div></div>
                </div>
                <button type="submit" class="btn-submit">Crear mi Cuenta</button>
            </form>
            <div class="foot">
                <p>¿Ya tiene cuenta? <a href="index.php?page=login">Iniciar Sesión</a></p>
                <p style="margin-top:8px"><a href="/">← Volver al sitio principal</a></p>
            </div>
        </div>
    </div>
</div>
<script>function toggleVis(id){var i=document.getElementById(id);i.type=i.type==='password'?'text':'password'}</script>
<?php echo portalPwaScript(); ?>
</body>
</html>
