<?php
/**
 * CRM Abogados - Configuración del Sistema
 * Carga las variables de entorno desde el archivo .env
 */

// Evitar acceso directo
if (!defined('CRM_ROOT')) {
    die('Acceso prohibido');
}

/**
 * Carga el archivo .env y define las constantes
 */
function cargarEnv($ruta) {
    if (!file_exists($ruta)) {
        die('Error: No se encontró el archivo de configuración .env');
    }
    
    $lineas = file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lineas as $linea) {
        $linea = trim($linea);
        // Ignorar comentarios
        if (strpos($linea, '#') === 0) continue;
        if (strpos($linea, '=') === false) continue;
        
        list($clave, $valor) = explode('=', $linea, 2);
        $clave = trim($clave);
        $valor = trim($valor);
        
        // Eliminar comillas
        $valor = trim($valor, '"\'');
        
        $_ENV[$clave] = $valor;
        putenv("$clave=$valor");
    }
}

// Cargar variables de entorno
cargarEnv(CRM_ROOT . '/.env');

// Función helper para obtener variables de entorno
function env($clave, $default = '') {
    return $_ENV[$clave] ?? getenv($clave) ?: $default;
}

// =====================================================
// Constantes de la aplicación
// =====================================================

// Base de datos
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_PORT', env('DB_PORT', '3306'));
define('DB_NAME', env('DB_NAME', 'crm_abogados'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));

// Aplicación
$default_url = 'https://app.leadsabogados.com/portal/crm';
$app_url_env = env('APP_URL', $default_url);
if (substr($app_url_env, -1) === '/') {
    $app_url_env = substr($app_url_env, 0, -1);
}
define('APP_URL', $app_url_env);
define('APP_NAME', env('APP_NAME', 'CRM Abogados'));
define('APP_SECRET', env('APP_SECRET', 'clave_secreta_por_defecto'));
define('DEBUG', env('DEBUG', 'false') === 'true');

// Email — prioridad: BD (si está disponible) → .env
// Esto permite configurarlos desde el panel admin sin tocar archivos
function _smtpValor(string $clave, string $default): string {
    // Solo intentar BD si PDO/MySQL está disponible y la tabla existe
    if (!class_exists('Database')) return $default;
    try {
        $db  = Database::getInstance();
        $val = $db->fetchColumn("SELECT valor FROM configuracion WHERE clave = ? AND valor != '' LIMIT 1", [$clave]);
        return ($val !== false && $val !== '') ? $val : $default;
    } catch (Throwable $e) {
        return $default; // BD no disponible todavía
    }
}

define('SMTP_HOST',      _smtpValor('smtp_host',      env('SMTP_HOST',      '')));
define('SMTP_PORT',      _smtpValor('smtp_port',      env('SMTP_PORT',      '587')));
define('SMTP_USER',      _smtpValor('smtp_user',      env('SMTP_USER',      '')));
define('SMTP_PASS',      _smtpValor('smtp_pass',      env('SMTP_PASS',      '')));
define('SMTP_FROM',      _smtpValor('smtp_from',      env('SMTP_FROM',      'noreply@despacho.com')));
define('SMTP_FROM_NAME', _smtpValor('smtp_from_name', env('SMTP_FROM_NAME', 'CRM Abogados')));

// reCAPTCHA
define('RECAPTCHA_SITE_KEY', env('RECAPTCHA_SITE_KEY', ''));
define('RECAPTCHA_SECRET_KEY', env('RECAPTCHA_SECRET_KEY', ''));

// Uploads
define('UPLOAD_DIR', CRM_ROOT . '/' . env('UPLOAD_DIR', 'uploads/'));

// Configurar errores según modo
if (DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Zona horaria
date_default_timezone_set('America/Caracas');

// Charset
ini_set('default_charset', 'UTF-8');
mb_internal_encoding('UTF-8');

// =====================================================
// Cola de emails — procesar pendientes en cada request
// (máx. 2 por carga para no bloquear la respuesta)
// =====================================================
register_shutdown_function(function() {
    if (class_exists('Mailer') && class_exists('Database')) {
        Mailer::procesarCola(2);
    }
});

// =====================================================
// Nombre de sesión propio — evita colisión con otros
// CRMs en el mismo dominio (ej. InfinityFree)
// =====================================================
if (session_status() === PHP_SESSION_NONE) {
    session_name('crm_abogados');
}
