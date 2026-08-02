<?php
define('CRM_ROOT', __DIR__ . '/portal/crm');
require 'portal/crm/includes/config.php';
require 'portal/crm/includes/Database.php';
try {
    $db = Database::getInstance();
    $stmt = $db->getConnection()->query("SHOW COLUMNS FROM usuarios_internos");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        if (strpos($c['Field'], 'tarifa') !== false || strpos($c['Field'], 'pago') !== false) {
            echo $c['Field'] . " - " . $c['Type'] . "\n";
        }
    }
} catch (Throwable $e) {
    echo $e->getMessage();
}
