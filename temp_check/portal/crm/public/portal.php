<?php
/**
 * CRM Abogados - Portal del Cliente
 * Registro con OTP, Login, Dashboard unificado
 */
define('CRM_ROOT', dirname(__DIR__));
require_once CRM_ROOT . '/includes/config.php';
require_once CRM_ROOT . '/includes/Database.php';
require_once CRM_ROOT . '/includes/AuditLog.php';
require_once CRM_ROOT . '/includes/CSRF.php';
require_once CRM_ROOT . '/includes/Mailer.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!function_exists('getConfig')) {
    function getConfig(string $c, $d = '') {
        try { $r = Database::getInstance()->fetchOne("SELECT valor FROM configuracion WHERE clave = ?", [$c]); return $r ? $r['valor'] : $d; } catch (Exception $e) { return $d; }
    }
}

$nombreDespacho = getConfig('nombre_despacho', 'CRM Abogados');
$db = Database::getInstance();

// ===== Cerrar sesión =====
if (isset($_GET['salir'])) {
    unset($_SESSION['portal_cliente_id'], $_SESSION['portal_cliente_nombre'],
          $_SESSION['portal_cliente_email'], $_SESSION['portal_ultimo_activity']);
    header('Location: portal.php');
    exit;
}

// ===== Timeout de sesión (30 min de inactividad) =====
if (!empty($_SESSION['portal_cliente_id'])) {
    $timeout = 1800; // 30 minutos
    if (isset($_SESSION['portal_ultimo_activity']) && (time() - $_SESSION['portal_ultimo_activity']) > $timeout) {
        unset($_SESSION['portal_cliente_id'], $_SESSION['portal_cliente_nombre'],
              $_SESSION['portal_cliente_email'], $_SESSION['portal_ultimo_activity']);
        header('Location: portal.php?v=login&exp=1');
        exit;
    }
    $_SESSION['portal_ultimo_activity'] = time();

    // Reparar sesión rota: si falta email o nombre, recargar desde DB
    if (empty($_SESSION['portal_cliente_email']) || empty($_SESSION['portal_cliente_nombre'])) {
        $reparar = $db->fetchOne("SELECT * FROM portal_clientes WHERE id=?", [$_SESSION['portal_cliente_id']]);
        if ($reparar) {
            $_SESSION['portal_cliente_nombre']  = $reparar['nombre'] . ' ' . $reparar['apellidos'];
            $_SESSION['portal_cliente_email']   = $reparar['email'];
        }
    }
}

// Determinar vista
$logueado = !empty($_SESSION['portal_cliente_id']);
$vista = $_GET['v'] ?? ($logueado ? 'dashboard' : 'login');
$error = '';
$exito = '';

// ===== REGISTRO =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($vista === 'registro' || isset($_POST['registrar']))) {
    $nombre    = trim($_POST['nombre'] ?? '');
    $apellidos = trim($_POST['apellidos'] ?? '');
    $email     = trim(strtolower($_POST['email'] ?? ''));
    $pass      = $_POST['password'] ?? '';
    $pass2     = $_POST['password2'] ?? '';
    $codigoPais = preg_replace('/[^\d]/', '', $_POST['codigo_pais'] ?? '');
    $numTel = ltrim(preg_replace('/[^\d]/', '', $_POST['numero_telefono'] ?? ''), '0');
    $telefono = (!empty($codigoPais) && !empty($numTel)) ? '+' . $codigoPais . $numTel : '';

    if (empty($nombre) || empty($apellidos) || empty($email) || empty($pass)) {
        $error = 'Todos los campos obligatorios deben estar completos.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'El correo electrónico no es válido.';
    } elseif (!Mailer::dominioTieneCorreo($email)) {
        $error = 'El dominio del correo no existe o no puede recibir emails. Verifica que lo escribiste bien (ej: @gmail.com, @hotmail.com).';
    } elseif (strlen($pass) < 6) {
        $error = 'La contraseña debe tener al menos 6 caracteres.';
    } elseif ($pass !== $pass2) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        $existe = $db->fetchOne("SELECT id, verificado FROM portal_clientes WHERE email = ?", [$email]);
        if ($existe && $existe['verificado']) {
            $error = 'Ya existe una cuenta con ese correo. <a href="?v=login" style="color:#4f46e5;font-weight:600">Inicia sesión</a>';
        } else {
            $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $otpExpira = date('Y-m-d H:i:s', strtotime('+15 minutes'));

            if ($existe) {
                // Actualizar cuenta no verificada
                $db->update('portal_clientes', [
                    'nombre' => $nombre, 'apellidos' => $apellidos, 'telefono' => $telefono,
                    'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
                    'otp_codigo' => $otp, 'otp_expira' => $otpExpira,
                ], 'id = ?', [$existe['id']]);
            } else {
                $db->insert('portal_clientes', [
                    'nombre' => $nombre, 'apellidos' => $apellidos, 'email' => $email, 'telefono' => $telefono,
                    'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
                    'otp_codigo' => $otp, 'otp_expira' => $otpExpira,
                ]);
            }

            // Enviar OTP por email
            try {
                Mailer::enviar($email, "🔑 Código de verificación — $nombreDespacho", Mailer::htmlOTP($nombre, $otp));
            } catch (Exception $e) {
                error_log('[Portal] Error enviando OTP: ' . $e->getMessage());
            }

            $_SESSION['portal_otp_email'] = $email;
            header('Location: portal.php?v=verificar');
            exit;
        }
    }
    $vista = 'registro';
}

