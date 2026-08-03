<?php
define('CRM_ROOT', __DIR__);
require_once 'includes/config.php';
require_once 'includes/Database.php';

$db = Database::getInstance();
$tables = ['clientes', 'notas_caso', 'casos', 'solicitudes'];

foreach ($tables as $table) {
    echo "TABLE: $table\n";
    $columns = $db->fetchAll("DESCRIBE $table");
    foreach ($columns as $col) {
        echo "  - {$col['Field']} ({$col['Type']})\n";
    }
    echo "\n";
}
