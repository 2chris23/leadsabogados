<?php
/**
 * Migración para el sistema de notas
 * Crear tabla notas_caso y actualizar tabla documentos
 */
define('CRM_ROOT', __DIR__);
require_once 'includes/config.php';
require_once 'includes/Database.php';

$db = Database::getInstance();
$mensajes = [];

try {
    // 1. Crear tabla notas_caso
    $sqlCreate = "CREATE TABLE IF NOT EXISTS notas_caso (
        id INT AUTO_INCREMENT PRIMARY KEY,
        caso_id INT NOT NULL,
        tipo ENUM('publica', 'interna') NOT NULL DEFAULT 'publica',
        contenido TEXT NOT NULL,
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_caso_id (caso_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    
    $db->query($sqlCreate);
    $mensajes[] = "Tabla 'notas_caso' creada o ya existía.";

    // 2. Modificar tabla documentos para enlazarlos a notas
    try {
        $db->query("ALTER TABLE documentos ADD COLUMN nota_id INT NULL DEFAULT NULL AFTER caso_id");
        $mensajes[] = "Columna 'nota_id' añadida a 'documentos'.";
    } catch (Exception $e) {
        $mensajes[] = "La columna 'nota_id' ya existe en 'documentos' o no se pudo crear: " . $e->getMessage();
    }

    echo "<h3>Migración Completada</h3>";
    echo "<ul>";
    foreach ($mensajes as $msg) {
        echo "<li>" . htmlspecialchars($msg) . "</li>";
    }
    echo "</ul>";
    echo "<p><a href='index.php'>Volver al CRM</a></p>";

} catch (Exception $e) {
    echo "<h3>Error en la migración</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
