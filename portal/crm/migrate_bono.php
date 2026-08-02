<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('CRM_ROOT', __DIR__);
require CRM_ROOT . '/includes/config.php';
require CRM_ROOT . '/includes/Database.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $columns = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM solicitudes");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $columns[] = $row['Field'];
    }

    if (!in_array('es_bonificacion', $columns)) {
        $pdo->exec("ALTER TABLE solicitudes ADD COLUMN es_bonificacion TINYINT(1) DEFAULT 0 AFTER honorarios_abogado");
        echo "Added es_bonificacion column.\n";
    } else {
        echo "es_bonificacion already exists.\n";
    }
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage();
}