// ===== VERIFICAR OTP =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($vista === 'verificar' || isset($_POST['verificar_otp']))) {
    $codigo = trim($_POST['codigo'] ?? '');
    $email  = $_SESSION['portal_otp_email'] ?? '';

    if (empty($codigo) || empty($email)) {
        $error = 'Ingresa el código de verificación.';
    } else {
        $cuenta = $db->fetchOne("SELECT * FROM portal_clientes WHERE email = ? AND verificado = 0", [$email]);
        if (!$cuenta) {
            $error = 'No se encontró la cuenta.';
        } elseif ($cuenta['intentos_otp'] >= 5) {
            // Demasiados intentos — invalidar OTP
            $db->update('portal_clientes', ['otp_codigo' => null, 'otp_expira' => null, 'intentos_otp' => 0], 'id = ?', [$cuenta['id']]);
            $error = 'Demasiados intentos. Solicita un nuevo código.';
        } elseif ($cuenta['otp_codigo'] !== $codigo || strtotime($cuenta['otp_expira']) < time()) {
            // Código incorrecto o expirado — incrementar contador
            $db->update('portal_clientes', ['intentos_otp' => $cuenta['intentos_otp'] + 1], 'id = ?', [$cuenta['id']]);
            $restantes = 4 - $cuenta['intentos_otp'];
            $error = 'Código incorrecto o expirado. ' . ($restantes > 0 ? "Te quedan $restantes intentos." : 'Solicita un nuevo código.');
        } else {
            // OTP correcto
            $db->update('portal_clientes', [
                'verificado' => 1, 'otp_codigo' => null, 'otp_expira' => null, 'intentos_otp' => 0
            ], 'id = ?', [$cuenta['id']]);

            // Enviar email de bienvenida
            try {
                $htmlBienvenida = Mailer::htmlBienvenida($cuenta['nombre'], APP_URL . '/public/portal.php');
                Mailer::enviar($cuenta['email'], "🎉 ¡Bienvenido/a al Portal — $nombreDespacho", $htmlBienvenida);
            } catch (Exception $e) {
                error_log('[Portal] Error email bienvenida: ' . $e->getMessage());
            }

            $_SESSION['portal_cliente_id']     = $cuenta['id'];
            $_SESSION['portal_cliente_nombre'] = $cuenta['nombre'] . ' ' . $cuenta['apellidos'];
            $_SESSION['portal_cliente_email']  = $cuenta['email'];
            $_SESSION['portal_ultimo_activity'] = time();
            unset($_SESSION['portal_otp_email']);

            header('Location: portal.php?v=dashboard');
            exit;
        }
    }
    $vista = 'verificar';
}

// ===== REENVIAR OTP =====
if (isset($_GET['v']) && $_GET['v'] === 'reenviar' && !empty($_SESSION['portal_otp_email'])) {
    $email = $_SESSION['portal_otp_email'];
    $cuenta = $db->fetchOne("SELECT * FROM portal_clientes WHERE email = ? AND verificado = 0", [$email]);
    if ($cuenta) {
        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $db->update('portal_clientes', ['otp_codigo' => $otp, 'otp_expira' => date('Y-m-d H:i:s', strtotime('+15 minutes'))], 'id = ?', [$cuenta['id']]);
        try { Mailer::enviar($email, "🔑 Nuevo código — $nombreDespacho", Mailer::htmlOTP($cuenta['nombre'], $otp)); } catch (Exception $e) {}
    }
    header('Location: portal.php?v=verificar&reenviado=1');
    exit;
}

// ===== LOGIN =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($vista === 'login' || isset($_POST['iniciar_sesion']))) {
    $email = trim(strtolower($_POST['email'] ?? ''));
    $pass  = $_POST['password'] ?? '';

    if (empty($email) || empty($pass)) {
        $error = 'Ingresa tu email y contraseña.';
    } else {
        $cuenta = $db->fetchOne("SELECT * FROM portal_clientes WHERE email = ?", [$email]);

        // Verificar bloqueo por brute force
        if ($cuenta && !empty($cuenta['bloqueado_hasta']) && strtotime($cuenta['bloqueado_hasta']) > time()) {
            $minutos = ceil((strtotime($cuenta['bloqueado_hasta']) - time()) / 60);
            $error = "Cuenta bloqueada por demasiados intentos fallidos. Intenta de nuevo en $minutos minuto(s).";
        } elseif (!$cuenta || !password_verify($pass, $cuenta['password_hash'])) {
            // Incrementar contador de intentos fallidos
            if ($cuenta) {
                $intentos = $cuenta['intentos_login'] + 1;
                $bloqueo  = $intentos >= 5 ? date('Y-m-d H:i:s', strtotime('+15 minutes')) : null;
                $db->update('portal_clientes', ['intentos_login' => $intentos, 'bloqueado_hasta' => $bloqueo], 'id = ?', [$cuenta['id']]);
                if ($intentos >= 5) {
                    $error = 'Cuenta bloqueada 15 minutos por demasiados intentos fallidos.';
                } else {
                    $restantes = 5 - $intentos;
                    $error = "Email o contraseña incorrectos. Te quedan $restantes intento(s) antes del bloqueo.";
                }
            } else {
                $error = 'Email o contraseña incorrectos.';
            }
        } elseif (!$cuenta['verificado']) {
            // Cuenta sin verificar — reenviar OTP
            $_SESSION['portal_otp_email'] = $email;
            $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $db->update('portal_clientes', [
                'otp_codigo' => $otp, 'otp_expira' => date('Y-m-d H:i:s', strtotime('+15 minutes')),
                'intentos_otp' => 0
            ], 'id = ?', [$cuenta['id']]);
            try { Mailer::enviar($email, "🔑 Código — $nombreDespacho", Mailer::htmlOTP($cuenta['nombre'], $otp)); } catch (Exception $e) {}
            header('Location: portal.php?v=verificar');
            exit;
        } else {
            // Login correcto — resetear contadores
            $db->update('portal_clientes', [
                'intentos_login' => 0, 'bloqueado_hasta' => null,
                'ultimo_login'   => date('Y-m-d H:i:s')
            ], 'id = ?', [$cuenta['id']]);
            $_SESSION['portal_cliente_id']      = $cuenta['id'];
            $_SESSION['portal_cliente_nombre']  = $cuenta['nombre'] . ' ' . $cuenta['apellidos'];
            $_SESSION['portal_cliente_email']   = $cuenta['email'];
            $_SESSION['portal_ultimo_activity'] = time();
            header('Location: portal.php?v=dashboard');
            exit;
        }
    }
    $vista = 'login';
}

