<?php
define('CRM_ROOT', __DIR__ . '/portal/crm');
require_once __DIR__ . '/portal/crm/includes/config.php';
$db = Database::getInstance();
try {
    $db->query("ALTER TABLE usuarios_internos ADD COLUMN foto VARCHAR(255) NULL");
    $db->query("ALTER TABLE usuarios_internos ADD COLUMN especialidades VARCHAR(255) NULL");
    $db->query("ALTER TABLE usuarios_internos ADD COLUMN sitio_web VARCHAR(255) NULL");
    echo "OK";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
