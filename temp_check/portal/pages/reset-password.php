<?php
/**
 * Portal del Cliente — Resetear Contraseña (usando token)
 */
$error = '';
$exito = '';
$token = $_GET['token'] ?? '';
$nombreDespacho = 'Mi Despacho de Abogados';
try {
    $nombreDespacho = $db->fetchColumn("SELECT valor FROM configuracion WHERE clave = 'nombre_despacho'") ?: $nombreDespacho;
} catch(Exception $e) {}

$crmUrl = APP_URL . '/portal/crm';

if (empty($token)) {
    header("Location: index.php?page=login");
    exit;
}

// Verificar token
$cuenta = $db->fetchOne("SELECT id, reset_expires FROM portal_cuentas WHERE reset_token = ?", [$token]);

if (!$cuenta) {
    $error = 'El enlace de recuperación no es válido o ya ha sido utilizado.';
} elseif (strtotime($cuenta['reset_expires']) < time()) {
    $error = 'El enlace de recuperación ha caducado (su validez es de 2 horas). Por favor, solicite uno nuevo.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error && !$exito) {
    $password = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';
    
    if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $error = 'La contraseña debe tener al menos 8 caracteres, incluir mayúsculas, minúsculas y números.';
    } elseif ($password !== $password2) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        
        $db->update('portal_cuentas', [
            'password_hash' => $hash,
            'reset_token' => null,
            'reset_expires' => null
        ], 'id = ?', [$cuenta['id']]);
        
        $exito = '¡Su contraseña se ha actualizado correctamente!';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nueva Contraseña — <?php echo e($nombreDespacho); ?></title>
    <link rel="icon" type="image/png" href="crm/assets/images/logo.png?v=2">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        
        .rp-form { background: #fff; width: 100%; max-width: 440px; border-radius: 24px; box-shadow: 0 20px 40px rgba(0,0,0,.08); overflow: hidden; }
        .rp-inner { padding: 48px; }
        
        .badge-new { display: inline-flex; align-items: center; gap: 6px; background: #eff6ff; color: #2563eb; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; padding: 6px 14px; border-radius: 12px; margin-bottom: 20px; }
        h1 { font-size: 1.75rem; font-weight: 800; color: #0f172a; letter-spacing: -0.02em; margin-bottom: 12px; }
        p.sub { font-size: 0.9375rem; color: #64748b; line-height: 1.6; margin-bottom: 32px; }
        
        .err { background: #fef2f2; color: #dc2626; padding: 14px 18px; border-radius: 12px; font-size: 0.875rem; font-weight: 500; margin-bottom: 24px; }
        .success { background: #f0fdf4; color: #059669; padding: 14px 18px; border-radius: 12px; font-size: 0.875rem; font-weight: 500; margin-bottom: 24px; }
        
        .fld { margin-bottom: 20px; }
        .fld label { display: block; font-size: 0.8125rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 10px; }
        .fld label span.r { color: #ef4444; }
        
        .fi { position: relative; }
        .fi svg { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #94a3b8; pointer-events: none; }
        .fi input { width: 100%; height: 52px; background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 14px; padding: 0 44px 0 44px; font-family: inherit; font-size: 0.9375rem; color: #1e293b; outline: none; transition: all 0.2s; }
        .fi input:focus { background: #fff; border-color: #2563eb; box-shadow: 0 0 0 4px rgba(37,99,235,0.1); }
        .fi .eye { position: absolute; right: 16px; top: 50%; transform: translateY(-50%); color: #94a3b8; cursor: pointer; }
        .fi .eye:hover { color: #64748b; }
        .fi .eye svg { position: static; transform: none; }
        
        .btn-submit { width: 100%; height: 52px; background: #2563eb; color: #fff; border: none; border-radius: 14px; font-family: inherit; font-size: 0.9375rem; font-weight: 700; cursor: pointer; transition: all 0.2s; margin-top: 10px; }
        .btn-submit:hover { background: #1d4ed8; transform: translateY(-1px); }
        
        .foot { margin-top: 32px; text-align: center; }
        .foot a { color: #2563eb; font-weight: 600; text-decoration: none; font-size: 0.875rem; }
        .foot a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="rp-form">
        <div class="rp-inner">
            <div class="badge-new">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                Seguridad
            </div>
            <h1>Crear nueva contraseña</h1>
            <p class="sub">Escriba su nueva contraseña para acceder al portal.</p>

            <?php if ($error): ?>
                <div class="err"><?php echo $error; ?></div>
                <div class="foot">
                    <a href="index.php?page=forgot-password">Solicitar un nuevo enlace</a>
                </div>
            <?php elseif ($exito): ?>
                <div class="success"><?php echo $exito; ?></div>
                <div class="foot">
                    <a href="index.php?page=login" style="display:inline-block;background:#2563eb;color:#fff;padding:12px 24px;border-radius:12px;text-decoration:none">Ir a Iniciar Sesión</a>
                </div>
            <?php else: ?>
                <form method="POST">
                    <div class="fld">
                        <label>Nueva Contraseña <span class="r">*</span></label>
                        <div class="fi">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            <input type="password" name="password" id="pw1" placeholder="Mín. 8 caracteres" required minlength="8">
                            <span class="eye" onclick="toggleVis('pw1')">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            </span>
                        </div>
                    </div>
                    <div class="fld">
                        <label>Confirmar <span class="r">*</span></label>
                        <div class="fi">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            <input type="password" name="password2" id="pw2" placeholder="Repita contraseña" required minlength="8">
                            <span class="eye" onclick="toggleVis('pw2')">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            </span>
                        </div>
                    </div>
                    <button type="submit" class="btn-submit">Guardar y Continuar</button>
                </form>
                <div class="foot">
                    <a href="index.php?page=login">Cancelar y volver</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <script>
        function toggleVis(id){
            var i = document.getElementById(id);
            i.type = i.type === 'password' ? 'text' : 'password';
        }
    </script>
</body>
</html>
