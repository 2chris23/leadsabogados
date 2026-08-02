<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
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

    if (!in_array('valor_cliente', $columns)) {
        $pdo->exec("ALTER TABLE solicitudes ADD COLUMN valor_cliente DECIMAL(10,2) DEFAULT NULL AFTER estado");
        echo "Added valor_cliente column. <br>";
    }
    
    if (!in_array('honorarios_abogado', $columns)) {
        $pdo->exec("ALTER TABLE solicitudes ADD COLUMN honorarios_abogado DECIMAL(10,2) DEFAULT NULL AFTER valor_cliente");
        echo "Added honorarios_abogado column. <br>";
    }

    echo "Migration completed successfully.";

} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine();
}
