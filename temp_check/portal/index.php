<?php
/**
 * Portal del Cliente — Punto de Entrada
 * Autenticación con tabla portal_cuentas
 */

define('PORTAL_ROOT', __DIR__);
define('CRM_ROOT', __DIR__ . '/crm');

require_once CRM_ROOT . '/includes/config.php';
require_once CRM_ROOT . '/includes/Database.php';
require_once CRM_ROOT . '/includes/AuditLog.php';
require_once CRM_ROOT . '/includes/CSRF.php';
require_once PORTAL_ROOT . '/pwa_helper.php';

// Sesión separada del CRM
if (session_status() === PHP_SESSION_NONE) {
    session_name('portal_cliente');
    session_start();
}

$db = Database::getInstance();
$page = isset($_GET['page']) ? preg_replace('/[^a-zA-Z0-9\-\/]/', '', trim($_GET['page'])) : 'login';

// Helpers
function e($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function portalUrl() { 
    return rtrim(APP_URL, '/') . '/portal';
}
function setFlash($t, $m) { $_SESSION['flash'] = ['tipo'=>$t,'mensaje'=>$m]; }
function getFlash() { if(isset($_SESSION['flash'])){$f=$_SESSION['flash'];unset($_SESSION['flash']);return $f;} return null; }

// Auth helpers — ahora usa portal_id (tabla portal_cuentas)
function clienteLogueado() { return isset($_SESSION['portal_id']); }
function requireClienteLogin() {
    if (!clienteLogueado()) {
        header('Location: ' . portalUrl() . '/index.php?page=login');
        exit;
    }
}

// Logout
if ($page === 'logout') {
    $_SESSION = [];
    session_destroy();
    // Borrar cookie de recordarme del portal
    setcookie('portal_remember', '', time() - 3600, '/');
    header('Location: ' . portalUrl() . '/index.php?page=login');
    exit;
}

// Login
if ($page === 'login') {
    if (clienteLogueado()) {
        header('Location: ' . portalUrl() . '/index.php?page=dashboard');
        exit;
    }
    require_once PORTAL_ROOT . '/pages/login.php';
    exit;
}

// Registro
if ($page === 'register') {
    if (clienteLogueado()) {
        header('Location: ' . portalUrl() . '/index.php?page=dashboard');
        exit;
    }
    require_once PORTAL_ROOT . '/pages/register.php';
    exit;
}

// Recuperar contraseña
if ($page === 'forgot-password') {
    if (clienteLogueado()) { header('Location: ' . portalUrl() . '/index.php?page=dashboard'); exit; }
    require_once PORTAL_ROOT . '/pages/forgot-password.php'; exit;
}
if ($page === 'reset-password') {
    if (clienteLogueado()) { header('Location: ' . portalUrl() . '/index.php?page=dashboard'); exit; }
    require_once PORTAL_ROOT . '/pages/reset-password.php'; exit;
}


// A partir de aquí, requiere login
requireClienteLogin();

$rutas = [
    'dashboard'       => 'pages/dashboard.php',
    'nueva-solicitud' => 'pages/nueva-solicitud.php',
    'caso'            => 'pages/caso.php',
    'descargar-doc'   => 'pages/descargar-doc.php',
    'perfil'          => 'pages/profile.php',
];

if (isset($rutas[$page]) && file_exists(PORTAL_ROOT . '/' . $rutas[$page])) {
    require_once PORTAL_ROOT . '/' . $rutas[$page];
} else {
    header('Location: ' . portalUrl() . '/index.php?page=dashboard');
    exit;
}
