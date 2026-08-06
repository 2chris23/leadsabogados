<?php
if (!defined('PORTAL_ROOT')) die('Acceso directo denegado');

$token = $_GET['token'] ?? '';
if (empty($token)) {
    setFlash('error', 'Enlace inválido.');
    header('Location: ' . portalUrl() . '/index.php?page=login');
    exit;
}

// Buscar el token
$cuenta = $db->fetchOne("SELECT id, email, reset_expires FROM portal_cuentas WHERE reset_token = ?", [$token]);

if (!$cuenta) {
    setFlash('error', 'El enlace es inválido o ya ha caducado.');
    header('Location: ' . portalUrl() . '/index.php?page=login');
    exit;
}

if (strtotime($cuenta['reset_expires']) < time()) {
    setFlash('error', 'El enlace ha caducado. Por favor, solicita uno nuevo.');
    header('Location: ' . portalUrl() . '/index.php?page=login');
    exit;
}

// Login automático
$_SESSION['portal_id'] = $cuenta['id'];
$_SESSION['portal_email'] = $cuenta['email'];

setFlash('exito', 'Has accedido correctamente a tu portal.');
header('Location: ' . portalUrl() . '/index.php?page=dashboard');
exit;