// ===== OLVIDÉ MI CONTRASEÑA =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recuperar_pass'])) {
    $email = trim(strtolower($_POST['email'] ?? ''));
    if (!empty($email)) {
        $cuenta = $db->fetchOne("SELECT * FROM portal_clientes WHERE email = ? AND verificado = 1", [$email]);
        if ($cuenta) {
            $token = bin2hex(random_bytes(32));
            $db->update('portal_clientes', [
                'reset_token'  => $token,
                'reset_expira' => date('Y-m-d H:i:s', strtotime('+30 minutes'))
            ], 'id = ?', [$cuenta['id']]);
            $linkReset = APP_URL . '/public/portal.php?v=reset&token=' . $token;
            try {
                Mailer::enviar($email,
                    "🔒 Restablecer contraseña — $nombreDespacho",
                    Mailer::htmlResetPassword($cuenta['nombre'], $linkReset)
                );
            } catch (Exception $e) {
                error_log('[Portal] Error email reset: ' . $e->getMessage());
            }
        }
        // Siempre enviamos el mismo mensaje (no revelar si el email existe)
        $exito = 'Si ese correo está registrado, recibirás un enlace para restablecer tu contraseña.';
    } else {
        $error = 'Ingresa tu correo electrónico.';
    }
    $vista = 'recuperar';
}

// ===== RESETEAR CONTRASEÑA =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nuevo_password'])) {
    $token = trim($_POST['reset_token'] ?? '');
    $pass  = $_POST['nuevo_password'] ?? '';
    $pass2 = $_POST['nuevo_password2'] ?? '';
    if (empty($token) || empty($pass)) {
        $error = 'Datos incompletos.';
    } elseif (strlen($pass) < 6) {
        $error = 'La contraseña debe tener al menos 6 caracteres.';
    } elseif ($pass !== $pass2) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        $cuenta = $db->fetchOne(
            "SELECT * FROM portal_clientes WHERE reset_token = ? AND reset_expira > NOW()", [$token]);
        if (!$cuenta) {
            $error = 'El enlace es inválido o ya expiró. Solicita uno nuevo.';
        } else {
            $db->update('portal_clientes', [
                'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
                'reset_token'   => null, 'reset_expira' => null,
                'intentos_login' => 0, 'bloqueado_hasta' => null
            ], 'id = ?', [$cuenta['id']]);
            $exito = '¡Contraseña restablecida! Ya puedes iniciar sesión.';
            $vista = 'login';
        }
    }
    if ($error) $vista = 'reset';
}

