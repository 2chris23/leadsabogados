<?php
/**
 * Portal del Cliente — Login
 * Autenticación con tabla portal_cuentas (email + password)
 */

// Security headers
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

$error = '';
$nombreDespacho = 'Mi Despacho de Abogados';
try {
    $nombreDespacho = $db->fetchColumn("SELECT valor FROM configuracion WHERE clave = 'nombre_despacho'") ?: $nombreDespacho;
} catch(Exception $e) {}

// Ensure DB columns exist for password resets
try {
    $db->query("ALTER TABLE portal_cuentas ADD COLUMN reset_token VARCHAR(64) DEFAULT NULL, ADD COLUMN reset_expires DATETIME DEFAULT NULL");
} catch(Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Rate limiting por IP
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $lockKey = 'portal_login_' . md5($ip);
    $intentos = (int)($_SESSION[$lockKey . '_count'] ?? 0);
    $bloqueadoHasta = (int)($_SESSION[$lockKey . '_until'] ?? 0);
    
    if ($bloqueadoHasta > time()) {
        $mins = ceil(($bloqueadoHasta - time()) / 60);
        $error = "Demasiados intentos. Espere {$mins} minutos.";
    } else {
        if ($bloqueadoHasta && $bloqueadoHasta <= time()) {
            $_SESSION[$lockKey . '_count'] = 0;
            $_SESSION[$lockKey . '_until'] = 0;
            $intentos = 0;
        }
        
        $email    = trim($_POST['usuario'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = isset($_POST['remember']);

        if (empty($email) || empty($password)) {
            $error = 'Complete todos los campos';
        } else {
            $cuenta = $db->fetchOne(
                "SELECT * FROM portal_cuentas WHERE email = ? AND activo = 1",
                [$email]
            );
            if ($cuenta && password_verify($password, $cuenta['password_hash'])) {
                // Reset rate limit
                $_SESSION[$lockKey . '_count'] = 0;
                $_SESSION[$lockKey . '_until'] = 0;
                
                session_regenerate_id(true);
                $_SESSION['portal_id']        = $cuenta['id'];
                $_SESSION['portal_nombre']    = $cuenta['nombre'];
                $_SESSION['portal_apellidos'] = $cuenta['apellidos'];
                $_SESSION['portal_email']     = $cuenta['email'];
                $_SESSION['portal_es_cliente']= (bool)$cuenta['es_cliente'];
                $_SESSION['portal_cliente_id']= $cuenta['cliente_id'];
                $_SESSION['portal_via_remember'] = $remember;

                // Recordarme: cookie de 30 días
                if ($remember) {
                    $secret = env('APP_SECRET', 'portal_secret_key');
                    $token  = hash_hmac('sha256', $cuenta['id'] . $cuenta['password_hash'], $secret);
                    $esHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
                    setcookie('portal_remember', $cuenta['id'] . ':' . $token, time() + (30 * 24 * 3600), '/', '', $esHttps, true);
                }

                header('Location: ' . portalUrl() . '/index.php?page=dashboard');
                exit;
            } else {
                $intentos++;
                $_SESSION[$lockKey . '_count'] = $intentos;
                if ($intentos >= 5) {
                    $_SESSION[$lockKey . '_until'] = time() + (15 * 60);
                    $error = 'Cuenta bloqueada por 15 minutos. Demasiados intentos.';
                } else {
                    $error = 'Correo o contraseña incorrectos';
                }
            }
        }
    }
}

// Auto-login por cookie portal_remember
if (!clienteLogueado() && isset($_COOKIE['portal_remember'])) {
    $parts = explode(':', $_COOKIE['portal_remember'], 2);
    if (count($parts) === 2) {
        [$cid, $tok] = $parts;
        $cid = (int)$cid;
        $cuenta = $db->fetchOne("SELECT * FROM portal_cuentas WHERE id = ? AND activo = 1", [$cid]);
        if ($cuenta) {
            $secret   = env('APP_SECRET', 'portal_secret_key');
            $expected = hash_hmac('sha256', $cuenta['id'] . $cuenta['password_hash'], $secret);
            if (hash_equals($expected, $tok)) {
                session_regenerate_id(true);
                $_SESSION['portal_id']         = $cuenta['id'];
                $_SESSION['portal_nombre']      = $cuenta['nombre'];
                $_SESSION['portal_apellidos']   = $cuenta['apellidos'];
                $_SESSION['portal_email']       = $cuenta['email'];
                $_SESSION['portal_es_cliente']  = (bool)$cuenta['es_cliente'];
                $_SESSION['portal_cliente_id']  = $cuenta['cliente_id'];
                $_SESSION['portal_via_remember'] = true;
                header('Location: ' . portalUrl() . '/index.php?page=dashboard');
                exit;
            } else {
                setcookie('portal_remember', '', time() - 3600, '/');
            }
        } else {
            setcookie('portal_remember', '', time() - 3600, '/');
        }
    }
}


$crmUrl = APP_URL . '/portal/crm';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo portalPwaHead(); ?>
    <title>Portal del Cliente — <?php echo e($nombreDespacho); ?></title>
    <link rel="icon" type="image/png" href="crm/assets/images/logo.png?v=<?php echo time(); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; min-height: 100vh; background: #0f172a; color: #fff; }

        .portal-login {
            display: grid;
            grid-template-columns: 1fr 1fr;
            min-height: 100vh;
        }

        @media (max-width: 991px) {
            .portal-login { grid-template-columns: 1fr; }
            .portal-visual { display: none; }
        }

        /* Visual Side */
        .portal-visual {
            position: relative;
            overflow: hidden;
        }

        .portal-visual > img {
            width: 100%; height: 100%;
            object-fit: cover;
            filter: brightness(0.4);
        }

        .portal-visual-content {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 48px;
        }

        .portal-visual-logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .portal-visual-logo img {
            width: 44px; height: 44px;
            filter: drop-shadow(0 2px 8px rgba(0,0,0,0.3));
            object-fit: contain;
        }

        .portal-visual-logo span {
            font-size: 1.125rem;
            font-weight: 700;
            color: #fff;
            letter-spacing: -0.01em;
        }

        .portal-visual-text h2 {
            font-size: 2.5rem;
            font-weight: 900;
            line-height: 1.15;
            letter-spacing: -0.03em;
            margin-bottom: 16px;
        }

        .portal-visual-text h2 span {
            color: #6ba3ff;
        }

        .portal-visual-text p {
            font-size: 1rem;
            color: rgba(255,255,255,0.65);
            line-height: 1.6;
            max-width: 420px;
        }

        .portal-visual-stats {
            display: flex;
            gap: 40px;
        }

        .portal-visual-stats div strong {
            display: block;
            font-size: 1.75rem;
            font-weight: 800;
            color: #fff;
        }

        .portal-visual-stats div span {
            font-size: 0.8125rem;
            color: rgba(255,255,255,0.5);
            font-weight: 500;
        }

        /* Form Side */
        .portal-form-side {
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 60px;
            background: #fff;
            color: #1a1a2e;
        }

        @media (max-width: 991px) {
            .portal-form-side { padding: 40px 24px; min-height: 100vh; }
        }

        .portal-form-inner {
            max-width: 400px;
            width: 100%;
            margin: 0 auto;
        }

        .portal-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #e8f0fe;
            color: #2e6edd;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 6px 14px;
            border-radius: 99px;
            margin-bottom: 32px;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .portal-form-inner h1 {
            font-size: 2rem;
            font-weight: 800;
            color: #1a1a2e;
            margin-bottom: 8px;
            letter-spacing: -0.03em;
        }

        .portal-form-inner .subtitle {
            color: #64748b;
            font-size: 0.9375rem;
            line-height: 1.5;
            margin-bottom: 36px;
        }

        .field {
            margin-bottom: 20px;
        }

        .field label {
            display: block;
            font-size: 0.8125rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 6px;
        }

        .field-input {
            position: relative;
        }

        /* Icono izquierdo del campo */
        .field-input > svg {
            position: absolute;
            left: 14px; top: 50%; transform: translateY(-50%);
            color: #94a3b8;
            pointer-events: none;
            display: block;
        }

        /* El input con padding para dejar espacio al icono izquierdo */

        .field-input input {
            width: 100%;
            padding: 13px 14px 13px 44px;
            border: 2px solid #e2e8f0;
            border-radius: 14px;
            font-size: 0.9375rem;
            font-weight: 500;
            color: #1a1a2e;
            background: #f8fafc;
            transition: all 0.2s;
            outline: none;
            font-family: 'Inter', sans-serif;
        }

        .field-input input:focus {
            border-color: #2e6edd;
            box-shadow: 0 0 0 4px rgba(46, 110, 221, 0.1);
            background: #fff;
        }

        .field-input input::placeholder {
            color: #94a3b8; font-weight: 400;
        }

        .field-input .toggle-pw {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #94a3b8;
            display: flex;
            align-items: center;
            justify-content: center;
            line-height: 0;
            z-index: 2;
        }

        .field-input .toggle-pw svg {
            position: static;
            transform: none;
            pointer-events: none;
        }

        .field-input .toggle-pw:hover { color: #2e6edd; }

        .submit-btn {
            width: 100%;
            padding: 14px;
            background: #2e6edd;
            color: #fff;
            border: none;
            border-radius: 14px;
            font-size: 0.9375rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.25s;
            font-family: 'Inter', sans-serif;
            margin-top: 8px;
            letter-spacing: 0.01em;
        }

        .submit-btn:hover {
            background: #1e52ab;
            box-shadow: 0 12px 32px rgba(46, 110, 221, 0.25);
            transform: translateY(-2px);
        }

        .submit-btn:active { transform: translateY(0); }

        .error-msg {
            padding: 12px 16px;
            border-radius: 12px;
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            font-size: 0.8125rem;
            font-weight: 500;
            margin-bottom: 24px;
        }

        .portal-form-footer {
            margin-top: 24px;
            text-align: center;
            font-size: 0.8125rem;
            color: #94a3b8;
        }

        .portal-form-footer a {
            color: #2e6edd;
            font-weight: 600;
            text-decoration: none;
        }

        .portal-form-footer a:hover { text-decoration: underline; }

        .portal-form-footer a {
            color: #2e6edd;
            font-weight: 600;
            text-decoration: none;
        }

        .portal-form-footer a:hover { text-decoration: underline; }

        .register-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            padding: 13px;
            border: 2px solid #2e6edd;
            border-radius: 14px;
            color: #2e6edd;
            font-weight: 700;
            font-size: 0.9375rem;
            text-decoration: none;
            transition: all 0.25s;
            font-family: 'Inter', sans-serif;
        }

        .register-link:hover {
            background: #2e6edd;
            color: #fff;
            box-shadow: 0 8px 24px rgba(46, 110, 221, 0.2);
            transform: translateY(-1px);
        }

        .separator {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 28px 0;
            font-size: 0.75rem;
            color: #94a3b8;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .separator::before, .separator::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #e2e8f0;
        }
    </style>
</head>
<body>

<div class="portal-login">
    <!-- Visual -->
    <div class="portal-visual">
        <img src="crm/assets/images/steptodown.com552020.jpg?v=<?php echo time(); ?>" alt="Justicia">
        <div class="portal-visual-content">
            <div class="portal-visual-logo">
                <img src="crm/assets/images/logo.png?v=<?php echo time(); ?>" alt="Logo">
                <span><?php echo e($nombreDespacho); ?></span>
            </div>

            <div>
                <div class="portal-visual-text">
                    <h2>Su caso, <span>siempre<br>a su alcance.</span></h2>
                    <p>Consulte el estado de su expediente, revise pagos y suba documentos desde cualquier lugar, en cualquier momento.</p>
                </div>
                <div style="height: 40px;"></div>
                <div class="portal-visual-stats">
                    <div><strong>100%</strong><span>Confidencial</span></div>
                    <div><strong>24/7</strong><span>Disponible</span></div>
                    <div><strong>Seguro</strong><span>Cifrado SSL</span></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Formulario -->
    <div class="portal-form-side">
        <div class="portal-form-inner">
            <div class="portal-badge">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                Portal Seguro del Cliente
            </div>

            <h1>Bienvenido</h1>
            <p class="subtitle">Acceda a su portal con el correo y contraseña que utilizó al crear su cuenta.</p>

            <?php if ($error): ?>
            <div class="error-msg"><?php echo e($error); ?></div>
            <?php endif; ?>

            <form method="POST" action="<?php echo portalUrl(); ?>/index.php?page=login">
                <div class="field">
                    <label>Correo Electrónico</label>
                    <div class="field-input">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                        <input type="email" name="usuario" placeholder="correo@ejemplo.com" value="<?php echo e($_POST['usuario'] ?? ''); ?>" required autofocus>
                    </div>
                </div>
                <div class="field">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                        <label style="margin-bottom:0;">Contraseña</label>
                        <a href="index.php?page=forgot-password" style="font-size:0.8125rem; color:#2563eb; font-weight:600; text-decoration:none;">¿Olvidaste tu contraseña?</a>
                    </div>
                    <div class="field-input">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <input type="password" name="password" id="pw" placeholder="Su contraseña de acceso" required>
                        <span class="toggle-pw" onclick="document.getElementById('pw').type = document.getElementById('pw').type === 'password' ? 'text' : 'password'">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </span>
                    </div>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; margin:0 0 24px;">
                    <label style="display:flex;align-items:center;gap:8px;font-size:.8125rem;color:#64748b;font-weight:500;cursor:pointer;">
                        <input type="checkbox" name="remember" style="width:16px;height:16px;accent-color:#2e6edd;border-radius:4px;cursor:pointer;">
                        Recordarme 30 días
                    </label>
                </div>

                <button type="submit" class="submit-btn">Acceder a Mi Expediente</button>
            </form>

            <div class="portal-form-footer">
                <p><a href="/">← Volver al sitio principal</a></p>
            </div>

            <div class="separator">¿Primera vez?</div>

            <a href="index.php?page=register" class="register-link">Crear una Cuenta Nueva</a>
        </div>
    </div>
</div>

<?php echo portalPwaScript(); ?>
</body>
</html>
