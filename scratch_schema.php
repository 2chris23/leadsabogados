<?php
define('CRM_ROOT', __DIR__ . '/portal/crm');
require 'portal/crm/includes/config.php';
require 'portal/crm/includes/Database.php';
$db = Database::getInstance();
$tables = $db->query('SHOW TABLES');
foreach($tables as $t) {
    $tname = array_values($t)[0];
    echo $tname . ":\n";
    $cols = $db->query("SHOW COLUMNS FROM $tname");
    foreach($cols as $c) {
        echo ' - ' . $c['Field'] . "\n";
    }
}
