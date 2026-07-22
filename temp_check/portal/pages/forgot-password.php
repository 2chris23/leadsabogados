<?php
/**
 * Portal del Cliente — Solicitar Reseteo de Contraseña
 */
$error = '';
$exito = '';
$nombreDespacho = 'Mi Despacho de Abogados';
try {
    $nombreDespacho = $db->fetchColumn("SELECT valor FROM configuracion WHERE clave = 'nombre_despacho'") ?: $nombreDespacho;
} catch(Exception $e) {}

$crmUrl = APP_URL . '/portal/crm';

// Asegurar que las columnas existen
try {
    $db->query("ALTER TABLE portal_cuentas ADD COLUMN reset_token VARCHAR(64) DEFAULT NULL, ADD COLUMN reset_expires DATETIME DEFAULT NULL");
} catch(Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Por favor, introduzca un correo electrónico válido.';
    } else {
        $cuenta = $db->fetchOne("SELECT id, nombre FROM portal_cuentas WHERE email = ?", [$email]);
        if ($cuenta) {
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+2 hours'));
            
            $db->update('portal_cuentas', [
                'reset_token' => $token,
                'reset_expires' => $expires
            ], 'id = ?', [$cuenta['id']]);
            
            $resetLink = portalUrl() . '/index.php?page=reset-password&token=' . $token;
            
            // Intento de envío de email
            $subject = "Recuperar Contraseña - $nombreDespacho";
            $message = "Hola " . $cuenta['nombre'] . ",\n\n";
            $message .= "Has solicitado restablecer tu contraseña. Haz clic en el siguiente enlace para crear una nueva (es válido por 2 horas):\n\n";
            $message .= $resetLink . "\n\n";
            $message .= "Si no solicitaste este cambio, ignora este mensaje.\n";
            
            $headers = "From: no-reply@" . $_SERVER['HTTP_HOST'] . "\r\n";
            $headers .= "Reply-To: no-reply@" . $_SERVER['HTTP_HOST'] . "\r\n";
            $headers .= "X-Mailer: PHP/" . phpversion();
            
            if (@mail($email, $subject, $message, $headers)) {
                $exito = 'Le hemos enviado un correo con las instrucciones para restablecer su contraseña.';
            } else {
                // Fallback para entornos locales o de prueba sin SMTP
                $exito = '<strong>[Aviso Local]</strong> El servidor no tiene el correo configurado. Haz clic aquí para resetear: <br><br><a href="'.$resetLink.'" style="word-break: break-all; color: #2563eb; font-weight: 600;">'.$resetLink.'</a>';
            }
        } else {
            // No revelar si el correo existe o no por seguridad, mostrar el mismo mensaje
            $exito = 'Le hemos enviado un correo con las instrucciones para restablecer su contraseña.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar Contraseña — <?php echo e($nombreDespacho); ?></title>
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
        
        .fld { margin-bottom: 24px; }
        .fld label { display: block; font-size: 0.8125rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 10px; }
        .fld label span.r { color: #ef4444; }
        
        .fi { position: relative; }
        .fi svg { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #94a3b8; pointer-events: none; }
        .fi input { width: 100%; height: 52px; background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 14px; padding: 0 16px 0 44px; font-family: inherit; font-size: 0.9375rem; color: #1e293b; outline: none; transition: all 0.2s; }
        .fi input:focus { background: #fff; border-color: #2563eb; box-shadow: 0 0 0 4px rgba(37,99,235,0.1); }
        
        .btn-submit { width: 100%; height: 52px; background: #2563eb; color: #fff; border: none; border-radius: 14px; font-family: inherit; font-size: 0.9375rem; font-weight: 700; cursor: pointer; transition: all 0.2s; }
        .btn-submit:hover { background: #1d4ed8; transform: translateY(-1px); }
        
        .foot { margin-top: 32px; text-align: center; }
        .foot p { font-size: 0.875rem; color: #64748b; }
        .foot a { color: #2563eb; font-weight: 600; text-decoration: none; }
        .foot a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="rp-form">
        <div class="rp-inner">
            <div class="badge-new">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                Recuperación
            </div>
            <h1>Olvidé mi contraseña</h1>
            <p class="sub">Introduce el correo electrónico de tu cuenta y te enviaremos un enlace seguro para crear una nueva.</p>

            <?php if ($error): ?>
            <div class="err"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <?php if ($exito): ?>
            <div class="success"><?php echo $exito; ?></div>
            <?php else: ?>
            <form method="POST" action="<?php echo portalUrl(); ?>/index.php?page=forgot-password">
                <div class="fld">
                    <label>Correo Electrónico <span class="r">*</span></label>
                    <div class="fi">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                        <input type="email" name="email" placeholder="correo@ejemplo.com" required>
                    </div>
                </div>
                <button type="submit" class="btn-submit">Enviar enlace de reseteo</button>
            </form>
            <?php endif; ?>
            
            <div class="foot">
                <p>¿Recordaste tu contraseña? <a href="index.php?page=login">Volver al login</a></p>
            </div>
        </div>
    </div>
</body>
</html>
