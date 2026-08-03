<?php
define('CRM_ROOT', __DIR__);
require 'includes/config.php';
require 'includes/Database.php';

$db = Database::getInstance();
try {
    $db->query("ALTER TABLE notas_caso ADD COLUMN titulo VARCHAR(255) NULL AFTER caso_id");
    echo "OK: Columna añadida\n";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "OK: La columna ya existe\n";
    } else {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
}
