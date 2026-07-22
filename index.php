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
$crmUrl = APP_URL; // Usar el APP_URL definido en config.php (https://app.leadsabogados.com/portal/crm o similar)
$logoUrl = 'https://app.leadsabogados.com/portal/crm/assets/images/logo.png';
$heroUrl = 'https://app.leadsabogados.com/portal/crm/assets/images/hero-abogados.png';

// Migración: agregar columna password_plain y fecha_nacimiento si no existen
try {
    $db->query("ALTER TABLE portal_cuentas ADD COLUMN password_plain VARCHAR(100) DEFAULT NULL");
} catch (Throwable $e) {} 
try {
    $db->query("ALTER TABLE portal_cuentas ADD COLUMN fecha_nacimiento DATE DEFAULT NULL");
} catch (Throwable $e) {} 


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
            'dni_nif'         => trim($_POST['dni_nif'] ?? ''),
            'direccion'       => trim($_POST['direccion'] ?? ''),
            'fecha_nacimiento'=> trim($_POST['fecha_nacimiento'] ?? ''),
            'tipo_problema'   => trim($_POST['tipo_problema'] ?? '') ?: 'Otro',
            'descripcion'     => trim($_POST['descripcion'] ?? '')
        ];

        $autoPassword = substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$'), 0, 10);

        if (empty($formData['nombre']) || empty($formData['apellidos']) || empty($formData['dni_nif']) || empty($formData['direccion']) || empty($formData['fecha_nacimiento'])) {
            $formError = 'Nombre, apellidos, DNI/NIF, dirección y fecha de nacimiento son obligatorios.';
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
                $formError = 'Ya existe una cuenta con este correo. <a href="https://app.leadsabogados.com/portal/index.php?page=login" style="color:#2e6edd;font-weight:700">Inicie sesión aquí</a>';
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
                    'fecha_nacimiento'=> $formData['fecha_nacimiento'] ?: null,
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

                $allowedExts = ['jpg','jpeg','png','gif','webp','pdf','doc','docx','xls','xlsx','zip','rar','txt'];
                $blockedExts = ['php','php3','php4','php5','phtml','js','sh','exe','bat','cmd','msi','vbs','py','rb'];
                $maxFileSize = 10 * 1024 * 1024;

                if (!empty($_FILES['archivos']['name'][0])) {
                    $files = $_FILES['archivos'];
                    $count = count($files['name']);
                    for ($i = 0; $i < $count; $i++) {
                        if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
                        if ($files['size'][$i] > $maxFileSize) continue;

                        $nombreOriginal = basename($files['name'][$i]);
                        $ext = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));

                        if (in_array($ext, $blockedExts)) continue;
                        if (!in_array($ext, $allowedExts)) continue;

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
                $formError = 'Error al procesar su solicitud. Intente de nuevo.';
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
    <title>Despacho de Abogados — Asesoría Jurídica Integral</title>
    <meta name="description" content="Despacho de abogados especializado en derecho civil, penal, laboral y mercantil. Consulte su caso desde nuestro portal seguro.">
    <link rel="icon" type="image/png" href="<?php echo $logoUrl; ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; color: #1a1a2e; background: #fff; overflow-x: hidden; }
        a { text-decoration: none; color: inherit; }
        img { max-width: 100%; height: auto; }

        :root {
            --primary: #2e6edd;
            --primary-dark: #1e52ab;
            --primary-light: #e8f0fe;
            --dark: #0f172a;
            --text: #1a1a2e;
            --muted: #64748b;
            --border: #e2e8f0;
        }

        /* --- NAV --- */
        .nav {
            position: fixed; top: 0; width: 100%; z-index: 100;
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(226,232,240,0.6);
            transition: all 0.3s;
        }
        .nav-inner {
            max-width: 1200px; margin: 0 auto;
            padding: 0 24px; height: 72px;
            display: flex; align-items: center; justify-content: space-between;
        }
        .nav-logo { display: flex; align-items: center; gap: 10px; }
        .nav-logo img { height: 36px; }
        .nav-logo span { font-weight: 800; font-size: 1.125rem; color: var(--primary); letter-spacing: -0.02em; }
        .nav-links { display: flex; align-items: center; gap: 32px; }
        .nav-links a { font-size: 0.875rem; font-weight: 600; color: var(--muted); transition: color 0.2s; }
        .nav-links a:hover { color: var(--primary); }
        .nav-cta {
            padding: 10px 24px; background: var(--primary); color: #fff !important;
            border-radius: 12px; font-weight: 700; font-size: 0.875rem;
            transition: all 0.2s; border: none; cursor: pointer;
            display: inline-block;
        }
        .nav-cta:hover { background: var(--primary-dark); color: #fff !important; transform: translateY(-1px); box-shadow: 0 8px 24px rgba(46,110,221,0.25); }

        @media (max-width: 768px) {
            .nav-links { display: none; }
            .nav-inner { padding: 0 16px; }
        }

        /* --- HERO --- */
        .hero {
            padding: 140px 24px 80px;
            background: linear-gradient(180deg, #f8fafc 0%, #fff 100%);
            position: relative;
        }
        .hero-inner {
            max-width: 1200px; margin: 0 auto;
            display: grid; grid-template-columns: 1fr 1fr; gap: 60px; align-items: center;
        }
        @media (max-width: 768px) { .hero-inner { grid-template-columns: 1fr; gap: 40px; } }

        .hero-badge {
            display: inline-flex; align-items: center; gap: 8px;
            background: var(--primary-light); color: var(--primary);
            padding: 8px 16px; border-radius: 99px;
            font-size: 0.75rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.06em;
            margin-bottom: 24px;
        }
        .hero h1 {
            font-size: 3.25rem; font-weight: 900; line-height: 1.1;
            letter-spacing: -0.03em; color: var(--dark);
            margin-bottom: 20px;
        }
        .hero h1 span { color: var(--primary); }
        @media (max-width: 768px) { .hero h1 { font-size: 2.25rem; } }

        .hero-text {
            font-size: 1.0625rem; color: var(--muted); line-height: 1.7;
            margin-bottom: 36px; max-width: 520px;
        }
        .hero-btns { display: flex; gap: 12px; flex-wrap: wrap; }
        .btn-hero {
            padding: 16px 32px; border-radius: 14px; font-weight: 700;
            font-size: 0.9375rem; transition: all 0.25s; cursor: pointer;
            border: 2px solid transparent;
        }
        .btn-hero-primary { background: var(--primary); color: #fff; }
        .btn-hero-primary:hover { background: var(--primary-dark); box-shadow: 0 12px 32px rgba(46,110,221,0.3); transform: translateY(-2px); }
        .btn-hero-outline { background: transparent; color: var(--primary); border-color: var(--primary); }
        .btn-hero-outline:hover { background: var(--primary); color: #fff; }

        .hero-img {
            position: relative; border-radius: 24px; overflow: hidden;
            box-shadow: 0 25px 50px rgba(0,0,0,0.1);
        }
        .hero-img img { width: 100%; height: 400px; object-fit: cover; display: block; }
        .hero-stats {
            position: absolute; bottom: 0; left: 0; right: 0;
            background: rgba(15,23,42,0.85); backdrop-filter: blur(8px);
            padding: 20px 28px; display: flex; justify-content: space-around;
        }
        .hero-stat { text-align: center; color: #fff; }
        .hero-stat strong { display: block; font-size: 1.5rem; font-weight: 800; }
        .hero-stat span { font-size: 0.75rem; color: rgba(255,255,255,0.6); font-weight: 500; }

        /* --- SECTION COMMON --- */
        .section { padding: 80px 24px; }
        .section-dark { background: var(--dark); color: #fff; }
        .section-gray { background: #f8fafc; }
        .container { max-width: 1200px; margin: 0 auto; }
        .section-header { text-align: center; margin-bottom: 56px; }
        .section-tag {
            display: inline-block; background: var(--primary-light); color: var(--primary);
            padding: 6px 16px; border-radius: 99px; font-size: 0.75rem;
            font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em;
            margin-bottom: 16px;
        }
        .section-header h2 { font-size: 2.25rem; font-weight: 800; letter-spacing: -0.02em; margin-bottom: 12px; }
        .section-header p { color: var(--muted); font-size: 1rem; max-width: 560px; margin: 0 auto; line-height: 1.6; }

        /* --- PRACTICE AREAS --- */
        .practice-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 24px; }
        .practice-card {
            background: #fff; border: 1px solid var(--border); border-radius: 20px;
            padding: 32px; transition: all 0.3s; position: relative; overflow: hidden;
        }
        .practice-card:hover { border-color: var(--primary); box-shadow: 0 12px 32px rgba(46,110,221,0.1); transform: translateY(-4px); }
        .practice-icon {
            width: 56px; height: 56px; border-radius: 14px;
            background: var(--primary-light); color: var(--primary);
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 20px; font-size: 1.5rem;
        }
        .practice-card h3 { font-size: 1.125rem; font-weight: 700; margin-bottom: 10px; }
        .practice-card p { font-size: 0.875rem; color: var(--muted); line-height: 1.6; }

        /* --- CTA --- */
        .cta-section {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%);
            padding: 80px 24px;
            text-align: center;
            color: #fff;
        }
        .cta-section h2 { font-size: 2.5rem; font-weight: 900; margin-bottom: 16px; letter-spacing: -0.02em; }
        @media (max-width: 768px) { .cta-section h2 { font-size: 1.75rem; } }
        .cta-section p { font-size: 1.0625rem; color: rgba(255,255,255,0.8); margin-bottom: 36px; max-width: 560px; margin-left: auto; margin-right: auto; }
        .cta-btn {
            display: inline-flex; align-items: center; gap: 10px;
            padding: 18px 40px; background: #fff; color: var(--primary);
            border-radius: 14px; font-weight: 800; font-size: 1rem;
            transition: all 0.25s; border: none; cursor: pointer;
        }
        .cta-btn:hover { transform: translateY(-2px); box-shadow: 0 16px 40px rgba(0,0,0,0.2); }

        /* --- FOOTER --- */
        .footer { background: var(--dark); color: rgba(255,255,255,0.7); padding: 60px 24px 30px; }
        .footer-inner { max-width: 1200px; margin: 0 auto; display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 40px; }
        @media (max-width: 768px) { .footer-inner { grid-template-columns: 1fr; } }
        .footer h4 { color: #fff; font-size: 0.875rem; font-weight: 700; margin-bottom: 16px; text-transform: uppercase; letter-spacing: 0.06em; }
        .footer p { font-size: 0.875rem; line-height: 1.7; }
        .footer-logo { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; }
        .footer-logo img { height: 32px; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.3)); }
        .footer-logo span { font-weight: 800; color: #fff; font-size: 1rem; }
        .footer a { color: rgba(255,255,255,0.6); font-size: 0.875rem; display: block; margin-bottom: 8px; transition: color 0.2s; }
        .footer a:hover { color: #fff; }
        .footer-bottom { max-width: 1200px; margin: 0 auto; border-top: 1px solid rgba(255,255,255,0.1); padding-top: 24px; margin-top: 40px; text-align: center; font-size: 0.8125rem; }
        /* --- CONSULTATION FORM --- */
        .consult-section { padding: 80px 24px; background: linear-gradient(180deg, #f0f4ff 0%, #f8fafc 100%); }
        .consult-wrap { max-width: 780px; margin: 0 auto; }
        .consult-card { background: #fff; border-radius: 24px; padding: 48px; box-shadow: 0 8px 40px rgba(46,110,221,.08); border: 1px solid rgba(46,110,221,.1); }
        @media (max-width: 768px) { .consult-card { padding: 28px 20px; } }

        .cf-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        @media (max-width: 560px) { .cf-row { grid-template-columns: 1fr; } }
        .cf-group { margin-bottom: 18px; }
        .cf-group label { display: block; font-size: .8125rem; font-weight: 600; color: #374151; margin-bottom: 6px; }
        .cf-group label .req { color: #dc2626; }
        .cf-input-wrap { position: relative; }
        .cf-input-wrap > svg { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #94a3b8; pointer-events: none; flex-shrink: 0; }
        .cf-input-wrap input, .cf-input-wrap textarea {
            width: 100%; padding: 13px 44px 13px 44px; border: 2px solid #e2e8f0; border-radius: 14px;
            font-size: .9375rem; font-weight: 500; color: #1a1a2e; background: #f8fafc;
            transition: all .2s; outline: none; font-family: 'Inter', sans-serif;
        }
        .cf-input-wrap input:focus, .cf-input-wrap textarea:focus { border-color: var(--primary); box-shadow: 0 0 0 4px rgba(46,110,221,.1); background: #fff; }
        .cf-submit {
            width: 100%; padding: 16px; background: var(--primary); color: #fff; border: none;
            border-radius: 14px; font-size: 1rem; font-weight: 700; cursor: pointer;
            transition: all .25s; font-family: 'Inter', sans-serif; margin-top: 8px;
            display: flex; align-items: center; justify-content: center; gap: 10px;
        }
        .cf-submit:hover { background: var(--primary-dark); box-shadow: 0 12px 32px rgba(46,110,221,.25); transform: translateY(-2px); }

        .cf-error { padding: 14px 18px; border-radius: 14px; background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; font-size: .875rem; font-weight: 500; margin-bottom: 20px; line-height: 1.5; }
        .cf-success { text-align: center; padding: 48px 24px; }
        .cf-success-icon { width: 80px; height: 80px; border-radius: 50%; background: #f0fdf4; display: flex; align-items: center; justify-content: center; margin: 0 auto 24px; }
        .cf-success h3 { font-size: 1.5rem; font-weight: 800; color: var(--dark); margin-bottom: 12px; }
        .cf-success p { color: var(--muted); font-size: .9375rem; line-height: 1.6; margin-bottom: 24px; max-width: 420px; margin-left: auto; margin-right: auto; }
        .cf-success a { display: inline-flex; align-items: center; gap: 8px; padding: 14px 32px; background: var(--primary); color: #fff; border-radius: 14px; font-weight: 700; font-size: .9375rem; transition: all .2s; }

        .ohnohoney { position: absolute; left: -9999px; opacity: 0; height: 0; width: 0; overflow: hidden; }

        .cf-drop-zone {
            border: 2px dashed #c7d7f0; border-radius: 16px;
            padding: 28px 20px; text-align: center; cursor: pointer;
            transition: all .25s; background: #f8fafc; position: relative;
        }
        .cf-drop-zone:hover, .cf-drop-zone.drag-over { border-color: var(--primary); background: #eff5ff; }
        .cf-dz-icon { width: 48px; height: 48px; background: var(--primary-light); border-radius: 14px; display: flex; align-items: center; justify-content: center; margin: 0 auto 10px; color: var(--primary); }
        .cf-dz-title { font-size: .875rem; font-weight: 700; color: #1a1a2e; margin-bottom: 4px; }
        .cf-dz-hint { font-size: .75rem; color: #94a3b8; }
        .cf-dz-input { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%; }
        .cf-file-list { margin-top: 12px; display: flex; flex-direction: column; gap: 8px; }
        .cf-file-item { display: flex; align-items: center; gap: 10px; padding: 9px 12px; background: #fff; border: 1.5px solid #e2e8f0; border-radius: 12px; }
        .cf-file-name { font-size: .8125rem; font-weight: 600; color: #1a1a2e; flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .cf-radio-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px; }
        .cf-radio-item { position: relative; }
        .cf-radio-item input { position: absolute; opacity: 0; }
        .cf-radio-item label {
            display: block; padding: 12px; text-align: center; border: 2px solid #e2e8f0;
            border-radius: 12px; font-size: .875rem; font-weight: 600; color: #64748b;
            cursor: pointer; transition: all .2s; background: #fff;
        }
        .cf-radio-item input:checked + label { border-color: var(--primary); background: var(--primary-light); color: var(--primary); }
        .cf-radio-item input:focus-visible + label { box-shadow: 0 0 0 3px rgba(46,110,221,.2); }
        
        .cf-section-title { font-size: .875rem; font-weight: 800; color: var(--primary); text-transform: uppercase; letter-spacing: 0.05em; text-align: center; margin: 32px 0 24px; display: flex; align-items: center; }
        .cf-section-title::before, .cf-section-title::after { content: ''; flex: 1; height: 1px; background: #e2e8f0; }
        .cf-section-title::before { margin-right: 16px; }
        .cf-section-title::after { margin-left: 16px; }
    </style>
</head>
<body>

<nav class="nav">
    <div class="nav-inner">
        <a href="#" class="nav-logo"><img src="<?php echo $logoUrl; ?>" alt="Logo"><span>CRM Abogados</span></a>
        <div class="nav-links">
            <a href="#servicios">Servicios</a>
            <a href="#consulta">Consulta Gratis</a>
            <a href="https://app.leadsabogados.com/portal/" class="nav-cta">Portal del Cliente</a>
        </div>
    </div>
</nav>

<section class="hero">
    <div class="hero-inner">
        <div>
            <div class="hero-badge">Despacho de Confianza</div>
            <h1>Soluciones legales <span>creativas</span> para su tranquilidad</h1>
            <p class="hero-text">Más de 15 años defendiendo los derechos de nuestros clientes con profesionalismo, ética y resultados comprobados. Acceda a su caso desde nuestro portal seguro.</p>
            <div class="hero-btns">
                <a href="#consulta" class="btn-hero btn-hero-primary">Consulta Gratuita</a>
                <a href="https://app.leadsabogados.com/portal/" class="btn-hero btn-hero-outline">Ya Soy Cliente</a>
            </div>
        </div>
        <div class="hero-img">
            <img src="<?php echo $heroUrl; ?>" alt="Justicia">
            <div class="hero-stats">
                <div class="hero-stat"><strong>500+</strong><span>Casos Ganados</span></div>
                <div class="hero-stat"><strong>15</strong><span>Años Experiencia</span></div>
                <div class="hero-stat"><strong>98%</strong><span>Satisfechos</span></div>
            </div>
        </div>
    </div>
</section>

<section class="consult-section" id="consulta">
    <div class="container">
        <div class="section-header">
            <span class="section-tag">Primera Consulta Gratuita</span>
            <h2>Envíe Su Caso</h2>
            <p>Cree su cuenta y describa su situación legal. Nuestro equipo revisará su caso y le asignará un abogado especializado.</p>
        </div>
        <div class="consult-wrap">
            <div class="consult-card">

                <?php if ($formExito): ?>
                <div class="cf-success">
                    <div class="cf-success-icon">✓</div>
                    <h3>Solicitud Enviada</h3>
                    <p>Su cuenta ha sido creada y su consulta ha sido registrada. Nuestro equipo la revisará en las próximas 24-48 horas.</p>
                    <a href="https://app.leadsabogados.com/portal/index.php?page=login">Acceder a Mi Portal</a>
                </div>
                <?php else: ?>

                <?php if ($formError): ?>
                <div class="cf-error"><?php echo $formError; ?></div>
                <?php endif; ?>

                <form method="POST" action="#consulta" id="formConsulta" autocomplete="off" enctype="multipart/form-data">
                    <input type="hidden" name="_token" value="<?php echo esc($csrfToken); ?>">
                    <input type="hidden" name="consulta_submit" value="1">
                    <div class="ohnohoney"><label>Website</label><input type="text" name="website_url" tabindex="-1" autocomplete="off"></div>

                    <div class="cf-row">
                        <div class="cf-group">
                            <label>Nombre <span class="req">*</span></label>
                            <div class="cf-input-wrap">
                                <input type="text" name="nombre" placeholder="Su nombre" value="<?php echo esc($formData['nombre']); ?>" required>
                            </div>
                        </div>
                        <div class="cf-group">
                            <label>Apellidos <span class="req">*</span></label>
                            <div class="cf-input-wrap">
                                <input type="text" name="apellidos" placeholder="Sus apellidos" value="<?php echo esc($formData['apellidos']); ?>" required>
                            </div>
                        </div>
                    </div>
                    <div class="cf-row">
                        <div class="cf-group">
                            <label>Correo Electrónico <span class="req">*</span></label>
                            <div class="cf-input-wrap">
                                <input type="email" name="email" placeholder="correo@ejemplo.com" value="<?php echo esc($formData['email']); ?>" required>
                            </div>
                        </div>
                        <div class="cf-group">
                            <label>Teléfono <span style="color:#94a3b8;font-weight:400">(opcional)</span></label>
                            <div class="cf-input-wrap">
                                <input type="tel" name="telefono" placeholder="+34 600 000 000" value="<?php echo esc($formData['telefono']); ?>">
                            </div>
                        </div>
                    </div>
                    <div class="cf-row">
                        <div class="cf-group">
                            <label>DNI / NIF <span class="req">*</span></label>
                            <div class="cf-input-wrap">
                                <input type="text" name="dni_nif" placeholder="12345678A" value="<?php echo esc($formData['dni_nif']); ?>" required minlength="5">
                            </div>
                        </div>
                        <div class="cf-group">
                            <label>Dirección <span class="req">*</span></label>
                            <div class="cf-input-wrap">
                                <input type="text" name="direccion" placeholder="Ciudad" value="<?php echo esc($formData['direccion']); ?>" required minlength="5">
                            </div>
                        </div>
                    </div>
                    <div class="cf-row">
                        <div class="cf-group">
                            <label>Fecha de Nacimiento <span class="req">*</span></label>
                            <div class="cf-input-wrap">
                                <input type="date" name="fecha_nacimiento" value="<?php echo esc($formData['fecha_nacimiento']); ?>" required>
                            </div>
                        </div>
                        <div class="cf-group">
                        </div>
                    </div>

                    <div class="cf-section-title">Describa su situación legal</div>

                    <div class="cf-group">
                        <label>Tipo de Consulta <span class="req">*</span></label>
                        <div class="cf-radio-grid">
                            <?php
                            $tipos = ['Civil','Penal','Laboral','Mercantil','Inmobiliario','Familia','Extranjería','Administrativo','Otro'];
                            foreach ($tipos as $t):
                                $sel = (($formData['tipo_problema'] ?? '') === $t) ? 'checked' : '';
                            ?>
                            <div class="cf-radio-item">
                                <input type="radio" name="tipo_problema" id="tipo_<?php echo strtolower($t); ?>" value="<?php echo $t; ?>" <?php echo $sel; ?> required>
                                <label for="tipo_<?php echo strtolower($t); ?>"><?php echo $t; ?></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="cf-group">
                        <label>Describa Su Caso <span class="req">*</span></label>
                        <div class="cf-input-wrap">
                            <textarea name="descripcion" rows="5" placeholder="Explique con detalle su situación legal. Cuanta más información nos proporcione, mejor podremos ayudarle (mín. 20 caracteres)..." required minlength="20"><?php echo esc($formData['descripcion']); ?></textarea>
                        </div>
                    </div>

                    <div class="cf-group">
                        <label>Documentos Adjuntos <span style="color:#94a3b8;font-weight:400">(opcional — máx. 10 MB)</span></label>
                        <div class="cf-drop-zone" id="cfDropZone">
                            <input type="file" name="archivos[]" id="cfFileInput" class="cf-dz-input" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.zip,.rar,.txt">
                            <div class="cf-dz-title">Arrastre archivos aquí o haga clic para seleccionar</div>
                        </div>
                        <div class="cf-file-list" id="cfFileList"></div>
                    </div>

                    <button type="submit" class="cf-submit">Enviar Solicitud</button>

                    <p style="text-align:center;margin-top:16px;font-size:.8125rem;color:#94a3b8">
                        ¿Ya tiene cuenta? <a href="https://app.leadsabogados.com/portal/index.php?page=login" style="color:var(--primary);font-weight:700">Inicie sesión aquí</a>
                    </p>
                </form>
                <?php endif; ?>

            </div>
        </div>
    </div>
</section>

<section class="section section-gray" id="servicios">
    <div class="container">
        <div class="section-header">
            <span class="section-tag">Áreas de Práctica</span>
            <h2>Especialidades Jurídicas</h2>
            <p>Ofrecemos asesoramiento integral en las principales ramas del derecho, adaptándonos a sus necesidades.</p>
        </div>
        <div class="practice-grid">
            <div class="practice-card"><h3>Derecho Civil</h3><p>Contratos, herencias, reclamaciones de deuda, propiedad.</p></div>
            <div class="practice-card"><h3>Derecho Penal</h3><p>Defensa penal, delitos económicos, violencia de género.</p></div>
            <div class="practice-card"><h3>Derecho Laboral</h3><p>Despidos, reclamaciones salariales, acoso laboral.</p></div>
            <div class="practice-card"><h3>Derecho Mercantil</h3><p>Constitución de sociedades, contratos, propiedad intelectual.</p></div>
        </div>
    </div>
</section>

<section class="cta-section" id="contacto">
    <div class="container">
        <h2>¿Ya es cliente del despacho?</h2>
        <p>Acceda a nuestro portal seguro para consultar el estado de su caso, revisar pagos y subir documentos.</p>
        <a href="https://app.leadsabogados.com/portal/" class="cta-btn">Acceder al Portal del Cliente</a>
    </div>
</section>

<footer class="footer">
    <div class="footer-inner">
        <div>
            <div class="footer-logo"><img src="<?php echo $logoUrl; ?>" alt="Logo"><span>CRM Abogados</span></div>
            <p>Despacho de abogados comprometido con la excelencia jurídica y la defensa de sus derechos.</p>
        </div>
        <div>
            <h4>Servicios</h4>
            <a href="#servicios">Derecho Civil</a>
            <a href="#servicios">Derecho Penal</a>
        </div>
        <div>
            <h4>Acceso</h4>
            <a href="https://app.leadsabogados.com/portal/">Portal del Cliente</a>
            <a href="https://app.leadsabogados.com/">Panel de Gestión (CRM)</a>
        </div>
    </div>
    <div class="footer-bottom">
        <p>&copy; <?php echo date('Y'); ?> CRM Abogados. Todos los derechos reservados.</p>
    </div>
</footer>

<script>
document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', e => {
        e.preventDefault();
        const t = document.querySelector(a.getAttribute('href'));
        if (t) t.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
});
const dz = document.getElementById('cfDropZone'), fi = document.getElementById('cfFileInput'), fl = document.getElementById('cfFileList');
if(fi) {
    fi.addEventListener('change', function() {
        fl.innerHTML = '';
        Array.from(this.files).forEach(f => {
            const div = document.createElement('div'); div.className = 'cf-file-item';
            div.innerHTML = '<div class="cf-file-name">'+f.name+' ('+Math.round(f.size/1024)+' KB)</div>';
            fl.appendChild(div);
        });
    });
}
</script>
</body>
</html>