// ===== ENVIAR SOLICITUD (desde dashboard) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enviar_solicitud']) && $logueado) {
    $tipo = trim($_POST['tipo_problema'] ?? '');
    $desc = trim($_POST['descripcion'] ?? '');

    if (empty($tipo) || empty($desc)) {
        $error = 'Complete el tipo de problema y la descripción.';
    } else {
        $clienteData = $db->fetchOne("SELECT * FROM portal_clientes WHERE id = ?", [$_SESSION['portal_cliente_id']]);
        $solicitudId = $db->insert('solicitudes', [
            'nombre'       => $clienteData['nombre'],
            'apellidos'    => $clienteData['apellidos'],
            'email'        => $clienteData['email'],
            'telefono'     => $clienteData['telefono'],
            'tipo_problema'=> $tipo,
            'descripcion'  => $desc,
            'ip_solicitante' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
        AuditLog::registrar('nueva_solicitud', 'solicitudes', $solicitudId,
            "Solicitud desde portal: {$clienteData['nombre']} {$clienteData['apellidos']} ({$clienteData['email']})");

        // Notificar admin (cola — no urgente)
        $emailAdmin = getConfig('email_despacho', SMTP_USER);
        if (!empty($emailAdmin)) {
            try {
                Mailer::encolar($emailAdmin,
                    "⚖️ Nueva Solicitud #$solicitudId — $tipo ({$clienteData['nombre']})",
                    Mailer::htmlNuevaSolicitud([
                        'nombre' => $clienteData['nombre'], 'apellidos' => $clienteData['apellidos'],
                        'email' => $clienteData['email'], 'telefono' => $clienteData['telefono'],
                        'tipo_problema' => $tipo, 'descripcion' => $desc,
                    ], $solicitudId, APP_URL));
            } catch (Exception $e) {}
        }

        // Email de confirmación al cliente (cola — no urgente)
        try {
            $htmlConf = Mailer::htmlConfirmacionCliente([
                'nombre' => $clienteData['nombre'],
                'tipo_problema' => $tipo,
            ], $solicitudId);
            Mailer::encolar($clienteData['email'], "✅ Solicitud #$solicitudId recibida — $nombreDespacho", $htmlConf);
        } catch (Exception $e) {
            error_log('[Portal] Error encolando confirmación: ' . $e->getMessage());
        }

        // Notificar admin por Telegram
        try {
            require_once CRM_ROOT . '/includes/Telegram.php';
            Telegram::enviar(
                "🟡 <b>Nueva solicitud desde portal</b>\n" .
                "👤 <b>{$clienteData['nombre']} {$clienteData['apellidos']}</b>\n" .
                "📋 {$tipo}\n" .
                "📧 {$clienteData['email']}"
            );
        } catch (Throwable $tgEx) {}

        // PRG: redirigir para evitar re-envío al recargar
        header('Location: portal.php?v=dashboard&ok=' . $solicitudId);
        exit;
    }
    $vista = 'dashboard';
}

// Leer mensaje de éxito desde GET (después del redirect)
if (!empty($_GET['ok'])) {
    $exito = 'Solicitud #' . (int)$_GET['ok'] . ' enviada correctamente.';
}

// ===== SUBIR DOCUMENTO (desde dashboard) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subir_documento']) && $logueado) {
    require_once CRM_ROOT . '/includes/FileUpload.php';
    $casoId = (int)$_POST['caso_id'];
    
    // Verificar que el caso pertenezca al cliente
    $clienteReg = $db->fetchOne("SELECT id FROM clientes WHERE email = ?", [$_SESSION['portal_cliente_email']]);
    if ($clienteReg) {
        $casoValido = $db->fetchOne("SELECT id FROM casos WHERE id = ? AND cliente_id = ?", [$casoId, $clienteReg['id']]);
        
        if ($casoValido) {
            if (isset($_FILES['documento']) && $_FILES['documento']['error'] !== UPLOAD_ERR_NO_FILE) {
                $resultado = FileUpload::subir($_FILES['documento'], $casoId);
                if ($resultado['exito']) {
                    $db->insert('documentos', array_merge($resultado['datos'], [
                        'caso_id' => $casoId,
                        'descripcion' => trim($_POST['descripcion'] ?? 'Subido desde el portal'),
                        'subido_por' => null
                    ]));
                    AuditLog::registrar('subir_documento_portal', 'documentos', $casoId, 'Documento cliente: ' . $resultado['datos']['nombre_original']);
                    $exito = 'Documento subido correctamente';
                } else {
                    $error = $resultado['mensaje'];
                }
            } else {
                $error = 'No se seleccionó ningún archivo';
            }
        } else {
            $error = 'Caso no válido';
        }
    }
    $vista = 'dashboard';
}

