<?php
/**
 * Migración: Crear tabla portal_cuentas
 * Separa las cuentas del portal de las solicitudes
 */

define('CRM_ROOT', dirname(__DIR__));
require_once CRM_ROOT . '/includes/config.php';
require_once CRM_ROOT . '/includes/Database.php';

$db = Database::getInstance();

try {
    // Tabla de cuentas del portal (independiente de solicitudes)
    $db->query("
        CREATE TABLE IF NOT EXISTS portal_cuentas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nombre VARCHAR(100) NOT NULL,
            apellidos VARCHAR(150) NOT NULL,
            email VARCHAR(255) NOT NULL UNIQUE,
            telefono VARCHAR(20) DEFAULT NULL,
            password_hash VARCHAR(255) NOT NULL,
            es_cliente TINYINT(1) NOT NULL DEFAULT 0,
            cliente_id INT DEFAULT NULL,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            ip_registro VARCHAR(45) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_email (email),
            INDEX idx_cliente (cliente_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Añadir portal_cuenta_id a solicitudes para vincular
    try {
        $db->query("ALTER TABLE solicitudes ADD COLUMN portal_cuenta_id INT DEFAULT NULL AFTER ip_solicitante");
        $db->query("ALTER TABLE solicitudes ADD INDEX idx_portal_cuenta (portal_cuenta_id)");
    } catch(Exception $e) {
        // Column may already exist
    }

    echo "✅ Migración portal_cuentas completada exitosamente.\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
