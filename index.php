<?php
/**
 * Landing Page — Despacho de Abogados
 * Incluye formulario combinado de Registro + Primera Solicitud
 * PARA SER SUBIDO A httpdocs/
 */

$rutas_posibles = [
    __DIR__ . '/portal/crm/includes/config.php',
    __DIR__ . '/crm/includes/config.php',
    (realpath(__DIR__ . '/../app.leadsabogados.com/portal/crm/includes/config.php') ?: ''),
    '/var/www/vhosts/leadsabogados.com/app.leadsabogados.com/portal/crm/includes/config.php'
];

$crm_root = '';
foreach ($rutas_posibles as $ruta) {
    if ($ruta && file_exists($ruta)) {
        $crm_root = dirname(dirname($ruta));
        break;
    }
}

if ($crm_root) {
    define('CRM_ROOT', $crm_root);
} else {
    define('CRM_ROOT', '/var/www/vhosts/leadsabogados.com/app.leadsabogados.com/portal/crm');
}

require_once CRM_ROOT . '/includes/config.php';
require_once CRM_ROOT . '/includes/Database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name('landing_form');
    session_start();
}

// CSRF simple para landing
if (empty($_SESSION['landing_csrf'])) {
    $_SESSION['landing_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['landing_csrf'];

function esc($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

$db = Database::getInstance();
$crmUrl = APP_URL;
$logoUrl = '/portal/crm/assets/images/logo.png';
$heroUrl = '/portal/crm/assets/images/hero_lawyer.jpg';
$videoUrl = '/portal/crm/assets/images/family_video.jpg';

// Migración: agregar columna password_plain y fecha_nacimiento si no existen
try { $db->query("ALTER TABLE portal_cuentas ADD COLUMN password_plain VARCHAR(100) DEFAULT NULL"); } catch (Throwable $e) {} 
try { $db->query("ALTER TABLE portal_cuentas ADD COLUMN fecha_nacimiento DATE DEFAULT NULL"); } catch (Throwable $e) {} 

// --- Procesar formulario ---
$formExito = false;
$formError = '';
$formData = ['nombre'=>'','apellidos'=>'','email'=>'','telefono'=>'','dni_nif'=>'','direccion'=>'','fecha_nacimiento'=>'','tipo_problema'=>'','descripcion'=>''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['consulta_submit'])) {
    if (!hash_equals($csrfToken, $_POST['_token'] ?? '')) {
        $formError = 'Token de seguridad inválido. Recargue la página.';
    }

    if (empty($formError) && !empty($_POST['website_url'])) {
        $formExito = true; 
    }

    if (empty($formError) && !$formExito) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $rlKey = 'rl_landing_' . md5($ip);
        $rlCount = $_SESSION[$rlKey . '_c'] ?? 0;
        $rlTime = $_SESSION[$rlKey . '_t'] ?? 0;

        if ($rlTime && (time() - $rlTime) > 3600) {
            $_SESSION[$rlKey . '_c'] = 0;
            $rlCount = 0;
        }
        if ($rlCount >= 3) {
            $formError = 'Ha excedido el límite de envíos. Intente más tarde.';
        }
    }

    if (empty($formError) && !$formExito) {
        $formData = [
            'nombre'          => trim($_POST['nombre'] ?? ''),
            'apellidos'       => trim($_POST['apellidos'] ?? ''),
            'email'           => trim($_POST['email'] ?? ''),
            'telefono'        => trim($_POST['telefono'] ?? ''),
            'direccion'       => trim($_POST['direccion'] ?? ''),
            'tipo_problema'   => trim($_POST['tipo_problema'] ?? '') ?: 'Otro',
            'descripcion'     => trim($_POST['descripcion'] ?? ''),
            'dni_nif'         => 'No provisto',
            'fecha_nacimiento'=> null
        ];

        $autoPassword = substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$'), 0, 10);

        if (empty($formData['nombre']) || empty($formData['apellidos']) || empty($formData['email'])) {
            $formError = 'Nombre, apellidos y email son obligatorios.';
        } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
            $formError = 'El correo electrónico no es válido.';
        }

        if (empty($formError)) {
            foreach (['nombre','apellidos'] as $campo) {
                $formData[$campo] = preg_replace('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u', '', $formData[$campo]);
            }
        }

        if (empty($formError)) {
            $existe = $db->fetchOne("SELECT id FROM portal_cuentas WHERE email = ?", [$formData['email']]);
            if ($existe) {
                $formError = 'Ya existe una cuenta con este correo. <a href="https://app.leadsabogados.com/portal" style="color:#2e6edd;font-weight:700">Inicie sesión aquí</a>';
            }
        }

        if (empty($formError)) {
            try {
                $pdo = $db->getConnection();
                $pdo->beginTransaction();

                $portalId = $db->insert('portal_cuentas', [
                    'nombre'        => $formData['nombre'],
                    'apellidos'     => $formData['apellidos'],
                    'email'         => $formData['email'],
                    'telefono'      => $formData['telefono'] ?: null,
                    'dni_nif'       => $formData['dni_nif'],
                    'direccion'     => $formData['direccion'],
                    'fecha_nacimiento'=> $formData['fecha_nacimiento'],
                    'password_hash' => password_hash($autoPassword, PASSWORD_DEFAULT),
                    'password_plain'=> $autoPassword,
                    'ip_registro'   => $_SERVER['REMOTE_ADDR'] ?? '',
                ]);

                $solId = $db->insert('solicitudes', [
                    'nombre'           => $formData['nombre'],
                    'apellidos'        => $formData['apellidos'],
                    'email'            => $formData['email'],
                    'telefono'         => $formData['telefono'] ?: null,
                    'tipo_problema'    => $formData['tipo_problema'],
                    'descripcion'      => $formData['descripcion'],
                    'estado'           => 'pendiente',
                    'portal_cuenta_id' => $portalId,
                    'ip_solicitante'   => $_SERVER['REMOTE_ADDR'] ?? '',
                ]);

                // Procesar archivos adjuntos
                $uploadsDir = CRM_ROOT . '/public/uploads/solicitudes/';
                if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0755, true);

                $blockedExts = ['php','php3','php4','php5','phtml','js','sh','exe','bat','cmd','msi','vbs','py','rb','cgi','pl'];
                $maxFileSize = 10 * 1024 * 1024; // 10 MB

                if (!empty($_FILES['archivos']['name'][0])) {
                    $files = $_FILES['archivos'];
                    $count = count($files['name']);
                    for ($i = 0; $i < $count; $i++) {
                        if ($files['error'][$i] === UPLOAD_ERR_INI_SIZE || $files['size'][$i] > $maxFileSize) {
                            throw new Exception('Uno de los archivos seleccionados ("' . htmlspecialchars($files['name'][$i]) . '") pesa demasiado. El límite es de 10MB.');
                        }
                        if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;

                        $nombreOriginal = basename($files['name'][$i]);
                        $ext = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));

                        if (in_array($ext, $blockedExts)) {
                            throw new Exception('El tipo de archivo ".' . $ext . '" no está permitido por motivos de seguridad.');
                        }

                        $realMime = $files['type'][$i];
                        if (function_exists('finfo_open')) {
                            $finfo = finfo_open(FILEINFO_MIME_TYPE);
                            if ($finfo !== false) {
                                $detectedMime = finfo_file($finfo, $files['tmp_name'][$i]);
                                if ($detectedMime !== false) $realMime = $detectedMime;
                                finfo_close($finfo);
                            }
                        }

                        $baseName = preg_replace('/[^\w\-. ]/u', '_', pathinfo($nombreOriginal, PATHINFO_FILENAME));
                        $baseName = trim($baseName, '. _');
                        $safeName = $baseName . '_' . uniqid() . ($ext ? '.' . $ext : '');
                        $destPath = $uploadsDir . $safeName;

                        if (move_uploaded_file($files['tmp_name'][$i], $destPath)) {
                            $db->insert('solicitud_archivos', [
                                'solicitud_id'       => $solId,
                                'nombre_original'    => $nombreOriginal,
                                'nombre_archivo'     => $safeName,
                                'ruta'               => 'uploads/solicitudes/' . $safeName,
                                'tipo_mime'          => $realMime,
                                'tamano_bytes'       => $files['size'][$i],
                                'subido_por_cliente' => 1,
                            ]);
                        }
                    }
                }

                $pdo->commit();
                $formExito = true;

                $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                $rlKey = 'rl_landing_' . md5($ip);
                $_SESSION[$rlKey . '_c'] = ($_SESSION[$rlKey . '_c'] ?? 0) + 1;
                $_SESSION[$rlKey . '_t'] = $_SESSION[$rlKey . '_t'] ?: time();

                $_SESSION['landing_csrf'] = bin2hex(random_bytes(32));
                $csrfToken = $_SESSION['landing_csrf'];

                $formData = ['nombre'=>'','apellidos'=>'','email'=>'','telefono'=>'','dni_nif'=>'','direccion'=>'','tipo_problema'=>'','descripcion'=>''];

            } catch (Exception $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $formError = $ex->getMessage() ?: 'Error al procesar su solicitud. Intente de nuevo.';
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
    <title>leadsabogados.com — Asesoría Jurídica Profesional</title>
    <meta name="description" content="Tu despacho de confianza. Cuéntanos tu caso y un abogado experto lo revisará de forma gratuita y sin compromiso.">
    <link rel="icon" type="image/png" href="/portal/crm/assets/images/favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,700;0,800;1,700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy:   #0b1d3a;
            --navy2:  #122855;
            --blue:   #1a56db;
            --blue2:  #2563eb;
            --accent: #3b82f6;
            --gold:   #c9a84c;
            --light:  #f0f4ff;
            --gray:   #64748b;
            --border: #e2e8f0;
            --white:  #ffffff;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }
        body { font-family: 'Inter', sans-serif; color: #1e293b; background: #fff; -webkit-font-smoothing: antialiased; overflow-x: hidden; }
        a { text-decoration: none; color: inherit; }
        img { max-width: 100%; height: auto; display: block; }

        /* ============ TOPBAR ============ */
        .topbar {
            background: var(--navy);
            color: rgba(255,255,255,0.75);
            font-size: 0.8125rem;
            padding: 8px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .topbar a { color: rgba(255,255,255,0.75); transition: color .2s; }
        .topbar a:hover { color: #fff; }
        .topbar-right { display: flex; align-items: center; gap: 24px; }

        /* ============ NAVBAR ============ */
        .navbar {
            background: #fff;
            border-bottom: 1px solid var(--border);
            padding: 0 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: 80px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 20px rgba(0,0,0,0.06);
        }
        .nav-logo { height: 52px; }
        .nav-menu { display: flex; align-items: center; gap: 32px; }
        .nav-menu a {
            font-size: 0.9375rem;
            font-weight: 500;
            color: #374151;
            transition: color .2s;
            position: relative;
            padding-bottom: 0;
        }
        .nav-menu a::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--blue2);
            transition: width .25s;
        }
        .nav-menu a:hover { color: var(--blue2); }
        .nav-menu a:hover::after { width: 100%; }
        .btn-portal { background: transparent; border: none; color: #475569; padding: 0 16px; font-weight: 600; font-size: 0.9375rem; transition: all .2s; display: inline-flex; align-items: center; gap: 6px; height: 100%; } .btn-portal:hover { color: var(--blue2); }
        .btn-portal:hover { background: var(--navy); color: #fff; }
        .btn-cta { background: var(--navy); color: #fff; padding: 12px 28px; border-radius: 99px; font-weight: 700; font-size: 0.9375rem; transition: all .2s; display: inline-block; box-shadow: 0 4px 14px rgba(11,29,58,0.25); } .btn-cta:hover { background: #1a365d; transform: translateY(-1px); box-shadow: 0 6px 20px rgba(11,29,58,0.3); }
        .btn-cta:hover { background: #1d4ed8; transform: translateY(-1px); box-shadow: 0 6px 20px rgba(37,99,235,0.4); }

        /* ============ HERO ============ */
        .hero {
            background: var(--navy);
            min-height: 92vh;
            display: grid;
            grid-template-columns: 1fr 1fr;
            position: relative;
            overflow: hidden;
        }
        
        .hero-left { position: relative; z-index: 2;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 80px 60px 80px 80px;
            position: relative;
            z-index: 5;
        }
        .hero-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(59,130,246,0.15);
            border: 1px solid rgba(59,130,246,0.3);
            border-radius: 99px;
            padding: 6px 14px;
            font-size: 0.75rem;
            font-weight: 700;
            color: #93c5fd;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 24px;
            width: fit-content;
        }
        .hero-eyebrow-dot {
            width: 6px; height: 6px; border-radius: 50%; background: #3b82f6;
            animation: pulse 1.8s infinite;
        }
        @keyframes pulse {
            0%,100% { opacity:1; transform: scale(1); }
            50% { opacity:.5; transform: scale(1.4); }
        }
        .hero-title {
            font-family: 'Playfair Display', serif;
            font-size: clamp(2.5rem, 4vw, 3.75rem);
            font-weight: 800;
            color: #fff;
            line-height: 1.1;
            margin-bottom: 24px;
            letter-spacing: -0.02em;
        }
        .hero-title em { font-style: italic; color: #93c5fd; }
        .hero-subtitle {
            font-size: 1.0625rem;
            color: rgba(255,255,255,0.65);
            line-height: 1.7;
            margin-bottom: 40px;
            max-width: 440px;
        }
        .hero-badges {
            display: flex;
            flex-direction: column;
            gap: 14px;
            margin-bottom: 48px;
        }
        .hero-badge {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.9375rem;
            color: rgba(255,255,255,0.85);
            font-weight: 500;
        }
        .badge-icon {
            width: 36px; height: 36px; border-radius: 8px;
            background: rgba(59,130,246,0.2);
            border: 1px solid rgba(59,130,246,0.35);
            display: flex; align-items: center; justify-content: center;
            color: #93c5fd; flex-shrink: 0;
        }
        .hero-divider {
            width: 48px; height: 2px;
            background: linear-gradient(90deg, #3b82f6, transparent);
            margin-bottom: 32px;
        }
        .hero-trust {
            display: flex;
            align-items: center;
            gap: 16px;
            font-size: 0.8125rem;
            color: rgba(255,255,255,0.45);
        }
        .hero-trust strong { color: rgba(255,255,255,0.7); }
        .trust-avatars { display: flex; }
        .trust-avatars div {
            width: 32px; height: 32px; border-radius: 50%;
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            border: 2px solid var(--navy);
            display: flex; align-items: center; justify-content: center;
            font-size: 0.6875rem; font-weight: 700; color: #fff;
            margin-left: -8px;
        }
        .trust-avatars div:first-child { margin-left: 0; }

        /* Hero Right — Form Panel */
        .hero-right { position: relative; z-index: 2;
            display: flex;
            align-items: center;
            justify-content: flex-end; padding: 60px 80px 60px 20px;
            z-index: 5;
        }
        .hero-img-bg {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            background-size: cover;
            background-position: center 10%;
            z-index: 0;
        }
        .hero-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(90deg, var(--navy) 0%, rgba(11,29,58,0.85) 40%, transparent 100%);
            z-index: 1;
        }
        .form-panel {
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 30px 80px rgba(0,0,0,0.45), 0 0 0 1px rgba(255,255,255,0.08);
            padding: 44px 40px;
            width: 100%;
            max-width: 440px;
            position: relative;
            z-index: 2;
        }
        .form-panel-header {
            margin-bottom: 28px;
        }
        .form-panel-tag {
            display: inline-block;
            background: #eff6ff;
            color: var(--blue2);
            font-size: 0.6875rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            padding: 4px 10px;
            border-radius: 4px;
            margin-bottom: 10px;
        }
        .form-panel h2 {
            font-family: 'Playfair Display', serif;
            font-size: 1.625rem;
            font-weight: 700;
            color: var(--navy);
            line-height: 1.2;
        }
        .form-panel h2 span { color: var(--blue2); font-style: italic; }
        .form-group { margin-bottom: 14px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px; }
        .inp {
            width: 100%;
            padding: 12px 14px;
            border: 1.5px solid #e5e7eb;
            border-radius: 8px;
            font-size: 0.9rem;
            font-family: 'Inter', sans-serif;
            color: #1e293b;
            background: #fafafa;
            transition: all .2s;
            outline: none;
        }
        .inp::placeholder { color: #9ca3af; }
        .inp:focus { border-color: var(--blue2); background: #fff; box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
        .inp-textarea {
            min-height: 90px;
            resize: vertical;
        }
        .upload-label {
            display: block;
            padding: 12px 14px;
            border: 1.5px dashed #d1d5db;
            border-radius: 8px;
            cursor: pointer;
            text-align: center;
            color: #6b7280;
            font-size: 0.8125rem;
            background: #fafafa;
            transition: all .2s;
        }
        .upload-label:hover { border-color: var(--blue2); background: #eff6ff; color: var(--blue2); }
        #archivos-input { display: none; }
        .form-alert {
            padding: 14px 16px;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            margin-bottom: 20px;
        }
        .form-alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
        .form-alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .btn-form-submit {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #1d4ed8, #2563eb);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all .25s;
            letter-spacing: 0.02em;
            box-shadow: 0 4px 16px rgba(37,99,235,0.4);
            margin-top: 6px;
        }
        .btn-form-submit:hover { background: linear-gradient(135deg, #1e40af, #1d4ed8); transform: translateY(-1px); box-shadow: 0 8px 24px rgba(37,99,235,0.45); }
        .form-security {
            text-align: center;
            font-size: 0.75rem;
            color: #9ca3af;
            margin-top: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }

        /* ============ STATS ============ */
        .stats-bar {
            background: #fff;
            border-bottom: 1px solid var(--border);
            padding: 0;
        }
        .stats-inner {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
        }
        .stat-item {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 76px 40px;
            border-right: 1px solid var(--border);
            transition: background .2s;
        }
        .stat-item:last-child { border-right: none; }
        .stat-icon {
            width: 52px; height: 52px; border-radius: 12px;
            background: var(--light);
            display: flex; align-items: center; justify-content: center;
            color: var(--blue2);
            flex-shrink: 0;
        }
        .stat-text strong { display: block; font-size: 1.375rem; font-weight: 800; color: var(--navy); margin-bottom: 2px; }
        .stat-text span { font-size: 0.875rem; color: var(--gray); font-weight: 500; }

        /* ============ VIDEO SECTION ============ */
        .video-wrap {
            position: relative;
            min-height: 480px;
            overflow: hidden;
        }
        .video-wrap video {
            position: absolute; inset: 0;
            width: 100%; height: 100%;
            object-fit: cover; z-index: 1;
        }
        .video-wrap::after {
            content: '';
            position: absolute; inset: 0; z-index: 2;
            background: linear-gradient(100deg, rgba(11,29,58,0.92) 38%, rgba(11,29,58,0.5) 70%, rgba(11,29,58,0.2) 100%);
        }
        .video-body {
            position: relative; z-index: 3;
            max-width: 1200px; margin: 0 auto;
            padding: 100px 80px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 80px;
            align-items: center;
        }
        .video-body h2 {
            font-family: 'Playfair Display', serif;
            font-size: clamp(2rem, 3vw, 3rem);
            color: #fff;
            line-height: 1.2;
            margin-bottom: 20px;
        }
        .video-body h2 span { color: #93c5fd; font-style: italic; }
        .video-body p { color: rgba(255,255,255,0.7); font-size: 1.0625rem; line-height: 1.7; margin-bottom: 32px; }
        .btn-light {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
            border: 1.5px solid rgba(255,255,255,0.25);
            color: #fff;
            padding: 12px 28px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9375rem;
            transition: all .2s;
        }
        .btn-light:hover { background: rgba(255,255,255,0.2); border-color: rgba(255,255,255,0.5); }

        /* ============ STEPS ============ */
        .steps-section {
            background: #f8fafc;
            padding: 100px 40px;
            text-align: center;
        }
        .section-eyebrow {
            display: inline-block;
            color: var(--blue2);
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            margin-bottom: 14px;
        }
        .section-title {
            font-family: 'Playfair Display', serif;
            font-size: clamp(1.75rem, 3vw, 2.5rem);
            font-weight: 800;
            color: var(--navy);
            margin-bottom: 64px;
            line-height: 1.2;
        }
        .section-title span { color: var(--blue2); font-style: italic; }
        .steps-grid {
            max-width: 900px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 32px;
            position: relative;
        }
        .steps-grid::before {
            content: '';
            position: absolute;
            top: 36px;
            left: 18%;
            right: 18%;
            height: 1px;
            border-top: 2px dashed #cbd5e1;
        }
        .step {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 36px 24px;
            position: relative;
            z-index: 2;
            transition: all .25s;
        }
        .step:hover { transform: translateY(-4px); box-shadow: 0 16px 40px rgba(0,0,0,0.08); border-color: #bfdbfe; }
        .step-num {
            width: 48px; height: 48px; border-radius: 12px;
            background: linear-gradient(135deg, var(--blue2), #1d4ed8);
            color: #fff;
            font-weight: 800;
            font-size: 1.125rem;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 20px;
            box-shadow: 0 8px 16px rgba(37,99,235,0.35);
        }
        .step h4 { font-size: 1rem; font-weight: 700; color: var(--navy); margin-bottom: 8px; }
        .step p { font-size: 0.875rem; color: var(--gray); line-height: 1.6; }

        /* ============ CTA BAND ============ */
        .cta-band {
            background: linear-gradient(135deg, var(--navy) 0%, var(--navy2) 100%);
            padding: 80px 40px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .cta-band::before {
            content: '';
            position: absolute;
            top: -60px; right: -60px;
            width: 320px; height: 320px;
            border-radius: 50%;
            background: rgba(59,130,246,0.08);
        }
        .cta-band::after {
            content: '';
            position: absolute;
            bottom: -80px; left: -60px;
            width: 400px; height: 400px;
            border-radius: 50%;
            background: rgba(59,130,246,0.06);
        }
        .cta-band-inner { position: relative; z-index: 2; max-width: 680px; margin: 0 auto; }
        .cta-band h2 {
            font-family: 'Playfair Display', serif;
            font-size: clamp(2rem, 3.5vw, 3rem);
            color: #fff;
            margin-bottom: 16px;
            line-height: 1.2;
        }
        .cta-band h2 span { color: #93c5fd; font-style: italic; }
        .cta-band p { color: rgba(255,255,255,0.65); font-size: 1.125rem; margin-bottom: 40px; line-height: 1.6; }
        .cta-band-btns { display: flex; gap: 16px; justify-content: center; flex-wrap: wrap; }
        .btn-white {
            background: #fff;
            color: var(--navy);
            padding: 14px 32px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.9375rem;
            transition: all .2s;
            box-shadow: 0 4px 16px rgba(0,0,0,0.2);
        }
        .btn-white:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,0.25); }
        .btn-outline-white {
            border: 2px solid rgba(255,255,255,0.35);
            color: #fff;
            padding: 14px 32px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9375rem;
            transition: all .2s;
        }
        .btn-outline-white:hover { border-color: #fff; background: rgba(255,255,255,0.1); }

        /* ============ FOOTER ============ */
        .footer { background: #ffffff; border-top: 1px solid var(--border); padding: 48px 80px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 24px; }
        .footer-logo { height: 70px; }
        .footer-copy { color: var(--gray); font-size: 0.875rem; }
        .footer-right { display: flex; align-items: center; gap: 20px; }
        .footer-portal { display: inline-flex; align-items: center; gap: 8px; background: #f8fafc; border: 1px solid var(--border); color: var(--navy); padding: 12px 24px; border-radius: 8px; font-size: 0.9375rem; font-weight: 600; transition: all .2s; } .footer-portal:hover { background: #f1f5f9; color: var(--blue2); }
        .footer-portal:hover { background: rgba(255,255,255,0.15); color: #fff; }

        /* ============ RESPONSIVE ============ */
        @media (max-width: 1024px) {
            .hero { grid-template-columns: 1fr; min-height: auto; }
            .hero-left { position: relative; z-index: 2; padding: 60px 32px 40px; }
            .hero-right { padding: 20px 32px 60px; }
            .video-body { grid-template-columns: 1fr; padding: 60px 32px; gap: 40px; }
            .steps-grid { grid-template-columns: 1fr; }
            .steps-grid::before { display: none; }
            .stats-inner { grid-template-columns: 1fr; }
            .stat-item { border-right: none; border-bottom: 1px solid var(--border); padding: 24px 32px; }
            .stat-item:last-child { border-bottom: none; }
            .footer { background: #ffffff; border-top: 1px solid var(--border); padding: 48px 80px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 24px; }
        }
        @media (max-width: 768px) {
            .topbar { display: none; }
            .navbar { padding: 0 20px; height: 68px; }
            .nav-logo { height: 40px; }
            .nav-menu { gap: 12px; }
            .hero-left { position: relative; z-index: 2; padding: 48px 20px 32px; }
            .hero-right { padding: 0 20px 48px; }
            .form-panel { padding: 28px 20px; }
            .form-row { grid-template-columns: 1fr; }
            .cta-band { padding: 60px 20px; }
            .btn-portal span { display: none; }
        }
    </style>
</head>
<body>

<!-- TOP ANNOUNCEMENT BAR -->
<div class="topbar">
    <span>Consulta gratuita · Sin compromiso · Respuesta en 24h</span>
    <div class="topbar-right">
        
        <a href="https://app.leadsabogados.com/portal" style="color:rgba(255,255,255,0.8)">Acceso Portal Cliente &rarr;</a>
    </div>
</div>

<!-- NAVBAR -->
<nav class="navbar" id="top">
    <a href="/"><img src="<?php echo $logoUrl; ?>" alt="leadsabogados.com" class="nav-logo"></a>
    <div class="nav-menu">
        <a href="#contacto">Consulta gratis</a>
        <a href="#como-funciona">¿Cómo funciona?</a>
        
        <a href="https://app.leadsabogados.com/portal" class="btn-portal">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <span>Mi Portal</span>
        </a>
        
    </div>
</nav>

<!-- HERO -->
<section class="hero" id="contacto">
    <div class="hero-img-bg" style="background-image: url('<?php echo $heroUrl; ?>');"></div>
    <div class="hero-overlay"></div>
    <div class="hero-left">
        <div class="hero-eyebrow">
            <span class="hero-eyebrow-dot"></span>
            Consulta 100% gratuita y confidencial
        </div>
        <h1 class="hero-title">Tu caso merece<br>la mejor <em>solución legal</em></h1>
        <p class="hero-subtitle">Cuéntanos qué ha ocurrido y un abogado especialista revisará tu situación de forma gratuita, rápida y sin ningún compromiso.</p>
        <div class="hero-divider"></div>
        <div class="hero-badges">
            <div class="hero-badge">
                <div class="badge-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
                Respuesta garantizada en menos de 24 horas
            </div>
            <div class="hero-badge">
                <div class="badge-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></div>
                Confidencialidad absoluta en tu caso
            </div>
            <div class="hero-badge">
                <div class="badge-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
                Sin compromiso — tú decides si continúas
            </div>
        </div>
        
    </div>

    <div class="hero-right">
        <div class="form-panel">
            <div class="form-panel-header">
                <span class="form-panel-tag">Consulta gratuita</span>
                <h2>Cuéntanos <span>tu caso</span></h2>
            </div>

            <?php if ($formExito): ?>
                <div class="form-alert form-alert-success">
                    <strong>✓ ¡Solicitud recibida!</strong><br>
                    Nos pondremos en contacto contigo en menos de 24h. Recibirás también los datos de acceso a tu Portal del Cliente.
                </div>
            <?php else: ?>
                <?php if (!empty($formError)): ?>
                    <div class="form-alert form-alert-error"><?php echo $formError; ?></div>
                <?php endif; ?>

                <form action="index.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="consulta_submit" value="1">
                    <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="text" name="website_url" style="display:none" tabindex="-1" autocomplete="off">

                    <div class="form-row">
                        <input type="text" name="nombre" class="inp" placeholder="Nombre *" value="<?php echo esc($formData['nombre']); ?>" required>
                        <input type="text" name="apellidos" class="inp" placeholder="Apellidos *" value="<?php echo esc($formData['apellidos']); ?>" required>
                    </div>
                    <div class="form-row">
                        <input type="tel" name="telefono" class="inp" placeholder="Teléfono *" value="<?php echo esc($formData['telefono']); ?>" required>
                        <input type="email" name="email" class="inp" placeholder="Email *" value="<?php echo esc($formData['email']); ?>" required>
                    </div>
                    <div class="form-group">
                        <input type="text" name="direccion" class="inp" placeholder="Provincia / Ciudad" value="<?php echo esc($formData['direccion']); ?>">
                    </div>
                    <div class="form-group">
                        <textarea name="descripcion" class="inp inp-textarea" placeholder="Describe brevemente tu caso *" required><?php echo esc($formData['descripcion']); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label class="upload-label" for="archivos-input">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline;vertical-align:middle;margin-right:6px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                            Adjuntar documentos <span style="opacity:.6;">(opcional · máx. 10MB)</span>
                        </label>
                        <input type="file" name="archivos[]" id="archivos-input" multiple accept="*/*" onchange="this.previousElementSibling.textContent = this.files.length + ' archivo(s) seleccionado(s)'">
                    </div>
                    <button type="submit" class="btn-form-submit">
                        Quiero que revisen mi caso &rarr;
                    </button>
                    <div class="form-security">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        Información 100% segura y confidencial
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- STATS BAR -->
<section class="stats-bar">
    <div class="stats-inner">
        <div class="stat-item">
            <div class="stat-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
            <div class="stat-text"><strong>Trato personal</strong><span>Atención a medida para tu caso</span></div>
        </div>
        <div class="stat-item">
            <div class="stat-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
            <div class="stat-text"><strong>Abogados expertos</strong><span>Especialistas en cada área legal</span></div>
        </div>
        <div class="stat-item">
            <div class="stat-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></div>
            <div class="stat-text"><strong>100% Seguro</strong><span>Tu privacidad está garantizada</span></div>
        </div>
    </div>
</section>

<!-- VIDEO SECTION -->
<section class="video-wrap">
    <video autoplay muted loop playsinline>
        <source src="/portal/crm/assets/images/promo.mp4" type="video/mp4">
    </video>
    <div class="video-body">
        <div>
            <h2>Estamos aquí para proteger <span>lo que más te importa</span></h2>
            <p>Cada caso es único. Nuestros abogados analizan tu situación con detalle y te ofrecen la mejor estrategia legal posible.</p>
            <a href="#contacto" class="btn-light">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                Hablar con un abogado
            </a>
        </div>
        <div></div>
    </div>
</section>

<!-- HOW IT WORKS -->
<section class="steps-section" id="como-funciona">
    <span class="section-eyebrow">Proceso simple y rápido</span>
    <h2 class="section-title">¿Cómo <span>funciona?</span></h2>
    <div class="steps-grid">
        <div class="step">
            <div class="step-num">01</div>
            <h4>Cuéntanos tu caso</h4>
            <p>Rellena el formulario en menos de 2 minutos con los detalles de tu situación.</p>
        </div>
        <div class="step">
            <div class="step-num">02</div>
            <h4>Un abogado lo revisa</h4>
            <p>Un especialista analiza tu caso en profundidad y prepara la mejor estrategia.</p>
        </div>
        <div class="step">
            <div class="step-num">03</div>
            <h4>Hablamos contigo</h4>
            <p>Nos ponemos en contacto contigo en menos de 24h con una solución clara.</p>
        </div>
    </div>
</section>

<!-- CTA BAND -->
<section class="cta-band">
    <div class="cta-band-inner">
        <h2>¿Ya tienes una cuenta? Accede a <span>tu portal</span></h2>
        <p>Consulta el estado de tu caso, documentos y comunicaciones con tu abogado desde cualquier dispositivo.</p>
        <div class="cta-band-btns">
            <a href="https://app.leadsabogados.com/portal" class="btn-white">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline;vertical-align:middle;margin-right:6px"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                Entrar al Portal del Cliente
            </a>
            <a href="#contacto" class="btn-outline-white">Primera consulta gratis &rarr;</a>
        </div>
    </div>
</section>

<!-- FOOTER -->
<footer class="footer">
    <img src="<?php echo $logoUrl; ?>" alt="leadsabogados.com" class="footer-logo">
    <span class="footer-copy">© <?php echo date('Y'); ?> leadsabogados.com · Todos los derechos reservados</span>
    <div class="footer-right">
        <a href="https://app.leadsabogados.com/portal" class="footer-portal">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            Portal del Cliente
        </a>
    </div>
</footer>

<script>
// Sticky navbar shadow on scroll
window.addEventListener('scroll', () => {
    document.querySelector('.navbar').style.boxShadow =
        window.scrollY > 10 ? '0 4px 24px rgba(0,0,0,0.10)' : '0 2px 20px rgba(0,0,0,0.06)';
});
</script>
</body>
</html>