// ===== CARGAR DATOS PARA DASHBOARD =====
$solicitudes = [];
$casos = [];
if ($logueado) {
    $emailCliente = $_SESSION['portal_cliente_email'];
    // Excluir solicitudes aceptadas del historial — esas ya se ven en Mis Casos
    $solicitudes = $db->fetchAll("SELECT * FROM solicitudes WHERE email = ? AND estado != 'aceptada' ORDER BY created_at DESC", [$emailCliente]);
    $clienteReg = $db->fetchOne("SELECT id FROM clientes WHERE email = ?", [$emailCliente]);
    if ($clienteReg) {
        $casos = $db->fetchAll(
            "SELECT c.*, u.nombre as abogado_nombre, u.apellidos as abogado_apellidos,
                    COALESCE((SELECT SUM(p.cantidad) FROM pagos p WHERE p.caso_id = c.id), 0) as total_pagado
             FROM casos c LEFT JOIN usuarios_internos u ON c.abogado_id = u.id
             WHERE c.cliente_id = ? ORDER BY c.created_at DESC", [$clienteReg['id']]);
             
        foreach ($casos as &$c) {
            $c['documentos'] = $db->fetchAll("SELECT * FROM documentos WHERE caso_id = ? ORDER BY created_at DESC", [$c['id']]);
        }
    }
}

$estadoColor = function($e) {
    $colores = [
        'pendiente'=>['#f59e0b','<i class="fa-solid fa-hourglass-half"></i>'], 'aceptada'=>['#10b981','<i class="fa-solid fa-check"></i>'], 'denegada'=>['#ef4444','<i class="fa-solid fa-xmark"></i>'],
        'archivada'=>['#6b7280','<i class="fa-solid fa-folder"></i>'], 'en_estudio'=>['#3b82f6','<i class="fa-solid fa-magnifying-glass"></i>'], 'en_proceso'=>['#f59e0b','<i class="fa-solid fa-gear"></i>'],
        'en_tramitacion'=>['#8b5cf6','<i class="fa-solid fa-clipboard-list"></i>'], 'pendiente_juicio'=>['#ef4444','<i class="fa-solid fa-scale-balanced"></i>'], 'cerrado'=>['#10b981','<i class="fa-solid fa-check-double"></i>']
    ];
    return $colores[$e] ?? ['#6b7280','<i class="fa-solid fa-circle"></i>'];
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Portal del Cliente — <?php echo htmlspecialchars($nombreDespacho); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',sans-serif;background:linear-gradient(135deg,#1e52ab 0%,#2e6edd 40%,#3b82f6 100%);min-height:100vh;color:#374151}

/* Auth cards */
.auth-wrap{display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px}
.auth-card{background:#fff;border-radius:20px;padding:40px;width:100%;max-width:440px;box-shadow:0 25px 60px rgba(0,0,0,.3)}
.auth-logo{text-align:center;margin-bottom:28px}
.auth-logo h1{font-size:20px;font-weight:700;color:#1e52ab;margin-top:8px}
.auth-logo p{font-size:13px;color:#6b7280;margin-top:4px}
.logo-icon{margin-bottom: 10px;}
.logo-icon img { height: 60px; width: auto; }
.fg{margin-bottom:14px}
.fg label{display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px}
.fc{width:100%;padding:10px 14px;border:1.5px solid #e5e7eb;border-radius:10px;font-size:14px;font-family:inherit;transition:border-color .2s}
.fc:focus{outline:none;border-color:#2e6edd;box-shadow:0 0 0 3px rgba(46,110,221,.1)}
select.fc{appearance:auto}
.btn{width:100%;padding:12px;background:linear-gradient(135deg,#2e6edd,#3b82f6);color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:600;cursor:pointer;font-family:inherit;transition:opacity .2s}
.btn:hover{opacity:.9}
.btn-outline{background:transparent;border:1.5px solid #2e6edd;color:#2e6edd}
.btn-outline:hover{background:#2e6edd;color:#fff}
.link{text-align:center;margin-top:16px;font-size:13px;color:#6b7280}
.link a{color:#2e6edd;text-decoration:none;font-weight:500}
.alert{padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:16px}
.alert-error{background:#fef2f2;border:1px solid #fecaca;color:#dc2626}
.alert-ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534}
.row2{display:flex;gap:12px}
.row2 > *{flex:1}

/* OTP inputs */
.otp-wrap{display:flex;gap:8px;justify-content:center;margin:20px 0}
.otp-input{width:48px;height:56px;text-align:center;font-size:24px;font-weight:800;border:2px solid #e5e7eb;border-radius:10px;font-family:monospace;transition:border-color .2s}
.otp-input:focus{outline:none;border-color:#4f46e5;box-shadow:0 0 0 3px rgba(79,70,229,.1)}

/* Dashboard */
.dash{min-height:100vh;padding:16px;max-width:900px;margin:0 auto}
.dash-header{background:rgba(255,255,255,.1);backdrop-filter:blur(12px);border-radius:16px;padding:14px 20px;display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;border:1px solid rgba(255,255,255,.2);flex-wrap:wrap;gap:8px}
.dash-header h1{color:#fff;font-size:16px;font-weight:700}
.dash-header span{color:rgba(255,255,255,.8);font-size:13px}
.btn-s{color:#fff;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);padding:6px 14px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;font-family:inherit}
.btn-s:hover{background:rgba(255,255,255,.25)}
.stitle{color:#fff;font-size:15px;font-weight:700;margin-bottom:12px;display:flex;align-items:center;gap:6px}
.card{background:#fff;border-radius:16px;padding:20px;margin-bottom:20px;box-shadow:0 4px 20px rgba(0,0,0,.1)}
.sol-item{border:1.5px solid #e5e7eb;border-radius:10px;padding:14px;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
.sol-item:last-child{margin-bottom:0}
.sol-info h3{font-size:13px;font-weight:600;color:#111827;margin-bottom:2px}
.sol-info p{font-size:11px;color:#6b7280}
.badge{display:inline-flex;align-items:center;gap:3px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;color:#fff;white-space:nowrap}
.caso-item{border:1.5px solid #e5e7eb;border-radius:10px;padding:14px;margin-bottom:10px}
.caso-item:last-child{margin-bottom:0}
.caso-head{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:6px;margin-bottom:8px}
.caso-ref{font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px}
.caso-tit{font-size:14px;font-weight:700;color:#111827}
.caso-meta{display:flex;flex-wrap:wrap;gap:12px;font-size:11px;color:#6b7280}
.caso-meta span{display:flex;align-items:center;gap:3px}
.prog{height:5px;background:#e5e7eb;border-radius:3px;overflow:hidden;margin-top:10px}
.prog-fill{height:100%;background:linear-gradient(90deg,#10b981,#059669);border-radius:3px}
.empty{text-align:center;color:#9ca3af;padding:24px 0;font-size:13px}
.tabs{display:flex;gap:8px;margin-bottom:16px}
.tab{padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:none;font-family:inherit;background:rgba(255,255,255,.15);color:rgba(255,255,255,.8);transition:all .2s}
.tab.active,.tab:hover{background:#fff;color:#2e6edd}

/* Domino Tracker */
.tracker{display:flex;justify-content:space-between;align-items:center;position:relative;margin:30px 0;padding:0 10px}
.tracker::before{content:'';position:absolute;top:50%;left:20px;right:20px;height:4px;background:#e5e7eb;transform:translateY(-50%);z-index:1}
.tracker-step{position:relative;z-index:2;display:flex;flex-direction:column;align-items:center;gap:8px;background:#fff;padding:0 10px}
.tracker-dot{width:24px;height:24px;border-radius:50%;background:#e5e7eb;border:4px solid #fff;box-shadow:0 0 0 2px #e5e7eb;transition:all .3s}
.tracker-step.active .tracker-dot{background:#2e6edd;box-shadow:0 0 0 2px #2e6edd}
.tracker-label{font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:0.5px}
.tracker-step.active .tracker-label{color:#2e6edd}

/* Documents */
.doc-list{margin-top:15px;background:#f9fafb;border-radius:8px;padding:10px}
.doc-item{display:flex;justify-content:space-between;align-items:center;padding:8px 10px;border-bottom:1px solid #e5e7eb;font-size:12px}
.doc-item:last-child{border-bottom:none}
.doc-item a{color:#2e6edd;text-decoration:none;font-weight:500;display:flex;align-items:center;gap:6px}
.doc-upload{margin-top:15px;border-top:1px dashed #d1d5db;padding-top:15px}
@media(max-width:600px){
    .sol-item,.caso-head{flex-direction:column;align-items:flex-start}
    .dash-header{flex-direction:column;text-align:center}
    .row2{flex-direction:column}
    .otp-input{width:40px;height:48px;font-size:20px}
}
</style>
</head>
<body>

<?php if ($vista === 'registro'): ?>
<!-- =================== REGISTRO =================== -->
<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-logo">
      <div class="logo-icon"><img src="../assets/images/logo.png" alt="Logo"></div>
      <h1><?php echo htmlspecialchars($nombreDespacho); ?></h1>
      <p>Crear cuenta en el Portal del Cliente</p>
    </div>
    <?php if ($error): ?><div class="alert alert-error">⚠️ <?php echo $error; ?></div><?php endif; ?>
    <form method="POST">
      <input type="hidden" name="registrar" value="1">
      <div class="row2">
        <div class="fg"><label>Nombre *</label><input type="text" name="nombre" class="fc" value="<?php echo htmlspecialchars($_POST['nombre'] ?? ''); ?>" required></div>
        <div class="fg"><label>Apellidos *</label><input type="text" name="apellidos" class="fc" value="<?php echo htmlspecialchars($_POST['apellidos'] ?? ''); ?>" required></div>
      </div>
      <div class="fg"><label>Correo Electrónico *</label><input type="email" name="email" class="fc" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required></div>
      <div class="fg">
        <label>Teléfono</label>
        <div style="display:flex;gap:8px">
          <select name="codigo_pais" class="fc" style="max-width:120px">
            <option value="">Código</option>
            <option value="58">🇻🇪 +58</option><option value="34">🇪🇸 +34</option><option value="1">🇺🇸 +1</option>
            <option value="52">🇲🇽 +52</option><option value="57">🇨🇴 +57</option><option value="54">🇦🇷 +54</option>
            <option value="56">🇨🇱 +56</option><option value="51">🇵🇪 +51</option><option value="55">🇧🇷 +55</option>
            <option value="593">🇪🇨 +593</option><option value="44">🇬🇧 +44</option><option value="33">🇫🇷 +33</option>
          </select>
          <input type="text" name="numero_telefono" class="fc" placeholder="Ej: 4144016009" oninput="this.value=this.value.replace(/[^\d]/g,'')">
        </div>
      </div>
      <div class="row2">
        <div class="fg"><label>Contraseña *</label><input type="password" name="password" class="fc" minlength="6" required></div>
        <div class="fg"><label>Confirmar *</label><input type="password" name="password2" class="fc" minlength="6" required></div>
      </div>
      <button type="submit" class="btn">Crear Cuenta →</button>
    </form>
    <div class="link">¿Ya tienes cuenta? <a href="?v=login">Inicia sesión</a></div>
  </div>
</div>

<?php elseif ($vista === 'verificar'): ?>
<!-- =================== VERIFICAR OTP =================== -->
<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-logo">
      <div class="logo-icon"><img src="../assets/images/logo.png" alt="Logo"></div>
      <h1>Verificar Correo</h1>
      <p>Ingresa el código de 6 dígitos que enviamos a<br><strong><?php echo htmlspecialchars($_SESSION['portal_otp_email'] ?? ''); ?></strong></p>
    </div>
    <?php if ($error): ?><div class="alert alert-error">⚠️ <?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if (isset($_GET['reenviado'])): ?><div class="alert alert-ok">✅ Nuevo código enviado.</div><?php endif; ?>
    <form method="POST" id="otpForm">
      <input type="hidden" name="verificar_otp" value="1">
      <input type="hidden" name="codigo" id="otpHidden">
      <div class="otp-wrap">
        <?php for ($i = 0; $i < 6; $i++): ?>
        <input type="text" maxlength="1" class="otp-input" data-idx="<?php echo $i; ?>" inputmode="numeric" pattern="[0-9]" autocomplete="off">
        <?php endfor; ?>
      </div>
      <button type="submit" class="btn">Verificar Código →</button>
    </form>
    <div class="link" style="margin-top:12px">¿No recibiste el código? <a href="?v=reenviar">Reenviar</a></div>
    <div class="link" style="margin-top:8px;font-size:12px;color:#9ca3af">📁 Si no lo encuentras, revisa tu carpeta de <strong>spam</strong> o <strong>correo no deseado</strong></div>
    <div class="link"><a href="?v=registro">← Volver al registro</a></div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const inputs = document.querySelectorAll('.otp-input');
    const hidden = document.getElementById('otpHidden');
    inputs.forEach((inp, i) => {
        inp.addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '');
            if (this.value && i < 5) inputs[i+1].focus();
            hidden.value = Array.from(inputs).map(x => x.value).join('');
        });
        inp.addEventListener('keydown', function(e) {
            if (e.key === 'Backspace' && !this.value && i > 0) inputs[i-1].focus();
        });
        inp.addEventListener('paste', function(e) {
            e.preventDefault();
            const text = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g,'').slice(0,6);
            text.split('').forEach((c,j) => { if (inputs[j]) inputs[j].value = c; });
            hidden.value = text;
            if (inputs[text.length-1]) inputs[Math.min(text.length,5)].focus();
        });
    });
    inputs[0].focus();
});
</script>

<?php elseif ($vista === 'login' && !$logueado): ?>
<!-- =================== LOGIN =================== -->
<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-logo">
      <div class="logo-icon"><img src="../assets/images/logo.png" alt="Logo"></div>
      <h1><?php echo htmlspecialchars($nombreDespacho); ?></h1>
      <p>Portal del Cliente &mdash; Inicia sesión</p>
    </div>
    <?php if (isset($_GET['exp'])): ?><div class="alert alert-error">⏱️ Tu sesión ha expirado por inactividad. Inicia sesión de nuevo.</div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error">⚠️ <?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($exito): ?><div class="alert alert-ok">✅ <?php echo htmlspecialchars($exito); ?></div><?php endif; ?>
    <form method="POST">
      <input type="hidden" name="iniciar_sesion" value="1">
      <div class="fg"><label>Correo Electrónico</label><input type="email" name="email" class="fc" required autocomplete="email"></div>
      <div class="fg"><label>Contraseña</label><input type="password" name="password" class="fc" required autocomplete="current-password"></div>
      <button type="submit" class="btn">Iniciar Sesión &rarr;</button>
    </form>
    <div class="link" style="margin-top:10px"><a href="?v=recuperar">¿Olvidaste tu contraseña?</a></div>
    <div class="link">¿No tienes cuenta? <a href="?v=registro">Regístrate aquí</a></div>
  </div>
</div>

<?php elseif ($vista === 'recuperar'): ?>
<!-- =================== RECUPERAR CONTRASEÑA =================== -->
<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-logo">
      <div class="logo-icon"><img src="../assets/images/logo.png" alt="Logo"></div>
      <h1>Recuperar Contraseña</h1>
      <p>Te enviaremos un enlace a tu correo para restablecerla</p>
    </div>
    <?php if ($error): ?><div class="alert alert-error">⚠️ <?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($exito): ?><div class="alert alert-ok">✅ <?php echo htmlspecialchars($exito); ?></div><?php endif; ?>
    <?php if (!$exito): ?>
    <form method="POST">
      <input type="hidden" name="recuperar_pass" value="1">
      <div class="fg"><label>Correo Electrónico</label><input type="email" name="email" class="fc" required autocomplete="email"></div>
      <button type="submit" class="btn">Enviar enlace &rarr;</button>
    </form>
    <?php endif; ?>
    <div class="link"><a href="?v=login">&larr; Volver al login</a></div>
  </div>
</div>

<?php elseif ($vista === 'reset'): ?>
<!-- =================== RESET CONTRASEÑA =================== -->
<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-logo">
      <div class="logo-icon"><img src="../assets/images/logo.png" alt="Logo"></div>
      <h1>Nueva Contraseña</h1>
      <p>Elige una nueva contraseña para tu cuenta</p>
    </div>
    <?php if ($error): ?><div class="alert alert-error">⚠️ <?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <form method="POST">
      <input type="hidden" name="nuevo_password" value="1">
      <input type="hidden" name="reset_token" value="<?php echo htmlspecialchars($_GET['token'] ?? ($_POST['reset_token'] ?? '')); ?>">
      <div class="fg"><label>Nueva Contraseña</label><input type="password" name="nuevo_password" class="fc" minlength="6" required></div>
      <div class="fg"><label>Confirmar Contraseña</label><input type="password" name="nuevo_password2" class="fc" minlength="6" required></div>
      <button type="submit" class="btn">Guardar contraseña &rarr;</button>
    </form>
    <div class="link"><a href="?v=login">&larr; Volver al login</a></div>
  </div>
</div>

<?php else: ?>
<!-- =================== DASHBOARD =================== -->
<div class="dash">
  <div class="dash-header">
    <div>
      <h1>⚖️ <?php echo htmlspecialchars($nombreDespacho); ?></h1>
      <span>Bienvenido/a, <strong><?php echo htmlspecialchars($_SESSION['portal_cliente_nombre'] ?? 'Cliente'); ?></strong></span>
    </div>
    <a href="?salir=1" class="btn-s">Cerrar Sesión</a>
  </div>

  <?php if ($error): ?><div class="alert alert-error" style="margin-bottom:16px"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>
  <?php if ($exito): ?><div class="alert alert-ok" style="margin-bottom:16px"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($exito); ?></div><?php endif; ?>

  <div class="tabs">
    <button class="tab active" onclick="showTab('solicitar')"><i class="fa-solid fa-pen-to-square me-2"></i> Nueva Solicitud</button>
    <button class="tab" onclick="showTab('historial')"><i class="fa-solid fa-list me-2"></i> Mis Solicitudes</button>
    <?php if (!empty($casos)): ?><button class="tab" onclick="showTab('casos')"><i class="fa-solid fa-scale-balanced me-2"></i> Mis Casos</button><?php endif; ?>
  </div>

  <!-- Tab: Nueva solicitud -->
  <div id="tab-solicitar" class="card">
    <h6 style="font-size:15px;font-weight:700;margin-bottom:14px">Solicitar Consulta Legal</h6>
    <form method="POST">
      <input type="hidden" name="enviar_solicitud" value="1">
      <div class="fg">
        <label>Tipo de Problema Legal *</label>
        <select name="tipo_problema" class="fc" required>
          <option value="">Seleccione...</option>
          <option>Civil</option><option>Penal</option><option>Laboral</option><option>Mercantil</option>
          <option>Familia</option><option>Inmobiliario</option><option>Administrativo</option><option>Otro</option>
        </select>
      </div>
      <div class="fg"><label>Descripción del Problema *</label><textarea name="descripcion" class="fc" rows="4" placeholder="Describa brevemente su situación legal..." required></textarea></div>
      <button type="submit" class="btn">Enviar Solicitud</button>
    </form>
  </div>

  <!-- Tab: Historial -->
  <div id="tab-historial" class="card" style="display:none">
    <?php if (empty($solicitudes)): ?>
      <div class="empty">No tienes solicitudes pendientes.<br><small style="color:#bbb">Las solicitudes aceptadas aparecen en "Mis Casos".</small></div>
    <?php else: ?>
      <?php foreach ($solicitudes as $sol):
        [$color, $icono] = $estadoColor($sol['estado']);
        $labels = ['pendiente'=>'Pendiente', 'aceptada'=>'Aceptada', 'denegada'=>'Denegada', 'archivada'=>'Archivada', 'cancelada'=>'Cancelada'];
        $label = $labels[$sol['estado']] ?? ucfirst($sol['estado']);
      ?>
      <div class="sol-item">
        <div class="sol-info">
          <h3><?php echo htmlspecialchars($sol['tipo_problema']); ?></h3>
          <p>Enviada el <?php echo date('d/m/Y H:i', strtotime($sol['created_at'])); ?></p>
          <?php if ($sol['descripcion']): ?><p style="margin-top:3px;color:#374151"><?php echo htmlspecialchars(substr($sol['descripcion'],0,80)); ?>…</p><?php endif; ?>
        </div>
        <span class="badge" style="background:<?php echo $color; ?>"><?php echo "$icono $label"; ?></span>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Tab: Casos -->
  <?php if (!empty($casos)): ?>
  <div id="tab-casos" class="card" style="display:none">
    <?php foreach ($casos as $caso):
      [$color, $icono] = $estadoColor($caso['estado']);
      $pct = $caso['honorarios_totales'] > 0 ? min(100, round($caso['total_pagado']/$caso['honorarios_totales']*100)) : 0;
      
      $pasos = [
          ['id'=>'en_estudio', 'lbl'=>'Estudio', 'act'=>in_array($caso['estado'], ['en_estudio','en_proceso','en_tramitacion','pendiente_juicio','cerrado'])],
          ['id'=>'en_proceso', 'lbl'=>'Proceso', 'act'=>in_array($caso['estado'], ['en_proceso','en_tramitacion','pendiente_juicio','cerrado'])],
          ['id'=>'en_tramitacion', 'lbl'=>'Trámite', 'act'=>in_array($caso['estado'], ['en_tramitacion','pendiente_juicio','cerrado'])],
          ['id'=>'cerrado', 'lbl'=>'Cerrado', 'act'=>in_array($caso['estado'], ['cerrado'])]
      ];
    ?>
    <div class="caso-item">
      <div class="caso-head">
        <div>
          <div class="caso-ref"><?php echo htmlspecialchars($caso['referencia']); ?></div>
          <div class="caso-tit"><?php echo htmlspecialchars($caso['titulo']); ?></div>
        </div>
        <span class="badge" style="background:<?php echo $color; ?>"><?php echo $icono.' '.ucfirst(str_replace('_',' ',$caso['estado'])); ?></span>
      </div>
      <div class="caso-meta">
        <span><i class="fa-solid fa-scale-balanced"></i> <?php echo htmlspecialchars($caso['tipo_caso']); ?></span>
        <span><i class="fa-solid fa-user"></i> <?php echo $caso['abogado_nombre'] ? htmlspecialchars($caso['abogado_nombre'].' '.$caso['abogado_apellidos']) : 'Por asignar'; ?></span>
        <span><i class="fa-solid fa-calendar"></i> <?php echo date('d/m/Y', strtotime($caso['fecha_apertura'])); ?></span>
      </div>
      
      <!-- Domino's Tracker -->
      <div class="tracker">
        <?php foreach ($pasos as $p): ?>
        <div class="tracker-step <?php echo $p['act'] ? 'active' : ''; ?>">
            <div class="tracker-dot"></div>
            <div class="tracker-label"><?php echo $p['lbl']; ?></div>
        </div>
        <?php endforeach; ?>
      </div>

      <?php if ($caso['honorarios_totales'] > 0): ?>
      <div class="prog"><div class="prog-fill" style="width:<?php echo $pct; ?>%"></div></div>
      <p style="font-size:10px;color:#6b7280;margin-top:4px">Pagado: €<?php echo number_format($caso['total_pagado'],2,',','.'); ?> / €<?php echo number_format($caso['honorarios_totales'],2,',','.'); ?></p>
      <?php endif; ?>
      
      <!-- Documentos -->
      <div class="doc-list">
        <h4 style="font-size:12px;font-weight:700;margin-bottom:8px">Documentos del Caso</h4>
        <?php if (!empty($caso['documentos'])): ?>
            <?php foreach ($caso['documentos'] as $doc): ?>
            <div class="doc-item">
                <a href="../<?php echo htmlspecialchars($doc['ruta']); ?>" target="_blank"><i class="fa-solid fa-file-pdf"></i> <?php echo htmlspecialchars($doc['nombre_original']); ?></a>
                <span style="color:#6b7280"><?php echo date('d/m/Y', strtotime($doc['created_at'])); ?></span>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="font-size:12px;color:#9ca3af;padding:4px 0">No hay documentos compartidos.</p>
        <?php endif; ?>
      </div>
      
      <!-- Subir archivo -->
      <div class="doc-upload">
        <h4 style="font-size:12px;font-weight:700;margin-bottom:8px">Añadir Documento al Caso</h4>
        <form method="POST" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <input type="hidden" name="subir_documento" value="1">
            <input type="hidden" name="caso_id" value="<?php echo $caso['id']; ?>">
            <input type="file" name="documento" required class="fc" style="flex:1;min-width:200px;padding:6px 10px" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png">
            <input type="text" name="descripcion" placeholder="Descripción breve" class="fc" style="flex:1;min-width:150px;padding:6px 10px">
            <button type="submit" class="btn btn-outline" style="width:auto;padding:6px 12px;font-size:13px">Subir Archivo</button>
        </form>
      </div>

    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<script>
function showTab(name) {
    document.querySelectorAll('[id^="tab-"]').forEach(t => t.style.display = 'none');
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.getElementById('tab-' + name).style.display = 'block';
    event.target.classList.add('active');
}
</script>
<?php endif; ?>
</body>
</html>

