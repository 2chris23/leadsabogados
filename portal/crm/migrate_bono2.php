<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('CRM_ROOT', __DIR__);
require CRM_ROOT . '/includes/config.php';
require CRM_ROOT . '/includes/Database.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    try {
        $pdo->exec("ALTER TABLE solicitudes DROP COLUMN es_bonificacion");
        echo "Dropped es_bonificacion from solicitudes.<br>";
    } catch(Exception $e) {}

    $cols = $pdo->query("SHOW COLUMNS FROM solicitudes")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('bonificacion', $cols)) {
        $pdo->exec("ALTER TABLE solicitudes ADD COLUMN bonificacion DECIMAL(10,2) DEFAULT 0 AFTER honorarios_abogado");
        echo "Added bonificacion to solicitudes.<br>";
    }

    $cols2 = $pdo->query("SHOW COLUMNS FROM casos")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('bonificacion', $cols2)) {
        $pdo->exec("ALTER TABLE casos ADD COLUMN bonificacion DECIMAL(10,2) DEFAULT 0 AFTER honorarios_abogado");
        echo "Added bonificacion to casos.<br>";
    }
    
    echo "Migration completed.";

} catch (Throwable $e) {
    echo "Error: " . $e->getMessage();
}
