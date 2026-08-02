<?php
require 'includes/config.php';
require 'includes/Database.php';

try {
    $db = Database::getInstance();
    $db->query("ALTER TABLE usuarios_internos 
                ADD COLUMN tarifa_hitos_senal DECIMAL(10,2) DEFAULT 0, 
                ADD COLUMN tarifa_hitos_intermedio DECIMAL(10,2) DEFAULT 0, 
                ADD COLUMN tarifa_hitos_final DECIMAL(10,2) DEFAULT 0");
    echo "<h1>Migración exitosa</h1>";
    echo "<p>Las columnas de hitos (tarifa_hitos_senal, tarifa_hitos_intermedio, tarifa_hitos_final) han sido creadas en usuarios_internos.</p>";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "<h1>Migración ya ejecutada</h1>";
        echo "<p>Las columnas de hitos ya existían en la base de datos.</p>";
    } else {
        echo "<h1>Error en migración</h1>";
        echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
    }
}
