<?php
/**
 * CRM para Despacho de Abogados
 * Router principal - Punto de entrada único
 */

// Forzar mostrar errores en vez de pantalla blanca 500
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Limpiar código viejo de la memoria de Plesk
if (function_exists('opcache_reset')) {
    opcache_reset();
}

// Definir raíz del CRM (si no fue definida por el index.php raíz)
if (!defined('CRM_ROOT')) {
    define('CRM_ROOT', __DIR__);
}

// Cargar configuración
require_once CRM_ROOT . '/includes/config.php';
require_once CRM_ROOT . '/includes/Database.php';
require_once CRM_ROOT . '/includes/AuditLog.php';
require_once CRM_ROOT . '/includes/Auth.php';
require_once CRM_ROOT . '/includes/CSRF.php';
require_once CRM_ROOT . '/includes/RoleGuard.php';
require_once CRM_ROOT . '/includes/FileUpload.php';
require_once CRM_ROOT . '/includes/Mailer.php';

// Security headers — prevent clickjacking, XSS, MIME sniffing
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

// Inicializar autenticación
$auth = new Auth();

// Obtener la página solicitada
$page = isset($_GET['page']) ? trim($_GET['page']) : 'dashboard';

// Sanitizar nombre de página (solo alfanumérico, guiones y barras)
$page = preg_replace('/[^a-zA-Z0-9\-\/]/', '', $page);

// Páginas públicas (no requieren login)
$paginasPublicas = ['login', 'forgot-password', 'reset-password', 'solicitud-publica'];

// Helper para mensajes flash
function setFlash($tipo, $mensaje) {
    $_SESSION['flash'] = ['tipo' => $tipo, 'mensaje' => $mensaje];
}

