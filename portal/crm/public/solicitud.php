<?php
/**
 * CRM Abogados - Formulario Público de Solicitud
 * Accesible sin autenticación
 */
define('CRM_ROOT', dirname(__DIR__));
require_once CRM_ROOT . '/includes/config.php';
require_once CRM_ROOT . '/includes/Database.php';
require_once CRM_ROOT . '/includes/AuditLog.php';
require_once CRM_ROOT . '/includes/CSRF.php';

require_once CRM_ROOT . '/includes/Mailer.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$nombreDespacho = getConfig('nombre_despacho', 'CRM Abogados');
$exito = false;
$error = '';

// Rate limiting por IP
function verificarRateLimit() {
    $db = Database::getInstance();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $limite = (int)getConfig('rate_limit_solicitudes', 5);
    
    $count = $db->fetchColumn(
        "SELECT COUNT(*) FROM solicitudes WHERE ip_solicitante = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
        [$ip]
    );
    return $count < $limite;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verificarOAbortar();
    
    // Honeypot
    if (!empty($_POST['website'])) {
        $error = 'Solicitud rechazada';
    } elseif (!verificarRateLimit()) {
        $error = 'Ha excedido el límite de solicitudes. Intente más tarde.';
    } else {
        $nombre = trim($_POST['nombre'] ?? '');
        $apellidos = trim($_POST['apellidos'] ?? '');
        $email = trim($_POST['email'] ?? '');
        // Normalizar teléfono: código de país + número, sin ceros iniciales
        $codigoPais = preg_replace('/[^\d]/', '', $_POST['codigo_pais'] ?? '');
        $numTel = preg_replace('/[^\d]/', '', $_POST['numero_telefono'] ?? '');
        $numTel = ltrim($numTel, '0');
        $telefono = (!empty($codigoPais) && !empty($numTel)) ? '+' . $codigoPais . $numTel : '';
        $tipo = trim($_POST['tipo_problema'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        
        if (empty($nombre) || empty($apellidos) || empty($email) || empty($tipo) || empty($descripcion)) {
            $error = 'Todos los campos marcados con * son obligatorios';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'El correo electrónico no es válido';
        } else {
            $db = Database::getInstance();
            
            // Generar credenciales para portal del cliente
            $portalUser = strtolower(substr($nombre, 0, 3) . substr($apellidos, 0, 3)) . rand(100, 999);
            $portalPass = bin2hex(random_bytes(4));
            
            $solicitudId = $db->insert('solicitudes', [
                'nombre' => $nombre,
                'apellidos' => $apellidos,
                'email' => $email,
                'telefono' => $telefono,
                'tipo_problema' => $tipo,
                'descripcion' => $descripcion,
                'portal_usuario' => $portalUser,
                'portal_password_hash' => password_hash($portalPass, PASSWORD_DEFAULT),
                'ip_solicitante' => $_SERVER['REMOTE_ADDR'] ?? ''
            ]);
            
            AuditLog::registrar('nueva_solicitud', 'solicitudes', $solicitudId, "Solicitud de: $nombre $apellidos ($email)");

            // Notificación Telegram al despacho
            try {
                require_once CRM_ROOT . '/includes/Telegram.php';
                Telegram::enviar(
                    "🟡 <b>Nueva solicitud recibida</b>\n" .
                    "👤 <b>{$nombre} {$apellidos}</b>\n" .
                    "📋 Asunto: {$tipo}\n" .
                    (!empty($telefono) ? "📞 Tel: {$telefono}\n" : '') .
                    "📧 {$email}\n" .
                    "\n<i>Revísala en el CRM.</i>"
                );
            } catch (Exception $e) { error_log('[Telegram] ' . $e->getMessage()); }

            // Notificar al administrador por email
            $emailAdmin = getConfig('email_despacho', SMTP_USER);
            if (!empty($emailAdmin)) {
                try {
                    $htmlEmail = Mailer::htmlNuevaSolicitud([
                        'nombre'       => $nombre,
                        'apellidos'    => $apellidos,
                        'email'        => $email,
                        'telefono'     => $telefono,
                        'tipo_problema'=> $tipo,
                        'descripcion'  => $descripcion,
                    ], $solicitudId, APP_URL);
                    
                    Mailer::enviar(
                        $emailAdmin,
                        "⚖️ Nueva Solicitud #$solicitudId — $tipo ($nombre $apellidos)",
                        $htmlEmail
                    );
                } catch (Exception $e) {
                    error_log('[Mailer] No se pudo enviar notificación al admin: ' . $e->getMessage());
                }
            }
            
            // Email de confirmación al cliente (con credenciales del portal)
            if (!empty($email)) {
                try {
                    $htmlCliente = Mailer::htmlConfirmacionCliente([
                        'nombre'         => $nombre,
                        'tipo_problema'  => $tipo,
                        'portal_usuario' => $portalUser,
                        'portal_pass'    => $portalPass,
                        'portal_url'     => APP_URL . '/public/portal.php',
                    ], $solicitudId);
                    
                    Mailer::enviar(
                        $email,
                        "✅ Solicitud #$solicitudId recibida — CRM Abogados",
                        $htmlCliente
                    );
                } catch (Exception $e) {
                    error_log('[Mailer] No se pudo enviar confirmación al cliente: ' . $e->getMessage());
                }
            }
            
            $exito = true;
        }
    }
}

function getConfig($c, $d = '') {
    try {
        $db = Database::getInstance();
        $v = $db->fetchColumn("SELECT valor FROM configuracion WHERE clave = ?", [$c]);
        return $v !== false ? $v : $d;
    } catch (Exception $e) { return $d; }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solicitar Consulta — <?php echo htmlspecialchars($nombreDespacho); ?></title>
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/lib/bootstrap.min.css">
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/style.css">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; }
        .solicitud-card { max-width: 700px; margin: 2rem auto; }
    </style>
</head>
<body>
    <div class="container py-5">
        <div class="solicitud-card">
            <div class="card radius-16 shadow-lg border-0">
                <div class="card-body p-32">
                    <div class="text-center mb-24">
                        <h4 class="fw-bold"><?php echo htmlspecialchars($nombreDespacho); ?></h4>
                        <p class="text-secondary-light">Complete el formulario para solicitar una consulta legal</p>
                    </div>

                    <?php if ($exito): ?>
                    <div class="text-center py-24">
                        <div class="w-80-px h-80-px bg-success-main text-white rounded-circle d-flex justify-content-center align-items-center mx-auto mb-16" style="font-size:2rem">✓</div>
                        <h5 class="fw-semibold mb-8">¡Solicitud Enviada!</h5>
                        <p class="text-secondary-light">Hemos recibido su solicitud. Nos pondremos en contacto con usted a la brevedad.</p>
                        <a href="<?php echo APP_URL; ?>/public/solicitud.php" class="btn btn-outline-primary radius-8 mt-12">Enviar otra solicitud</a>
                    </div>
                    <?php else: ?>

                    <?php if ($error): ?>
                    <div class="alert alert-danger mb-16"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>

                    <form method="POST">
                        <?php echo CSRF::campo(); ?>
                        <!-- Honeypot -->
                        <div style="display:none"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>

                        <div class="row gy-3">
                            <div class="col-sm-6">
                                <label class="form-label">Nombre <span class="text-danger">*</span></label>
                                <input type="text" name="nombre" class="form-control radius-8 h-48-px" value="<?php echo htmlspecialchars($_POST['nombre'] ?? ''); ?>" required>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label">Apellidos <span class="text-danger">*</span></label>
                                <input type="text" name="apellidos" class="form-control radius-8 h-48-px" value="<?php echo htmlspecialchars($_POST['apellidos'] ?? ''); ?>" required>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label">Correo Electrónico <span class="text-danger">*</span></label>
                                <input type="email" name="email" class="form-control radius-8 h-48-px" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label">Teléfono</label>
                                <div class="d-flex gap-2">
                                    <select name="codigo_pais" class="form-select radius-8 h-48-px" style="max-width:130px">
                                        <option value="">Código</option>
                                        <option value="58"  <?php echo ($_POST['codigo_pais']??'')==='58'  ?'selected':'';?>>🇻🇪 +58</option>
                                        <option value="34"  <?php echo ($_POST['codigo_pais']??'')==='34'  ?'selected':'';?>>🇪🇸 +34</option>
                                        <option value="1"   <?php echo ($_POST['codigo_pais']??'')==='1'   ?'selected':'';?>>🇺🇸 +1</option>
                                        <option value="52"  <?php echo ($_POST['codigo_pais']??'')==='52'  ?'selected':'';?>>🇲🇽 +52</option>
                                        <option value="57"  <?php echo ($_POST['codigo_pais']??'')==='57'  ?'selected':'';?>>🇨🇴 +57</option>
                                        <option value="54"  <?php echo ($_POST['codigo_pais']??'')==='54'  ?'selected':'';?>>🇦🇷 +54</option>
                                        <option value="56"  <?php echo ($_POST['codigo_pais']??'')==='56'  ?'selected':'';?>>🇨🇱 +56</option>
                                        <option value="51"  <?php echo ($_POST['codigo_pais']??'')==='51'  ?'selected':'';?>>🇵🇪 +51</option>
                                        <option value="55"  <?php echo ($_POST['codigo_pais']??'')==='55'  ?'selected':'';?>>🇧🇷 +55</option>
                                        <option value="593" <?php echo ($_POST['codigo_pais']??'')==='593' ?'selected':'';?>>🇪🇨 +593</option>
                                        <option value="507" <?php echo ($_POST['codigo_pais']??'')==='507' ?'selected':'';?>>🇵🇦 +507</option>
                                        <option value="44"  <?php echo ($_POST['codigo_pais']??'')==='44'  ?'selected':'';?>>🇬🇧 +44</option>
                                        <option value="33"  <?php echo ($_POST['codigo_pais']??'')==='33'  ?'selected':'';?>>🇫🇷 +33</option>
                                        <option value="49"  <?php echo ($_POST['codigo_pais']??'')==='49'  ?'selected':'';?>>🇩🇪 +49</option>
                                        <option value="351" <?php echo ($_POST['codigo_pais']??'')==='351' ?'selected':'';?>>🇵🇹 +351</option>
                                    </select>
                                    <input type="text" name="numero_telefono" class="form-control radius-8 h-48-px"
                                        placeholder="Ej: 4144016009"
                                        value="<?php echo htmlspecialchars($_POST['numero_telefono'] ?? ''); ?>"
                                        oninput="this.value = this.value.replace(/[^\d]/g, '')">
                                </div>
                                <small class="text-secondary-light" style="font-size:0.78rem">Sin ceros al inicio</small>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Tipo de Problema Legal <span class="text-danger">*</span></label>
                                <select name="tipo_problema" class="form-select radius-8 h-48-px" required>
                                    <option value="">Seleccione...</option>
                                    <option value="Civil">Civil</option>
                                    <option value="Penal">Penal</option>
                                    <option value="Laboral">Laboral</option>
                                    <option value="Familia">Familia</option>
                                    <option value="Mercantil">Mercantil</option>
                                    <option value="Administrativo">Administrativo</option>
                                    <option value="Inmobiliario">Inmobiliario</option>
                                    <option value="Otro">Otro</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Descripción del Problema <span class="text-danger">*</span></label>
                                <textarea name="descripcion" class="form-control radius-8" rows="5" placeholder="Describa brevemente su situación legal..." required><?php echo htmlspecialchars($_POST['descripcion'] ?? ''); ?></textarea>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 radius-8 mt-24 h-48-px">
                            Enviar Solicitud
                        </button>
                        <p class="text-center text-sm text-secondary-light mt-12">
                            Sus datos serán tratados de forma confidencial
                        </p>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
