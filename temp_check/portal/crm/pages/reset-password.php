<?php
/**
 * CRM Abogados — Restablecer Contraseña
 */
if (!defined('CRM_ROOT')) die('Acceso prohibido');

$error = '';
$exito = '';
$nombreDespacho = getConfig('nombre_despacho', 'CRM Abogados');
$logoVersion = filemtime(CRM_ROOT . '/assets/images/logo.png');

$token = $_GET['token'] ?? '';
$db = Database::getInstance();

if (empty($token)) {
    $error = 'Enlace de recuperación inválido o ausente.';
} else {
    // Buscar el token
    $cuenta = $db->fetchOne("SELECT id, reset_expires FROM usuarios_internos WHERE reset_token = ?", [$token]);
    
    if (!$cuenta) {
        $error = 'El enlace de recuperación es inválido o ya ha sido utilizado.';
    } elseif (strtotime($cuenta['reset_expires']) < time()) {
        $error = 'El enlace de recuperación ha expirado. Por favor, solicita uno nuevo.';
    } else {
        // Token válido, procesar formulario
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $password = $_POST['password'] ?? '';
            $password_confirm = $_POST['password_confirm'] ?? '';
            
            if (empty($password) || empty($password_confirm)) {
                $error = 'Por favor, completa ambos campos.';
            } elseif ($password !== $password_confirm) {
                $error = 'Las contraseñas no coinciden.';
            } elseif (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
                $error = 'La contraseña debe tener al menos 8 caracteres, e incluir mayúsculas, minúsculas y números.';
            } else {
                // Actualizar contraseña y limpiar token
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $db->update('usuarios_internos', [
                    'password_hash' => $hash,
                    'reset_token' => null,
                    'reset_expires' => null
                ], 'id = ?', [$cuenta['id']]);
                
                $exito = 'Tu contraseña ha sido restablecida correctamente. Ya puedes iniciar sesión.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restablecer Contraseña — <?php echo e($nombreDespacho); ?></title>
    <link rel="icon" type="image/png" href="<?php echo APP_URL; ?>/assets/images/logo.png?v=<?php echo $logoVersion; ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: #f8fafc; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        
        .rp-form { background: #fff; width: 100%; max-width: 440px; border-radius: 24px; box-shadow: 0 20px 40px rgba(0,0,0,.08); overflow: hidden; border: 1px solid #e2e8f0; }
        .rp-inner { padding: 48px; }
        
        .badge-new { display: inline-flex; align-items: center; gap: 6px; background: #eff6ff; color: #2e6edd; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; padding: 6px 14px; border-radius: 12px; margin-bottom: 20px; }
        h1 { font-size: 1.75rem; font-weight: 800; color: #0f172a; letter-spacing: -0.02em; margin-bottom: 12px; }
        p.sub { font-size: 0.9375rem; color: #64748b; line-height: 1.6; margin-bottom: 32px; }
        
        .err { background: #fef2f2; color: #dc2626; padding: 14px 18px; border-radius: 12px; font-size: 0.875rem; font-weight: 500; margin-bottom: 24px; border: 1px solid #fecaca; }
        .success { background: #f0fdf4; color: #059669; padding: 14px 18px; border-radius: 12px; font-size: 0.875rem; font-weight: 500; margin-bottom: 24px; border: 1px solid #a7f3d0; line-height: 1.5; }
        
        .fld { margin-bottom: 24px; }
        .fld label { display: block; font-size: 0.8125rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 10px; }
        .fld label span.r { color: #ef4444; }
        
        .fi { position: relative; }
        .fi svg { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #94a3b8; pointer-events: none; }
        .fi input { width: 100%; height: 52px; background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 14px; padding: 0 16px 0 44px; font-family: inherit; font-size: 0.9375rem; color: #1e293b; outline: none; transition: all 0.2s; }
        .fi input:focus { background: #fff; border-color: #2e6edd; box-shadow: 0 0 0 4px rgba(46,110,221,0.1); }
        
        .btn-submit { width: 100%; height: 52px; background: #2e6edd; color: #fff; border: none; border-radius: 14px; font-family: inherit; font-size: 0.9375rem; font-weight: 700; cursor: pointer; transition: all 0.2s; }
        .btn-submit:hover { background: #1e52ab; transform: translateY(-1px); box-shadow: 0 8px 24px rgba(46,110,221,0.25); }
        
        .foot { margin-top: 32px; text-align: center; }
        .foot a { color: #2e6edd; font-weight: 600; text-decoration: none; font-size: 0.9375rem; }
        .foot a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="rp-form">
        <div class="rp-inner">
            <div class="badge-new">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                Nueva Contraseña
            </div>
            <h1>Restablecer</h1>
            <p class="sub">Por favor, introduce tu nueva contraseña. Asegúrate de que sea segura.</p>

            <?php if ($error): ?>
            <div class="err"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <?php if ($exito): ?>
            <div class="success"><?php echo $exito; ?></div>
            <div class="foot">
                <a href="<?php echo APP_URL; ?>/index.php?page=login">Ir al panel de inicio de sesión</a>
            </div>
            <?php elseif (empty($error) || (!empty($error) && isset($cuenta) && strtotime($cuenta['reset_expires']) >= time())): ?>
            <form method="POST" action="">
                <div class="fld">
                    <label>Nueva Contraseña <span class="r">*</span></label>
                    <div class="fi">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <input type="password" name="password" placeholder="Mínimo 8 caracteres" required autofocus>
                    </div>
                </div>
                <div class="fld">
                    <label>Confirmar Contraseña <span class="r">*</span></label>
                    <div class="fi">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <input type="password" name="password_confirm" placeholder="Repite la contraseña" required>
                    </div>
                </div>
                <button type="submit" class="btn-submit">Guardar contraseña</button>
            </form>
            <?php else: ?>
            <div class="foot">
                <a href="<?php echo APP_URL; ?>/index.php?page=forgot-password">Solicitar un nuevo enlace</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
