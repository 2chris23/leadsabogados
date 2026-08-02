<?php
define('CRM_ROOT', __DIR__ . '/portal/crm');
require 'portal/crm/includes/config.php';
require 'portal/crm/includes/Database.php';
try {
    $db = Database::getInstance();
    $stmt = $db->getConnection()->query("SHOW COLUMNS FROM usuarios_internos");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    print_r($cols);
} catch (Throwable $e) {
    echo $e->getMessage();
}