function getFlash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// Helper para escapar HTML (prevención XSS)
function e($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

// Helper para obtener configuración del tema
function getConfig($clave, $default = '') {
    static $cache = [];
    if (!isset($cache[$clave])) {
        try {
            $db = Database::getInstance();
            $valor = $db->fetchColumn("SELECT valor FROM configuracion WHERE clave = ?", [$clave]);
            $cache[$clave] = $valor !== false ? $valor : $default;
        } catch (Exception $ex) {
            $cache[$clave] = $default;
        }
    }
    return $cache[$clave];
}

// =====================================================
// Enrutamiento
// =====================================================

// Página pública de solicitud
if ($page === 'solicitud-publica') {
    require_once CRM_ROOT . '/public/solicitud.php';
    exit;
}

// Procesar logout
if ($page === 'logout') {
    $auth->logout();
    header('Location: ' . APP_URL . '/index.php?page=login');
    exit;
}

// Página de login
if ($page === 'login') {
    if ($auth->estaLogueado()) {
        header('Location: ' . APP_URL . '/index.php?page=dashboard');
        exit;
    }
    require_once CRM_ROOT . '/pages/login.php';
    exit;
}

// Forgot password
if ($page === 'forgot-password') {
    require_once CRM_ROOT . '/pages/forgot-password.php';
    exit;
}

// Reset password
if ($page === 'reset-password') {
    require_once CRM_ROOT . '/pages/reset-password.php';
    exit;
}

// =====================================================
// A partir de aquí, todo requiere autenticación
// =====================================================
$auth->requireLogin();
$usuario = $auth->getUsuario();

// Mapa de rutas a archivos y roles permitidos
$rutas = [
    'dashboard'             => ['archivo' => 'pages/dashboard.php',              'roles' => ['admin', 'abogado']],
    
    // Solicitudes
    'solicitudes'           => ['archivo' => 'pages/solicitudes/index.php',      'roles' => ['admin', 'abogado', 'gestor']],
    'solicitudes/ver'       => ['archivo' => 'pages/solicitudes/ver.php',        'roles' => ['admin', 'abogado', 'gestor']],
    'solicitudes/descargar' => ['archivo' => 'pages/solicitudes/descargar.php',  'roles' => ['admin', 'abogado', 'gestor']],
    
    // Clientes
    'clientes'              => ['archivo' => 'pages/clientes/index.php',         'roles' => ['admin', 'abogado', 'gestor']],
    'clientes/ver'          => ['archivo' => 'pages/clientes/ver.php',           'roles' => ['admin', 'abogado', 'gestor']],
    
    // Casos
    'casos'                 => ['archivo' => 'pages/casos/index.php',            'roles' => ['admin', 'abogado']],
    'casos/ver'             => ['archivo' => 'pages/casos/ver.php',              'roles' => ['admin', 'abogado']],
    'casos/documentos'      => ['archivo' => 'pages/casos/documentos.php',       'roles' => ['admin', 'abogado']],
    'casos/descargar'       => ['archivo' => 'pages/casos/descargar.php',        'roles' => ['admin', 'abogado', 'gestor']],
    
    // Pagos (solo admin)
    'pagos'                 => ['archivo' => 'pages/pagos/index.php',            'roles' => ['admin']],
    'pagos/registrar'       => ['archivo' => 'pages/pagos/registrar.php',        'roles' => ['admin']],
    
    // Usuarios (solo admin)
    'usuarios'              => ['archivo' => 'pages/usuarios/index.php',         'roles' => ['admin']],
    'usuarios/crear'        => ['archivo' => 'pages/usuarios/crear.php',         'roles' => ['admin']],
    'usuarios/editar'       => ['archivo' => 'pages/usuarios/editar.php',        'roles' => ['admin']],

    // Permisos de Roles (solo admin)
    'permisos'              => ['archivo' => 'pages/permisos/index.php',         'roles' => ['admin']],
    
    // Abogados (Módulo dedicado)
    'abogados'              => ['archivo' => 'pages/abogados/index.php',         'roles' => ['admin']],
    'abogados/ver'          => ['archivo' => 'pages/abogados/ver.php',           'roles' => ['admin', 'abogado']],
    'abogados/crear'        => ['archivo' => 'pages/abogados/crear.php',         'roles' => ['admin']],
    'abogados/editar'       => ['archivo' => 'pages/abogados/editar.php',        'roles' => ['admin']],
    
    
    // Configuración (solo admin)
    'configuracion/tema'    => ['archivo' => 'pages/configuracion/tema.php',    'roles' => ['admin']],
    'configuracion/correo'  => ['archivo' => 'pages/configuracion/correo.php',  'roles' => ['admin']],
    
    // Auditoría (solo admin)
    'auditoria'             => ['archivo' => 'pages/auditoria/index.php',        'roles' => ['admin']],

    // Tools (solo admin)
    'tools/backups'         => ['archivo' => 'pages/tools/backups.php',          'roles' => ['admin', 'superadmin']],
    'tools/migrar-storage'  => ['archivo' => 'pages/tools/migrar-storage.php',   'roles' => ['admin', 'superadmin']],
];

// Buscar ruta
if (isset($rutas[$page])) {
    $ruta = $rutas[$page];
    
    // Verificar permisos de rol
    RoleGuard::requireRole($ruta['roles']);
    
    // Cargar la página
    $archivoPage = CRM_ROOT . '/' . $ruta['archivo'];
    
    if (file_exists($archivoPage)) {
        require_once $archivoPage;
    } else {
        http_response_code(404);
        echo '<h1>Página en construcción</h1>';
        echo '<p>Esta sección estará disponible próximamente.</p>';
        echo '<a href="' . APP_URL . '/index.php?page=dashboard">Volver al Dashboard</a>';
    }
} else {
    // Página no encontrada - redirigir al dashboard según rol
    if ($auth->esAdmin()) {
        header('Location: ' . APP_URL . '/index.php?page=dashboard');
    } elseif ($auth->esGestor()) {
        header('Location: ' . APP_URL . '/index.php?page=solicitudes');
    } else {
        header('Location: ' . APP_URL . '/index.php?page=casos');
    }
    exit;
}
