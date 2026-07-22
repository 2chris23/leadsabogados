<?php
define('CRM_ROOT', __DIR__);
require_once 'includes/config.php';
require_once 'includes/Database.php';

$db = Database::getInstance();
$mysql_version = $db->fetchColumn("SELECT VERSION()");

echo "PHP Version: " . PHP_VERSION . "\n";
echo "SQL Version: " . $mysql_version . "\n";
unlink(__FILE__); // Autodestrucción
