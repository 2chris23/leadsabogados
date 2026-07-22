<?php
define('CRM_ROOT', __DIR__ . '/portal/crm');
require_once __DIR__ . '/portal/crm/includes/config.php';
require_once __DIR__ . '/portal/crm/includes/Database.php';
$db = Database::getInstance();
try {
    $db->query("ALTER TABLE usuarios_internos ADD COLUMN foto VARCHAR(255) NULL");
} catch (Exception $e) {}
try {
    $db->query("ALTER TABLE usuarios_internos ADD COLUMN especialidades VARCHAR(255) NULL");
} catch (Exception $e) {}
try {
    $db->query("ALTER TABLE usuarios_internos ADD COLUMN sitio_web VARCHAR(255) NULL");
} catch (Exception $e) {}
echo "<h1>Actualizacion de Base de Datos completada.</h1>";
echo "<a href='index.php?page=dashboard'>Volver al CRM</a>";
