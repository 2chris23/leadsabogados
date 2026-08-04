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
                $formError = 'Ya existe una cuenta con este correo. <a href="/portal/" style="color:#2e6edd;font-weight:700">Inicie sesión aquí</a>';
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
    <title>LA leadsabogados.com — Tu caso merece la mejor solución legal</title>
    <link rel="icon" type="image/png" href="/portal/crm/assets/images/favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #0a1f44;
            --secondary: #1a4fba;
            --accent: #2e6edd;
            --text-main: #111827;
            --text-light: #6b7280;
            --bg-gray: #f3f4f6;
            --bg-light: #f8fafc;
            --white: #ffffff;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; color: var(--text-main); background: var(--bg-gray); -webkit-font-smoothing: antialiased; }
        a { text-decoration: none; color: inherit; }
        img { max-width: 100%; height: auto; display: block; }
        
        /* Typography */
        h1, h2, h3 { font-family: 'Playfair Display', serif; }

        /* Navbar */
        .navbar {
            background: var(--white);
            padding: 16px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 50;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .nav-logo { height: 40px; }
        .nav-right { display: flex; align-items: center; gap: 24px; }
        .nav-phone { font-weight: 700; display: flex; align-items: center; gap: 8px; font-size: 1rem; }
        .btn-dark {
            background: var(--primary);
            color: var(--white);
            padding: 12px 24px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 0.875rem;
            border: none;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-dark:hover { background: #000; color: white; }

        /* Hero Section */
        .hero {
            position: relative;
            background: var(--bg-gray);
            overflow: hidden;
            display: flex;
            align-items: center;
        }
        .hero-bg {
            position: absolute;
            right: 0;
            top: 0;
            width: 50%;
            height: 100%;
            object-fit: cover;
            object-position: top center;
        }
        .hero-container {
            max-width: 1200px;
            margin: 0 auto;
            width: 100%;
            padding: 80px 24px;
            position: relative;
            z-index: 10;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            align-items: center;
        }
        .hero-content {
            padding-right: 40px;
        }
        .hero-title {
            font-size: 3.5rem;
            line-height: 1.1;
            font-weight: 700;
            margin-bottom: 24px;
            color: var(--text-main);
        }
        .hero-title span { color: var(--secondary); }
        .hero-subtitle {
            font-size: 1.125rem;
            line-height: 1.6;
            color: var(--text-light);
            margin-bottom: 32px;
        }
        .hero-features { list-style: none; margin-bottom: 32px; }
        .hero-features li {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
            font-weight: 500;
        }
        .hero-features svg { color: var(--secondary); flex-shrink: 0; }
        
        /* Form Card */
        .form-card {
            background: var(--white);
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.08);
            margin-left: 20px;
        }
        .form-card h3 {
            font-size: 1.75rem;
            margin-bottom: 24px;
            font-family: 'Playfair Display', serif;
        }
        .form-card h3 span { color: var(--secondary); }
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 16px;
        }
        .input-field {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 0.9375rem;
            font-family: 'Inter', sans-serif;
        }
        .input-field:focus { outline: none; border-color: var(--secondary); }
        .textarea-field {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 0.9375rem;
            min-height: 100px;
            font-family: 'Inter', sans-serif;
            margin-bottom: 16px;
            resize: vertical;
        }
        .checkbox-row {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.8125rem;
            color: var(--text-light);
            margin-bottom: 24px;
        }
        .btn-submit {
            width: 100%;
            background: var(--primary);
            color: var(--white);
            padding: 16px;
            border: none;
            border-radius: 4px;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            transition: background 0.2s;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .btn-submit:hover { background: #000; }

        .alert { padding: 16px; border-radius: 4px; margin-bottom: 24px; font-weight: 500; font-size: 0.9375rem; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

        /* Stats / Features Bar */
        .features-bar {
            background: var(--white);
            border-top: 1px solid #e5e7eb;
            border-bottom: 1px solid #e5e7eb;
            padding: 40px 24px;
        }
        .features-grid {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 40px;
            text-align: center;
        }
        .feature-item { display: flex; flex-direction: column; align-items: center; }
        .feature-icon { width: 48px; height: 48px; margin-bottom: 16px; color: var(--secondary); display: flex; align-items: center; justify-content: center; border: 1px solid #e5e7eb; border-radius: 50%; }
        .feature-title { font-weight: 700; margin-bottom: 8px; font-size: 1rem; }
        .feature-desc { color: var(--text-light); font-size: 0.875rem; line-height: 1.5; }

        /* Video Section */
        .video-section {
            position: relative;
            background: var(--primary);
            color: var(--white);
            padding: 100px 24px;
            display: flex;
            align-items: center;
            overflow: hidden;
        }
        .video-bg {
            position: absolute;
            right: 0;
            top: 0;
            width: 55%;
            height: 100%;
            object-fit: cover;
            opacity: 0.5;
        }
        .video-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(90deg, var(--primary) 40%, transparent 100%);
        }
        .video-content {
            position: relative;
            z-index: 10;
            max-width: 1200px;
            margin: 0 auto;
            width: 100%;
            display: flex;
            align-items: center;
        }
        .video-text { max-width: 500px; }
        .video-text h2 { font-size: 2.5rem; margin-bottom: 24px; line-height: 1.2; font-family: 'Playfair Display', serif; }
        .video-text h2 span { color: var(--accent); }
        .video-text p { font-size: 1.125rem; color: #d1d5db; margin-bottom: 32px; border-top: 1px solid rgba(255,255,255,0.2); padding-top: 24px; }
        .play-btn {
            width: 64px; height: 64px; border-radius: 50%; border: 2px solid white; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.3); cursor: pointer; transition: transform 0.2s;
            position: absolute; left: 60%; top: 50%; transform: translate(-50%, -50%);
        }
        .play-btn:hover { transform: translate(-50%, -50%) scale(1.1); }
        .play-btn svg { fill: white; margin-left: 4px; }
        
        /* Process Steps */
        .process-section {
            background: var(--bg-light);
            padding: 80px 24px;
            text-align: center;
        }
        .process-title { font-size: 2rem; margin-bottom: 60px; }
        .process-title span { color: var(--secondary); }
        .process-grid {
            max-width: 1000px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 40px;
            position: relative;
        }
        .process-step { position: relative; z-index: 2; background: var(--bg-light); padding: 0 20px; }
        .step-number { font-size: 3rem; font-family: 'Playfair Display', serif; color: #e5e7eb; font-weight: 700; margin-bottom: -15px; position: relative; z-index: 1; }
        .step-icon { width: 64px; height: 64px; margin: 0 auto 16px; background: var(--white); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: var(--secondary); box-shadow: 0 4px 6px rgba(0,0,0,0.05); position: relative; z-index: 2; border: 1px solid #e5e7eb; }
        .step-title { font-weight: 700; margin-bottom: 8px; }
        .step-desc { font-size: 0.875rem; color: var(--text-light); }
        .process-line {
            position: absolute; top: 60px; left: 15%; right: 15%; height: 1px; border-top: 1px dashed #cbd5e1; z-index: 0;
        }

        /* Bottom CTA */
        .bottom-cta {
            display: flex;
            align-items: center;
            background: var(--white);
        }
        .cta-img { width: 50%; object-fit: cover; height: 500px; }
        .cta-content { padding: 60px 80px; width: 50%; }
        .cta-content h2 { font-size: 2.5rem; margin-bottom: 24px; line-height: 1.2; }
        .cta-content h2 span { color: var(--secondary); }
        .cta-content p { color: var(--text-light); margin-bottom: 32px; font-size: 1.125rem; }

        /* Footer */
        .footer {
            background: var(--white);
            border-top: 1px solid #e5e7eb;
            padding: 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .footer-logo { height: 32px; }
        .footer-links { display: flex; gap: 24px; font-size: 0.875rem; color: var(--text-light); }
        .footer-copy { text-align: center; color: var(--text-light); font-size: 0.75rem; width: 100%; display: block; margin-top: 24px; }

        /* Responsive */
        @media (max-width: 1024px) {
            .hero-container, .bottom-cta { grid-template-columns: 1fr; flex-direction: column; }
            .hero-bg, .video-bg { width: 100%; height: 50%; top: auto; bottom: 0; }
            .cta-img, .cta-content { width: 100%; }
            .play-btn { left: 50%; top: 75%; }
            .process-grid, .features-grid { grid-template-columns: 1fr; gap: 40px; }
            .process-line { display: none; }
        }
        @media (max-width: 768px) {
            .navbar { padding: 16px; }
            .nav-phone { display: none; }
            .hero-title { font-size: 2.5rem; }
            .form-card { margin-left: 0; padding: 24px; }
            .form-grid { grid-template-columns: 1fr; }
            .footer { flex-direction: column; gap: 20px; }
        }
    </style>
</head>
<body>

    <!-- NAVBAR -->
    <nav class="navbar">
        <a href="/">
            <img src="<?php echo $logoUrl; ?>" alt="LA leadsabogados" class="nav-logo">
        </a>
        <div class="nav-right">
            <a href="tel:900123456" class="nav-phone">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                900 123 456
            </a>
            <a href="#contacto" class="btn-dark">Te llamamos</a>
        </div>
    </nav>

    <!-- HERO SECTION -->
    <section class="hero">
        <img src="<?php echo $heroUrl; ?>" alt="Abogada" class="hero-bg">
        <div class="hero-container">
            <div class="hero-content">
                <h1 class="hero-title">Tu caso merece la mejor <span>solución legal</span></h1>
                <p class="hero-subtitle">Cuéntanos qué ha ocurrido y un abogado experto revisará tu caso de forma gratuita y sin compromiso.</p>
                <ul class="hero-features">
                    <li><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10zm-1.177-7.86l-2.765-2.765 1.414-1.414 1.351 1.351 4.316-4.316 1.414 1.414-5.73 5.73z"/></svg> Respuesta en menos de 24h</li>
                    <li><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10zm-1.177-7.86l-2.765-2.765 1.414-1.414 1.351 1.351 4.316-4.316 1.414 1.414-5.73 5.73z"/></svg> Confidencialidad 100% garantizada</li>
                    <li><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10zm-1.177-7.86l-2.765-2.765 1.414-1.414 1.351 1.351 4.316-4.316 1.414 1.414-5.73 5.73z"/></svg> Sin compromiso alguno</li>
                </ul>
            </div>
            
            <div class="form-card" id="contacto">
                <h3>Cuéntanos <span>tu caso</span></h3>
                
                <?php if ($formExito): ?>
                    <div class="alert alert-success">
                        ¡Gracias por contactarnos! Hemos recibido su consulta y nos pondremos en contacto con usted en breve.<br><br>
                        Le hemos enviado un correo con los datos de acceso al Portal del Cliente para que pueda hacer seguimiento de su caso.
                    </div>
                <?php else: ?>
                    <?php if (!empty($formError)): ?>
                        <div class="alert alert-error"><?php echo $formError; ?></div>
                    <?php endif; ?>

                    <form action="index.php" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="consulta_submit" value="1">
                        <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="text" name="website_url" style="display:none" tabindex="-1" autocomplete="off">
                        
                        <div class="form-grid">
                            <input type="text" name="nombre" class="input-field" placeholder="Nombre" value="<?php echo esc($formData['nombre']); ?>" required>
                            <input type="text" name="apellidos" class="input-field" placeholder="Apellidos" value="<?php echo esc($formData['apellidos']); ?>" required>
                        </div>
                        <div class="form-grid">
                            <input type="tel" name="telefono" class="input-field" placeholder="Teléfono" value="<?php echo esc($formData['telefono']); ?>" required>
                            <input type="email" name="email" class="input-field" placeholder="Email" value="<?php echo esc($formData['email']); ?>" required>
                        </div>
                        <input type="text" name="direccion" class="input-field" placeholder="Provincia / Ciudad" value="<?php echo esc($formData['direccion']); ?>" style="width:100%;margin-bottom:16px;">
                        
                        <textarea name="descripcion" class="textarea-field" placeholder="Describe tu caso" required><?php echo esc($formData['descripcion']); ?></textarea>
                        
                        <div style="margin-bottom:20px;">
                            <label style="display:block;font-size:.875rem;color:#6b7280;margin-bottom:6px;">Adjuntar documentos <span style="font-size:.8rem;color:#9ca3af;">(opcional, máx. 10MB)</span></label>
                            <input type="file" name="archivos[]" multiple accept="*/*" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:4px;font-size:.875rem;background:#f9fafb;">
                        </div>

                        <button type="submit" class="btn-submit">Quiero que revisen mi caso</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- STATS / FEATURES BAR -->
    <section class="features-bar">
        <div class="features-grid">
            <div class="feature-item">
                <div class="feature-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
                <h4 class="feature-title">Respuesta rápida</h4>
                <p class="feature-desc">Un abogado se pondrá en contacto contigo en menos de 24 horas.</p>
            </div>
            <div class="feature-item">
                <div class="feature-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></div>
                <h4 class="feature-title">Confidencial y seguro</h4>
                <p class="feature-desc">Tratamos tu caso con la máxima confidencialidad y protección de datos.</p>
            </div>
            <div class="feature-item">
                <div class="feature-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div>
                <h4 class="feature-title">Sin compromiso</h4>
                <p class="feature-desc">Estudiamos tu caso gratis y tú decides sin ningún tipo de obligación.</p>
            </div>
        </div>
    </section>

    <!-- VIDEO SECTION -->
    <section class="video-section">
        <div class="video-overlay"></div>
        <img src="<?php echo $videoUrl; ?>" alt="Video" class="video-bg">
        <div class="play-btn">
            <svg width="24" height="24" viewBox="0 0 24 24"><path d="M5 3l14 9-14 9z"/></svg>
        </div>
        <div class="video-content">
            <div class="video-text">
                <h2>Estamos aquí para proteger <span>lo que más te importa</span></h2>
                <p>Déjanos ayudarte a encontrar la mejor solución legal para tu caso.</p>
            </div>
        </div>
    </section>

    <!-- HOW IT WORKS -->
    <section class="process-section">
        <h2 class="process-title">¿Cómo <span>funciona?</span></h2>
        <div class="process-grid">
            <div class="process-line"></div>
            <div class="process-step">
                <div class="step-number">01</div>
                <div class="step-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg></div>
                <h4 class="step-title">Cuéntanos tu caso</h4>
                <p class="step-desc">Completa el formulario con los detalles de tu situación.</p>
            </div>
            <div class="process-step">
                <div class="step-number">02</div>
                <div class="step-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
                <h4 class="step-title">Un abogado lo revisa</h4>
                <p class="step-desc">Un especialista analiza tu caso y te dará la mejor solución.</p>
            </div>
            <div class="process-step">
                <div class="step-number">03</div>
                <div class="step-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg></div>
                <h4 class="step-title">Hablamos contigo</h4>
                <p class="step-desc">Nos pondremos en contacto contigo para informarte sin compromiso.</p>
            </div>
        </div>
    </section>

    <!-- BOTTOM CTA -->
    <section class="bottom-cta">
        <img src="<?php echo $videoUrl; ?>" alt="Familia feliz" class="cta-img">
        <div class="cta-content">
            <h2>Estamos aquí para proteger <span>lo que más te importa</span></h2>
            <p>Déjanos ayudarte a encontrar la mejor solución legal para tu caso.</p>
            <a href="#contacto" class="btn-dark" style="display:inline-block">Hablar con un abogado</a>
        </div>
    </section>

    <!-- FOOTER -->
    <footer class="footer">
        <div>
            <img src="<?php echo $logoUrl; ?>" alt="LA leadsabogados" class="footer-logo">
        </div>
        <div class="footer-links">
            <a href="#">Política de privacidad</a>
            <a href="#">Aviso legal</a>
            <a href="#">Política de cookies</a>
        </div>
        <span class="footer-copy">© leadsabogados.com - Todos los derechos reservados</span>
    </footer>

</body>
</html>
