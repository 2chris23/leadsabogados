<?php
/**
 * CRM Abogados — Login Rediseñado Premium
 */
if (!defined('CRM_ROOT')) {
    define('CRM_ROOT', dirname(__DIR__));
}

$nombreDespacho = getConfig('nombre_despacho', 'CRM Abogados');
$logoVersion = filemtime(CRM_ROOT . '/assets/images/logo.png');
$error = '';

// Evitar caché para que el token CSRF siempre sea fresco
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar token CSRF
    if (!CSRF::validar()) {
        $error = 'Error de seguridad (token CSRF inválido). Por favor, intente de nuevo.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        if (empty($email) || empty($password)) {
            $error = 'Por favor complete todos los campos';
        } else {
            $remember  = isset($_POST['remember']);
            $resultado = $auth->login($email, $password, $remember);
            if ($resultado['exito']) {
                $rolUsuario = $auth->getRol();
                if ($rolUsuario === 'admin') {
                    header('Location: /portal/crm/index.php?page=dashboard');
                } elseif ($rolUsuario === 'gestor') {
                    header('Location: /portal/crm/index.php?page=solicitudes');
                } else {
                    header('Location: /portal/crm/index.php?page=casos');
                }
                exit;
            } else {
                $error = $resultado['mensaje'];
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
    <title>Iniciar Sesión — <?php echo e($nombreDespacho); ?></title>
    <link rel="icon" type="image/png" href="assets/images/logo.png?v=<?php echo $logoVersion; ?>">
    <link rel="stylesheet" href="assets/css/remixicon.css">
    <link rel="stylesheet" href="assets/css/lib/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; }
        body { margin: 0; padding: 0; min-height: 100vh; }

        .login-wrapper {
            display: flex;
            min-height: 100vh;
        }

        .login-image-side {
            flex: 1;
            position: relative;
            display: none;
            overflow: hidden;
        }

        @media (min-width: 992px) {
            .login-image-side { display: block; }
        }

        .login-image-side img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .login-image-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(30, 82, 171, 0.85) 0%, rgba(46, 110, 221, 0.7) 100%);
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            padding: 60px;
        }

        .login-image-overlay h2 {
            color: #fff;
            font-size: 2.25rem;
            font-weight: 800;
            line-height: 1.2;
            margin-bottom: 16px;
            letter-spacing: -0.02em;
        }

        .login-image-overlay p {
            color: rgba(255,255,255,0.8);
            font-size: 1.0625rem;
            line-height: 1.6;
            max-width: 480px;
        }

        .login-form-side {
            flex: 0 0 520px;
            max-width: 520px;
            width: 100%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 60px 48px;
            background: #fff;
            box-sizing: border-box;
        }

        .login-form-side * {
            box-sizing: border-box;
        }

        @media (max-width: 991.98px) {
            .login-form-side {
                flex: 1;
                max-width: 100%;
                padding: 40px 24px;
            }
        }

        .login-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 48px;
        }

        .login-logo img {
            height: 42px;
            width: auto;
            object-fit: contain;
        }

        .login-logo span {
            font-size: 1.25rem;
            font-weight: 800;
            color: #2e6edd;
            letter-spacing: -0.02em;
        }

        .login-title {
            font-size: 1.75rem;
            font-weight: 800;
            color: #1a1a2e;
            margin-bottom: 8px;
            letter-spacing: -0.02em;
        }

        .login-subtitle {
            color: #64748b;
            font-size: 0.9375rem;
            margin-bottom: 36px;
            line-height: 1.5;
        }

        .login-field {
            margin-bottom: 20px;
        }

        .login-field label {
            display: block;
            font-size: 0.8125rem;
            font-weight: 600;
            color: #1a1a2e;
            margin-bottom: 8px;
        }

        .login-input-wrap {
            position: relative;
        }

        .login-input-wrap .icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 1.125rem;
            pointer-events: none;
            display: flex;
            align-items: center;
            justify-content: center;
            line-height: 0;
        }

        .login-input-wrap input {
            width: 100%;
            padding: 12px 14px 12px 44px;
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            font-size: 0.9375rem;
            font-weight: 500;
            color: #1a1a2e;
            background: #f8fafc;
            transition: all 0.2s;
            outline: none;
        }

        .login-input-wrap input:focus {
            border-color: #2e6edd;
            box-shadow: 0 0 0 4px rgba(46, 110, 221, 0.1);
            background: #fff;
        }

        .login-input-wrap input::placeholder {
            color: #94a3b8;
            font-weight: 400;
        }

        .login-input-wrap .toggle-pw {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #94a3b8;
            font-size: 1.125rem;
            transition: color 0.2s;
        }

        .login-input-wrap .toggle-pw:hover {
            color: #2e6edd;
        }

        .login-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 28px;
        }

        .login-options label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.8125rem;
            color: #64748b;
            cursor: pointer;
            font-weight: 500;
        }

        .login-options label input[type="checkbox"] {
            width: 16px;
            height: 16px;
            border-radius: 4px;
            border: 1.5px solid #e2e8f0;
            accent-color: #2e6edd;
        }

        .login-options a {
            color: #2e6edd;
            font-size: 0.8125rem;
            font-weight: 600;
            text-decoration: none;
        }

        .login-options a:hover {
            text-decoration: underline;
        }

        .login-btn {
            width: 100%;
            padding: 14px;
            background: #2e6edd;
            color: #fff !important;
            border: none;
            border-radius: 12px;
            font-size: 0.9375rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            letter-spacing: 0.01em;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
            text-align: center;
            line-height: 1.4;
        }

        .login-btn:hover {
            background: #1e52ab;
            box-shadow: 0 8px 24px rgba(46, 110, 221, 0.3);
            transform: translateY(-1px);
        }

        .login-btn:active {
            transform: translateY(0);
        }

        .login-alert {
            padding: 12px 16px;
            border-radius: 12px;
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            font-size: 0.8125rem;
            font-weight: 500;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .login-footer {
            margin-top: 32px;
            text-align: center;
            font-size: 0.8125rem;
            color: #94a3b8;
        }
    </style>
</head>
<body>

<div class="login-wrapper">
    <!-- Imagen Lateral -->
    <div class="login-image-side">
        <img src="/portal/crm/assets/images/steptodown.com753320.jpg?v=<?php echo time(); ?>" alt="Consulta jurídica">
        <div class="login-image-overlay">
            <h2>Gestión Jurídica<br>Profesional</h2>
            <p>Panel de administración exclusivo para abogados y personal autorizado del despacho.</p>
        </div>
    </div>

    <!-- Formulario -->
    <div class="login-form-side">
        <div class="login-logo">
            <img src="/portal/crm/assets/images/logo.png?v=<?php echo time(); ?>" alt="Logo">
            <span><?php echo e($nombreDespacho); ?></span>
        </div>

        <h1 class="login-title">Iniciar Sesión</h1>
        <p class="login-subtitle">Ingrese sus credenciales para acceder al panel de gestión.</p>

        <?php if ($error): ?>
        <div class="login-alert">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            <?php echo e($error); ?>
        </div>
        <?php endif; ?>

        <form action="/portal/crm/index.php?page=login" method="POST">
            <?php echo CSRF::campo(); ?>

            <div class="login-field">
                <label>Correo Electrónico</label>
                <div class="login-input-wrap">
                    <span class="icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                    </span>
                    <input type="email" name="email" placeholder="nombre@despacho.com" value="<?php echo e($_POST['email'] ?? ''); ?>" required autofocus>
                </div>
            </div>

            <div class="login-field">
                <label>Contraseña</label>
                <div class="login-input-wrap">
                    <span class="icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    </span>
                    <input type="password" name="password" id="pw" placeholder="Ingrese su contraseña" required>
                    <span class="toggle-pw" onclick="togglePassword()">
                        <svg id="eyeIcon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </span>
                </div>
            </div>

            <div class="login-options">
                <label><input type="checkbox" name="remember"> Recordarme</label>
                <a href="<?php echo APP_URL; ?>/index.php?page=forgot-password">¿Olvidó su contraseña?</a>
            </div>

            <button type="submit" class="login-btn">Iniciar Sesión</button>
        </form>

        <div class="login-footer">
            &copy; <?php echo date('Y'); ?> <?php echo e($nombreDespacho); ?>. Todos los derechos reservados.
        </div>
    </div>
</div>

<script>
function togglePassword() {
    const pw = document.getElementById('pw');
    pw.type = pw.type === 'password' ? 'text' : 'password';
}
</script>

</body>
</html>


