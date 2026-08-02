<?php
define('CRM_ROOT', __DIR__);
require 'includes/config.php';
require 'includes/Database.php';

try {
    $db = Database::getInstance();
    $db->query("ALTER TABLE usuarios_internos ADD COLUMN tarifa_es_variable TINYINT(1) DEFAULT 0");
    echo "<h1>Migración exitosa</h1>";
    echo "<p>Columna tarifa_es_variable creada con éxito.</p>";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "<h1>Migración ya ejecutada</h1>";
        echo "<p>La columna ya existía.</p>";
    } else {
        echo "<h1>Error en migración</h1>";
        echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
    }
}
